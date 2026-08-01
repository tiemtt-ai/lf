<?php

namespace App\Http\Controllers;

use App\Services\MediaService;
use App\Services\MediaThumbnailPresenter;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\View\View;

class MediaFileController extends Controller
{
    private const TABS = ['all', 'images', 'videos', 'documents', 'audio'];

    private const TAB_TYPES = [
        'images' => 'image',
        'videos' => 'video',
        'documents' => 'document',
        'audio' => 'audio',
    ];

    private const FILTER_TYPES = ['image', 'video', 'audio', 'document'];

    private const USAGE_STATUSES = ['in_use', 'unused'];

    public function __construct(
        private readonly MediaService $mediaService,
        private readonly MediaThumbnailPresenter $thumbnails
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $tab = $this->normalizedTab($request);
        $type = $this->normalizedType($request);
        $keyword = $this->normalizedKeyword($request);
        $ownerTypeOptions = $this->ownerTypeOptions($customerId);
        $ownerType = $this->normalizedValue(
            $request->query('owner_type'),
            $ownerTypeOptions->keys()->all()
        );
        $usageStatus = $this->normalizedValue(
            $request->query('usage_status'),
            self::USAGE_STATUSES
        );
        $fileType = $type ?? self::TAB_TYPES[$tab] ?? null;

        $mediaFiles = $this->mediaQuery($customerId, $fileType, $keyword, $ownerType, $usageStatus)
            ->orderByDesc('media_files.created_at')
            ->orderByDesc('media_files.id')
            ->paginate(10)
            ->withQueryString();

        $usageGroups = $this->usageGroups($customerId, $mediaFiles->getCollection()->pluck('id'));
        $mediaFiles->setCollection($mediaFiles->getCollection()->map(function (object $mediaFile) use ($usageGroups): object {
            $mediaFile->active_usages = $usageGroups->get((int) $mediaFile->id, collect());
            $mediaFile->preview_url = $this->previewUrl($mediaFile);
            $mediaFile->preview_mode = match ($mediaFile->file_type) {
                'image', 'video', 'audio' => $mediaFile->preview_url ? 'popup' : null,
                'document' => $mediaFile->preview_url ? 'new_tab' : null,
                default => null,
            };
            $mediaFile->signed_url = $mediaFile->preview_url;
            $mediaFile->thumbnail_presentation = match ($mediaFile->file_type) {
                'image' => $this->thumbnails->image($mediaFile),
                'video' => $this->thumbnails->uploadedVideo($mediaFile),
                'audio' => $this->thumbnails->audio($mediaFile),
                'document' => $this->thumbnails->document($mediaFile),
                default => [
                    'state' => 'fallback',
                    'kind' => 'document',
                    'url' => null,
                ],
            };

            return $mediaFile;
        }));

        return view('media-files.index', [
            'mediaFiles' => $mediaFiles,
            'tabs' => self::TABS,
            'tab' => $tab,
            'type' => $type,
            'keyword' => $keyword,
            'ownerType' => $ownerType,
            'usageStatus' => $usageStatus,
            'ownerTypeOptions' => $ownerTypeOptions,
            'tabCounts' => $this->tabCounts($customerId),
        ]);
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->mediaService->deleteMedia($id);

        return redirect()
            ->route('admin.media.index')
            ->with('success', __('lf.LF_media_file_common_deleted'));
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'media_ids' => ['required', 'array', 'min:1', 'max:100'],
            'media_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $deletedCount = $this->mediaService->deleteUnusedMedia(
            $validated['media_ids']
        );

