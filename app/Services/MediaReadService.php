<?php

namespace App\Services;

use App\Exceptions\MediaReadException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MediaReadService
{
    public function __construct(
        private readonly CourseMediaOwnerContextAuthorizer $authorizer,
        private readonly MediaService $mediaService,
        private readonly MediaOutputProfile $profiles,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function read(
        int $actorId,
        string $ownerType,
        int $ownerId,
        string $contentType,
        ?string $locale = null,
        ?string $processingVersion = null,
        ?string $sourceFingerprint = null,
        string $consumer = 'ai',
        array $auditContext = [],
    ): array {
        $customerId = TenantContext::customerId() ?? throw new MediaReadException('unauthorized');
        $media = null;
        $selectedLocale = $locale;

        try {
            if (! $this->authorizer->authorized($customerId, $ownerType, $ownerId, $actorId)) {
                $media = $this->mediaForOwner($customerId, $ownerType, $ownerId);
                throw new MediaReadException('unauthorized');
            }

            $usage = DB::table('media_file_usages')->where('customer_id', $customerId)->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)->where('status', 'active')->first();
            if (! $usage) {
                $detached = DB::table('media_file_usages')->where('customer_id', $customerId)->where('owner_type', $ownerType)->where('owner_id', $ownerId)->exists();
                throw new MediaReadException($detached ? 'detached' : 'missing');
            }
            $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $usage->media_file_id)->first();
            if (! $media) {
                throw new MediaReadException('missing');
            }
            $selectedLocale = $locale !== null ? $this->profiles->canonicalLocale($locale) : $media->processing_locale;
            if ($selectedLocale === null && $contentType !== 'variant') {
                throw new MediaReadException('locale_unavailable');
            }

            [$table, $asset] = match ($contentType) {
                'extracted_text' => ['media_extracted_texts', false],
                'transcript' => ['media_transcripts', false],
                'caption_asset' => ['media_captions', true],
                'variant' => ['media_variants', true],
                default => throw new MediaReadException('unsupported_source'),
            };

            $query = DB::table($table)->where('customer_id', $customerId)->where('media_file_id', $media->id);
            if ($contentType !== 'variant') {
                $query->where('locale', $selectedLocale);
            }
            if ($processingVersion !== null) {
                $query->where('processing_version', $processingVersion)->whereIn('status', ['ready', 'archived']);
            } else {
                $query->where('status', 'ready');
                $latestVersion = (clone $query)->orderByDesc('processing_job_id')->orderByDesc('id')->value('processing_version');
                if ($latestVersion !== null) {
                    $query->where('processing_version', $latestVersion);
                }
            }
            $rows = $query->orderBy('id')->get();
            if ($rows->isEmpty()) {
                $stateQuery = DB::table($table)->where('customer_id', $customerId)->where('media_file_id', $media->id)
                    ->when($contentType !== 'variant', fn ($q) => $q->where('locale', $selectedLocale));
                $state = $stateQuery->orderByDesc('created_at')->value('status');
                throw new MediaReadException($processingVersion !== null
                    ? 'revision_unavailable'
                    : ($state === null ? 'locale_unavailable' : (in_array($state, ['pending', 'processing', 'failed', 'archived'], true) ? $state : 'missing')));
            }
            if ($sourceFingerprint !== null && $rows->contains(fn ($row) => $row->source_fingerprint !== $sourceFingerprint)) {
                throw new MediaReadException('revision_mismatch');
            }

            $units = $rows->map(function ($row) use ($media, $contentType, $asset): array {
                return [
                    'media_file_id' => (int) $media->id,
                    'source_fingerprint' => $row->source_fingerprint,
                    'processing_version' => $row->processing_version,
                    'content_type' => $contentType,
                    'locale' => $row->locale ?? null,
                    'locator' => $asset ? null : ['type' => $row->locator_type, 'value' => $row->locator_value],
                    'text' => $asset ? null : $row->text,
                    'delivery_url' => $asset ? $this->mediaService->generateDerivedSignedUrl($media, $row->storage_key) : null,
                    'confidence' => $row->confidence_score ?? null,
                    'status' => $row->status,
                ];
            })->all();

            $this->audit($customerId, $media, $actorId, $consumer, $ownerType, $ownerId, $contentType,
                $selectedLocale, $processingVersion, $sourceFingerprint, 'allowed', null, $auditContext);

            return $units;
        } catch (MediaReadException $exception) {
            if ($media) {
                $this->audit($customerId, $media, $actorId, $consumer, $ownerType, $ownerId, $contentType,
                    $selectedLocale, $processingVersion, $sourceFingerprint, 'denied', $exception->errorCode, $auditContext);
            }
            throw $exception;
        }
    }

    private function mediaForOwner(int $customerId, string $ownerType, int $ownerId): ?object
    {
        return DB::table('media_file_usages as usages')
            ->join('media_files as media', fn ($join) => $join->on('media.id', '=', 'usages.media_file_id')
                ->on('media.customer_id', '=', 'usages.customer_id'))
            ->where('usages.customer_id', $customerId)->where('usages.owner_type', $ownerType)
            ->where('usages.owner_id', $ownerId)->select('media.*')->first();
    }

    private function audit(int $customerId, object $media, int $actorId, string $consumer, string $ownerType,
        int $ownerId, string $contentType, ?string $locale, ?string $processingVersion,
        ?string $sourceFingerprint, string $decision, ?string $errorCode, array $context): void
    {
        $tenantActorId = DB::table('users')->where('customer_id', $customerId)->where('id', $actorId)->value('id');
        try {
            DB::table('media_access_logs')->insert([
                'customer_id' => $customerId, 'media_file_id' => $media->id, 'user_id' => $tenantActorId,
                'action' => 'read_derived', 'source_type' => $consumer, 'source_id' => null,
                'ip_address' => $context['ip_address'] ?? null, 'user_agent' => $context['user_agent'] ?? null,
                'accessed_at' => now(), 'metadata' => json_encode([
                    'owner_type' => $ownerType, 'owner_id' => $ownerId, 'content_type' => $contentType,
                    'locale' => $locale, 'processing_version' => $processingVersion,
                    'source_fingerprint' => $sourceFingerprint, 'decision' => $decision, 'error_code' => $errorCode,
                ]),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Media derived-read audit insert failed.', [
                'customer_id' => $customerId,
                'media_file_id' => (int) $media->id,
                'actor_id' => $tenantActorId ? (int) $tenantActorId : null,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'content_type' => $contentType,
                'decision' => $decision,
                'error_code' => $errorCode,
                'exception' => $exception::class,
            ]);
            // Logging failure must not replace the stable Media Read error contract.
        }
    }
}
