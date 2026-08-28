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
        string $usageType,
        string $contentType,
        ?string $locale = null,
        ?string $processingVersion = null,
        ?string $sourceFingerprint = null,
        string $consumer = 'ai',
        array $auditContext = [],
        ?int $page = null,
        bool $includeCrop = false,
    ): array {
        $customerId = TenantContext::customerId() ?? throw new MediaReadException('unauthorized');
        $media = null;
        $selectedLocale = $locale;
        // Selector phai nam trong audit metadata (§ 8): phai tra loi duoc "ai doc
        // trang nao, co xin chu ky crop khong, luc nao".
        if ($includeCrop) {
            $auditContext['include_crop'] = true;
        }
        if ($page !== null) {
            $auditContext['page'] = $page;
        }

        try {
            if (! $this->authorizer->authorized($customerId, $ownerType, $ownerId, $actorId)) {
                $media = $this->mediaForOwner($customerId, $ownerType, $ownerId, $usageType);
                throw new MediaReadException('unauthorized');
            }

            $this->assertUsageTypeMatchesContentType($usageType, $contentType);
            if ($includeCrop && $contentType !== 'region') {
                throw new MediaReadException('unsupported_source');
            }
            $usageQuery = DB::table('media_file_usages')->where('customer_id', $customerId)->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)->where('usage_type', $usageType);
            $activeUsages = (clone $usageQuery)->where('status', 'active')->limit(2)->get();
            if ($activeUsages->isEmpty()) {
                $detached = $usageQuery->exists();
                throw new MediaReadException($detached ? 'detached' : 'missing');
            }
            if ($activeUsages->count() > 1) {
                $media = DB::table('media_files')->where('customer_id', $customerId)
                    ->where('id', $activeUsages->first()->media_file_id)->first();
                throw new MediaReadException('ambiguous_source');
            }
            $usage = $activeUsages->first();
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
                'region' => ['media_extracted_regions', false],
                'table' => ['media_extracted_tables', false],
                default => throw new MediaReadException('unsupported_source'),
            };

            $query = DB::table($table)->where('customer_id', $customerId)->where('media_file_id', $media->id);
            if ($contentType !== 'variant') {
                $query->where('locale', $selectedLocale);
            }
            if ($page !== null && ! in_array($contentType, ['region', 'table'], true)) {
                throw new MediaReadException('unsupported_source');
            }
            $selectedProcessingVersion = $processingVersion;
            if ($processingVersion !== null) {
                $query->where('processing_version', $processingVersion)->whereIn('status', ['ready', 'archived']);
            } else {
                $query->where('status', 'ready');
                $latestVersion = (clone $query)->orderByDesc('processing_job_id')->orderByDesc('id')->value('processing_version');
                if ($latestVersion !== null) {
                    $query->where('processing_version', $latestVersion);
                    $selectedProcessingVersion = $latestVersion;
                }
            }
            $selectedOutputFingerprint = (clone $query)->orderByDesc('processing_job_id')->orderByDesc('id')
                ->value('source_fingerprint');
            if ($page !== null && $contentType === 'region') {
                $query->where('page', $page);
            } elseif ($page !== null && $contentType === 'table') {
                // A table belongs to a region; constrain the region to the same
                // structured revision before applying the requested page.
                $query->whereIn('region_id', DB::table('media_extracted_regions')
                    ->where('customer_id', $customerId)->where('media_file_id', $media->id)
                    ->where('locale', $selectedLocale)->where('page', $page)
                    ->when($selectedProcessingVersion !== null,
                        fn ($q) => $q->where('processing_version', $selectedProcessingVersion))
                    ->when($selectedOutputFingerprint !== null,
                        fn ($q) => $q->where('source_fingerprint', $selectedOutputFingerprint))
                    ->select('id'));
            }
            $rows = $query->orderBy('id')->get();
            if ($rows->isEmpty()) {
                // Trang co canonical text nhung khong co cau truc trong revision nay.
                // Tra mang rong o day se buoc consumer doan giua ba tinh huong khac
                // nhau: trang trang, model truot, va loi he thong. Dat ten cho no de
                // consumer fallback sang trich dan theo trang thay vi im lang bo qua.
                if ($page !== null && in_array($contentType, ['region', 'table'], true)
                    && DB::table('media_extracted_texts')->where('customer_id', $customerId)
                        ->where('media_file_id', $media->id)->where('locale', $selectedLocale)
                        ->where('locator_type', 'page')->where('locator_value', (string) $page)
                        ->where('status', 'ready')
                        ->when($selectedOutputFingerprint !== null,
                            fn ($q) => $q->where('source_fingerprint', $selectedOutputFingerprint))
                        ->exists()) {
                    throw new MediaReadException('structure_unavailable');
                }

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

            $units = $rows->map(function ($row) use ($media, $contentType, $asset, $customerId, $includeCrop): array {
                $structure = null;
                $text = $asset ? null : ($row->text ?? null);
                if ($contentType === 'region') {
                    $structure = [
                        'role' => $row->role,
                        'reading_order' => (int) $row->reading_order,
                        'bbox' => $row->bbox_x === null ? null : [
                            'x' => (float) $row->bbox_x, 'y' => (float) $row->bbox_y,
                            'width' => (float) $row->bbox_width, 'height' => (float) $row->bbox_height,
                        ],
                        // Crop la thuoc tinh cua vung, khong phai mot content_type
                        // rieng: tach ra se buoc consumer tu ghep lai theo
                        // locator_value, dung viec ma § 1 cam. Xem Spec B § 5.3.
                        //
                        // `null` o day khong mo ho. Crop la tat-ca-hoac-khong-co
                        // trong mot revision, nen null chi co mot nghia: revision
                        // nay sinh ra truoc khi crop duoc bat.
                        'crop' => $row->crop_storage_key === null ? null : [
                            'width' => (int) $row->crop_width,
                            'height' => (int) $row->crop_height,
                            'bytes' => (int) $row->crop_bytes,
                            // Khong ky URL khi consumer khong xin: mot trang co the
                            // co 100 vung, va ky ca tram chu ky cho nguoi chi doc
                            // text la lang phi va mo rong be mat ro ri vo co.
                            'delivery_url' => $includeCrop
                                ? $this->mediaService->generateDerivedSignedUrl($media, $row->crop_storage_key)
                                : null,
                        ],
                    ];
                } elseif ($contentType === 'table') {
                    $cells = DB::table('media_table_cells')->where('customer_id', $customerId)
                        ->where('extracted_table_id', $row->id)->orderBy('row_index')->orderBy('column_index')->get()
                        ->map(fn ($cell): array => [
                            'row' => (int) $cell->row_index, 'column' => (int) $cell->column_index,
                            'row_span' => (int) $cell->row_span, 'column_span' => (int) $cell->column_span,
                            'is_header' => (bool) $cell->is_header, 'text' => $cell->text,
                        ])->all();
                    $structure = [
                        'row_count' => (int) $row->row_count,
                        'column_count' => (int) $row->column_count,
                        'has_header' => (bool) $row->has_header,
                        'cells' => $cells,
                    ];
                }

                return [
                    'media_file_id' => (int) $media->id,
                    'source_fingerprint' => $row->source_fingerprint,
                    'processing_version' => $row->processing_version,
                    'content_type' => $contentType,
                    'locale' => $row->locale ?? null,
                    'locator' => $asset ? null : ['type' => $row->locator_type, 'value' => $row->locator_value],
                    'text' => $text,
                    'delivery_url' => $asset ? $this->mediaService->generateDerivedSignedUrl($media, $row->storage_key) : null,
                    'confidence' => $row->confidence_score ?? null,
                    'status' => $row->status,
                    'structure' => $structure,
                ];
            })->all();

            $this->audit($customerId, $media, $actorId, $consumer, $ownerType, $ownerId, $contentType,
                $usageType, $selectedLocale, $processingVersion, $sourceFingerprint, 'allowed', null, $auditContext);

            return $units;
        } catch (MediaReadException $exception) {
            if ($media) {
                $this->audit($customerId, $media, $actorId, $consumer, $ownerType, $ownerId, $contentType,
                    $usageType, $selectedLocale, $processingVersion, $sourceFingerprint, 'denied', $exception->errorCode, $auditContext);
            }
            throw $exception;
        }
    }

    /**
     * Do phu cua cau truc so voi text canonical, tinh truc tiep tu bang output.
     *
     * Co y KHONG doc `media_processing_jobs.metadata.structure_coverage`: hop dong
     * nay cam consumer cham bang `media_*` cua Media, va metadata do chi ton tai
     * cho job moi — job cu chua backfill. Tinh live tu hai bang output thi luon
     * dung, ke ca voi revision da chay tu truoc.
     *
     * @return array{pages_with_text: int, pages_with_regions: int, pages_text_without_structure: array<int, int>}
     */
    public function structureCoverage(
        int $actorId,
        string $ownerType,
        int $ownerId,
        string $usageType,
        ?string $locale = null,
    ): array {
        $customerId = TenantContext::customerId() ?? throw new MediaReadException('unauthorized');
        $media = null;
        $selectedLocale = $locale;

        try {
            if (! $this->authorizer->authorized($customerId, $ownerType, $ownerId, $actorId)) {
                throw new MediaReadException('unauthorized');
            }
            $this->assertUsageTypeMatchesContentType($usageType, 'region');
            $media = $this->activeMediaForOwner($customerId, $ownerType, $ownerId, $usageType);
            $selectedLocale = $locale !== null ? $this->profiles->canonicalLocale($locale) : $media->processing_locale;
            if ($selectedLocale === null) {
                throw new MediaReadException('locale_unavailable');
            }

            $regionRevision = DB::table('media_extracted_regions')
                ->where('customer_id', $customerId)->where('media_file_id', $media->id)
                ->where('locale', $selectedLocale)->where('status', 'ready')
                ->orderByDesc('processing_job_id')->orderByDesc('id')->first();
            if (! $regionRevision) {
                throw new MediaReadException('missing');
            }

            $regionPages = DB::table('media_extracted_regions')
                ->where('customer_id', $customerId)->where('media_file_id', $media->id)
                ->where('locale', $selectedLocale)->where('status', 'ready')
                ->where('processing_version', $regionRevision->processing_version)
                ->where('source_fingerprint', $regionRevision->source_fingerprint)
                ->pluck('page')->map(static fn ($value): int => (int) $value)
                ->unique()->sort()->values()->all();

            $textRevisionQuery = DB::table('media_extracted_texts')
                ->where('customer_id', $customerId)->where('media_file_id', $media->id)
                ->where('locale', $selectedLocale)->where('locator_type', 'page')
                ->where('status', 'ready')->where('source_fingerprint', $regionRevision->source_fingerprint);
            $textRevision = (clone $textRevisionQuery)->orderByDesc('processing_job_id')->orderByDesc('id')->first();
            $textPages = $textRevision === null ? [] : $textRevisionQuery
                ->when($textRevision->processing_job_id !== null,
                    fn ($q) => $q->where('processing_job_id', $textRevision->processing_job_id),
                    fn ($q) => $q->where('processing_version', $textRevision->processing_version))
                ->pluck('locator_value')->map(static fn ($value): int => (int) $value)
                ->unique()->sort()->values()->all();

            $coverage = [
                'pages_with_text' => count($textPages),
                'pages_with_regions' => count($regionPages),
                'pages_text_without_structure' => array_values(array_diff($textPages, $regionPages)),
            ];
            $this->audit($customerId, $media, $actorId, 'ai', $ownerType, $ownerId, 'region',
                $usageType, $selectedLocale, null, $regionRevision->source_fingerprint, 'allowed', null,
                ['operation' => 'structure_coverage']);

            return $coverage;
        } catch (MediaReadException $exception) {
            // Match `read()`: a denied or ambiguous owner lookup is still an
            // auditable access attempt when an owner-scoped Media row can be
            // resolved. Resolution here grants no read authority.
            $media ??= $this->mediaForOwner($customerId, $ownerType, $ownerId, $usageType);
            if ($media) {
                $this->audit($customerId, $media, $actorId, 'ai', $ownerType, $ownerId, 'region',
                    $usageType, $selectedLocale, null, null, 'denied', $exception->errorCode,
                    ['operation' => 'structure_coverage']);
            }
            throw $exception;
        }
    }

    private function activeMediaForOwner(int $customerId, string $ownerType, int $ownerId, string $usageType): object
    {
        $usageQuery = DB::table('media_file_usages')->where('customer_id', $customerId)
            ->where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('usage_type', $usageType);
        $activeUsages = (clone $usageQuery)->where('status', 'active')->limit(2)->get();
        if ($activeUsages->isEmpty()) {
            throw new MediaReadException($usageQuery->exists() ? 'detached' : 'missing');
        }
        if ($activeUsages->count() > 1) {
            throw new MediaReadException('ambiguous_source');
        }

        $media = DB::table('media_files')->where('customer_id', $customerId)
            ->where('id', $activeUsages->first()->media_file_id)->first();

        return $media ?? throw new MediaReadException('missing');
    }

    private function mediaForOwner(int $customerId, string $ownerType, int $ownerId, string $usageType): ?object
    {
        return DB::table('media_file_usages as usages')
            ->join('media_files as media', fn ($join) => $join->on('media.id', '=', 'usages.media_file_id')
                ->on('media.customer_id', '=', 'usages.customer_id'))
            ->where('usages.customer_id', $customerId)->where('usages.owner_type', $ownerType)
            ->where('usages.owner_id', $ownerId)->where('usages.usage_type', $usageType)
            ->select('media.*')->first();
    }

    private function assertUsageTypeMatchesContentType(string $usageType, string $contentType): void
    {
        $allowed = match ($contentType) {
            'extracted_text', 'region', 'table' => ['document'],
            'transcript' => ['audio', 'video'],
            'caption_asset', 'variant' => ['video'],
            default => throw new MediaReadException('unsupported_source'),
        };

        if (! in_array($usageType, $allowed, true)) {
            throw new MediaReadException('unsupported_source');
        }
    }

    private function audit(int $customerId, object $media, int $actorId, string $consumer, string $ownerType,
        int $ownerId, string $contentType, string $usageType, ?string $locale, ?string $processingVersion,
        ?string $sourceFingerprint, string $decision, ?string $errorCode, array $context): void
    {
        $tenantActorId = DB::table('users')->where('customer_id', $customerId)->where('id', $actorId)->value('id');
        try {
            DB::table('media_access_logs')->insert([
                'customer_id' => $customerId, 'media_file_id' => $media->id, 'user_id' => $tenantActorId,
                'action' => 'read_derived', 'source_type' => $consumer, 'source_id' => null,
                'ip_address' => $context['ip_address'] ?? null, 'user_agent' => $context['user_agent'] ?? null,
                'accessed_at' => now(), 'metadata' => json_encode([
                    'owner_type' => $ownerType, 'owner_id' => $ownerId, 'usage_type' => $usageType,
                    'content_type' => $contentType,
                    'locale' => $locale, 'processing_version' => $processingVersion,
                    'source_fingerprint' => $sourceFingerprint, 'decision' => $decision, 'error_code' => $errorCode,
                ] + array_intersect_key($context, ['include_crop' => true, 'page' => true])),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Media derived-read audit insert failed.', [
                'customer_id' => $customerId,
                'media_file_id' => (int) $media->id,
                'actor_id' => $tenantActorId ? (int) $tenantActorId : null,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'usage_type' => $usageType,
                'content_type' => $contentType,
                'decision' => $decision,
                'error_code' => $errorCode,
                'exception' => $exception::class,
            ]);
            // Logging failure must not replace the stable Media Read error contract.
        }
    }
}
