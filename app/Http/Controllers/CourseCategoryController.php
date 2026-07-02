<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseCategoryController extends Controller
{
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
            ->orderBy('categories.sort_order')
            ->orderBy('categories.name')
            ->select('categories.*', 'parent.name as parent_name')
            ->get();

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
        ]);
    }

    public function store(Request $request)
    {
        $customerId = $this->customerId();
        $validated = $this->validatedData($request, $customerId);
        $now = now();

        DB::table('core_course_categories')->insert([
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
        ]);
    }

    public function update(Request $request, int $id)
    {
        $customerId = $this->customerId();
        $this->findCategory($customerId, $id);
        $validated = $this->validatedData($request, $customerId, $id);

        DB::table('core_course_categories')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->update([
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
                'updated_at' => now(),
            ]);

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

    private function validatedData(Request $request, int $customerId, ?int $categoryId = null): array
    {
        $validator = Validator::make($request->all(), [
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

        return $category;
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
