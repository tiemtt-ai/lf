<?php

namespace App\Services;

use App\Jobs\ProcessMediaProcessingJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MediaProcessingOrchestrator
{
    public function __construct(private readonly MediaOutputProfile $profiles) {}

    public function materializeVirusScanOnUpload(int $customerId, int $mediaFileId, ?int $actorId): void
    {
        DB::transaction(function () use ($customerId, $mediaFileId, $actorId): void {
            $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->lockForUpdate()->first();
            if (! $media) {
                return;
            }
            $this->createInitialJob($customerId, $media, 'virus_scan', '', $actorId);
        });
    }

    /**
     * @param  bool  $structuredExtraction  Opt-in cua tac gia tren form upload. Docling khong thay
     *                                      the OCR — no them lop cau truc ben tren cung text.
     */
    public function materializeForCourseActivity(int $customerId, int $mediaFileId, ?string $locale, ?int $actorId, bool $structuredExtraction = false, bool $speechToText = true, bool $reattach = false): void
    {
        DB::transaction(function () use ($customerId, $mediaFileId, $locale, $actorId, $structuredExtraction, $speechToText, $reattach): void {
            $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->lockForUpdate()->first();
            if (! $media) {
                throw new InvalidArgumentException('Media File not found.');
            }

            $this->assertDocumentUsage($customerId, $media);
            $this->assertAudioUsage($customerId, $media, $speechToText);

            try {
                $canonicalLocale = $this->canonicalLocaleFor($media, $locale, $speechToText);
            } catch (InvalidArgumentException) {
                DB::table('media_files')->where('id', $mediaFileId)->where('customer_id', $customerId)->update([
                    'status' => 'failed', 'processing_error_code' => 'required_profile_configuration_missing', 'updated_at' => now(),
                ]);

                return;
            }

            if ($media->processing_locale !== null && $canonicalLocale !== null && $media->processing_locale !== $canonicalLocale) {
                DB::table('media_files')->where('id', $mediaFileId)->where('customer_id', $customerId)->update([
                    'status' => 'failed', 'processing_error_code' => 'required_profile_configuration_missing', 'updated_at' => now(),
                ]);

                return;
            }

            DB::table('media_files')->where('id', $mediaFileId)->where('customer_id', $customerId)->update([
                'processing_locale' => $media->processing_locale ?? $canonicalLocale,
                'processing_error_code' => null,
                'updated_at' => now(),
            ]);

            foreach ($this->initialMaterializationProfiles($media->file_type, $canonicalLocale, $speechToText) as [$jobType, $profile]) {
                $this->createInitialJob($customerId, $media, $jobType, $profile, $actorId, $reattach, $structuredExtraction);
            }

            // Opt-in, khong nam trong required set: structured extraction that bai
            // khong duoc lam file mat 'ready', va moi truong chua co Python runtime
            // van upload duoc binh thuong.
            if ($structuredExtraction && $media->file_type === 'document') {
                $this->createInitialJob($customerId, $media, 'structured_extraction',
                    $this->profiles->canonical(['locale' => (string) $canonicalLocale, 'structure' => $this->structureFor($media)]),
                    $actorId, $reattach);
            }
        });
    }

    /**
     * Khoi tao STT lan dau cho Media legacy duoc upload truoc khi locale bat buoc.
     * Day khong phai retry: Media da co bat ky STT job nao phai di theo state
     * machine/retry chain hien co, va locale da ghi khong duoc sua tai day.
     */
    public function initializeLegacySpeechToText(int $customerId, int $activityId, string $locale, ?int $actorId): void
    {
        DB::transaction(function () use ($customerId, $activityId, $locale, $actorId): void {
            $usage = DB::table('media_file_usages')
                ->where('customer_id', $customerId)
                ->where('owner_type', 'course_activity')
                ->where('owner_id', $activityId)
                ->where('usage_type', 'audio')
                ->where('status', 'active')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if (! $usage) {
                throw new InvalidArgumentException('Active audio usage not found.');
            }
            $mediaFileId = (int) $usage->media_file_id;
            $media = DB::table('media_files')
                ->where('customer_id', $customerId)
                ->where('id', $mediaFileId)
                ->lockForUpdate()
                ->first();

            if (! $media || $media->status !== 'ready' || $media->file_type !== 'audio') {
                throw new InvalidArgumentException('Media is not eligible for initial transcription.');
            }
            if ($media->processing_locale !== null) {
                throw new InvalidArgumentException('Processing locale is already fixed.');
            }
            if (DB::table('media_processing_jobs')->where('customer_id', $customerId)
                ->where('media_file_id', $mediaFileId)->where('job_type', 'speech_to_text')->exists()) {
                throw new InvalidArgumentException('A transcription job already exists.');
            }

            $canonicalLocale = $this->profiles->canonicalLocale($locale);
            $usageMetadata = json_decode((string) ($usage->metadata ?? ''), true);
            $usageMetadata = is_array($usageMetadata) ? $usageMetadata : [];
            $usageMetadata['speech_to_text'] = true;
            $usageMetadata['processing_locale'] = $canonicalLocale;
            DB::table('media_file_usages')->where('customer_id', $customerId)->where('id', $usage->id)->update([
                'metadata' => json_encode($usageMetadata, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->update([
                'processing_locale' => $canonicalLocale,
                'processing_error_code' => null,
                'updated_at' => now(),
            ]);

            $media->processing_locale = $canonicalLocale;
            $this->createInitialJob(
                $customerId,
                $media,
                'speech_to_text',
                $this->profiles->canonical(['diarization' => 'off', 'locale' => $canonicalLocale]),
                $actorId,
            );
        });
    }

    public function retry(int $customerId, int $failedJobId, ?int $actorId = null): object
    {
        return DB::transaction(function () use ($customerId, $failedJobId, $actorId): object {
            $failed = DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('id', $failedJobId)->lockForUpdate()->first();
            if (! $failed || $failed->status !== 'failed'
                || ! in_array($failed->error_code, ['provider_timeout', 'provider_unavailable', 'rate_limited'], true)
                || (int) $failed->attempt >= 3) {
                throw new InvalidArgumentException('Job is not retry eligible.');
            }
            if (in_array($failed->job_type, ['ocr', 'structured_extraction'], true)
                || $failed->job_type === 'speech_to_text') {
                $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $failed->media_file_id)->lockForUpdate()->first();
                if (! $media) {
                    throw new InvalidArgumentException('Media File not found.');
                }
                $this->assertDocumentUsage($customerId, $media);
                $this->assertAudioUsage($customerId, $media, true);
            }
            $highest = DB::table('media_processing_jobs')->where('customer_id', $customerId)
                ->where('media_file_id', $failed->media_file_id)->where('job_type', $failed->job_type)
                ->where('source_fingerprint', $failed->source_fingerprint)->where('processing_version', $failed->processing_version)
                ->where('output_profile_hash', $failed->output_profile_hash)->max('attempt');
            if (DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('supersedes_job_id', $failed->id)->exists() || (int) $highest !== (int) $failed->attempt) {
                throw new InvalidArgumentException('Only the highest attempt may retry.');
            }
            $attempt = (int) $failed->attempt + 1;
            $key = $this->generationKey($customerId, $failed->media_file_id, $failed->job_type, $failed->source_fingerprint, $failed->processing_version, $failed->output_profile_hash, $attempt, (int) $failed->dispatch_generation);
            $id = DB::table('media_processing_jobs')->insertGetId([
                'customer_id' => $customerId, 'media_file_id' => $failed->media_file_id, 'job_type' => $failed->job_type,
                'status' => 'pending', 'attempt' => $attempt, 'dispatch_generation' => $failed->dispatch_generation, 'supersedes_job_id' => $failed->id,
                'metadata' => in_array($failed->job_type, ['ocr', 'structured_extraction'], true) ? $failed->metadata : null,
                'idempotency_key' => $key, 'correlation_id' => $failed->correlation_id,
                'source_fingerprint' => $failed->source_fingerprint, 'processing_version' => $failed->processing_version,
                'output_profile' => $failed->output_profile, 'output_profile_hash' => $failed->output_profile_hash,
                'provider' => $failed->provider, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $jitter = random_int(80, 120) / 100;
            $delay = (int) round(60 * (2 ** ($attempt - 2)) * $jitter);
            DB::afterCommit(fn () => ProcessMediaProcessingJob::dispatch($customerId, (int) $id)->delay(now()->addSeconds($delay)));

            return DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('id', $id)->first();
        });
    }

    /** @param array<string, string> $parameters */
    public function materializeOnDemandProfile(int $customerId, int $mediaFileId, string $jobType, array $parameters, ?int $actorId = null): void
    {
        $allowed = [
            'ocr' => ['layout', 'locale'],
            'speech_to_text' => ['diarization', 'locale'],
            'caption' => ['format', 'locale'],
            'thumbnail' => ['size'],
            'transcode' => ['preset'],
            'structured_extraction' => ['locale', 'structure'],
        ];
        $parameterKeys = array_keys($parameters);
        sort($parameterKeys);
        $expectedKeys = $allowed[$jobType] ?? [];
        sort($expectedKeys);
        if ($parameterKeys !== $expectedKeys) {
            throw new InvalidArgumentException('Unsupported output profile.');
        }
        if (isset($parameters['locale'])) {
            $parameters['locale'] = $this->profiles->canonicalLocale($parameters['locale']);
        }
        if (($jobType === 'ocr' && ($parameters['layout'] ?? null) !== 'preserve')
            || ($jobType === 'structured_extraction' && ! in_array($parameters['structure'] ?? null, ['layout', 'cells'], true))) {
            throw new InvalidArgumentException('Unsupported output profile.');
        }
        $profile = $this->profiles->canonical($parameters);
        DB::transaction(function () use ($customerId, $mediaFileId, $jobType, $profile, $actorId): void {
            $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->lockForUpdate()->first();
            if (! $media) {
                throw new InvalidArgumentException('Media File not found.');
            }
            if ($jobType === 'structured_extraction') {
                $locale = $this->profiles->parse($profile)['locale'];
                if (app(DocumentCanonicalRevision::class)->current($customerId, (int) $media->id, $this->sourceFingerprint($media), $locale) === null) {
                    throw new InvalidArgumentException('Canonical OCR revision is not ready.');
                }
                $extension = strtolower((string) ($media->extension ?? pathinfo($media->storage_key, PATHINFO_EXTENSION)));
                $expected = in_array($extension, ['xls', 'xlsx'], true) ? 'cells' : 'layout';
                if (($this->profiles->parse($profile)['structure'] ?? null) !== $expected) {
                    throw new InvalidArgumentException('Unsupported output profile.');
                }
            }
            $this->createInitialJob($customerId, $media, $jobType, $profile, $actorId);
        });
    }

    /**
     * Read-only mirror of materializeForCourseActivity, for operator inspection
     * before a chain that costs a provider call is enqueued.
     *
     * @return array<int, array{job_type: string, output_profile: string, provider: string, processing_version: string, existing_job_id: int|null}>
     */
    public function planForCourseActivity(int $customerId, int $mediaFileId, ?string $locale): array
    {
        $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->first();
        if (! $media) {
            throw new InvalidArgumentException('Media File not found.');
        }

        $canonicalLocale = $this->canonicalLocaleFor($media, $locale);
        if ($media->processing_locale !== null && $canonicalLocale !== null && $media->processing_locale !== $canonicalLocale) {
            throw new InvalidArgumentException('Requested locale conflicts with the recorded processing locale.');
        }

        $fingerprint = $this->sourceFingerprint($media);
        $plan = [];
        foreach ($this->initialMaterializationProfiles($media->file_type, $canonicalLocale) as [$jobType, $profile]) {
            if (in_array($jobType, ['ocr', 'structured_extraction'], true)) {
                $this->assertDocumentUsage($customerId, $media);
            }
            $version = $this->versionFor($jobType, $media, $this->profiles->parse($profile));
            $key = $this->initialIdempotencyKey($media, $jobType, $fingerprint, $version, $this->profiles->hash($profile));
            $existing = DB::table('media_processing_jobs')->where('customer_id', $customerId)
                ->when($media->file_type === 'document' && $jobType === 'ocr',
                    fn ($q) => $q->where('media_file_id', $media->id)->where('job_type', $jobType)->where('source_fingerprint', $fingerprint)
                        ->where('processing_version', $version)->where('output_profile_hash', $this->profiles->hash($profile)),
                    fn ($q) => $q->where('idempotency_key', $key))
                ->orderByDesc('dispatch_generation')->orderByDesc('attempt')->value('id');
            $plan[] = [
                'job_type' => $jobType,
                'output_profile' => $profile,
                'provider' => $this->providerFor($jobType, $media),
                'processing_version' => $version,
                'existing_job_id' => $existing !== null ? (int) $existing : null,
            ];
        }

        return $plan;
    }

    /** @return array<int, array{string, string}> */
    /**
     * Profile duoc materialize NGAY luc upload — khong phai toan bo required set.
     *
     * `caption` van la output bat buoc cua video, nhung deferred: no chi duoc tao
     * sau khi STT commit mot transcript revision `ready`. Ten cu
     * `requiredProfiles()` vi the noi sai pham vi cua chinh no.
     */
    /** Transcript revision `ready` hien hanh cua mot Media/locale, neu co dung mot. */
    private function currentTranscriptRevision(object $media, string $locale): ?string
    {
        $versions = DB::table('media_transcripts')
            ->where('customer_id', $media->customer_id)
            ->where('media_file_id', $media->id)
            ->where('locale', $locale)
            ->where('status', 'ready')
            ->distinct()
            ->pluck('processing_version')
            ->all();

        return count($versions) === 1 ? (string) $versions[0] : null;
    }

    private function initialMaterializationProfiles(string $fileType, ?string $locale, bool $speechToText = true): array
    {
        $jobs = [['virus_scan', '']];
        if ($fileType === 'document') {
            $jobs[] = ['ocr', $this->profiles->canonical(['layout' => 'preserve', 'locale' => (string) $locale])];
        }
        $videoSttEligible = (bool) config('media.processing.speech_to_text.video_enabled', false)
            && app(VideoSttQualification::class)->isQualified();
        if (($fileType === 'video' && $speechToText && $videoSttEligible) || ($fileType === 'audio' && $speechToText)) {
            $jobs[] = ['speech_to_text', $this->profiles->canonical(['diarization' => 'off', 'locale' => (string) $locale])];
        }
        // Caption KHONG thuoc initial required set. Amendment 2.19 / conflict
        // 0025 chot rang caption chi duoc materialize sau khi STT da commit mot
        // transcript revision `ready`. Tao no o day se dispatch dong thoi voi
        // STT; khi video gate tat thi con te hon: caption ton tai trong khi
        // transcript bi quyet dinh khong sinh ra.

        return $jobs;
    }

    private function createInitialJob(int $customerId, object $media, string $jobType, string $profile, ?int $actorId, bool $reattach = false, bool $requestStructure = false): void
    {
        if (in_array($jobType, ['ocr', 'structured_extraction'], true)) {
            $this->assertDocumentUsage($customerId, $media);
        }
        if ($jobType === 'speech_to_text') {
            $this->assertAudioUsage($customerId, $media, true);
        }
        $metadata = $jobType === 'ocr' && $requestStructure ? ['structured_requested' => true] : [];
        if ($jobType === 'structured_extraction') {
            $locale = $this->profiles->parse($profile)['locale'];
            $input = app(DocumentCanonicalRevision::class)->current($customerId, (int) $media->id, $this->sourceFingerprint($media), $locale);
            if ($input === null) {
                return; // Initial opt-in is recorded on OCR and active usage, then dispatched after ready.
            }
            $metadata['canonical_processing_job_id'] = (int) $input->id;
        }
        $version = $this->versionFor($jobType, $media, $this->profiles->parse($profile));
        $provider = $this->providerFor($jobType, $media);
        $fingerprint = $this->sourceFingerprint($media);
        $profileHash = $this->profiles->hash($profile);
        $key = $this->initialIdempotencyKey($media, $jobType, $fingerprint, $version, $profileHash);
        $latest = DB::table('media_processing_jobs')->where('customer_id', $customerId)
            ->where('media_file_id', $media->id)->where('job_type', $jobType)
            ->where('source_fingerprint', $fingerprint)->where('processing_version', $version)
            ->where('output_profile_hash', $profileHash)
            ->when($media->file_type !== 'document' || ! in_array($jobType, ['ocr', 'structured_extraction'], true), fn ($q) => $q->where('idempotency_key', $key))
            ->orderByDesc('dispatch_generation')->orderByDesc('attempt')->first();
        $successor = $reattach
            && (($media->file_type === 'document' && in_array($jobType, ['ocr', 'structured_extraction'], true))
                || ($media->file_type === 'audio' && $jobType === 'speech_to_text'))
            && $latest?->status === 'cancelled' && $latest->started_at === null
            && $latest->error_code === null;
        $generation = $successor ? (int) $latest->dispatch_generation + 1 : 1;
        $attempt = $successor ? (int) $latest->attempt : 1;
        if ($successor) {
            $key = $this->generationKey($customerId, $media->id, $jobType, $fingerprint, $version, $profileHash, $attempt, $generation);
        }
        $id = $successor ? null : $latest?->id;
        if (! $id) {
            try {
                $id = DB::table('media_processing_jobs')->insertGetId([
                    'customer_id' => $customerId, 'media_file_id' => $media->id, 'job_type' => $jobType,
                    'status' => 'pending', 'attempt' => $attempt, 'dispatch_generation' => $generation,
                    'supersedes_job_id' => $successor ? $latest->id : null,
                    'idempotency_key' => $key, 'correlation_id' => $successor ? $latest->correlation_id : (string) Str::uuid(),
                    'source_fingerprint' => $fingerprint, 'processing_version' => $version, 'output_profile' => $profile,
                    'output_profile_hash' => $profileHash, 'provider' => $provider, 'created_by' => $actorId,
                    'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (QueryException $exception) {
                $id = DB::table('media_processing_jobs')->where('customer_id', $customerId)
                    ->where('idempotency_key', $key)->value('id');
                if (! $id) {
                    throw $exception;
                }
            }
        }
        if ($jobType === 'ocr' && $requestStructure) {
            $pending = DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('id', $id)->where('status', 'pending')->first();
            if ($pending !== null) {
                $meta = json_decode((string) ($pending->metadata ?? ''), true) ?: [];
                DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('id', $id)->where('status', 'pending')
                    ->update(['metadata' => json_encode($meta + ['structured_requested' => true], JSON_THROW_ON_ERROR)]);
            }
        }
        $jobId = (int) $id;
        DB::afterCommit(fn () => ProcessMediaProcessingJob::dispatch($customerId, $jobId));
    }

    private function structureFor(object $media): string
    {
        $extension = strtolower((string) ($media->extension ?? pathinfo($media->storage_key, PATHINFO_EXTENSION)));

        return in_array($extension, ['xls', 'xlsx'], true) ? 'cells' : 'layout';
    }

    private function assertDocumentUsage(int $customerId, object $media): void
    {
        if ($media->file_type === 'document' && ($media->status === 'deleted'
            || ! app(DocumentProcessingEligibility::class)->hasActiveUsage($customerId, (int) $media->id))) {
            throw new InvalidArgumentException('Active Document Course usage is required.');
        }
    }

    private function assertAudioUsage(int $customerId, object $media, bool $speechToText): void
    {
        if ($speechToText && $media->file_type === 'audio' && ($media->status === 'deleted'
            || ! app(AudioProcessingEligibility::class)->hasActiveUsage($customerId, (int) $media->id))) {
            throw new InvalidArgumentException('Active Audio Course usage is required.');
        }
    }

    private function canonicalLocaleFor(object $media, ?string $locale, bool $speechToText = true): ?string
    {
        return ($media->file_type === 'document'
            || (in_array($media->file_type, ['audio', 'video'], true) && $speechToText))
            ? $this->profiles->canonicalLocale((string) $locale)
            : null;
    }

    private function sourceFingerprint(object $media): string
    {
        if (! is_string($media->checksum) || trim($media->checksum) === '') {
            throw new InvalidArgumentException('Source checksum is required for processing.');
        }

        return hash('sha256', $media->checksum.':'.$media->file_type);
    }

    private function generationKey(int $customerId, int $mediaId, string $type, string $fingerprint, string $version, string $profileHash, int $attempt, int $generation): string
    {
        $tuple = [$type, $mediaId, $fingerprint, $version, $profileHash, $attempt];

        return $generation === 1 ? implode(':', $tuple)
            : hash('sha256', json_encode([$customerId, ...$tuple, $generation], JSON_THROW_ON_ERROR));
    }

    private function initialIdempotencyKey(object $media, string $jobType, string $fingerprint, string $version, string $profileHash): string
    {
        return implode(':', [$jobType, $media->id, $fingerprint, $version, $profileHash, 1]);
    }

    public function providerFor(string $jobType, ?object $media = null): string
    {
        if ($jobType === 'structured_extraction' && $media !== null && $this->structureFor($media) === 'cells') {
            return 'local_document';
        }

        return (string) config("media.processing.providers.$jobType", 'unconfigured');
    }

    /**
     * Version cau hinh hien hanh cho mot job type tren mot Media cu the.
     *
     * Public vi consumer ngoai orchestrator — vi du `media:reprocess` khi so
     * chain cu voi cau hinh hien tai — phai dung DUNG mot phep dung version.
     * Doc thang `config(...)` o noi khac se bo qua extraction profile cua video
     * va bao "version da doi" trong khi khong co gi doi.
     */
    public function versionFor(string $jobType, ?object $media = null, array $parameters = []): string
    {
        $version = (string) config("media.processing.versions.$jobType", 'unconfigured-v1');

        if ($jobType === 'ocr' && ($media->file_type ?? null) === 'document' && $this->providerFor('ocr') === 'local_document') {
            $version .= '+document-v2';
            if (strlen($version) > 100) {
                $version = 'document-v2-'.hash('sha256', $version);
            }
        }

        if ($jobType === 'structured_extraction' && $media !== null && isset($parameters['locale'])) {
            $input = app(DocumentCanonicalRevision::class)->current((int) $media->customer_id, (int) $media->id, $this->sourceFingerprint($media), $parameters['locale']);
            if ($input !== null) {
                $version = 'structure-v2-'.hash('sha256', json_encode([$version, $this->structureFor($media), (int) $input->id, $input->processing_version], JSON_THROW_ON_ERROR));
            }
        }

        // Caption duoc dung TU transcript, nen identity cua no phai gom ca
        // revision nguon. Neu khong: caption dung tu transcript v1 va tu v2 co
        // cung processing_version, cung idempotency key — va lan materialize thu
        // hai bi dedupe. STT chay lai se khong bao gio sinh caption moi, trong khi
        // caption cu da bi stale cascade archive. Video mat phu de vinh vien.
        if ($jobType === 'caption' && $media !== null && isset($parameters['locale'])) {
            $source = $this->currentTranscriptRevision($media, (string) $parameters['locale']);
            if ($source !== null) {
                $version .= '+from-'.substr(hash('sha256', $source), 0, 8);
            }
        }

        // Transcript cua video phu thuoc CACH audio duoc tach ra, nhung
        // `source_fingerprint` chi la van tay cua binary goc. Khong dua
        // extraction profile vao version thi doi mot tham so ffmpeg se lam
        // transcript doi noi dung ma khong sinh revision moi.
        //
        // Chi nhanh video duoc them hau to. Them cho ca audio se doi identity
        // cua moi transcript audio dang chay, archive toan bo va bat chay lai —
        // Amendment Record 2.19 § 1 canh bao dung dieu nay.
        if ($jobType === 'speech_to_text' && ($media->file_type ?? null) === 'video') {
            $version .= '+'.app(VideoSpeechToTextProfile::class)->label();
        }

        return $version;
    }
}
