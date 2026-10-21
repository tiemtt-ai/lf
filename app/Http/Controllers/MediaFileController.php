<?php

namespace App\Http\Controllers;

use App\Services\MediaService;
use App\Services\MediaShareBlockedException;
use App\Services\MediaShareService;
use App\Services\MediaStorageResidueSweeper;
use App\Services\MediaThumbnailPresenter;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        private readonly MediaThumbnailPresenter $thumbnails,
        private readonly MediaStorageResidueSweeper $sweeper,
        private readonly MediaShareService $shares
    ) {}

    /**
     * Set who a Media is shared with: nobody, chosen teachers, or every teacher (ADR-0021).
     */
    public function updateShares(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', 'in:'.implode(',', MediaShareService::SCOPES)],
            'user_ids' => ['nullable', 'array', 'max:200'],
            'user_ids.*' => ['integer', 'distinct'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $state = $this->shares->apply($request->user(), $this->customerId(), $id, $validated['scope'], $validated['user_ids'] ?? [], $validated['note'] ?? null);
        } catch (MediaShareBlockedException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'blocked' => $exception->users], 409);
        }

        // The list reloads right after this answer, so the message is flashed for it to show as a toast.
        $request->session()->flash('success', __('lf.LF_media_share_saved'));

        return response()->json(['message' => __('lf.LF_media_share_saved'), 'state' => $state]);
    }

    /**
     * Don file mo coi tren storage cho tenant hien tai.
     *
     * KHONG co lich chay tu dong. Storage va DB khong co transaction chung, nen
     * mot revision trich xuat hong hoac mot lan xoa Media hong co the de lai file
     * khong con dong nao trong DB tro toi. Xoa file la thao tac khong hoan tac
     * duoc, nen no do admin bam, khong do he thong tu quyet.
     *
     * Chi quet tenant hien tai: TenantContext la ranh gioi, khong phai bo loc.
     */
    public function purgeOrphanStorage(Request $request): RedirectResponse|JsonResponse
    {
        Cache::forget($this->cleanupStatusKey());
        $found = $this->sweeper->scan($this->customerId());

        if ($found === []) {
            return $this->listResponse($request, 'success', __('lf.LF_media_storage_purge_nothing'));
        }

        $purged = 0;
        $failed = 0;
        foreach ($found as $item) {
            $result = $item['keys'] === []
                ? ['success' => false, 'deleted_count' => 0]
                : $this->sweeper->purge($item);
            if ($result['success']) {
                $purged += $result['deleted_count'];

                continue;
            }
            $failed++;
        }

        return $failed === 0
            ? $this->listResponse($request, 'success', __('lf.LF_media_storage_purge_done', ['count' => $purged]))
            : $this->listResponse($request, 'error', __('lf.LF_media_storage_purge_partial', [
                'count' => $purged,
                'failed' => $failed,
            ]));
    }

    /**
     * Whether the storage has leftovers, for the heading button (clean, or N to clean). The scan can be slow on a big
     * storage, so the answer is kept for ten minutes and forgotten whenever something may have changed (a Media
     * deleted, a clean-up run). Nothing is deleted here.
     */
    public function cleanupStatus(Request $request): JsonResponse
    {
        $status = Cache::get($this->cleanupStatusKey());

        if (! is_array($status)) {
            $found = array_filter($this->sweeper->scan($this->customerId()), static fn (array $item): bool => $item['keys'] !== []);
            $status = ['items' => count($found), 'files' => array_sum(array_map(static fn (array $item): int => count($item['keys']), $found))];
            Cache::put($this->cleanupStatusKey(), $status, now()->addMinutes(10));
        }

        return response()->json($status);
    }

    private function cleanupStatusKey(): string
    {
        return 'media-cleanup-status:'.$this->customerId();
    }

    /**
     * Step 1 of the storage clean-up dialog: scan this tenant's storage for leftovers and keep the findings for a few
     * minutes under a token, so the next calls can purge them in small batches (with real progress) without scanning
     * again. Nothing is deleted here.
     */
    public function cleanupScan(Request $request): JsonResponse
    {
        $found = $this->sweeper->scan($this->customerId());
        $purgeable = array_values(array_filter($found, static fn (array $item): bool => $item['keys'] !== []));
        $token = Str::random(32);
        Cache::put($this->cleanupCacheKey($request, $token), $purgeable, now()->addMinutes(15));
        Cache::put($this->cleanupStatusKey(), [
            'items' => count($purgeable),
            'files' => array_sum(array_map(static fn (array $item): int => count($item['keys']), $purgeable)),
        ], now()->addMinutes(10));

        return response()->json([
            'token' => $token,
            'items' => count($purgeable),
            'files' => array_sum(array_map(static fn (array $item): int => count($item['keys']), $purgeable)),
            // Media whose storage could not be read: reported, never touched.
            'unreadable' => count($found) - count($purgeable),
        ]);
    }

    /**
     * Step 3: purge one batch of the findings of a scan. The sweeper re-checks every reference before deleting, so a
     * leftover that became referenced since the scan is kept (reported as not purged).
     */
    public function cleanupRun(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:32'],
            'offset' => ['required', 'integer', 'min:0'],
            'limit' => ['required', 'integer', 'min:1', 'max:25'],
        ]);
        $key = $this->cleanupCacheKey($request, $validated['token']);
        $items = Cache::get($key);

        if (! is_array($items)) {
            return response()->json(['message' => __('lf.LF_media_cleanup_expired')], 410);
        }

        $batch = array_slice($items, (int) $validated['offset'], (int) $validated['limit']);
        $purged = 0;
        $failed = 0;
        foreach ($batch as $item) {
            $result = $this->sweeper->purge($item);
            $result['success'] ? $purged += $result['deleted_count'] : $failed++;
        }
        $next = (int) $validated['offset'] + count($batch);
        $finished = $next >= count($items);
        if ($finished) {
            Cache::forget($key);
            Cache::forget($this->cleanupStatusKey());
        }

        return response()->json([
            'processed' => count($batch),
            'purged' => $purged,
            'failed' => $failed,
            'total' => count($items),
            'next' => $next,
            'finished' => $finished,
        ]);
    }

    private function cleanupCacheKey(Request $request, string $token): string
    {
        return 'media-cleanup:'.$this->customerId().':'.$request->user()?->id.':'.$token;
    }

    /**
     * The answer to a list action: the legacy redirect back to the list the user was viewing (filters kept), or, for the
     * UI Kit's XHR, JSON with that same URL (the page reloads there and shows the flashed message as a toast). A
     * failure is a 409 with its message.
     */
    /** A teacher sees the library through their own and shared Media (ADR-0021); an admin sees the whole tenant. */
    private function teacherId(): ?int
    {
        $user = request()->user();

        return $user?->role === 'teacher' ? (int) $user->id : null;
    }

    private function routePrefix(): string
    {
        return $this->teacherId() !== null ? 'teacher' : 'admin';
    }

    private function listResponse(Request $request, string $flashKey, string $message): RedirectResponse|JsonResponse
    {
        $url = route($this->routePrefix().'.media.index', $this->listFilters($request));

        if ($request->expectsJson()) {
            if ($flashKey === 'error') {
                return response()->json(['message' => $message], 409);
            }
            $request->session()->flash('success', $message);

            return response()->json(['message' => $message, 'redirect' => $url]);
        }

        return redirect()->to($url)->with($flashKey, $message);
    }

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $teacherId = $this->teacherId();
        $tab = $this->normalizedTab($request);
        $type = $this->normalizedType($request);
        $keyword = $this->normalizedKeyword($request);
        $ownerTypeOptions = $this->ownerTypeOptions($customerId);
        // The "where it is used" filters look across the tenant: only admins have them.
        $ownerType = $teacherId !== null ? null : $this->normalizedValue(
            $request->query('owner_type'),
            $ownerTypeOptions->keys()->all()
        );
        $source = $teacherId !== null ? $this->normalizedValue($request->query('source'), ['mine', 'mine_private', 'mine_shared', 'shared']) : null;
        // Admins can also filter by how a Media is shared (nobody / chosen teachers / all teachers).
        $share = $teacherId === null ? $this->normalizedValue($request->query('share'), ['private', 'teachers', 'all_teachers']) : null;
        $usageStatus = $this->normalizedValue(
            $request->query('usage_status'),
            self::USAGE_STATUSES
        );
        $ownerObjects = $teacherId !== null ? [] : $this->ownerObjects($customerId);
        $request->validate(['owner_refs' => ['nullable', 'array', 'max:100'], 'owner_refs.*' => ['string', 'max:100']]);
        $ownerRefs = array_values(array_intersect($request->query('owner_refs', []), array_column($ownerObjects[$ownerType] ?? [], 'value')));
        $fileType = $type ?? self::TAB_TYPES[$tab] ?? null;

        $queryRefs = $ownerRefs;
        if ($ownerType === 'course_template' && $ownerRefs !== []) {
            $ids = array_map(fn ($ref) => (int) explode(':', $ref, 2)[1], $ownerRefs);
            foreach (DB::table('core_course_template_versions')->where('customer_id', $customerId)->whereIn('template_id', $ids)->pluck('id') as $id) {
                $queryRefs[] = 'course_template_version:'.$id;
            }
        }
        $mediaFiles = $this->mediaQuery($customerId, $fileType, $keyword, $ownerType, $usageStatus, $queryRefs, $teacherId, $source, $share)
            ->orderByDesc('media_files.created_at')
            ->orderByDesc('media_files.id')
            ->paginate(10)
            ->withQueryString();

        $usageGroups = $this->usageGroups($customerId, $mediaFiles->getCollection()->pluck('id'), $teacherId);
        $shareStates = $this->shares->stateFor($customerId, $mediaFiles->getCollection()->pluck('id'));
        $mediaFiles->setCollection($mediaFiles->getCollection()->map(function (object $mediaFile) use ($usageGroups, $shareStates, $teacherId): object {
            $mediaFile->active_usages = $usageGroups->get((int) $mediaFile->id, collect());
            $mediaFile->share_state = $shareStates[(int) $mediaFile->id];
            $mediaFile->is_mine = $teacherId === null || (int) $mediaFile->uploaded_by === $teacherId;
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
            // Candidates for the (not yet saved) sharing dialog: the active teachers of this tenant (admins already see every Media).
            'shareTeachers' => DB::table('users')->where('customer_id', $customerId)->where('role', 'teacher')
                ->where('status', 'active')->where('id', '<>', (int) $request->user()->id)->orderBy('name')->get(['id', 'name', 'email', 'role'])
                ->map(fn ($u): array => ['id' => (int) $u->id, 'name' => (string) $u->name, 'email' => (string) $u->email, 'role' => (string) $u->role])->all(),
            'ownerObjects' => $ownerObjects,
            'ownerRefs' => $ownerRefs,
            'tabCounts' => $this->tabCounts($customerId, $teacherId),
            'isTeacher' => $teacherId !== null,
            'routePrefix' => $this->routePrefix(),
            'source' => $source,
            'share' => $share,
            'listFilters' => $this->listFilters($request),
            // Only what is already known (no scan while the page renders); null = unknown, the page asks afterwards.
            'cleanupStatus' => Cache::get($this->cleanupStatusKey()),
        ]);
    }

    /**
     * The Media a form may pick from its library (JSON): ready ones of one type, an admin from the whole tenant, a teacher from
     * their own and what was shared with them. Newest first, 30 at most; `keyword` narrows by name.
     */
    public function picker(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:video,audio,document'],
            'keyword' => ['nullable', 'string', 'max:100'],
        ]);
        $customerId = $this->customerId();
        $teacherId = $this->teacherId();
        $keyword = trim((string) ($validated['keyword'] ?? ''));

        $rows = $this->mediaQuery($customerId, $validated['type'], $keyword !== '' ? $keyword : null, null, null, [], $teacherId)
            ->where('media_files.status', 'ready')
            ->orderByDesc('media_files.created_at')->orderByDesc('media_files.id')
            ->limit(30)->get();

        return response()->json(['items' => $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->display_name,
            'file' => (string) $row->original_name,
            'size' => number_format(((int) $row->file_size_bytes) / 1024, 1).' KB',
            'note' => (string) ($row->share_note ?? ''),
            'source' => $teacherId !== null && (int) $row->uploaded_by !== $teacherId
                ? __('lf.LF_media_source_shared_by', ['name' => $row->uploaded_by_name ?? '—'])
                : ($teacherId !== null ? __('lf.LF_media_source_mine') : (string) ($row->uploaded_by_name ?? '—')),
        ])->values()->all()]);
    }

    public function destroy(Request $request, int $id): RedirectResponse|JsonResponse
    {
        try {
            $this->mediaService->deleteMedia($id);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => (string) collect($exception->errors())->flatten()->first()], 409);
            }

            throw $exception;
        }

        Cache::forget($this->cleanupStatusKey());

        return $this->listResponse($request, 'success', __('lf.LF_media_file_common_deleted'));
    }

    /**
     * Giu nguyen bo loc nguoi dung dang xem khi quay lai danh sach.
     *
     * Truoc day bulkDestroy() dieu huong cung `usage_status=unused`. Hau qua:
     * xoa ban ghi chua dung cuoi cung thi danh sach ve rong va hien "Khong tim
     * thay Media phu hop" ngay ben duoi tab "Tat ca 17" — dung ve ky thuat nhung
     * doc len nhu mat du lieu. destroy() thi nguoc lai: vut bo moi bo loc va nem
     * nguoi dung ve danh sach khong loc.
     *
     * Chi lay lai cac khoa bo loc da biet, khong nhan URL tu request, de khong mo
     * duong open redirect.
     *
     * @return array<string, string>
     */
    private function listFilters(Request $request): array
    {
        $filters = array_filter([
            'tab' => (string) $request->input('tab', ''),
            'type' => (string) $request->input('type', ''),
            'keyword' => (string) $request->input('keyword', ''),
            'owner_type' => (string) $request->input('owner_type', ''),
            'usage_status' => (string) $request->input('usage_status', ''),
            'source' => (string) $request->input('source', ''),
            'share' => (string) $request->input('share', ''),
            'page' => (string) $request->input('page', ''),
        ], static fn (string $value): bool => $value !== '');
        foreach (array_slice((array) $request->input('owner_refs', []), 0, 100) as $index => $ref) {
            if (is_string($ref) && preg_match('/^[a-z_]+:[0-9]+$/', $ref)) {
                $filters['owner_refs['.$index.']'] = $ref;
            }
        }

        return $filters;
    }

    public function bulkDestroy(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'media_ids' => ['required', 'array', 'min:1', 'max:100'],
            'media_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $teacherId = $this->teacherId();
        if ($teacherId !== null) {
            // A teacher deletes their own Media only (and only when nothing uses it: the service refuses otherwise).
            $own = DB::table('media_files')->where('customer_id', $this->customerId())->where('uploaded_by', $teacherId)
                ->whereIn('id', $validated['media_ids'])->count();
            abort_if($own !== count($validated['media_ids']), 403);
        }

        try {
            $deletedCount = $this->mediaService->deleteUnusedMedia(
                $validated['media_ids']
            );
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => (string) collect($exception->errors())->flatten()->first()], 409);
            }

            throw $exception;
        }

        Cache::forget($this->cleanupStatusKey());

        return $this->listResponse($request, 'success', trans_choice(
            'lf.LF_media_file_bulk_deleted',
            $deletedCount,
            ['count' => $deletedCount]
        ));
    }

    /** Subquery: the Media has an active share (of a kind, when given). */
    private function activeShares(int $customerId, ?string $granteeType = null): \Closure
    {
        return function ($query) use ($customerId, $granteeType): void {
            $query->selectRaw('1')->from('media_file_shares as active_shares')
                ->whereColumn('active_shares.media_file_id', 'media_files.id')
                ->where('active_shares.customer_id', $customerId)
                ->whereNull('active_shares.revoked_at')
                ->when($granteeType !== null, fn ($q) => $q->where('active_shares.grantee_type', $granteeType));
        };
    }

    private function mediaQuery(
        int $customerId,
        ?string $fileType,
        ?string $keyword,
        ?string $ownerType,
        ?string $usageStatus,
        array $ownerRefs = [],
        ?int $teacherId = null,
        ?string $source = null,
        ?string $share = null
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
            ->when($teacherId !== null, fn ($query) => $query->whereIn('media_files.id', $this->shares->visibleToTeacher($customerId, $teacherId)->select('media_files.id')))
            ->when($teacherId !== null && $source === 'mine', fn ($query) => $query->where('media_files.uploaded_by', $teacherId))
            ->when($teacherId !== null && $source === 'shared', fn ($query) => $query->where('media_files.uploaded_by', '!=', $teacherId))
            ->when($teacherId !== null && in_array($source, ['mine_private', 'mine_shared'], true), fn ($query) => $query->where('media_files.uploaded_by', $teacherId))
            ->when($source === 'mine_private', fn ($query) => $query->whereNotExists($this->activeShares($customerId)))
            ->when($source === 'mine_shared', fn ($query) => $query->whereExists($this->activeShares($customerId)))
            ->when($share === 'private', fn ($query) => $query->whereNotExists($this->activeShares($customerId)))
            ->when($share === 'all_teachers', fn ($query) => $query->whereExists($this->activeShares($customerId, 'role')))
            ->when($share === 'teachers', fn ($query) => $query->whereExists($this->activeShares($customerId, 'user'))->whereNotExists($this->activeShares($customerId, 'role')))
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
            ->when($ownerType, function ($query) use ($customerId, $ownerType, $ownerRefs): void {
                $query->whereExists(function ($usageQuery) use ($customerId, $ownerType, $ownerRefs): void {
                    $usageQuery->selectRaw('1')
                        ->from('media_file_usages')
                        ->whereColumn('media_file_usages.media_file_id', 'media_files.id')
                        ->where('media_file_usages.customer_id', $customerId)
                        ->whereIn('media_file_usages.owner_type', $this->physicalOwnerTypes($ownerType))
                        ->where('media_file_usages.status', 'active')
                        ->when($ownerRefs, function ($q) use ($ownerRefs): void {
                            $q->where(function ($refs) use ($ownerRefs): void {
                                foreach ($ownerRefs as $ref) {
                                    [$kind, $id] = explode(':', $ref, 2);
                                    $refs->orWhere(fn ($pair) => $pair->where('media_file_usages.owner_type', $kind)->where('media_file_usages.owner_id', (int) $id));
                                }
                            });
                        });
                });
            })
            ->select([
                'media_files.*',
                'users.name as uploaded_by_name',
                'users.email as uploaded_by_email',
                DB::raw('COALESCE(usage_counts.usage_count, 0) as usage_count'),
                DB::raw($teacherId !== null
                    ? '(SELECT COUNT(*) FROM media_file_usages mine WHERE mine.customer_id = media_files.customer_id AND mine.media_file_id = media_files.id AND mine.status = \'active\' AND mine.created_by = '.(int) $teacherId.') as my_usage_count'
                    : '0 as my_usage_count'),
            ]);
    }

    private function usageGroups(int $customerId, Collection $mediaFileIds, ?int $onlyCreatedBy = null): Collection
    {
        $ids = $mediaFileIds->map(fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return collect();
        }

        $usages = DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->whereIn('media_file_id', $ids)
            ->where('status', 'active')
            // A teacher is told where they use a Media, never what other people built with it.
            ->when($onlyCreatedBy !== null, fn ($query) => $query->where('created_by', $onlyCreatedBy))
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
        // A snapshot whose source activity was deleted later stays a version activity: there is no live activity to point at.
        if ($sourceActivityIds->isNotEmpty()) {
            $liveActivityIds = DB::table('core_course_template_activities')
                ->where('customer_id', $customerId)
                ->whereIn('id', $sourceActivityIds->filter()->values()->all())
                ->pluck('id')->map(fn ($id): int => (int) $id);
            $sourceActivityIds = $sourceActivityIds->filter(fn ($id): bool => $liveActivityIds->contains((int) $id));
        }
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

        // Same for a version whose template was deleted later: keep pointing at the version.
        if ($sourceTemplateIds->isNotEmpty()) {
            $liveTemplateIds = DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->whereIn('id', $sourceTemplateIds->filter()->values()->all())
                ->pluck('id')->map(fn ($id): int => (int) $id);
            $sourceTemplateIds = $sourceTemplateIds->filter(fn ($id): bool => $liveTemplateIds->contains((int) $id));
        }

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
        $ownerUrls = $this->ownerUrls($customerId, $usages);

        return $usages->map(function (object $usage) use ($ownerNames, $ownerUrls): object {
            $key = $usage->owner_type.':'.$usage->owner_id;
            $usage->owner_name = $ownerNames[$key]
                ?? __('lf.LF_media_file_usage_unknown_owner');
            $usage->owner_url = $ownerUrls[$key] ?? null;

            return $usage;
        });
    }

    /**
     * Where each usage can be opened (keyed like the names); an owner without a page of its own links to its list.
     *
     * @return array<string, string>
     */
    private function ownerUrls(int $customerId, Collection $usages): array
    {
        $urls = [];

        foreach ($usages as $usage) {
            $id = (int) $usage->owner_id;
            $prefix = $this->routePrefix();
            $url = match ($usage->owner_type) {
                'course_template' => route($prefix.'.course-templates.edit', ['id' => $id]),
                'course_cohort' => $prefix === 'admin' ? route('admin.course-cohorts.show', ['id' => $id]) : null,
                'course_product' => $prefix === 'admin' ? route('admin.course-products.edit', ['id' => $id]) : null,
                'course_category' => route($prefix.'.course-categories.index'),
                default => null,
            };

            if ($url !== null) {
                $urls[$usage->owner_type.':'.$id] = $url;
            }
        }

        foreach ($usages->where('owner_type', 'course_template_version') as $usage) {
            $versionRow = DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)->where('id', (int) $usage->owner_id)->first(['id', 'template_id']);

            if ($versionRow !== null && $this->routePrefix() === 'admin') {
                $urls['course_template_version:'.$versionRow->id] = route('admin.course-templates.versions.show', ['templateId' => $versionRow->template_id, 'versionId' => $versionRow->id]);
            }
        }

        $versionActivityIds = $usages->where('owner_type', 'course_version_activity')->pluck('owner_id')->map(fn ($id): int => (int) $id)->unique()->all();

        if ($versionActivityIds !== []) {
            $versions = DB::table('core_course_template_version_activities as va')
                ->join('core_course_template_versions as v', 'v.id', '=', 'va.template_version_id')
                ->where('va.customer_id', $customerId)->whereIn('va.id', $versionActivityIds)
                ->get(['va.id', 'v.id as version_id', 'v.template_id']);

            foreach ($versions as $row) {
                if ($this->routePrefix() === 'admin') {
                    $urls['course_version_activity:'.$row->id] = route('admin.course-templates.versions.show', ['templateId' => $row->template_id, 'versionId' => $row->version_id]);
                }
            }
        }

        $activityIds = $usages->where('owner_type', 'course_activity')->pluck('owner_id')->map(fn ($id): int => (int) $id)->unique()->all();

        if ($activityIds !== []) {
            $rows = DB::table('core_course_template_activities as a')
                ->where('a.customer_id', $customerId)->whereIn('a.id', $activityIds)
                ->get(['a.id', 'a.template_id']);

            foreach ($rows as $row) {
                $urls['course_activity:'.$row->id] = route($this->routePrefix().'.course-templates.edit', ['id' => $row->template_id, 'tab' => 'structure']);
            }
        }

        return $urls;
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
            'course_template_version' => ['core_course_template_versions', 'title_snapshot'],
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

    private function ownerObjects(int $customerId): array
    {
        $sources = [
            'course_category' => ['core_course_categories', 'name'],
            'course_template' => ['core_course_templates', 'title'],
            'course_product' => ['core_course_products', 'title'],
            'course_activity' => ['core_course_template_activities', 'title'],
            'course_version_activity' => ['core_course_template_version_activities', 'title_snapshot'],
            'course_cohort' => ['core_course_cohorts', 'name'],
        ];
        $result = [];
        foreach ($sources as $kind => [$table, $label]) {
            $ids = DB::table('media_file_usages')->where('customer_id', $customerId)->where('status', 'active')->where('owner_type', $kind)->pluck('owner_id');
            if ($kind === 'course_template') {
                $versionIds = DB::table('media_file_usages')->where('customer_id', $customerId)->where('status', 'active')->where('owner_type', 'course_template_version')->pluck('owner_id');
                $ids = $ids->merge(DB::table('core_course_template_versions')->where('customer_id', $customerId)->whereIn('id', $versionIds)->pluck('template_id'))->unique();
            }
            foreach (DB::table($table)->where('customer_id', $customerId)->whereIn('id', $ids)->orderBy($label)->get(['id', $label]) as $object) {
                $result[$this->logicalOwnerType($kind)][] = ['value' => $kind.':'.$object->id, 'label' => $object->{$label}.(in_array($kind, ['course_template_version', 'course_version_activity'], true) ? ' · '.__('lf.LF_media_filter_published') : '').' #'.$object->id];
            }
        }

        return $result;
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

    private function tabCounts(int $customerId, ?int $teacherId = null): array
    {
        $counts = DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'deleted')
            ->when($teacherId !== null, fn ($query) => $query->whereIn('id', $this->shares->visibleToTeacher($customerId, $teacherId)->select('media_files.id')))
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
