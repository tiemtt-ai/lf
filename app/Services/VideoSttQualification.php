<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Deployment qualification boundary for Video STT.
 *
 * Correctness tests may run in local/test without production evidence. A
 * production deployment must present a tracked/deployment-managed PASS record
 * tied to the exact processing identity and resource-control snapshot.
 */
class VideoSttQualification
{
    public function __construct(
        private readonly MediaProcessingOrchestrator $orchestrator,
    ) {}

    /** @return array{qualified: bool, code: string, message_key: string} */
    public function status(): array
    {
        if (! (bool) config('media.processing.speech_to_text.video_enabled', false)) {
            return $this->result(false, 'feature_disabled');
        }

        if (! (bool) config('media.processing.video_qualification.required', false)) {
            return $this->result(true, 'local_test');
        }

        $path = trim((string) config('media.processing.video_qualification.evidence_path', ''));
        if ($path === '' || ! is_file($path)) {
            return $this->result(false, 'evidence_missing');
        }

        try {
            $evidence = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->result(false, 'evidence_invalid');
        }
        if (! is_array($evidence)
            || ($evidence['schema_version'] ?? null) !== 1
            || ($evidence['verdict'] ?? null) !== 'PASS'
            || ! is_string($evidence['expires_at'] ?? null)
            || ! is_string($evidence['configuration_hash'] ?? null)
            || ! is_string($evidence['processing_version'] ?? null)) {
            return $this->result(false, 'evidence_invalid');
        }

        try {
            if (CarbonImmutable::parse($evidence['expires_at'])->isPast()) {
                return $this->result(false, 'evidence_expired');
            }
        } catch (\Throwable) {
            return $this->result(false, 'evidence_invalid');
        }

        if (! hash_equals($this->configurationHash(), $evidence['configuration_hash'])
            || ! hash_equals($this->processingVersion(), $evidence['processing_version'])) {
            return $this->result(false, 'identity_mismatch');
        }

        return $this->result(true, 'qualified');
    }

    public function isQualified(): bool
    {
        return $this->status()['qualified'];
    }

    /** @return array<string, int|string> */
    public function configurationSnapshot(): array
    {
        return [
            'processing_version' => $this->processingVersion(),
            'max_video_duration_seconds' => (int) config('media.processing.speech_to_text.max_video_duration_seconds'),
            'provider_timeout_seconds' => (int) config('media.processing.speech_to_text.timeout_seconds'),
            'video_audio_timeout_seconds' => (int) config('media.processing.video_audio.timeout_seconds'),
            'queue_retry_after_seconds' => (int) config('queue.connections.redis.retry_after', 0),
            'caption_max_cues' => (int) config('media.processing.caption.max_cues'),
            'caption_max_bytes' => (int) config('media.processing.caption.max_bytes'),
        ];
    }

    public function configurationHash(): string
    {
        return hash('sha256', json_encode($this->configurationSnapshot(), JSON_THROW_ON_ERROR));
    }

    public function processingVersion(): string
    {
        return $this->orchestrator->versionFor('speech_to_text', (object) ['file_type' => 'video']);
    }

    /** @return array{qualified: bool, code: string, message_key: string} */
    private function result(bool $qualified, string $code): array
    {
        return [
            'qualified' => $qualified,
            'code' => $code,
            'message_key' => 'lf.LF_course_template_activity_video_stt_qualification_'.$code,
        ];
    }
}
