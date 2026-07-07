<?php

namespace App\Http\Controllers;

use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseCategoryController extends Controller
{
    public function __construct(private readonly MediaService $mediaService) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $keyword = trim((string) $request->query('keyword', ''));
        $status = $request->query('status');

        if (! in_array($status, ['active', 'inactive'], true)) {
            $status = null;
        }

        $categories = DB::table('core_course_categories as categories')
            ->leftJoin('core_course_categories as parent', function ($join) use ($customerId): void {
                $join->on('parent.id', '=', 'categories.parent_id')
                    ->where('parent.customer_id', '=', $customerId);
            })
            ->where('categories.customer_id', $customerId)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('categories.name', 'like', '%'.$keyword.'%')
                        ->orWhere('categories.slug', 'like', '%'.$keyword.'%');
                });
            })
            ->when($status, function ($query) use ($status): void {
                $query->where('categories.status', $status);
            })
            ->when(
                $request->user()?->role === 'teacher',
                fn ($query) => $query->where('categories.created_by', $request->user()->id)
            )
            ->orderBy('categories.sort_order')
            ->orderBy('categories.name')
            ->select('categories.*', 'parent.name as parent_name')
            ->get();

        $categories->each(function (object $category): void {
            $category->thumbnail_media = $this->singleMedia(
                'course_category',
                (int) $category->id,
                'thumbnail'
            );
        });

        return view('course-categories.index', [
            'categories' => $categories,
            'keyword' => $keyword,
            'status' => $status,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function create(Request $request): View
    {
        return view('course-categories.create', [
            'parentCategories' => $this->parentCategories(),
            'routePrefix' => $this->routePrefix($request),
            'thumbnailMedia' => null,
            'bannerMedia' => null,
        ]);
    }

    public function store(Request $request)
    {
        $customerId = $this->customerId();
        $validated = $this->validatedData($request, $customerId);
        $now = now();

        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId,
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'thumbnail_image' => $validated['thumbnail_image'] ?? null,
            'banner_image' => $validated['banner_image'] ?? null,
            'sort_order' => $validated['sort_order'],
            'is_featured' => (bool) $validated['is_featured'],
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'meta_keywords' => $validated['meta_keywords'] ?? null,
            'status' => $validated['status'],
            'created_by' => $request->user()?->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->syncUploadedMedia($request, $categoryId, $validated['name']);

        return redirect()
            ->route($this->routePrefix($request).'.index')
            ->with('success', __('lf.LF_course_category_common_created'));
    }

    public function edit(Request $request, int $id): View
    {
        $customerId = $this->customerId();
        $category = $this->findCategory($customerId, $id);
        $excludedIds = array_merge([$id], $this->descendantIds($customerId, $id));

        return view('course-categories.edit', [
            'category' => $category,
            'parentCategories' => $this->parentCategories($excludedIds),
            'routePrefix' => $this->routePrefix($request),
            'thumbnailMedia' => $this->singleMedia(
                'course_category',
                $id,
                'thumbnail'
            ),
            'bannerMedia' => $this->singleMedia(
                'course_category',
                $id,
                'banner_image'
            ),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $customerId = $this->customerId();
        $category = $this->findCategory($customerId, $id);
        $validated = $this->validatedData($request, $customerId, $id, $category);

        $values = [
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'thumbnail_image' => $validated['thumbnail_image'] ?? null,
            'banner_image' => $validated['banner_image'] ?? null,
            'sort_order' => $validated['sort_order'],
            'is_featured' => (bool) $validated['is_featured'],
            'status' => $validated['status'],
            'updated_at' => now(),
        ];

        foreach (['meta_title', 'meta_description', 'meta_keywords'] as $field) {
            if ($request->has($field)) {
                $values[$field] = $validated[$field] ?? null;
            }
        }

        DB::table('core_course_categories')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->update($values);

        $this->syncUploadedMedia($request, $id, $validated['name']);

        return redirect()
            ->route($this->routePrefix($request).'.edit', $id)
            ->with('success', __('lf.LF_course_category_common_updated'));
    }

    public function toggleStatus(Request $request, int $id)
    {
        $customerId = $this->customerId();

        DB::transaction(function () use ($customerId, $id): void {
            $category = DB::table('core_course_categories')
                ->where('customer_id', $customerId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            abort_if(! $category, 404);

            DB::table('core_course_categories')
                ->where('customer_id', $customerId)
                ->where('id', $id)
                ->update([
                    'status' => $category->status === 'active' ? 'inactive' : 'active',
                    'updated_at' => now(),
                ]);
        });

        return back()->with('success', __('lf.LF_course_category_common_status_updated'));
    }

    private function validatedData(
        Request $request,
        int $customerId,
        ?int $categoryId = null,
        ?object $category = null
    ): array {
        $input = $request->all();
        $input['slug'] = $this->systemSlug(
            (string) $request->input('name', ''),
            $category,
            'name'
        );

        $validator = Validator::make($input, [
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('core_course_categories', 'id')
                    ->where(fn ($query) => $query->where('customer_id', $customerId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('core_course_categories', 'slug')
                    ->where(fn ($query) => $query->where('customer_id', $customerId))
                    ->ignore($categoryId),
            ],
            'description' => ['nullable', 'string'],
            'thumbnail_image' => ['nullable', 'string', 'max:500'],
            'banner_image' => ['nullable', 'string', 'max:500'],
            'thumbnail_image_file' => [
                'nullable',
                'file',
                'max:'.(int) config('media.max_upload_kilobytes', 102400),
            ],
            'banner_image_file' => [
                'nullable',
                'file',
                'max:'.(int) config('media.max_upload_kilobytes', 102400),
            ],
            'remove_thumbnail_image_media' => ['nullable', 'boolean'],
            'remove_banner_image_media' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer'],
            'is_featured' => ['required', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        if ($categoryId !== null) {
            $validator->after(function ($validator) use ($request, $customerId, $categoryId): void {
                $parentId = $request->integer('parent_id');

                if ($parentId === 0) {
                    return;
                }

                if (
                    $parentId === $categoryId
                    || in_array($parentId, $this->descendantIds($customerId, $categoryId), true)
                ) {
                    $validator->errors()->add(
                        'parent_id',
                        __('lf.LF_course_category_common_invalid_parent')
                    );
                }
            });
        }

        return $validator->validate();
    }

    private function systemSlug(
        string $source,
        ?object $existingRecord = null,
        string $sourceField = 'name'
    ): string {
        if ($existingRecord === null) {
            return Str::slug($source);
        }

        $currentAutoSlug = Str::slug((string) $existingRecord->{$sourceField});

        if ((string) $existingRecord->slug !== $currentAutoSlug) {
            return (string) $existingRecord->slug;
        }

        return Str::slug($source);
    }

    private function parentCategories(array $excludedIds = [])
    {
        return DB::table('core_course_categories')
            ->where('customer_id', $this->customerId())
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function descendantIds(int $customerId, int $categoryId): array
    {
        $categories = DB::table('core_course_categories')
            ->where('customer_id', $customerId)
            ->select('id', 'parent_id')
            ->get();
        $descendantIds = [];
        $parentIds = [$categoryId];

        do {
            $children = $categories
                ->filter(fn ($category) => in_array((int) $category->parent_id, $parentIds, true))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->diff($descendantIds)
                ->values()
                ->all();

            $descendantIds = array_merge($descendantIds, $children);
            $parentIds = $children;
        } while ($parentIds !== []);

        return $descendantIds;
    }

    private function findCategory(int $customerId, int $id): object
    {
        $category = DB::table('core_course_categories')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->first();

        abort_if(! $category, 404);
        $this->authorizeCategoryAccess($category);

        return $category;
    }

    private function syncUploadedMedia(
        Request $request,
        int $categoryId,
        string $categoryName
    ): void {
        foreach (
            [
                'thumbnail_image_file' => [
                    'usage_type' => 'thumbnail',
                    'purpose' => 'thumbnail',
                    'remove_field' => 'remove_thumbnail_image_media',
                ],
                'banner_image_file' => [
                    'usage_type' => 'banner_image',
                    'purpose' => 'banner',
                    'remove_field' => 'remove_banner_image_media',
                ],
            ] as $field => $mediaConfig
        ) {
            if (
                ! $request->hasFile($field)
                && ! $request->boolean($mediaConfig['remove_field'])
            ) {
                continue;
            }

            $this->detachExistingMedia(
                $categoryId,
                $mediaConfig['usage_type']
            );

            if (! $request->hasFile($field)) {
                continue;
            }

            $mediaFile = $this->mediaService->upload(
                $request->file($field),
                [
                    'file_type' => 'image',
                    'module' => 'course',
                    'entity_type' => 'categories',
                    'entity_id' => $categoryId,
                    'purpose' => $mediaConfig['purpose'],
                    'display_name' => $categoryName,
                ],
                (int) $request->user()->id
            );

            $this->mediaService->attachUsage(
                (int) $mediaFile->id,
                'course_category',
                $categoryId,
                $mediaConfig['usage_type']
            );
        }
    }

    private function detachExistingMedia(
        int $categoryId,
        string $usageType
    ): void {
        foreach (
            $this->mediaService->getOwnerMedia(
                'course_category',
                $categoryId,
                $usageType
            ) as $media
        ) {
            $this->mediaService->detachUsage(
                (int) $media->id,
                'course_category',
                $categoryId,
                $usageType
            );
        }
    }

    private function singleMedia(
        string $ownerType,
        int $ownerId,
        string $usageType
    ): ?object {
        $media = $this->mediaService
            ->getOwnerMedia($ownerType, $ownerId, $usageType)
            ->first();

        if (! $media) {
            return null;
        }

        $media->signed_url = $this->mediaService->generateSignedUrl(
            (int) $media->id
        );

        return $media;
    }

    private function authorizeCategoryAccess(object $category): void
    {
        $user = request()->user();

        if ($user?->role === 'customer_admin') {
            return;
        }

        abort_unless(
            $user?->role === 'teacher'
            && (int) $category->created_by === (int) $user->id,
            403
        );
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }

    private function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.course-categories',
            'teacher' => 'teacher.course-categories',
            default => abort(403),
        };
    }
}
