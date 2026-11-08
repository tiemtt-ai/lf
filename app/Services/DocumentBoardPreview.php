<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * State of the board PDF of an Office document
 * (LF-Media-Processing-Contract § Document board preview).
 *
 * Readiness comes from the `media_variants` row, never from `media_files`. A
 * ready preview of an older processing version keeps serving until the newer
 * run commits and archives it.
 */
class DocumentBoardPreview
{
    public const READY = 'ready';

    public const PROCESSING = 'processing';

    public const FAILED = 'failed';

    public const ABSENT = 'absent';

    public function __construct(private readonly MediaProcessingOrchestrator $orchestrator) {}

    /**
     * Null when the file is not an Office document.
     *
     * @return array{status: string, variant_id: int|null, page_count: int|null, error_code: string|null, can_request: bool}|null
     */
    public function state(int $customerId, object $media): ?array
    {
        if (! DocumentPreviewStorage::eligible($media)) {
            return null;
        }

        $variant = $this->readyVariant($customerId, $media);
        if ($variant !== null) {
            $metadata = json_decode((string) ($variant->metadata ?? ''), true);

            return [
                'status' => self::READY,
                'variant_id' => (int) $variant->id,
                'page_count' => is_int($metadata['page_count'] ?? null) ? $metadata['page_count'] : null,
                'error_code' => null,
                'can_request' => false,
            ];
        }

        try {
            $job = $this->orchestrator->latestBoardPreviewJob($customerId, $media);
        } catch (InvalidArgumentException) {
            $job = null; // No checksum: nothing can ever be converted.
        }
        $status = match ($job?->status) {
            'pending', 'processing' => self::PROCESSING,
            'failed' => self::FAILED,
            default => self::ABSENT,
        };

        return [
            'status' => $status,
            'variant_id' => null,
            'page_count' => null,
            'error_code' => $status === self::FAILED ? $job->error_code : null,
            'can_request' => $status === self::ABSENT
                || ($status === self::FAILED && MediaProcessingOrchestrator::boardPreviewRetryable($job)),
        ];
    }

    public function readyVariant(int $customerId, object $media): ?object
    {
        if (! DocumentPreviewStorage::eligible($media) || ! is_string($media->checksum ?? null) || trim($media->checksum) === '') {
            return null;
        }

        return DB::table('media_variants')->where('customer_id', $customerId)
            ->where('media_file_id', $media->id)->where('variant_type', 'preview')
            ->where('status', 'ready')->where('mime_type', 'application/pdf')
            ->where('source_fingerprint', $this->orchestrator->sourceFingerprint($media))
            ->orderByDesc('processing_job_id')->orderByDesc('id')->first();
    }
}
