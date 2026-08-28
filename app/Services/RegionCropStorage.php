<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Duong luu va vong doi cua anh crop vung.
 *
 * Crop duoc upload TRONG provider, con row duoc ghi trong transaction cua job.
 * Hai buoc do khong cung mot don vi nguyen tu: DB rollback duoc, object storage
 * thi khong. Neu revision that bai sau khi da upload vai crop, nhung file do
 * khong con row nao tham chieu — va chung co the chua PII cua hoc lieu.
 *
 * Vi the prefix cua mot revision phai la DUY NHAT cho revision do, de khi that
 * bai co the xoa ca prefix ma khong cham vao revision khac hay locale khac.
 */
class RegionCropStorage
{
    public function key(object $mediaFile, object $job, string $locale, int $page, int $ordinal): string
    {
        return $this->revisionPrefix($mediaFile, $job, $locale).'/'.$page.'-'.$ordinal.'.png';
    }

    public function revisionPrefix(object $mediaFile, object $job, string $locale): string
    {
        $safe = static fn (string $value): string => preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?? $value;

        return 'tenants/'.(int) $mediaFile->customer_id
            .'/media/'.(int) $mediaFile->id
            .'/regions/'.$safe((string) $job->source_fingerprint)
            .'/'.$safe((string) $job->processing_version)
            .'/'.$safe($locale);
    }

    /**
     * Xoa moi crop cua dung mot revision. Goi khi revision that bai, o bat ky
     * buoc nao sau khi crop dau tien da len storage.
     */
    public function purgeRevision(object $mediaFile, object $job, string $locale): bool
    {
        $disk = Storage::disk((string) $mediaFile->storage_disk);
        $prefix = $this->revisionPrefix($mediaFile, $job, $locale);
        $disk->deleteDirectory($prefix);

        return $disk->allFiles($prefix) === [];
    }

    /**
     * Xoa moi crop cua mot Media File, moi revision va moi locale.
     *
     * Tra ve ket qua thay vi nuot loi: `deleteDirectory()` co the tra `false`,
     * va coi `false` la thanh cong nghia la bao "da xoa PII" trong khi file van
     * con. Xac minh lai bang cach liet ke — driver co the tra `true` ma van con
     * sot object.
     */
    public function purgeMediaFile(object $mediaFile): bool
    {
        $disk = Storage::disk((string) $mediaFile->storage_disk);
        $prefix = 'tenants/'.(int) $mediaFile->customer_id.'/media/'.(int) $mediaFile->id.'/regions';

        if ($disk->allFiles($prefix) === []) {
            return true;
        }

        $disk->deleteDirectory($prefix);

        return $disk->allFiles($prefix) === [];
    }

    /** Locale canonical cua mot job, doc tu output_profile. */
    public static function localeOf(object $job): string
    {
        foreach (array_filter(explode(';', (string) ($job->output_profile ?? ''))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key === 'locale') {
                return $value;
            }
        }

        return '';
    }
}
