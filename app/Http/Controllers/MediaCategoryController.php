<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MediaCategoryController extends Controller
{
    private const STATUSES = ['active', 'archived'];

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $keyword = trim((string) $request->query('keyword', ''));
        $status = $request->query('status');

        if (! in_array($status, self::STATUSES, true)) {
            $status = null;
        }

        $categories = DB::table('media_categories as categories')
            ->leftJoin('media_categories as parent', function ($join) use ($customerId): void {
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
            ->when($status, fn ($query) => $query->where('categories.status', $status))
            ->orderByDesc('categories.created_at')
            ->orderByDesc('categories.id')
            ->select('categories.*', 'parent.name as parent_name')
            ->paginate(10)
            ->withQueryString();

        return view('media-categories.index', [
            'categories' => $categories,
            'keyword' => $keyword,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        return view('media-categories.create', [
            'parentCategories' => $this->parentCategories(),
        ]);
    }

    public function store(Request $request)
    {
        $customerId = $this->customerId();
        $validated = $this->validatedData($request, $customerId);
        $now = now();

        DB::transaction(function () use ($customerId, $validated, $now): void {
            $sortOrder = $this->nextSortOrder($customerId, $validated['parent_id'] ?? null, true);
            DB::table('media_categories')->insert([
                'customer_id' => $customerId,
                'parent_id' => $validated['parent_id'] ?? null,
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'description' => $validated['description'] ?? null,
                'icon' => $validated['icon'] ?? null,
                'color' => $validated['color'] ?? null,
                'sort_order' => $sortOrder,
                'status' => $validated['status'],
                'metadata' => $validated['metadata'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return redirect()
            ->route('admin.media-categories.index')
            ->with('success', __('lf.LF_media_category_common_created'));
    }

    public function edit(int $id): View
    {
        $customerId = $this->customerId();
        $category = $this->findCategory($customerId, $id);
        $excludedIds = array_merge([$id], $this->descendantIds($customerId, $id));

        return view('media-categories.edit', [
            'category' => $category,
            'parentCategories' => $this->parentCategories($excludedIds),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $customerId = $this->customerId();
        $category = $this->findCategory($customerId, $id);
        $validated = $this->validatedData($request, $customerId, $id);

        DB::transaction(function () use ($customerId, $id, $category, $validated): void {
            $parentId = $validated['parent_id'] ?? null;
            $parentChanged = (int) ($category->parent_id ?? 0) !== (int) ($parentId ?? 0);
            $sortOrder = $parentChanged
                ? $this->nextSortOrder($customerId, $parentId, true)
                : (int) $category->sort_order;

            DB::table('media_categories')
                ->where('customer_id', $customerId)
                ->where('id', $id)
                ->update([
                    'parent_id' => $validated['parent_id'] ?? null,
                    'name' => $validated['name'],
                    'slug' => $validated['slug'],
                    'description' => $validated['description'] ?? null,
                    'icon' => $validated['icon'] ?? null,
                    'color' => $validated['color'] ?? null,
                    'sort_order' => $sortOrder,
                    'status' => $validated['status'],
                    'metadata' => $validated['metadata'] ?? null,
                    'updated_at' => now(),
                ]);
        });

        return redirect()
            ->route('admin.media-categories.edit', $id)
            ->with('success', __('lf.LF_media_category_common_updated'));
    }

    public function archive(int $id)
    {
        $customerId = $this->customerId();
        $category = $this->findCategory($customerId, $id);

        if ($this->hasReferences($customerId, $id)) {
            return back()->withErrors([
                'category' => __('lf.LF_media_category_common_archive_blocked'),
            ]);
        }

        if ($category->status !== 'archived') {
            DB::table('media_categories')
                ->where('customer_id', $customerId)
                ->where('id', $id)
                ->update([
                    'status' => 'archived',
                    'updated_at' => now(),
                ]);
        }

        return back()->with('success', __('lf.LF_media_category_common_archived_message'));
    }

    private function validatedData(Request $request, int $customerId, ?int $categoryId = null): array
    {
        $validator = Validator::make($request->all(), [
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('media_categories', 'id')
                    ->where(fn ($query) => $query->where('customer_id', $customerId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('media_categories', 'slug')
                    ->where(fn ($query) => $query->where('customer_id', $customerId))
                    ->ignore($categoryId),
            ],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:32'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'metadata' => ['nullable', 'json'],
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
                        __('lf.LF_media_category_common_invalid_parent')
                    );
                }
            });
        }

        return $validator->validate();
    }

    private function nextSortOrder(int $customerId, ?int $parentId, bool $lockTenant = false): int
    {
        if ($lockTenant) {
            DB::table('saas_customers')->where('id', $customerId)->lockForUpdate()->first(['id']);
        }

        $maximum = DB::table('media_categories')
            ->where('customer_id', $customerId)
            ->where('parent_id', $parentId)
            ->max('sort_order');

        return $maximum === null ? 1 : (int) $maximum + 1;
    }

    private function parentCategories(array $excludedIds = [])
    {
        return DB::table('media_categories')
            ->where('customer_id', $this->customerId())
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function descendantIds(int $customerId, int $categoryId): array
    {
        $categories = DB::table('media_categories')
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
        $category = DB::table('media_categories')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->first();

        abort_if(! $category, 404);

        return $category;
    }

    private function hasReferences(int $customerId, int $id): bool
    {
        $hasChildCategory = DB::table('media_categories')
            ->where('customer_id', $customerId)
            ->where('parent_id', $id)
            ->exists();

        if ($hasChildCategory) {
            return true;
        }

        return Schema::hasTable('media_files')
            && DB::table('media_files')
                ->where('customer_id', $customerId)
                ->where('category_id', $id)
                ->exists();
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }
}
