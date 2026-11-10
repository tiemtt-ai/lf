<?php

namespace App\Http\Controllers;

use App\Support\MediaFileResponse;
use App\Support\MediaInlineHeaders;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MediaFileDeliveryController extends Controller
{
    public function show(Request $request, int $mediaFile)
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        $file = DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $mediaFile)
            ->first();

        abort_if(! $file || $file->status !== 'ready', 404);

        $headers = [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => $file->mime_type,
        ] + MediaInlineHeaders::for($file->mime_type);

        return MediaFileResponse::inline($file->storage_disk, $file->storage_key, (string) $file->display_name, $headers);
    }

    /**
     * A `preview` of a ready Media File of this tenant: the PDF of an Office
     * document or the faststart copy of a video, served with the row's own
     * MIME type (a video gets Range requests). Archived is still served for the
     * life of an already signed URL: the board may have asked for it just
     * before a newer revision replaced it.
     */
    public function variant(Request $request, int $mediaFile, int $variant)
    {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        $file = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFile)->first();
        abort_if(! $file || $file->status !== 'ready', 404);
        $row = DB::table('media_variants')->where('customer_id', $customerId)->where('media_file_id', $file->id)
            ->where('id', $variant)->where('variant_type', 'preview')->whereIn('status', ['ready', 'archived'])->first();
        abort_if(! $row, 404);

        $video = str_starts_with((string) $row->mime_type, 'video/');
        $extension = $video ? pathinfo((string) $row->storage_key, PATHINFO_EXTENSION) : 'pdf';
        $name = pathinfo((string) $file->display_name, PATHINFO_FILENAME).'.'.$extension;
        $headers = [
            'Content-Type' => $video ? (string) $row->mime_type : 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return MediaFileResponse::inline($file->storage_disk, $row->storage_key, $name, $headers);
    }

    /**
     * A derived object of a ready Media File of this tenant (LF-Media § Signed Delivery): the crop of a `region` or a
     * `formula`, a `caption` file or a `variant`, looked up by customer_id, media_file_id and the row id. Archived rows
     * are still served for the life of an already signed URL, as a pinned revision may be read.
     */
    public function derived(Request $request, int $mediaFile, string $kind, int $derived)
    {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        $file = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFile)->first();
        abort_if(! $file || $file->status !== 'ready', 404);
        $live = ['ready', 'archived'];

        [$storageKey, $mimeType, $extension] = match ($kind) {
            'region' => $this->crop(DB::table('media_extracted_regions')->where('customer_id', $customerId)
                ->where('media_file_id', $file->id)->where('id', $derived)->whereIn('status', $live)->first(['crop_storage_key', 'crop_mime_type'])),
            'formula' => $this->crop(DB::table('media_extracted_formulas as formula')
                ->join('media_extracted_regions as region', function ($join): void {
                    $join->on('region.id', '=', 'formula.region_id')->on('region.customer_id', '=', 'formula.customer_id')
                        ->on('region.media_file_id', '=', 'formula.media_file_id');
                })
                ->where('formula.customer_id', $customerId)->where('formula.media_file_id', $file->id)->where('formula.id', $derived)
                ->whereIn('region.status', $live)->first(['region.crop_storage_key', 'region.crop_mime_type'])),
            'caption' => $this->caption(DB::table('media_captions')->where('customer_id', $customerId)
                ->where('media_file_id', $file->id)->where('id', $derived)->whereIn('status', $live)->first(['storage_key', 'caption_type'])),
            'variant' => $this->variantObject(DB::table('media_variants')->where('customer_id', $customerId)
                ->where('media_file_id', $file->id)->where('id', $derived)->whereIn('status', $live)->first(['storage_key', 'mime_type'])),
            default => abort(404),
        };

        $name = pathinfo((string) $file->display_name, PATHINFO_FILENAME).'-'.$kind.($extension !== '' ? '.'.$extension : '');

        return MediaFileResponse::inline($file->storage_disk, $storageKey, $name, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function crop(?object $row): array
    {
        abort_if(! $row || blank($row->crop_storage_key), 404);
        $key = (string) $row->crop_storage_key;

        return [$key, (string) ($row->crop_mime_type ?: 'image/png'), (string) pathinfo($key, PATHINFO_EXTENSION)];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function caption(?object $row): array
    {
        abort_if(! $row, 404);
        $type = strtolower((string) $row->caption_type);

        return [(string) $row->storage_key, $type === 'vtt' ? 'text/vtt; charset=UTF-8' : 'text/plain; charset=UTF-8', $type];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function variantObject(?object $row): array
    {
        abort_if(! $row, 404);

        return [(string) $row->storage_key, (string) $row->mime_type, (string) pathinfo((string) $row->storage_key, PATHINFO_EXTENSION)];
    }
}
