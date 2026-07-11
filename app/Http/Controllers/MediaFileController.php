<?php

namespace App\Http\Controllers;

use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private readonly MediaService $mediaService) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $tab = $this->normalizedTab($request);
        $type = $this->normalizedType($request);
        $ownerType = $this->normalizedValue(
            $request->query('owner_type'),
            $this->usageOptions($customerId, 'owner_type')->keys()->all()
        );
        $usageType = $this->normalizedValue(
            $request->query('usage_type'),
            $this->usageOptions($customerId, 'usage_type')->keys()->all()
        );
        $fileType = $type ?? self::TAB_TYPES[$tab] ?? null;

        $mediaFiles = $this->mediaQuery($customerId, $fileType, $ownerType, $usageType)
            ->orderByDesc('media_files.created_at')
            ->orderByDesc('media_files.id')
            ->paginate(10)
            ->withQueryString();

        $usageGroups = $this->usageGroups($customerId, $mediaFiles->getCollection()->pluck('id'));
        $mediaFiles->setCollection($mediaFiles->getCollection()->map(function (object $mediaFile) use ($usageGroups): object {
            $mediaFile->active_usages = $usageGroups->get((int) $mediaFile->id, collect());
            $mediaFile->preview_url = $this->previewUrl($mediaFile);

            return $mediaFile;
        }));

        return view('media-files.index', [
            'mediaFiles' => $mediaFiles,
            'tabs' => self::TABS,
            'tab' => $tab,
            'type' => $type,
            'ownerType' => $ownerType,
            'usageType' => $usageType,
            'ownerTypeOptions' => $this->usageOptions($customerId, 'owner_type'),
            'usageTypeOptions' => $this->usageOptions($customerId, 'usage_type'),
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

    private function mediaQuery(
        int $customerId,
        ?string $fileType,
        ?string $ownerType,
        ?string $usageType
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
            ->when($ownerType, function ($query) use ($customerId, $ownerType): void {
                $query->whereExists(function ($usageQuery) use ($customerId, $ownerType): void {
                    $usageQuery->selectRaw('1')
                        ->from('media_file_usages')
                        ->whereColumn('media_file_usages.media_file_id', 'media_files.id')
                        ->where('media_file_usages.customer_id', $customerId)
                        ->where('media_file_usages.owner_type', $ownerType)
                        ->where('media_file_usages.status', 'active');
                });
            })
            ->when($usageType, function ($query) use ($customerId, $usageType): void {
                $query->whereExists(function ($usageQuery) use ($customerId, $usageType): void {
                    $usageQuery->selectRaw('1')
                        ->from('media_file_usages')
                        ->whereColumn('media_file_usages.media_file_id', 'media_files.id')
                        ->where('media_file_usages.customer_id', $customerId)
                        ->where('media_file_usages.usage_type', $usageType)
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

        return $this->attachOwnerNames($customerId, $usages)
            ->groupBy('media_file_id');
    }

    private function attachOwnerNames(int $customerId, Collection $usages): Collection
    {
        $ownerNames = $this->ownerNames($customerId, $usages);

        return $usages->map(function (object $usage) use ($ownerNames): object {
            $key = $usage->owner_type.':'.$usage->owner_id;
            $usage->owner_name = $ownerNames[$key] ?? '#'.$usage->owner_id;

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

    private function usageOptions(int $customerId, string $field): Collection
    {
        return DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->whereNotNull($field)
            ->distinct()
            ->orderBy($field)
            ->pluck($field)
            ->mapWithKeys(fn (string $value): array => [$value => $this->usageLabel($value)])
            ->sort();
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
        if (
            ! in_array($mediaFile->file_type, ['image', 'video'], true)
            || $mediaFile->status !== 'ready'
        ) {
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
