<?php

namespace App\Http\Controllers;

use App\Services\CourseProductMediaAuthorizer;
use App\Services\MediaService;
use App\Services\MediaThumbnailPresenter;
use App\Services\TrustedVideoUrlService;
use App\Support\CourseProductV2;
use App\Support\CourseProductVersionSummaryPresenter;
use App\Support\SequentialCodeGenerator;
use App\Support\TenantContext;
use App\Support\UploadLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseProductController extends Controller
{
    private const STATUSES = ['draft', 'active', 'inactive', 'archived'];

    private const ITEM_STATUSES = ['active', 'inactive'];

    private const RELATION_TYPES = [
        'gift',
        'related',
        'upsell',
        'cross_sell',
        'recommended',
    ];

    public function __construct(
        private readonly MediaService $mediaService,
        private readonly TrustedVideoUrlService $trustedVideos,
        private readonly CourseProductMediaAuthorizer $productMediaAuthorizer,
        private readonly MediaThumbnailPresenter $mediaThumbnails,
        private readonly CourseProductVersionSummaryPresenter $versionSummaryPresenter
    ) {}

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
            ->paginate(10)
            ->withQueryString();

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
        $versionState = $this->versionSummaryPresenter->present($customerId, null, true);

        return view('course-products.create', [
            'requiredFields' => $this->requiredFields($customerId),
            'routePrefix' => $this->routePrefix($request),
            'coverImageMedia' => null,
            'categories' => $this->categories($customerId),
            'templates' => $versionState['templates'],
            'relatedProducts' => $this->relatedProducts($customerId, 0),
            'selectedRelatedIds' => [],
            'introMedia' => [],
            'introImageThumbnail' => null,
            'introVideoThumbnail' => null,
            'introDocumentThumbnail' => null,
            'introVideoEmbedUrl' => null,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $validated = $this->validatedData($request, $customerId);
        $now = now();

        $productId = DB::transaction(function () use ($request, $validated, $customerId, $now): int {
            $productId = DB::table('core_course_products')->insertGetId(
                $this->productValues($validated, [
                    'customer_id' => $customerId,
                    'product_code' => SequentialCodeGenerator::next(
                        $customerId,
                        'core_course_products',
                        'product_code',
                        'PRD'
                    ),
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
            if (! empty($validated['template_id'])) {
                $this->syncPhaseOneItem($customerId, $productId, $validated, $request->user()?->id);
            }
            if (array_key_exists('related_product_ids', $validated)) {
                $this->syncRelatedProducts($customerId, $productId, $validated['related_product_ids'] ?? [], $request->user()?->id);
            }
            if (array_key_exists('uses_custom_intro_media', $validated)) {
                $this->attachIntroMedia($request, $productId, $validated);
            }
            $this->attachUploadedMedia($request, $productId);

            return $productId;
        });

        return redirect()
            ->route($this->routePrefix($request).'.index')
            ->with('success', __('lf.LF_course_product_common_created'));
    }

    public function edit(Request $request, int $id): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $product = $this->findProduct($customerId, $id);
        $versionState = $this->versionSummaryPresenter->present($customerId, $id, true);
        $introImageMedia = $this->productMedia($customerId, $product, 'image');
        $introVideoMedia = $this->productMedia($customerId, $product, 'video');
        $introDocumentMedia = $this->productMedia($customerId, $product, 'document');
        $introVideoEmbedUrl = $product->intro_video_source === 'embed' && $product->intro_video_embed_url
            ? $this->trustedVideos->embedUrl($product->intro_video_embed_url)
            : null;

        return view('course-products.edit', [
            'product' => $product,
            'productItems' => $this->productItems($customerId, $id),
            'publishedVersions' => $this->publishedVersions($customerId, $id),
            'productRelations' => $this->productRelations($customerId, $id),
            'relatedProducts' => $this->relatedProducts($customerId, $id),
            'requiredFields' => $this->requiredFields($customerId, $id),
            'coverImageMedia' => $this->singleMedia(
                'course_product',
                $id,
                'cover_image'
            ),
            'categories' => $this->categories($customerId),
            'templates' => $versionState['templates'],
            'selectedTemplateId' => $versionState['selected_template_id'],
            'selectedRelatedIds' => DB::table('core_course_product_relations')->where('customer_id', $customerId)->where('product_id', $id)->where('relation_type', 'related')->pluck('related_product_id')->all(),
            'introMedia' => [
                'intro_image' => $introImageMedia,
                'intro_video' => $introVideoMedia,
                'intro_document' => $introDocumentMedia,
            ],
            'introImageThumbnail' => $this->mediaThumbnails->image($introImageMedia),
            'introVideoThumbnail' => $product->intro_video_source === 'embed'
                ? $this->mediaThumbnails->embeddedVideo($product->intro_video_embed_url)
                : $this->mediaThumbnails->uploadedVideo($introVideoMedia),
            'introDocumentThumbnail' => $this->mediaThumbnails->document($introDocumentMedia),
            'introVideoEmbedUrl' => $introVideoEmbedUrl,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $product = $this->findProduct($customerId, $id);
        $validated = $this->validatedData($request, $customerId, $id, $product);

        $values = $this->withoutMissingSeoValues(
            $request,
            $this->productValues($validated, [
                'published_at' => $product->published_at === null
                    && $validated['status'] === 'active'
                        ? now()
                        : $product->published_at,
                'updated_at' => now(),
            ])
        );

        DB::transaction(function () use ($request, $validated, $values, $customerId, $id): void {
            DB::table('core_course_products')->where('customer_id', $customerId)->where('id', $id)->update($values);
            if (! empty($validated['template_id'])) {
                $this->syncPhaseOneItem($customerId, $id, $validated, $request->user()?->id);
            }
            if (array_key_exists('related_product_ids', $validated)) {
                $this->syncRelatedProducts($customerId, $id, $validated['related_product_ids'] ?? [], $request->user()?->id);
            }
            if (array_key_exists('uses_custom_intro_media', $validated)) {
                $this->attachIntroMedia($request, $id, $validated);
            }
            $this->attachUploadedMedia($request, $id);
        });

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

    public function storeItem(Request $request, int $productId)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $this->findProduct($customerId, $productId);
        $validated = $this->validatedItemData(
            $request,
            $customerId,
            $productId
        );
        $now = now();

        DB::table('core_course_product_items')->insert([
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $validated['version_id'],
            'template_id' => DB::table('core_course_template_versions')->where('customer_id', $customerId)->where('id', $validated['version_id'])->value('template_id'),
            'title_override' => $validated['title_override'] ?? null,
            'short_description_override' => $validated['short_description_override'] ?? null,
            'sort_order' => $validated['sort_order'],
            'is_required' => (bool) $validated['is_required'],
            'status' => $validated['status'],
            'created_by' => $request->user()?->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()
            ->route($this->routePrefix($request).'.edit', $productId)
            ->with('success', __('lf.LF_course_product_item_common_attached'));
    }

    public function destroyItem(Request $request, int $productId, int $itemId)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $this->findProduct($customerId, $productId);
        $item = DB::table('core_course_product_items')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('id', $itemId)
            ->first();

        abort_if(! $item, 404);

        DB::table('core_course_product_items')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('id', $itemId)
            ->delete();

        return redirect()
            ->route($this->routePrefix($request).'.edit', $productId)
            ->with('success', __('lf.LF_course_product_item_common_removed'));
    }

    public function storeRelation(Request $request, int $productId)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $this->findProduct($customerId, $productId);
        $validated = $this->validatedRelationData(
            $request,
            $customerId,
            $productId
        );
        $now = now();

        DB::table('core_course_product_relations')->insert([
            'customer_id' => $customerId,
            'product_id' => $productId,
            'related_product_id' => $validated['related_product_id'],
            'relation_type' => $validated['relation_type'],
            'title_override' => $validated['title_override'] ?? null,
            'description_override' => $validated['description_override'] ?? null,
            'sort_order' => $validated['sort_order'],
            'is_featured' => (bool) $validated['is_featured'],
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'status' => $validated['status'],
            'created_by' => $request->user()?->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()
            ->route($this->routePrefix($request).'.edit', $productId)
            ->with('success', __('lf.LF_course_product_relation_common_attached'));
    }

    public function destroyRelation(
        Request $request,
        int $productId,
        int $relationId
    ) {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $this->findProduct($customerId, $productId);
        $relation = DB::table('core_course_product_relations')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('id', $relationId)
            ->first();

        abort_if(! $relation, 404);

        DB::table('core_course_product_relations')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('id', $relationId)
            ->delete();

        return redirect()
            ->route($this->routePrefix($request).'.edit', $productId)
            ->with('success', __('lf.LF_course_product_relation_common_removed'));
    }

    private function validatedData(
        Request $request,
        int $customerId,
        ?int $productId = null,
        ?object $product = null
    ): array {
        $input = $this->validationInput($request);
        $input['slug'] = $this->systemSlug(
            (string) $request->input('title', ''),
            $product,
            'title'
        );

        $validator = Validator::make($input, $this->validationRules($customerId, $productId));
        $validator->after(function ($validator) use ($input, $customerId, $productId, $request, $product): void {
            if (! array_key_exists('offering_type', $input)) {
                return;
            }
            $categoryId = (int) ($input['category_id'] ?? 0);
            $templateId = (int) ($input['template_id'] ?? 0);
            $template = DB::table('core_course_templates')->where('customer_id', $customerId)
                ->where('id', $templateId)->where('category_id', $categoryId)->first();
            if (! $template) {
                $validator->errors()->add('template_id', __('lf.LF_product_v2_invalid_template'));
            }

            $relatedIds = array_map('intval', $input['related_product_ids'] ?? []);
            if (count($relatedIds) !== count(array_unique($relatedIds))) {
                $validator->errors()->add('related_product_ids', __('lf.LF_product_v2_invalid_related'));
            }
            if ($productId && in_array($productId, $relatedIds, true)) {
                $validator->errors()->add('related_product_ids', __('lf.LF_course_product_relation_validation_self'));
            }
            $validRelated = DB::table('core_course_products')->where('customer_id', $customerId)
                ->whereIn('id', $relatedIds)->where('status', '!=', 'archived')->count();
            if ($validRelated !== count($relatedIds)) {
                $validator->errors()->add('related_product_ids', __('lf.LF_product_v2_invalid_related'));
            }

            if (($input['promotion_enabled'] ?? false) && ($input['discount_type'] ?? null) === 'fixed_amount'
                && (float) ($input['discount_value'] ?? 0) > (float) ($input['price'] ?? 0)) {
                $validator->errors()->add('discount_value', __('lf.LF_product_v2_discount_too_large'));
            }
            if (($input['status'] ?? null) === 'active') {
                $version = DB::table('core_course_template_versions')->where('customer_id', $customerId)
                    ->where('template_id', $templateId)->where('status', 'published')->where('is_current', true)->first();
                if (! $version) {
                    $validator->errors()->add('status', __('lf.LF_product_v2_activation_version_required'));
                }
            }
            if (($input['uses_custom_intro_media'] ?? false)) {
                $source = $input['intro_video_source'] ?? null;
                if ($source === 'upload' && ! $request->hasFile('intro_video_file')
                    && ! $product?->intro_video_media_file_id && ! ($input['remove_intro_video'] ?? false)) {
                    $validator->errors()->add('intro_video_file', __('validation.required', [
                        'attribute' => __('lf.LF_course_template_intro_video'),
                    ]));
                }
                if ($source === 'embed') {
                    try {
                        $this->trustedVideos->normalize((string) ($input['intro_video_embed_url'] ?? ''));
                    } catch (\InvalidArgumentException) {
                        $validator->errors()->add('intro_video_embed_url', __('lf.LF_course_template_invalid_embed_url'));
                    }
                }
            }
        });

        return $validator->validate();
    }

    private function validationRules(int $customerId, ?int $productId = null): array
    {
        $legacy = request()->has('product_type') && ! request()->has('offering_type');

        return [
            'category_id' => [$legacy ? 'nullable' : 'required', 'integer', Rule::exists('core_course_categories', 'id')->where(fn ($q) => $q->where('customer_id', $customerId))],
            'template_id' => [$legacy ? 'nullable' : 'required', 'integer'],
            'offering_type' => [$legacy ? 'nullable' : 'required', Rule::in(CourseProductV2::OFFERING_TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('core_course_products', 'slug')
                    ->where(fn ($query) => $query->where('customer_id', $customerId))
                    ->ignore($productId),
            ],
            'uses_custom_description' => [$legacy ? 'nullable' : 'required', 'boolean'],
            'uses_custom_intro_media' => [$legacy ? 'nullable' : 'required', 'boolean'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'thumbnail_type' => [$legacy ? 'required' : 'nullable', Rule::in(['image', 'video'])],
            'thumbnail_image' => ['nullable', 'string', 'max:500'],
            'thumbnail_video_source' => [
                'nullable',
                Rule::in(['youtube', 'aws']),
            ],
            'thumbnail_video_url' => ['nullable', 'string', 'max:1000'],
            'thumbnail_video_media_id' => ['nullable', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'promotion_enabled' => [$legacy ? 'nullable' : 'required', 'boolean'],
            'discount_type' => ['nullable', Rule::requiredIf(fn () => request()->boolean('promotion_enabled')), Rule::in(CourseProductV2::DISCOUNT_TYPES)],
            'discount_value' => ['nullable', Rule::requiredIf(fn () => request()->boolean('promotion_enabled')), 'numeric', 'gt:0', Rule::when(request('discount_type') === 'percentage', ['max:100'])],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date'],
            'currency' => ['required', 'string', 'max:10'],
            'enrollment_type' => [
                $legacy ? 'required' : 'nullable',
                Rule::in(['free', 'paid', 'invitation']),
            ],
            'max_students' => ['nullable', 'integer', 'min:0'],
            'access_duration_days' => ['nullable', Rule::requiredIf(fn () => request('offering_type') === 'self_paced_course'), 'integer', 'min:1'],
            'review_duration_days' => ['nullable', 'integer', 'min:0'],
            'is_refundable' => [$legacy ? 'required' : 'nullable', 'boolean'],
            'refund_days' => ['nullable', 'integer', 'min:0'],
            'tags' => ['nullable', 'json'],
            'badge_type' => ['nullable', 'string', 'max:50'],
            'show_enrollment_count' => [$legacy ? 'required' : 'nullable', 'boolean'],
            'display_enrollment_count' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['required', 'boolean'],
            'sort_order' => [$legacy ? 'required' : 'nullable', 'integer', 'min:0'],
            'visibility' => [
                $legacy ? 'required' : 'nullable',
                Rule::in(['public', 'private', 'hidden']),
            ],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date'],
            'registration_starts_at' => ['nullable', 'date'],
            'registration_ends_at' => ['nullable', 'date', 'after:registration_starts_at'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'cover_image_file' => [
                'nullable',
                'file',
                'max:'.UploadLimit::effectiveKilobytes(),
            ],
            'related_product_ids' => ['nullable', 'array'],
            'related_product_ids.*' => ['integer'],
            'intro_image_file' => ['nullable', 'file', 'max:'.UploadLimit::effectiveKilobytes()],
            'intro_video_file' => ['nullable', 'file', 'max:'.UploadLimit::effectiveKilobytes()],
            'intro_document_file' => ['nullable', 'file', 'max:'.UploadLimit::effectiveKilobytes()],
            'intro_video_source' => ['nullable', Rule::in(['upload', 'embed'])],
            'intro_video_embed_url' => ['nullable', 'string', 'max:2048'],
            'remove_intro_image' => ['nullable', 'boolean'],
            'remove_intro_video' => ['nullable', 'boolean'],
            'remove_intro_document' => ['nullable', 'boolean'],
        ];
    }

    private function validationInput(Request $request): array
    {
        $fields = [
            'category_id', 'template_id', 'offering_type',
            'uses_custom_description', 'uses_custom_intro_media',
            'title',
            'slug',
            'short_description',
            'description',
            'thumbnail_type',
            'thumbnail_image',
            'thumbnail_video_source',
            'thumbnail_video_url',
            'thumbnail_video_media_id',
            'price',
            'promotion_enabled', 'discount_type', 'discount_value',
            'sale_price',
            'sale_starts_at',
            'sale_ends_at',
            'currency',
            'enrollment_type',
            'max_students',
            'access_duration_days',
            'review_duration_days',
            'is_refundable',
            'refund_days',
            'tags',
            'badge_type',
            'show_enrollment_count',
            'display_enrollment_count',
            'is_featured',
            'sort_order',
            'visibility',
            'available_from',
            'available_until',
            'registration_starts_at',
            'registration_ends_at',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'status',
            'related_product_ids', 'intro_video_source', 'intro_video_embed_url',
            'remove_intro_image', 'remove_intro_video', 'remove_intro_document',
        ];

        $input = array_intersect_key(
            $request->request->all(),
            array_flip($fields)
        );

        if ($request->hasFile('cover_image_file')) {
            $input['cover_image_file'] = $request->file('cover_image_file');
        }

        foreach (['intro_image_file', 'intro_video_file', 'intro_document_file'] as $field) {
            if ($request->hasFile($field)) {
                $input[$field] = $request->file($field);
            }
        }

        if (! ($request->has('product_type') && ! $request->has('offering_type'))) {
            $input['uses_custom_description'] = $request->boolean('uses_custom_description');
            $input['uses_custom_intro_media'] = $request->boolean('uses_custom_intro_media');
            $input['promotion_enabled'] = $request->boolean('promotion_enabled');
        }

        if (array_key_exists('offering_type', $input) && $input['offering_type'] !== 'self_paced_course') {
            $input['access_duration_days'] = null;
            $input['review_duration_days'] = null;
        }
        if (array_key_exists('promotion_enabled', $input) && ! $input['promotion_enabled']) {
            $input['discount_type'] = $input['discount_value'] = null;
            $input['sale_starts_at'] = $input['sale_ends_at'] = null;
        }

        return $input;
    }

    private function validatedItemData(
        Request $request,
        int $customerId,
        int $productId
    ): array {
        $validator = Validator::make($request->all(), [
            'version_id' => ['required', 'integer', 'min:1'],
            'title_override' => ['nullable', 'string', 'max:255'],
            'short_description_override' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['required', 'integer'],
            'is_required' => ['required', 'boolean'],
            'status' => ['required', Rule::in(self::ITEM_STATUSES)],
        ]);

        $validator->after(function ($validator) use (
            $request,
            $customerId,
            $productId
        ): void {
            $versionId = (int) $request->input('version_id');

            if ($versionId < 1) {
                return;
            }

            $version = DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->where('id', $versionId)
                ->where('status', 'published')
                ->first();

            if (! $version) {
                $validator->errors()->add(
                    'version_id',
                    __('lf.LF_course_product_item_validation_published_version')
                );

                return;
            }

            $duplicateExists = DB::table('core_course_product_items')
                ->where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->where('version_id', $versionId)
                ->exists();

            if ($duplicateExists) {
                $validator->errors()->add(
                    'version_id',
                    __('lf.LF_course_product_item_validation_duplicate')
                );

                return;
            }

            if ($request->input('status') !== 'active') {
                return;
            }

            $activeTemplateExists = DB::table('core_course_product_items as items')
                ->join('core_course_template_versions as versions', function ($join) use (
                    $customerId
                ): void {
                    $join->on('versions.id', '=', 'items.version_id')
                        ->where('versions.customer_id', '=', $customerId);
                })
                ->where('items.customer_id', $customerId)
                ->where('items.product_id', $productId)
                ->where('items.status', 'active')
                ->where('versions.template_id', $version->template_id)
                ->exists();

            if ($activeTemplateExists) {
                $validator->errors()->add(
                    'version_id',
                    __('lf.LF_course_product_item_validation_active_template_version')
                );
            }
        });

        return $validator->validate();
    }

    private function validatedRelationData(
        Request $request,
        int $customerId,
        int $productId
    ): array {
        $validator = Validator::make($request->all(), [
            'related_product_id' => ['required', 'integer', 'min:1'],
            'relation_type' => ['required', Rule::in(self::RELATION_TYPES)],
            'title_override' => ['nullable', 'string', 'max:255'],
            'description_override' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['required', 'integer'],
            'is_featured' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(self::ITEM_STATUSES)],
        ]);

        $validator->after(function ($validator) use (
            $request,
            $customerId,
            $productId
        ): void {
            $relatedProductId = (int) $request->input('related_product_id');
            $relationType = (string) $request->input('relation_type');

            if ($relatedProductId < 1) {
                return;
            }

            if ($relatedProductId === $productId) {
                $validator->errors()->add(
                    'related_product_id',
                    __('lf.LF_course_product_relation_validation_self')
                );

                return;
            }

            $relatedProduct = DB::table('core_course_products')
                ->where('customer_id', $customerId)
                ->where('id', $relatedProductId)
                ->first();

            if (! $relatedProduct) {
                $validator->errors()->add(
                    'related_product_id',
                    __('lf.LF_course_product_relation_validation_related_product')
                );

                return;
            }

            if (! in_array($relationType, self::RELATION_TYPES, true)) {
                return;
            }

            $duplicateExists = DB::table('core_course_product_relations')
                ->where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->where('related_product_id', $relatedProductId)
                ->where('relation_type', $relationType)
                ->exists();

            if ($duplicateExists) {
                $validator->errors()->add(
                    'related_product_id',
                    __('lf.LF_course_product_relation_validation_duplicate')
                );
            }
        });

        return $validator->validate();
    }

    private function requiredFields(int $customerId, ?int $productId = null): array
    {
        return array_values(array_diff(array_keys(array_filter(
            $this->validationRules($customerId, $productId),
            fn (array $rules): bool => in_array('required', $rules, true)
        )), ['slug']));
    }

    private function productValues(array $validated, array $extra = []): array
    {
        return array_merge([
            'product_type' => CourseProductV2::PACKAGE_SINGLE,
            'category_id' => $validated['category_id'] ?? null,
            'offering_type' => $validated['offering_type'] ?? null,
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'] ?? null,
            'uses_custom_description' => (bool) ($validated['uses_custom_description'] ?? false),
            'uses_custom_intro_media' => (bool) ($validated['uses_custom_intro_media'] ?? false),
            'thumbnail_type' => $validated['thumbnail_type'] ?? 'image',
            'thumbnail_image' => $validated['thumbnail_image'] ?? null,
            'thumbnail_video_source' => $validated['thumbnail_video_source'] ?? null,
            'thumbnail_video_url' => $validated['thumbnail_video_url'] ?? null,
            'thumbnail_video_media_id' => $validated['thumbnail_video_media_id'] ?? null,
            'price' => $validated['price'],
            'promotion_enabled' => (bool) ($validated['promotion_enabled'] ?? false),
            'discount_type' => $validated['discount_type'] ?? null,
            'discount_value' => $validated['discount_value'] ?? null,
            'sale_price' => $validated['sale_price'] ?? null,
            'sale_starts_at' => $validated['sale_starts_at'] ?? null,
            'sale_ends_at' => $validated['sale_ends_at'] ?? null,
            'currency' => $validated['currency'],
            'enrollment_type' => $validated['enrollment_type'] ?? ((float) $validated['price'] > 0 ? 'paid' : 'free'),
            'max_students' => $validated['max_students'] ?? null,
            'access_duration_days' => $validated['access_duration_days'] ?? null,
            'review_duration_days' => $validated['review_duration_days'] ?? null,
            'is_refundable' => (bool) ($validated['is_refundable'] ?? false),
            'refund_days' => $validated['refund_days'] ?? null,
            'tags' => $validated['tags'] ?? null,
            'badge_type' => $validated['badge_type'] ?? null,
            'show_enrollment_count' => (bool) ($validated['show_enrollment_count'] ?? true),
            'display_enrollment_count' => $validated['display_enrollment_count'] ?? null,
            'is_featured' => (bool) $validated['is_featured'],
            'sort_order' => $validated['sort_order'] ?? $this->nextSortOrder((int) $validated['category_id']),
            'visibility' => $validated['visibility'] ?? 'public',
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

    private function systemSlug(
        string $source,
        ?object $existingRecord = null,
        string $sourceField = 'title'
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

    private function withoutMissingSeoValues(Request $request, array $values): array
    {
        foreach (['meta_title', 'meta_description', 'meta_keywords'] as $field) {
            if (! $request->has($field)) {
                unset($values[$field]);
            }
        }

        return $values;
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

    private function productItems(int $customerId, int $productId)
    {
        return DB::table('core_course_product_items as items')
            ->leftJoin('core_course_template_versions as versions', function ($join) use (
                $customerId
            ): void {
                $join->on(
                    'versions.id',
                    '=',
                    'items.version_id'
                )
                    ->where('versions.customer_id', '=', $customerId);
            })
            ->where('items.customer_id', $customerId)
            ->where('items.product_id', $productId)
            ->orderBy('items.sort_order')
            ->orderBy('items.id')
            ->select(
                'items.*',
                'versions.version_number',
                'versions.version_code',
                'versions.title_snapshot',
                'versions.status as version_status',
                'versions.is_current'
            )
            ->get();
    }

    private function publishedVersions(int $customerId, int $productId)
    {
        return DB::table('core_course_template_versions as versions')
            ->leftJoin('core_course_product_items as items', function ($join) use (
                $customerId,
                $productId
            ): void {
                $join->on('items.version_id', '=', 'versions.id')
                    ->where('items.customer_id', '=', $customerId)
                    ->where('items.product_id', '=', $productId);
            })
            ->where('versions.customer_id', $customerId)
            ->where('versions.status', 'published')
            ->whereNull('items.id')
            ->orderBy('versions.title_snapshot')
            ->orderBy('versions.version_number')
            ->select(
                'versions.id',
                'versions.title_snapshot',
                'versions.version_number',
                'versions.version_code',
                'versions.is_current'
            )
            ->get();
    }

    private function productRelations(int $customerId, int $productId)
    {
        return DB::table('core_course_product_relations as relations')
            ->join('core_course_products as related_products', function ($join) use (
                $customerId
            ): void {
                $join->on(
                    'related_products.id',
                    '=',
                    'relations.related_product_id'
                )
                    ->where('related_products.customer_id', '=', $customerId);
            })
            ->where('relations.customer_id', $customerId)
            ->where('relations.product_id', $productId)
            ->orderBy('relations.sort_order')
            ->orderBy('relations.id')
            ->select(
                'relations.*',
                'related_products.title as related_product_title',
                'related_products.product_code as related_product_code',
                'related_products.status as related_product_status'
            )
            ->get();
    }

    private function relatedProducts(int $customerId, int $productId)
    {
        return DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('id', '!=', $productId)
            ->orderBy('title')
            ->select('id', 'title', 'product_code', 'status')
            ->get();
    }

    private function categories(int $customerId)
    {
        return DB::table('core_course_categories')->where('customer_id', $customerId)
            ->where('status', '!=', 'archived')->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
    }

    private function syncPhaseOneItem(int $customerId, int $productId, array $validated, ?int $actorId): void
    {
        $existingVersionId = DB::table('core_course_product_items')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('template_id', $validated['template_id'])
            ->value('version_id');
        $boundVersionId = $existingVersionId ? DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $validated['template_id'])
            ->where('id', $existingVersionId)
            ->whereIn('status', ['published', 'deprecated', 'archived'])
            ->value('id') : null;
        $versionId = $boundVersionId ?: DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $validated['template_id'])
            ->where('status', 'published')
            ->where('is_current', true)
            ->value('id');
        if ($validated['status'] !== 'active' && ! $versionId) {
            $versionId = null;
        }

        DB::table('core_course_product_items')->updateOrInsert(
            ['customer_id' => $customerId, 'product_id' => $productId, 'template_id' => $validated['template_id']],
            ['version_id' => $versionId, 'title_override' => null, 'short_description_override' => null,
                'sort_order' => 0, 'is_required' => true, 'status' => 'active', 'created_by' => $actorId,
                'updated_at' => now(), 'created_at' => now()]
        );
        DB::table('core_course_product_items')->where('customer_id', $customerId)->where('product_id', $productId)
            ->where('template_id', '!=', $validated['template_id'])->delete();
    }

    private function syncRelatedProducts(int $customerId, int $productId, array $ids, ?int $actorId): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        DB::table('core_course_product_relations')->where('customer_id', $customerId)->where('product_id', $productId)
            ->where('relation_type', 'related')->when($ids, fn ($q) => $q->whereNotIn('related_product_id', $ids))->delete();
        foreach ($ids as $relatedId) {
            DB::table('core_course_product_relations')->updateOrInsert(
                ['customer_id' => $customerId, 'product_id' => $productId, 'related_product_id' => $relatedId, 'relation_type' => 'related'],
                ['title_override' => null, 'description_override' => null, 'sort_order' => 0, 'is_featured' => false,
                    'starts_at' => null, 'ends_at' => null, 'status' => 'active', 'created_by' => $actorId,
                    'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    private function nextSortOrder(int $categoryId): int
    {
        return ((int) DB::table('core_course_products')->where('customer_id', $this->customerId())
            ->where('category_id', $categoryId)->lockForUpdate()->max('sort_order')) + 1;
    }

    private function attachIntroMedia(Request $request, int $productId, array $validated): void
    {
        if (! $validated['uses_custom_intro_media']) {
            return;
        }

        $definitions = [
            'intro_image' => ['field' => 'intro_image_file', 'type' => 'image', 'column' => 'intro_image_media_file_id'],
            'intro_document' => ['field' => 'intro_document_file', 'type' => 'document', 'column' => 'intro_document_media_file_id'],
        ];
        foreach ($definitions as $purpose => $definition) {
            $hasReplacement = $request->hasFile($definition['field']);
            $remove = $request->boolean('remove_'.$purpose);
            if (! $hasReplacement && ! $remove) {
                continue;
            }
            foreach ($this->mediaService->getOwnerMedia(CourseProductV2::MEDIA_OWNER, $productId, $purpose) as $media) {
                $this->mediaService->detachUsage((int) $media->id, CourseProductV2::MEDIA_OWNER, $productId, $purpose);
            }
            $mediaId = null;
            if ($hasReplacement) {
                $media = $this->mediaService->upload($request->file($definition['field']), [
                    'file_type' => $definition['type'], 'module' => 'course', 'entity_type' => 'products',
                    'entity_id' => $productId, 'purpose' => $purpose, 'display_name' => $validated['title'],
                ], (int) $request->user()->id);
                $mediaId = (int) $media->id;
                $this->mediaService->attachUsage($mediaId, CourseProductV2::MEDIA_OWNER, $productId, $purpose);
            }
            DB::table('core_course_products')->where('customer_id', $this->customerId())
                ->where('id', $productId)->update([$definition['column'] => $mediaId]);
        }

        $product = DB::table('core_course_products')->where('customer_id', $this->customerId())
            ->where('id', $productId)->first();
        $source = $validated['intro_video_source'] ?? null;
        $hasVideoReplacement = $request->hasFile('intro_video_file');
        $removeVideo = $request->boolean('remove_intro_video');
        $videoValues = [];
        if ($source === 'embed') {
            $normalized = $this->trustedVideos->normalize((string) ($validated['intro_video_embed_url'] ?? ''));
            $isReplacement = $product->intro_video_source !== 'embed'
                || $product->intro_video_embed_url !== $normalized['url'];
            if ($isReplacement) {
                $this->detachProductMedia($productId, 'intro_video');
                $videoValues = ['intro_video_source' => 'embed', 'intro_video_embed_url' => $normalized['url'],
                    'intro_video_provider' => $normalized['provider'], 'intro_video_media_file_id' => null];
            } elseif ($removeVideo) {
                $this->detachProductMedia($productId, 'intro_video');
                $videoValues = ['intro_video_source' => null, 'intro_video_embed_url' => null,
                    'intro_video_provider' => null, 'intro_video_media_file_id' => null];
            }
        } elseif ($source === 'upload') {
            $mediaId = $product->intro_video_media_file_id;
            if ($hasVideoReplacement) {
                $this->detachProductMedia($productId, 'intro_video');
                $media = $this->mediaService->upload($request->file('intro_video_file'), [
                    'file_type' => 'video', 'module' => 'course', 'entity_type' => 'products',
                    'entity_id' => $productId, 'purpose' => 'intro_video', 'display_name' => $validated['title'],
                ], (int) $request->user()->id);
                $mediaId = (int) $media->id;
                $this->mediaService->attachUsage($mediaId, CourseProductV2::MEDIA_OWNER, $productId, 'intro_video');
            } elseif ($removeVideo) {
                $this->detachProductMedia($productId, 'intro_video');
                $mediaId = null;
            }
            $videoValues = ['intro_video_source' => 'upload', 'intro_video_embed_url' => null,
                'intro_video_provider' => null, 'intro_video_media_file_id' => $mediaId];
        } else {
            $this->detachProductMedia($productId, 'intro_video');
            $videoValues = ['intro_video_source' => null, 'intro_video_embed_url' => null,
                'intro_video_provider' => null, 'intro_video_media_file_id' => null];
        }
        if ($videoValues) {
            DB::table('core_course_products')->where('customer_id', $this->customerId())
                ->where('id', $productId)->update($videoValues);
        }
    }

    private function detachProductMedia(int $productId, string $purpose): void
    {
        foreach ($this->mediaService->getOwnerMedia(CourseProductV2::MEDIA_OWNER, $productId, $purpose) as $media) {
            $this->mediaService->detachUsage((int) $media->id, CourseProductV2::MEDIA_OWNER, $productId, $purpose);
        }
    }

    private function attachUploadedMedia(Request $request, int $productId): void
    {
        if (! $request->hasFile('cover_image_file')) {
            return;
        }

        foreach (
            $this->mediaService->getOwnerMedia(
                'course_product',
                $productId,
                'cover_image'
            ) as $media
        ) {
            $this->mediaService->detachUsage(
                (int) $media->id,
                'course_product',
                $productId,
                'cover_image'
            );
        }

        $mediaFile = $this->mediaService->upload(
            $request->file('cover_image_file'),
            [
                'file_type' => 'image',
                'module' => 'course',
                'entity_type' => 'products',
                'entity_id' => $productId,
                'purpose' => 'cover',
                'display_name' => $request->input('title'),
            ],
            (int) $request->user()->id
        );

        $this->mediaService->attachUsage(
            (int) $mediaFile->id,
            'course_product',
            $productId,
            'cover_image'
        );
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

    private function productMedia(int $customerId, object $product, string $slot): ?object
    {
        $field = match ($slot) {
            'image' => 'intro_image_media_file_id',
            'video' => 'intro_video_media_file_id',
            'document' => 'intro_document_media_file_id',
        };
        $mediaFileId = $product->{$field};
        if (! $mediaFileId) {
            return null;
        }

        $media = $this->productMediaAuthorizer->resolve(
            $customerId,
            (int) $product->id,
            (int) $mediaFileId,
            $slot
        );
        if ($media) {
            $media->signed_url = route('admin.course-products.media.preview', [
                'productId' => $product->id,
                'slot' => $slot,
                'mediaFileId' => $media->id,
            ]);
        }

        return $media;
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
