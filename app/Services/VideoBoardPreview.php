<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * The faststart copy of an uploaded MP4/MOV video for the LiveClass board
 * (LF-Media-Processing-Contract § Video board playback copy).
 *
 * A video whose `moov` index comes after `mdat` makes Safari fetch the end of
 * the file before it can play. The copy moves the index to the front; streams
 * are copied, never re-encoded. Readiness comes from the `media_variants` row.
 */
class VideoBoardPreview
{
    public const PROFILE = 'preset=faststart';

    /** Top-level boxes read before giving up: a real MP4 has a handful. */
    private const MAX_BOXES = 64;

    public function __construct(private readonly MediaProcessingOrchestrator $orchestrator) {}

    /** MP4/MOV video by extension; whether it needs a copy is `indexAtEnd`. */
    public static function eligible(object $mediaFile): bool
    {
        $extension = strtolower((string) ($mediaFile->extension ?? pathinfo((string) ($mediaFile->storage_key ?? ''), PATHINFO_EXTENSION)));

        return ($mediaFile->file_type ?? null) === 'video'
            && in_array($extension, (array) config('media.processing.video_preview.extensions', []), true);
    }

    /**
     * True when the stored file needs a faststart copy. Reads only box headers;
     * a file that cannot be read this way (missing, not seekable, malformed) is
     * left as it is.
     */
    public function needsCopy(object $mediaFile): bool
    {
        if (! self::eligible($mediaFile)) {
            return false;
        }
        try {
            $stream = Storage::disk((string) $mediaFile->storage_disk)->readStream((string) $mediaFile->storage_key);
        } catch (Throwable) {
            return false;
        }
        if (! is_resource($stream)) {
            return false;
        }
        try {
            return self::indexAtEnd($stream) === true;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Order of the top-level `moov` and `mdat` boxes of an ISO media file:
     * true when `moov` comes after `mdat`, false when it comes first, null when
     * the stream cannot tell (not seekable, malformed, no `moov`).
     *
     * @param  resource  $stream
     */
    public static function indexAtEnd($stream): ?bool
    {
        $meta = stream_get_meta_data($stream);
        if (! ($meta['seekable'] ?? false)) {
            return null;
        }
        $offset = 0;
        $seenMedia = false;
        for ($box = 0; $box < self::MAX_BOXES; $box++) {
            if (fseek($stream, $offset) !== 0) {
                return null;
            }
            $header = fread($stream, 8);
            if (! is_string($header) || strlen($header) < 8) {
                return null;
            }
            ['size' => $size, 'type' => $type] = unpack('Nsize/a4type', $header);
            $headerSize = 8;
            if ($size === 1) {
                $large = fread($stream, 8);
                if (! is_string($large) || strlen($large) < 8) {
                    return null;
                }
                $size = unpack('J', $large)[1];
                $headerSize = 16;
            }
            if ($type === 'moov') {
                return $seenMedia;
            }
            if ($type === 'mdat') {
                $seenMedia = true;
            }
            if ($size === 0) {
                return null; // The last box runs to the end and it was not `moov`.
            }
            if ($size < $headerSize) {
                return null;
            }
            $offset += $size;
        }

        return null;
    }

    /** The ready copy for the current source, or null (the board then plays the original). */
    public function readyVariant(int $customerId, object $media): ?object
    {
        if (! self::eligible($media) || ! is_string($media->checksum ?? null) || trim($media->checksum) === '') {
            return null;
        }
        try {
            $fingerprint = $this->orchestrator->sourceFingerprint($media);
        } catch (InvalidArgumentException) {
            return null;
        }

        return DB::table('media_variants')->where('customer_id', $customerId)
            ->where('media_file_id', $media->id)->where('variant_type', 'preview')
            ->where('status', 'ready')->where('mime_type', 'like', 'video/%')
            ->where('source_fingerprint', $fingerprint)
            ->orderByDesc('processing_job_id')->orderByDesc('id')->first();
    }
}
