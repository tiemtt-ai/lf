<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Duong luu va vong doi cua caption asset.
 *
 * LF-Media-Processing-Contract Amendment Record 2.21 § 6: storage key phai chua
 * tenant, Media, locale, source fingerprint, caption processing version va format
 * — de hai revision khong ghi de nhau. Bo doi mot thanh phan nao cung du de mot
 * revision moi de len ban cu, trong khi row cu van `archived` va van tro toi key do.
 */
class CaptionAssetStorage
{
    public function key(object $mediaFile, object $job, string $locale, string $format): string
    {
        $safe = static fn (string $value): string => preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?? $value;

        return 'tenants/'.(int) $mediaFile->customer_id
            .'/media/'.(int) $mediaFile->id
            .'/captions/'.$safe((string) $job->source_fingerprint)
            .'/'.$safe((string) $job->processing_version)
            .'/'.$safe($locale).'.'.$safe($format);
    }

    /**
     * Ghi asset roi XAC MINH no ton tai va dung do dai truoc khi tra ve.
     *
     * `put()` tra `true` khong chung minh object da nam tren storage — driver co
     * the bao thanh cong roi that bai o tang duoi. Database chi duoc chuyen `ready`
     * sau khi object duoc xac minh, nen phep xac minh phai nam o day.
     */
    public function write(object $mediaFile, string $key, string $contents): void
    {
        $disk = Storage::disk((string) $mediaFile->storage_disk);
        $disk->put($key, $contents);

        if (! $disk->exists($key) || (int) $disk->size($key) !== strlen($contents)) {
            $disk->delete($key);

            throw new \RuntimeException('caption_write_failed');
        }
    }

    /** Idempotent; goi khi persistence hong sau luc object da duoc ghi. */
    public function purge(object $mediaFile, string $key): bool
    {
        $disk = Storage::disk((string) $mediaFile->storage_disk);
        if (! $disk->exists($key)) {
            return true;
        }
        $disk->delete($key);

        return ! $disk->exists($key);
    }
}
