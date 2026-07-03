<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseProductController extends Controller
{
    private const STATUSES = ['draft', 'active', 'inactive', 'archived'];

    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $keyword = trim((string) $request->query('keyword', ''));
        $status = $request->query('status');
        $visibility = $request->query('visibility');

        if (! in_array($status, self::STATUSES, true)) {
            $status = null;
        }

        if (! in_array($visibility, ['public', 'private', 'hidden'], true)) {
            $visibility = null;
        }

        $products = DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('title', 'like', '%'.$keyword.'%')
                        ->orWhere('slug', 'like', '%'.$keyword.'%')
                        ->orWhere('product_code', 'like', '%'.$keyword.'%');
                });
            })
            ->when($status, function ($query) use ($status): void {
                $query->where('status', $status);
            })
            ->when($visibility, function ($query) use ($visibility): void {
                $query->where('visibility', $visibility);
            })
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return view('course-products.index', [
            'products' => $products,
            'keyword' => $keyword,
            'status' => $status,
            'visibility' => $visibility,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeAdmin($request);
        $customerId = $this->customerId();

        return view('course-products.create', [
            'requiredFields' => $this->requiredFields($customerId),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $validated = $this->validatedData($request, $customerId);
        $now = now();

        DB::table('core_course_products')->insert(
            $this->productValues($validated, [
                'customer_id' => $customerId,
                'enrollment_count' => 0,
                'is_certificate_enabled' => false,
                'created_by' => $request->user()?->id,
                'published_at' => $validated['status'] === 'active'
                    ? $now
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
        );

        return redirect()
            ->route($this->routePrefix($request).'.index')
            ->with('success', __('lf.LF_course_product_common_created'));
    }

    public function edit(Request $request, int $id): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();

        return view('course-products.edit', [
            'product' => $this->findProduct($customerId, $id),
            'requiredFields' => $this->requiredFields($customerId, $id),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $product = $this->findProduct($customerId, $id);
        $validated = $this->validatedData($request, $customerId, $id);

        DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->update($this->productValues($validated, [
                'published_at' => $product->published_at === null
                    && $validated['status'] === 'active'
                        ? now()
                        : $product->published_at,
                'updated_at' => now(),
            ]));

        return redirect()
            ->route($this->routePrefix($request).'.edit', $id)
            ->with('success', __('lf.LF_course_product_common_updated'));
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $this->findProduct($customerId, $id);

        DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->update([
                'status' => 'archived',
                'updated_at' => now(),
            ]);

        return redirect()
            ->route($this->routePrefix($request).'.index')
            ->with('success', __('lf.LF_course_product_common_archived'));
    }

    private function validatedData(
        Request $request,
        int $customerId,
        ?int $productId = null
    ): array {
        return Validator::make(
            $request->all(),
            $this->validationRules($customerId, $productId)
        )->validate();
    }

    private function validationRules(int $customerId, ?int $productId = null): array
    {
        return [
            'product_code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('core_course_products', 'product_code')
                    ->where(fn ($query) => $query->where('customer_id', $customerId))
                    ->ignore($productId),
            ],
            'product_type' => [
                'required',
                Rule::in(['single_course', 'bundle']),
            ],
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('core_course_products', 'slug')
                    ->where(fn ($query) => $query->where('customer_id', $customerId))
                    ->ignore($productId),
            ],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'thumbnail_type' => ['required', Rule::in(['image', 'video'])],
            'thumbnail_image' => ['nullable', 'string', 'max:500'],
            'thumbnail_video_source' => [
                'nullable',
                Rule::in(['youtube', 'aws']),
            ],
            'thumbnail_video_url' => ['nullable', 'string', 'max:1000'],
            'thumbnail_video_media_id' => ['nullable', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date'],
            'currency' => ['required', 'string', 'max:10'],
            'enrollment_type' => [
                'required',
                Rule::in(['free', 'paid', 'invitation']),
            ],
            'max_students' => ['nullable', 'integer', 'min:0'],
            'access_duration_days' => ['nullable', 'integer', 'min:0'],
            'review_duration_days' => ['nullable', 'integer', 'min:0'],
            'is_refundable' => ['required', 'boolean'],
            'refund_days' => ['nullable', 'integer', 'min:0'],
            'tags' => ['nullable', 'json'],
            'badge_type' => ['nullable', 'string', 'max:50'],
            'show_enrollment_count' => ['required', 'boolean'],
            'display_enrollment_count' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer'],
            'visibility' => [
                'required',
                Rule::in(['public', 'private', 'hidden']),
            ],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date'],
            'registration_starts_at' => ['nullable', 'date'],
            'registration_ends_at' => ['nullable', 'date'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ];
    }

    private function requiredFields(int $customerId, ?int $productId = null): array
    {
        return array_keys(array_filter(
            $this->validationRules($customerId, $productId),
            fn (array $rules): bool => in_array('required', $rules, true)
        ));
    }

    private function productValues(array $validated, array $extra = []): array
    {
        return array_merge([
            'product_code' => $validated['product_code'],
            'product_type' => $validated['product_type'],
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'] ?? null,
            'thumbnail_type' => $validated['thumbnail_type'],
            'thumbnail_image' => $validated['thumbnail_image'] ?? null,
            'thumbnail_video_source' => $validated['thumbnail_video_source'] ?? null,
            'thumbnail_video_url' => $validated['thumbnail_video_url'] ?? null,
            'thumbnail_video_media_id' => $validated['thumbnail_video_media_id'] ?? null,
            'price' => $validated['price'],
            'sale_price' => $validated['sale_price'] ?? null,
            'sale_starts_at' => $validated['sale_starts_at'] ?? null,
            'sale_ends_at' => $validated['sale_ends_at'] ?? null,
            'currency' => $validated['currency'],
            'enrollment_type' => $validated['enrollment_type'],
            'max_students' => $validated['max_students'] ?? null,
            'access_duration_days' => $validated['access_duration_days'] ?? null,
            'review_duration_days' => $validated['review_duration_days'] ?? null,
            'is_refundable' => (bool) $validated['is_refundable'],
            'refund_days' => $validated['refund_days'] ?? null,
            'tags' => $validated['tags'] ?? null,
            'badge_type' => $validated['badge_type'] ?? null,
            'show_enrollment_count' => (bool) $validated['show_enrollment_count'],
            'display_enrollment_count' => $validated['display_enrollment_count'] ?? null,
            'is_featured' => (bool) $validated['is_featured'],
            'sort_order' => $validated['sort_order'],
            'visibility' => $validated['visibility'],
            'available_from' => $validated['available_from'] ?? null,
            'available_until' => $validated['available_until'] ?? null,
            'registration_starts_at' => $validated['registration_starts_at'] ?? null,
            'registration_ends_at' => $validated['registration_ends_at'] ?? null,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'meta_keywords' => $validated['meta_keywords'] ?? null,
            'status' => $validated['status'],
        ], $extra);
    }

    private function findProduct(int $customerId, int $id): object
    {
        $product = DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->first();

        abort_if(! $product, 404);

        return $product;
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'customer_admin', 403);
    }

    private function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.course-products',
            default => abort(403),
        };
    }
}
