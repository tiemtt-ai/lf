<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Canonical input provenance for the optional structured revision. */
class DocumentCanonicalRevision
{
    private function rows(int $customerId, int $mediaId, string $fingerprint, string $locale): Builder
    {
        return DB::table('media_extracted_texts as text')
            ->join('media_processing_jobs as job', function ($join): void {
                $join->on('job.id', '=', 'text.processing_job_id')->on('job.customer_id', '=', 'text.customer_id')
                    ->on('job.media_file_id', '=', 'text.media_file_id')->on('job.processing_version', '=', 'text.processing_version')
                    ->on('job.source_fingerprint', '=', 'text.source_fingerprint');
            })
            ->where('text.customer_id', $customerId)->where('text.media_file_id', $mediaId)
            ->where('text.source_fingerprint', $fingerprint)->where('text.locale', $locale)
            ->where('text.status', 'ready')->where('job.status', 'ready')->where('job.job_type', 'ocr');
    }

    /** @param string|array<int, string> $profile */
    public function current(int $customerId, int $mediaId, string $fingerprint, string|array $profile): ?object
    {
        $locales = app(DocumentLanguageProfile::class)->canonical($profile);

        return $this->rows($customerId, $mediaId, $fingerprint, $locales[0])
            ->orderByDesc('job.id')->get(['job.id', 'job.processing_version', 'job.output_profile'])
            ->first(fn (object $row): bool => app(DocumentLanguageProfile::class)
                ->fromProfile((string) $row->output_profile) === $locales);
    }

    public function forStructure(int $customerId, object $media, object $job, string|array $profile): Collection
    {
        $locales = app(DocumentLanguageProfile::class)->canonical($profile);
        $metadata = json_decode((string) ($job->metadata ?? ''), true);
        $inputId = $metadata['canonical_processing_job_id'] ?? null;
        $current = $this->current($customerId, (int) $media->id, $job->source_fingerprint, $locales);
        if (! is_int($inputId) || $current === null || (int) $current->id !== $inputId) {
            throw new RuntimeException('source_unavailable');
        }

        return $this->rows($customerId, (int) $media->id, $job->source_fingerprint, $locales[0])
            ->where('job.id', $inputId)->orderBy('text.sequence')
            ->get(['text.locator_type', 'text.locator_value', 'text.char_count']);
    }
}
