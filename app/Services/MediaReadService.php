<?php

namespace App\Services;

use App\Exceptions\MediaReadException;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
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
        string|array|null $languageProfile = null,
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
        $selectedLanguageProfile = null;

        try {
            if (! $this->authorizer->authorized($customerId, $ownerType, $ownerId, $actorId)) {
                $media = $this->mediaForOwner($customerId, $ownerType, $ownerId, $usageType);
                throw new MediaReadException('unauthorized');
            }

            $this->assertUsageTypeMatchesContentType($usageType, $contentType);
            if ($includeCrop && ! in_array($contentType, ['region', 'formula'], true)) {
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
            $documentContent = in_array($contentType, ['extracted_text', 'region', 'table', 'formula'], true);
            if ($documentContent && $media->file_type !== 'document') {
                throw new MediaReadException('unsupported_source');
            }
            try {
                if ($documentContent && $languageProfile !== null) {
                    $selectedLanguageProfile = app(DocumentLanguageProfile::class)->canonical($languageProfile);
                    $selectedLocale = $selectedLanguageProfile[0];
                    $auditContext['language_profile'] = $selectedLanguageProfile;
                } else {
                    $selectedLocale = $locale !== null ? $this->profiles->canonicalLocale($locale) : $media->processing_locale;
                }
            } catch (\InvalidArgumentException) {
                throw new MediaReadException('locale_unavailable');
            }
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
                'formula' => ['media_extracted_formulas', false],
                default => throw new MediaReadException('unsupported_source'),
            };
            // Spec B § 6: vang mat phai co TEN. Khi chua co row output nao, trang
            // thai cua chinh job la nguon duy nhat phan biet duoc "dang xu ly",
            // "that bai" va "khong co locale nay". Transcript truoc day khong co
            // buoc nay nen mot STT `failed` doc ra thanh `locale_unavailable` —
            // consumer khong biet la minh dang thieu.
            $derivedJobType = match ($contentType) {
                'extracted_text' => 'ocr',
                'region', 'table', 'formula' => 'structured_extraction',
                'transcript' => 'speech_to_text',
                'caption_asset' => 'caption',
                default => null,
            };

            $query = $contentType === 'formula'
                ? DB::query()->fromSub(
                    DB::table('media_extracted_formulas as formula')
                        ->join('media_extracted_regions as region', function ($join): void {
                            $join->on('region.id', '=', 'formula.region_id')
                                ->on('region.customer_id', '=', 'formula.customer_id')
                                ->on('region.media_file_id', '=', 'formula.media_file_id')
                                ->on('region.processing_job_id', '=', 'formula.processing_job_id');
                        })->select([
                            'formula.id', 'formula.customer_id', 'formula.media_file_id', 'formula.processing_job_id',
                            'formula.raw_text as text', 'formula.normalized_format', 'formula.normalized_value',
                            'formula.normalization_status', 'formula.confidence_score',
                            'region.locale', 'region.locator_type', 'region.locator_value', 'region.page',
                            'region.reading_order', 'region.bbox_x', 'region.bbox_y', 'region.bbox_width', 'region.bbox_height',
                            'region.crop_storage_key', 'region.crop_width', 'region.crop_height', 'region.crop_bytes',
                            'region.source_fingerprint', 'region.processing_version', 'region.status',
                        ]),
                    'media_extracted_formulas'
                )->where('customer_id', $customerId)->where('media_file_id', $media->id)
                : DB::table($table)->where('customer_id', $customerId)->where('media_file_id', $media->id);
            if ($documentContent) {
                $this->withReadyDocumentJob($query, $table, $contentType === 'extracted_text' ? 'ocr' : 'structured_extraction');
                if ($selectedLanguageProfile !== null) {
                    $query->whereIn($table.'.processing_job_id', $this->documentJobIdsForProfile(
                        $customerId, (int) $media->id, $selectedLanguageProfile,
                        $contentType === 'extracted_text' ? 'ocr' : 'structured_extraction'
                    ));
                }
            }
            if ($contentType !== 'variant') {
                $query->where('locale', $selectedLocale);
            }
            if ($page !== null && ! in_array($contentType, ['region', 'table', 'formula'], true)) {
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
            if ($documentContent) {
                $revision = (clone $query)->orderByDesc('processing_job_id')->orderByDesc('id')->first();
                if ($revision !== null) {
                    $query->where('source_fingerprint', $revision->source_fingerprint)
                        ->where('processing_version', $revision->processing_version)
                        ->where('processing_job_id', $revision->processing_job_id);
                }
            }
            if ($page !== null && $contentType === 'region') {
                $query->where('page', $page);
            } elseif ($page !== null && $contentType === 'formula') {
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
            if ($contentType === 'extracted_text') {
                $query->orderBy('sequence');
            } elseif ($contentType === 'region') {
                $query->orderBy('reading_order');
            } elseif ($contentType === 'table') {
                $query->orderBy('sequence');
            } elseif ($contentType === 'formula') {
                $query->orderBy('reading_order');
            }
            $rows = $query->orderBy('id')->get();
            if ($rows->isEmpty()) {
                if ($derivedJobType !== null) {
                    $jobState = DB::table('media_processing_jobs')->where('customer_id', $customerId)
                        ->where('media_file_id', $media->id)
                        ->where('job_type', $derivedJobType)
                        ->when($processingVersion !== null, fn ($q) => $q->where('processing_version', $processingVersion))
                        ->where(function ($q) use ($selectedLocale): void {
                            // Profile da chuan hoa co bang con; doan locale bang chuoi
                            // truot khi locale khong dung dau CSV (`locales=en,vi`) va
                            // khop nham tien to (`vi` vs `vi-VN`).
                            $q->whereExists(fn ($profile) => $profile->selectRaw('1')
                                ->from('media_processing_job_locales as read_profile')
                                ->whereColumn('read_profile.processing_job_id', 'media_processing_jobs.id')
                                ->whereColumn('read_profile.customer_id', 'media_processing_jobs.customer_id')
                                ->where('read_profile.locale', $selectedLocale))
                                // Job legacy khong co row con: `locale=<value>` la profile
                                // mot locale. Hai pattern nay neo hai dau bang `;` nen
                                // khong the khop mot locale khac co cung tien to.
                                ->orWhere(fn ($legacy) => $legacy
                                    ->whereNotExists(fn ($profile) => $profile->selectRaw('1')
                                        ->from('media_processing_job_locales as legacy_profile')
                                        ->whereColumn('legacy_profile.processing_job_id', 'media_processing_jobs.id')
                                        ->whereColumn('legacy_profile.customer_id', 'media_processing_jobs.customer_id'))
                                    ->where(fn ($profile) => $profile
                                        ->where('output_profile', 'like', '%;locale='.$selectedLocale)
                                        ->orWhere('output_profile', 'like', 'locale='.$selectedLocale.';%')));
                        })->orderByDesc('id')->value('status');
                    if (in_array($jobState, ['pending', 'processing', 'failed'], true)) {
                        throw new MediaReadException($jobState);
                    }
                }
                // Trang co canonical text nhung khong co cau truc trong revision nay.
                // Tra mang rong o day se buoc consumer doan giua ba tinh huong khac
                // nhau: trang trang, model truot, va loi he thong. Dat ten cho no de
                // consumer fallback sang trich dan theo trang thay vi im lang bo qua.
                if ($page !== null && in_array($contentType, ['region', 'table'], true)
                    && DB::table('media_extracted_texts')->where('customer_id', $customerId)
                        ->where('media_file_id', $media->id)->where('locale', $selectedLocale)
                        ->where('locator_type', 'page')->where('locator_value', (string) $page)
                        ->where('char_count', '>', 0)->where('status', 'ready')
                        ->when($selectedOutputFingerprint !== null,
                            fn ($q) => $q->where('source_fingerprint', $selectedOutputFingerprint))
                        ->exists()) {
                    throw new MediaReadException('structure_unavailable');
                }

                $stateQuery = $contentType === 'formula'
                    ? DB::table('media_extracted_regions')->where('customer_id', $customerId)
                        ->where('media_file_id', $media->id)->where('locale', $selectedLocale)->where('role', 'formula')
                    : DB::table($table)->where('customer_id', $customerId)->where('media_file_id', $media->id)
                        ->when($contentType !== 'variant', fn ($q) => $q->where('locale', $selectedLocale));
                $state = $stateQuery->orderByDesc('created_at')->value('status');
                throw new MediaReadException($processingVersion !== null
                    ? 'revision_unavailable'
                    : ($state === null ? 'locale_unavailable' : (in_array($state, ['pending', 'processing', 'failed', 'archived'], true) ? $state : 'missing')));
            }
            if ($sourceFingerprint !== null && $rows->contains(fn ($row) => $row->source_fingerprint !== $sourceFingerprint)) {
                throw new MediaReadException('revision_mismatch');
            }

            $jobProfiles = [];
            // Mot lan doc region tra ve toan bo vung cua revision — do tren tai
            // lieu that la 1.949 vung. Query bang con theo tung vung se thanh
            // 1.949 round trip cho mot lan doc, nen nap mot lan roi group.
            $regionLanguages = $contentType !== 'region' ? [] : DB::table('media_region_languages')
                ->where('customer_id', $customerId)->whereIn('region_id', $rows->pluck('id')->all())
                ->orderBy('region_id')->orderBy('ordinal')->get()
                ->groupBy('region_id')
                ->map(fn ($group): array => $group->map(fn ($language): array => [
                    'script' => $language->script,
                    'locale' => $language->locale,
                    'char_count' => (int) $language->char_count,
                ])->all())->all();
            $units = $rows->map(function ($row) use ($media, $contentType, $asset, $customerId, $includeCrop, $regionLanguages, &$jobProfiles): array {
                $structure = null;
                $text = $asset ? null : ($row->text ?? null);
                if ($contentType === 'region') {
                    $structure = [
                        'role' => $row->role,
                        // Hai truong nay la gia tri dominant, giu nguyen y nghia cu.
                        // `languages` moi la bang chung day du: mot vung song ngu
                        // co nhieu hon mot phan tu, va ep no ve mot locale se lam
                        // mat han phan con lai. ADR-0019 v1.8.
                        'detected_locale' => $row->detected_locale ?? 'undetermined',
                        'script' => $row->script ?? 'undetermined',
                        'languages' => $regionLanguages[$row->id] ?? [],
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
                } elseif ($contentType === 'formula') {
                    $structure = [
                        'page' => (int) $row->page,
                        'reading_order' => (int) $row->reading_order,
                        'bbox' => $row->bbox_x === null ? null : [
                            'x' => (float) $row->bbox_x, 'y' => (float) $row->bbox_y,
                            'width' => (float) $row->bbox_width, 'height' => (float) $row->bbox_height,
                        ],
                        'crop' => $row->crop_storage_key === null ? null : [
                            'width' => (int) $row->crop_width, 'height' => (int) $row->crop_height,
                            'bytes' => (int) $row->crop_bytes,
                            'delivery_url' => $includeCrop
                                ? $this->mediaService->generateDerivedSignedUrl($media, $row->crop_storage_key)
                                : null,
                        ],
                        'normalization_status' => $row->normalization_status,
                        'normalized_format' => $row->normalized_format,
                        'normalized_value' => $row->normalized_value,
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
                    'language_profile' => isset($row->processing_job_id)
                        ? ($jobProfiles[$row->processing_job_id] ??= $this->languageProfileForJob((int) $row->processing_job_id))
                        : (($row->locale ?? null) === null ? null : [$row->locale]),
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
            // Spec B § 8: mot lan doc BI TU CHOI cung phai duoc audit khi owner
            // van resolve duoc toi Media File. `detached` va `missing-voi-usage-cu`
            // duoc nem TRUOC khi $media duoc gan, nen khong co buoc nay thi mot
            // no luc doc transcript da detach khong de lai dau vet nao. Resolve o
            // day KHONG cap quyen doc — no chi dinh danh muc tieu de ghi log.
            $media ??= $this->mediaForOwner($customerId, $ownerType, $ownerId, $usageType);
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
            try {
                $selectedLocale = $locale !== null ? $this->profiles->canonicalLocale($locale) : $media->processing_locale;
            } catch (\InvalidArgumentException) {
                throw new MediaReadException('locale_unavailable');
            }
            if ($selectedLocale === null) {
                throw new MediaReadException('locale_unavailable');
            }

            $regionQuery = DB::table('media_extracted_regions')
                ->where('customer_id', $customerId)->where('media_file_id', $media->id)
                ->where('locale', $selectedLocale)->where('status', 'ready');
            $this->withReadyDocumentJob($regionQuery, 'media_extracted_regions', 'structured_extraction');
            $regionRevision = (clone $regionQuery)->orderByDesc('processing_job_id')->orderByDesc('id')->first();
            if (! $regionRevision) {
                throw new MediaReadException('missing');
            }

            $regionPages = $regionQuery
                ->where('processing_job_id', $regionRevision->processing_job_id)
                ->where('processing_version', $regionRevision->processing_version)
                ->where('source_fingerprint', $regionRevision->source_fingerprint)
                ->pluck('page')->map(static fn ($value): int => (int) $value)
                ->unique()->sort()->values()->all();

            $textRevisionQuery = DB::table('media_extracted_texts')
                ->where('customer_id', $customerId)->where('media_file_id', $media->id)
                ->where('locale', $selectedLocale)->where('locator_type', 'page')
                ->where('status', 'ready')->where('source_fingerprint', $regionRevision->source_fingerprint);
            $this->withReadyDocumentJob($textRevisionQuery, 'media_extracted_texts', 'ocr');
            $textRevision = (clone $textRevisionQuery)->orderByDesc('processing_job_id')->orderByDesc('id')->first();
            $textPages = $textRevision === null ? [] : $textRevisionQuery->where('char_count', '>', 0)
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

    /** Legacy rows without job provenance remain readable; linked rows must match a committed job. */
    private function withReadyDocumentJob(Builder $query, string $table, string $jobType): void
    {
        $query->where(function ($q) use ($table, $jobType): void {
            $q->whereNull($table.'.processing_job_id')->orWhereExists(function ($jobs) use ($table, $jobType): void {
                $jobs->selectRaw('1')->from('media_processing_jobs as read_job')
                    ->whereColumn('read_job.id', $table.'.processing_job_id')
                    ->whereColumn('read_job.customer_id', $table.'.customer_id')
                    ->whereColumn('read_job.media_file_id', $table.'.media_file_id')
                    ->whereColumn('read_job.processing_version', $table.'.processing_version')
                    ->whereColumn('read_job.source_fingerprint', $table.'.source_fingerprint')
                    ->where('read_job.job_type', $jobType)->where('read_job.status', 'ready');
            });
        });
    }

    /** @param array<int, string> $profile @return array<int, int> */
    private function documentJobIdsForProfile(int $customerId, int $mediaId, array $profile, string $jobType): array
    {
        return DB::table('media_processing_jobs')->where('customer_id', $customerId)
            ->where('media_file_id', $mediaId)->where('job_type', $jobType)->get(['id', 'output_profile'])
            ->filter(function (object $job) use ($profile): bool {
                try {
                    return app(DocumentLanguageProfile::class)->fromProfile((string) $job->output_profile) === $profile;
                } catch (\InvalidArgumentException) {
                    return false;
                }
            })->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return array<int, string>|null */
    private function languageProfileForJob(int $jobId): ?array
    {
        $profile = DB::table('media_processing_jobs')->where('id', $jobId)->value('output_profile');
        if (! is_string($profile)) {
            return null;
        }
        try {
            return app(DocumentLanguageProfile::class)->fromProfile($profile);
        } catch (\InvalidArgumentException) {
            return null;
        }
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
            'extracted_text', 'region', 'table', 'formula' => ['document'],
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
