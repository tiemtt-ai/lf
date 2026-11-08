<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Where the board copy of a Media File lives: the PDF of an Office document
 * (LF-Media-Processing-Contract § Document board preview) or the faststart copy
 * of a video (§ Video board playback copy).
 *
 * The key carries the job id, so two runs never write the same object and a
 * failed run can only ever delete its own file, never a ready preview's.
 */
class DocumentPreviewStorage
{
    public const PROFILE = 'preset=pdf';

    public function key(object $mediaFile, object $job): string
    {
        $safe = static fn (string $value): string => preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?? $value;

        return 'tenants/'.(int) $mediaFile->customer_id
            .'/media/'.(int) $mediaFile->id
            .'/previews/'.$safe((string) $job->processing_version)
            .'/'.(int) $job->id.'.'.$this->extension($mediaFile);
    }

    /** An Office document's copy is a PDF; a video's copy keeps the source container (§ Video board playback copy). */
    private function extension(object $mediaFile): string
    {
        if (($mediaFile->file_type ?? null) !== 'video') {
            return 'pdf';
        }
        $extension = strtolower((string) ($mediaFile->extension ?? pathinfo((string) $mediaFile->storage_key, PATHINFO_EXTENSION)));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'mp4';
    }

    public static function eligible(object $mediaFile): bool
    {
        $extension = strtolower((string) ($mediaFile->extension ?? pathinfo((string) $mediaFile->storage_key, PATHINFO_EXTENSION)));

        return ($mediaFile->file_type ?? null) === 'document'
            && in_array($extension, (array) config('media.processing.document_preview.extensions', []), true);
    }

    /** Writes the PDF and confirms the stored object before the database may call it ready. */
    public function write(object $mediaFile, string $key, string $path): int
    {
        $size = filesize($path);
        $stream = fopen($path, 'rb');
        if ($size === false || $stream === false) {
            throw new RuntimeException('processing_failed');
        }
        $disk = Storage::disk((string) $mediaFile->storage_disk);
        try {
            $disk->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $disk->exists($key) || (int) $disk->size($key) !== $size) {
            $disk->delete($key);

            throw new RuntimeException('processing_failed');
        }

        return $size;
    }

    /** Idempotent; true once the object is gone. */
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
