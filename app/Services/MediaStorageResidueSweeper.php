<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Tim va xoa object storage khong con ai tham chieu.
 *
 * Object storage va DB khong co transaction chung, nen co hai nguon rac khac
 * nhau — va chung KHONG cung mot dieu kien:
 *
 * 1. Media da `deleted`: ca source lan cay crop deu phai bien mat.
 * 2. Revision structured that bai tren mot Media VAN `ready`: crop da len storage
 *    truoc khi persistence hong. Media khong doi trang thai, dung theo thiet ke —
 *    mot lan trich xuat hong khong duoc lam tai lieu mat `ready`. Quet theo
 *    `status = 'deleted'` se khong bao gio thay loai rac nay.
 *
 * Voi loai 2, thu duoc xoa la crop KHONG duoc row `media_extracted_regions` nao
 * tham chieu. Do la dinh nghia chinh xac cua "mo coi", va no dung ca khi revision
 * duoc chay lai thanh cong sau do.
 *
 * KHONG co lich chay tu dong. Admin bam nut trong Quan ly Media, hoac operator
 * chay `media:purge-deleted-storage`. Quyet dinh cua Owner 2026-08-28: khong dat
 * bat ky tac vu nao tu dong xoa file.
 */
class MediaStorageResidueSweeper
{
    public function __construct(private readonly MediaService $media) {}

    /**
     * `$limit` dem so Media CO RESIDUE, khong dem row da quet va khong dem row
     * loi. Neu row loi cung tieu ton limit thi 500 Media tren mot disk dang hong
     * se chan vinh vien moi residue hop le nam phia sau — dung kieu doi ma
     * `--limit` sinh ra de tranh.
     *
     * @return array<int, array{media: object, keys: array<int, string>}>
     */
    public function scan(?int $customerId = null, int $limit = 500, int $failureCap = 100): array
    {
        $found = [];
        $residue = 0;
        $failures = 0;

        foreach ($this->candidates($customerId) as $row) {
            if ($residue >= $limit) {
                break;
            }
            if ($this->hasInFlightStructuredJob($row)) {
                continue;
            }
            try {
                $keys = $this->residueOf($row);
            } catch (Throwable) {
                // Mot disk hong khong duoc lam ca luot quet dung lai. Row loi bao
                // cao rieng bang mang keys rong, va KHONG tinh vao $residue.
                if ($failures < $failureCap) {
                    $failures++;
                    $found[] = ['media' => $row, 'keys' => []];
                }

                continue;
            }
            if ($keys !== []) {
                $residue++;
                $found[] = ['media' => $row, 'keys' => $keys];
            }
        }

        return $found;
    }

    /**
     * Xoa residue cua mot Media, duoi khoa row `media_files`.
     *
     * Khoa la bat buoc, khong phai than trong thua. Provider day crop len storage
     * TRUOC khi job ghi `media_extracted_regions`. Giua hai buoc do, crop ton tai
     * ma chua row nao tro toi — nhin tu ngoai vao thi khong phan biet duoc voi
     * rac mo coi. Bam Don ngay dung luc do se xoa crop cua mot job sap thanh cong,
     * va revision `ready` se tro toi file khong con ton tai.
     *
     * `ProcessMediaProcessingJob` claim job trong mot transaction co
     * `lockForUpdate()` tren dung row `media_files` nay, nen hai ben xep hang:
     * khong the vua claim job vua xoa file cua no.
     *
     * Danh sach keys duoc tinh LAI trong khoa. Danh sach tu luc quet co the da cu
     * — mot revision chay lai co the vua ghi de dung nhung key do.
     *
     * @param  array{media: object, keys: array<int, string>}  $item
     */
    /** @return array{success: bool, deleted_count: int} */
    public function purge(array $item): array
    {
        try {
            return DB::transaction(function () use ($item): array {
                $row = DB::table('media_files')
                    ->where('customer_id', $item['media']->customer_id)
                    ->where('id', $item['media']->id)
                    ->lockForUpdate()
                    ->first(['id', 'customer_id', 'storage_disk', 'storage_key', 'status']);

                if ($row === null || $this->hasInFlightStructuredJob($row)) {
                    return ['success' => false, 'deleted_count' => 0];
                }

                if ($row->status === 'deleted') {
                    $keys = $this->residueOf($row);
                    $success = $this->media->purgeMediaStorage($row);

                    return ['success' => $success, 'deleted_count' => $success ? count($keys) : 0];
                }

                $keys = $this->residueOf($row);
                if ($keys === []) {
                    return ['success' => true, 'deleted_count' => 0];
                }

                $disk = Storage::disk((string) $row->storage_disk);
                $disk->delete($keys);

                foreach ($keys as $key) {
                    if ($disk->exists($key)) {
                        return ['success' => false, 'deleted_count' => 0];
                    }
                }

                return ['success' => true, 'deleted_count' => count($keys)];
            });
        } catch (Throwable) {
            return ['success' => false, 'deleted_count' => 0];
        }
    }

    /**
     * Co job structured nao dang tren duong chay cho Media nay khong.
     *
     * `pending` cung tinh: job da duoc xep hang co the bi claim bat cu luc nao,
     * va tu luc claim toi luc crop dau tien len storage chi la mot lenh render.
     */
    private function hasInFlightStructuredJob(object $row): bool
    {
        return DB::table('media_processing_jobs')
            ->where('customer_id', $row->customer_id)
            ->where('media_file_id', $row->id)
            ->where('job_type', 'structured_extraction')
            ->whereIn('status', ['pending', 'processing'])
            ->exists();
    }

    /**
     * Media da `deleted`, cong Media con `ready` nhung tung co revision
     * structured that bai.
     *
     * Phan trang theo id thay vi giu mot cursor mo: vong lap than ham nay chay
     * them query, va mot cursor dang mo tren cung connection co the bi cat ngan.
     *
     * @return iterable<int, object>
     */
    private function candidates(?int $customerId): iterable
    {
        $columns = ['id', 'customer_id', 'storage_disk', 'storage_key', 'status', 'display_name'];
        $sources = [
            fn () => DB::table('media_files')->where('status', 'deleted'),
            fn () => DB::table('media_files')
                ->whereIn('id', DB::table('media_processing_jobs')->select('media_file_id')
                    ->where('job_type', 'structured_extraction')->where('status', 'failed'))
                ->where('status', '<>', 'deleted'),
        ];

        foreach ($sources as $source) {
            $lastId = 0;
            while (true) {
                $batch = $source()
                    ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
                    ->where('id', '>', $lastId)
                    ->orderBy('id')->limit(200)->get($columns);

                if ($batch->isEmpty()) {
                    break;
                }
                $lastId = (int) $batch->last()->id;
                yield from $batch;
            }
        }
    }

    /** @return array<int, string> */
    private function residueOf(object $row): array
    {
        $disk = Storage::disk((string) $row->storage_disk);
        $tree = 'tenants/'.(int) $row->customer_id.'/media/'.(int) $row->id.'/regions';

        if ($row->status === 'deleted') {
            return array_merge(
                $disk->exists((string) $row->storage_key) ? [(string) $row->storage_key] : [],
                $disk->allFiles($tree),
            );
        }

        $referenced = DB::table('media_extracted_regions')
            ->where('customer_id', $row->customer_id)->where('media_file_id', $row->id)
            ->whereNotNull('crop_storage_key')->pluck('crop_storage_key')->all();

        return array_values(array_diff($disk->allFiles($tree), $referenced));
    }
}