        return redirect()
            ->route('admin.media.index', ['usage_status' => 'unused'])
            ->with('success', trans_choice(
                'lf.LF_media_file_bulk_deleted',
                $deletedCount,
                ['count' => $deletedCount]
            ));
    }

    private function mediaQuery(
        int $customerId,
        ?string $fileType,
        ?string $keyword,
        ?string $ownerType,
        ?string $usageStatus
    ) {
        $usageCounts = DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->select('media_file_id', DB::raw('COUNT(*) as usage_count'))
            ->groupBy('media_file_id');

        return DB::table('media_files')
            ->leftJoin('users', function ($join) use ($customerId): void {
                $join->on('users.id', '=', 'media_files.uploaded_by')
                    ->where('users.customer_id', '=', $customerId);
            })
            ->leftJoinSub($usageCounts, 'usage_counts', function ($join): void {
                $join->on('usage_counts.media_file_id', '=', 'media_files.id');
            })
            ->where('media_files.customer_id', $customerId)
            ->where('media_files.status', '!=', 'deleted')
            ->when($fileType, fn ($query) => $query->where('media_files.file_type', $fileType))
            ->when($keyword, function ($query) use ($keyword): void {
                $query->where(function ($keywordQuery) use ($keyword): void {
                    $pattern = '%'.$keyword.'%';

                    $keywordQuery
                        ->where('media_files.display_name', 'like', $pattern)
                        ->orWhere('media_files.original_name', 'like', $pattern);
                });
            })
            ->when($usageStatus === 'in_use', fn ($query) => $query->whereRaw('COALESCE(usage_counts.usage_count, 0) > 0'))
            ->when($usageStatus === 'unused', fn ($query) => $query->whereRaw('COALESCE(usage_counts.usage_count, 0) = 0'))
            ->when($ownerType, function ($query) use ($customerId, $ownerType): void {
                $query->whereExists(function ($usageQuery) use ($customerId, $ownerType): void {
                    $usageQuery->selectRaw('1')
                        ->from('media_file_usages')
                        ->whereColumn('media_file_usages.media_file_id', 'media_files.id')
                        ->where('media_file_usages.customer_id', $customerId)
                        ->whereIn('media_file_usages.owner_type', $this->physicalOwnerTypes($ownerType))
                        ->where('media_file_usages.status', 'active');
                });
            })
            ->select([
                'media_files.*',
                'users.name as uploaded_by_name',
                DB::raw('COALESCE(usage_counts.usage_count, 0) as usage_count'),
            ]);
    }

    private function usageGroups(int $customerId, Collection $mediaFileIds): Collection
    {
        $ids = $mediaFileIds->map(fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return collect();
        }

        $usages = DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->whereIn('media_file_id', $ids)
            ->where('status', 'active')
            ->orderBy('owner_type')
            ->orderBy('usage_type')
            ->get();

        return $this->collapseLogicalUsages(
            $customerId,
            $this->attachOwnerNames($customerId, $usages)
        )->groupBy('media_file_id');
    }

    private function collapseLogicalUsages(
        int $customerId,
        Collection $usages
    ): Collection {
        $versionActivityIds = $usages
            ->where('owner_type', 'course_version_activity')
            ->pluck('owner_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $sourceActivityIds = $versionActivityIds === []
            ? collect()
            : DB::table('core_course_template_version_activities')
                ->where('customer_id', $customerId)
                ->whereIn('id', $versionActivityIds)
                ->pluck('source_template_activity_id', 'id');
        $templateVersionIds = $usages
            ->where('owner_type', 'course_template_version')
            ->pluck('owner_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $sourceTemplateIds = $templateVersionIds === []
            ? collect()
            : DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->whereIn('id', $templateVersionIds)
                ->pluck('template_id', 'id');

        return $usages
            ->map(function (object $usage) use (
                $sourceActivityIds,
                $sourceTemplateIds
            ): object {
                if ($usage->owner_type === 'course_version_activity') {
                    $sourceActivityId = $sourceActivityIds[(int) $usage->owner_id] ?? null;

                    if ($sourceActivityId !== null) {
                        $usage->owner_type = 'course_activity';
                        $usage->owner_id = (int) $sourceActivityId;
                    }
                }
                if ($usage->owner_type === 'course_template_version') {
                    $sourceTemplateId = $sourceTemplateIds[(int) $usage->owner_id] ?? null;

                    if ($sourceTemplateId !== null) {
                        $usage->owner_type = 'course_template';
                        $usage->owner_id = (int) $sourceTemplateId;
                    }
                }

                $usage->logical_owner_key = $usage->media_file_id.':'
                    .$usage->owner_type.':'.$usage->owner_id;

                return $usage;
            })
            ->unique('logical_owner_key')
            ->values();
    }

    private function attachOwnerNames(int $customerId, Collection $usages): Collection
    {
        $ownerNames = $this->ownerNames($customerId, $usages);

        return $usages->map(function (object $usage) use ($ownerNames): object {
            $key = $usage->owner_type.':'.$usage->owner_id;
            $usage->owner_name = $ownerNames[$key]
                ?? __('lf.LF_media_file_usage_unknown_owner');

            return $usage;
        });
    }

    private function ownerNames(int $customerId, Collection $usages): array
    {
        $names = [];
        $sources = [
            'course_category' => ['core_course_categories', 'name'],
            'course_template' => ['core_course_templates', 'title'],
            'course_product' => ['core_course_products', 'title'],
            'course_activity' => ['core_course_template_activities', 'title'],
            'course_version_activity' => [
                'core_course_template_version_activities',
                'title_snapshot',
            ],
            'course_cohort' => ['core_course_cohorts', 'name'],
        ];

        foreach ($sources as $ownerType => [$table, $labelColumn]) {
            $ownerIds = $usages
                ->where('owner_type', $ownerType)
                ->pluck('owner_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($ownerIds === []) {
                continue;
            }

            DB::table($table)
                ->where('customer_id', $customerId)
                ->whereIn('id', $ownerIds)
                ->select('id', $labelColumn)
                ->get()
                ->each(function (object $owner) use (&$names, $ownerType, $labelColumn): void {
                    $names[$ownerType.':'.$owner->id] = $owner->{$labelColumn};
                });
        }

        return $names;
    }

    private function ownerTypeOptions(int $customerId): Collection
    {
        return DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->whereNotNull('owner_type')
            ->distinct()
            ->pluck('owner_type')
            ->map(fn (string $ownerType): string => $this->logicalOwnerType($ownerType))
            ->unique()
            ->mapWithKeys(fn (string $ownerType): array => [
                $ownerType => $this->usageLabel($ownerType),
            ])
            ->sort();
    }

    private function logicalOwnerType(string $ownerType): string
    {
        return match ($ownerType) {
            'course_template_version' => 'course_template',
            'course_version_activity' => 'course_activity',
            default => $ownerType,
        };
    }

    private function physicalOwnerTypes(string $ownerType): array
    {
        return match ($ownerType) {
            'course_template' => ['course_template', 'course_template_version'],
            'course_activity' => ['course_activity', 'course_version_activity'],
            default => [$ownerType],
        };
    }

    private function tabCounts(int $customerId): array
    {
        $counts = DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'deleted')
            ->select('file_type', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('file_type')
            ->pluck('aggregate', 'file_type')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return [
            'all' => array_sum($counts),
            'images' => $counts['image'] ?? 0,
            'videos' => $counts['video'] ?? 0,
            'documents' => $counts['document'] ?? 0,
            'audio' => $counts['audio'] ?? 0,
        ];
    }

    private function previewUrl(object $mediaFile): ?string
    {
        if ($mediaFile->status !== 'ready') {
            return null;
        }

        return $this->mediaService->generateSignedUrl((int) $mediaFile->id);
    }

    private function normalizedTab(Request $request): string
    {
        $tab = (string) $request->query('tab', 'all');

        return in_array($tab, self::TABS, true) ? $tab : 'all';
    }

    private function normalizedType(Request $request): ?string
    {
        $type = (string) $request->query('type', '');

        return in_array($type, self::FILTER_TYPES, true) ? $type : null;
    }

    private function normalizedKeyword(Request $request): ?string
    {
        $keyword = trim((string) $request->query('keyword', ''));

        return $keyword === '' ? null : mb_substr($keyword, 0, 100);
    }

    private function normalizedValue(mixed $value, array $allowed): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function usageLabel(string $value): string
    {
        $translationKey = 'lf.LF_media_usage_label_'.$value;

        if (Lang::has($translationKey)) {
            return __($translationKey);
        }

        return str((string) $value)
            ->replace('_', ' ')
            ->headline()
            ->toString();
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }
}
