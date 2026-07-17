<?php

namespace App\Http\Controllers;

use App\Services\CourseProductMediaAuthorizer;
use App\Services\CourseProductLifecyclePolicy;
use App\Services\CourseProductTemplateChangePolicy;
use App\Services\MediaService;
use App\Services\MediaThumbnailPresenter;
use App\Services\TrustedVideoUrlService;
use App\Support\CourseProductV2;
use App\Support\AuditLog;
use App\Support\CourseProductVersionSummaryPresenter;
use App\Support\SequentialCodeGenerator;
use App\Support\TenantContext;
use App\Support\UploadLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CourseProductController extends Controller
{
    private const STATUSES = ['draft', 'active', 'inactive', 'archived'];

    private const ITEM_STATUSES = ['active', 'inactive'];

    public function __construct(
        private readonly MediaService $mediaService,
        private readonly TrustedVideoUrlService $trustedVideos,
        private readonly CourseProductMediaAuthorizer $productMediaAuthorizer,
        private readonly MediaThumbnailPresenter $mediaThumbnails,
        private readonly CourseProductVersionSummaryPresenter $versionSummaryPresenter,
        private readonly CourseProductTemplateChangePolicy $templateChangePolicy,
        private readonly CourseProductLifecyclePolicy $lifecyclePolicy
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $keyword = trim((string) $request->query('keyword', ''));
        $status = $request->query('status');
        $visibility = $request->query('visibility');

        if (! in_array($status, [...self::STATUSES, 'all'], true)) {
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
                if ($status !== 'all') {
                    $query->where('status', $status);
                }
            })
            ->when($status === null, function ($query): void {
                $query->where('status', '!=', 'archived');
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
            'introMedia' => [],
            'introImageThumbnail' => null,
            'introVideoThumbnail' => null,
            'introDocumentThumbnail' => null,
            'introVideoEmbedUrl' => null,
            'hasActiveCourseVersion' => false,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $validated = $this->validatedData($request, $customerId);
        $now = now();

        $productId = DB::transaction(function () use ($request, $validated, $customerId, $now): int {
            $this->assertEligibleTemplateVersionBinding($customerId, $validated, true);
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
            if (array_key_exists('uses_custom_intro_media', $validated)) {
                $this->attachIntroMedia($request, $productId, $validated);
            }
            $this->attachUploadedMedia($request, $productId);

            return $productId;
        });

        return redirect()
            ->route($this->routePrefix($request).'.edit', $productId)
            ->with('success', __('lf.LF_course_product_common_created_next_steps'));
    }

    public function edit(Request $request, int $id): View
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $product = $this->findProduct($customerId, $id);
        $versionState = $this->versionSummaryPresenter->present($customerId, $id, true);
        $templateChange = $this->templateChangePolicy->decision($product, $customerId);
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
            'hasActiveCourseVersion' => $this->productHasActivePublishedVersion($customerId, $id),
            'templateChange' => $templateChange,
            'canChangeTemplate' => $templateChange['allowed'],
            'templateLockReason' => $templateChange['reason'],
            'allowedStatuses' => $this->lifecyclePolicy->allowedStatuses($product, $customerId),
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
            $lockedProduct = DB::table('core_course_products')->where('customer_id', $customerId)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if(! $lockedProduct, 404);
            if ($lockedProduct->status === 'archived') {
                throw ValidationException::withMessages(['status' => __('lf.LF_product_status_archived_readonly')]);
            }
            $targetStatus = $validated['status'];
            if (! $this->lifecyclePolicy->allows($lockedProduct, $customerId, $targetStatus)) {
                throw ValidationException::withMessages(['status' => $targetStatus === 'draft'
                    ? __('lf.LF_product_status_used_draft_blocked')
                    : __('lf.LF_product_status_transition_invalid')]);
            }
            if ($targetStatus === 'active' && $lockedProduct->status !== 'active'
                && ! $this->lifecyclePolicy->activationValid($lockedProduct, $customerId)) {
                throw ValidationException::withMessages([
                    'status' => __('lf.LF_product_v2_activation_version_required'),
                ]);
            }
            $beforeItem = DB::table('core_course_product_items')->where('customer_id', $customerId)
                ->where('product_id', $id)->orderBy('id')->lockForUpdate()
                ->first(['template_id', 'version_id']);
            if (! $this->bindingRequestIsUnchanged($lockedProduct, $beforeItem, $validated)) {
                $this->assertEligibleTemplateVersionBinding($customerId, $validated, true);
            }
            DB::table('core_course_products')->where('customer_id', $customerId)->where('id', $id)->update($values);
            if (! empty($validated['template_id'])) {
                $this->syncPhaseOneItem($customerId, $id, $validated, $request->user()?->id);
            }
            if (array_key_exists('uses_custom_intro_media', $validated)) {
                $this->attachIntroMedia($request, $id, $validated);
            }
            $this->attachUploadedMedia($request, $id);
            if ($lockedProduct->status !== $targetStatus) {
                $afterItem = DB::table('core_course_product_items')->where('customer_id', $customerId)
                    ->where('product_id', $id)->orderBy('id')->first(['template_id', 'version_id']);
                AuditLog::record($request, $customerId,
                    'course_product_'.$this->lifecyclePolicy->action($lockedProduct->status, $targetStatus), null,
                    ['product_id' => $id, 'status' => $lockedProduct->status,
                        'template_id' => $beforeItem?->template_id, 'version_id' => $beforeItem?->version_id],
                    ['product_id' => $id, 'status' => $targetStatus,
                        'template_id' => $afterItem?->template_id, 'version_id' => $afterItem?->version_id]);
            }
        });

        return redirect()
            ->route($this->routePrefix($request).'.edit', $id)
            ->with('success', __('lf.LF_course_product_common_updated'));
    }

    public function destroy(Request $request, int $id)
    {
        return $this->archive($request, $id);
    }

    public function archive(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        DB::transaction(function () use ($request, $customerId, $id): void {
            $product = DB::table('core_course_products')->where('customer_id', $customerId)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if(! $product, 404);
            if ($product->status !== 'inactive') {
                throw ValidationException::withMessages(['status' => __('lf.LF_product_status_archive_requires_inactive')]);
            }
            DB::table('core_course_products')->where('customer_id', $customerId)->where('id', $id)
                ->update(['status' => 'archived', 'updated_at' => now()]);
            AuditLog::record($request, $customerId, 'course_product_archive', null,
                ['product_id' => $id, 'status' => 'inactive'],
                ['product_id' => $id, 'status' => 'archived']);
        });

        return redirect()
            ->route($this->routePrefix($request).'.index')
            ->with('success', __('lf.LF_product_status_archived_success'));
    }

    public function restore(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $customerId = $this->customerId();
        DB::transaction(function () use ($request, $customerId, $id): void {
            $product = DB::table('core_course_products')->where('customer_id', $customerId)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if(! $product, 404);
            if ($product->status !== 'archived') {
                throw ValidationException::withMessages(['status' => __('lf.LF_product_status_restore_requires_archived')]);
            }
            DB::table('core_course_products')->where('customer_id', $customerId)->where('id', $id)
                ->update(['status' => 'inactive', 'updated_at' => now()]);
            AuditLog::record($request, $customerId, 'course_product_restore', null,
                ['product_id' => $id, 'status' => 'archived'],
                ['product_id' => $id, 'status' => 'inactive']);
        });

        return redirect()->route($this->routePrefix($request).'.edit', $id)
            ->with('success', __('lf.LF_product_status_restored'));
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

        DB::transaction(function () use ($customerId, $productId, $validated, $request, $now): void {
            $product = DB::table('core_course_products')->where('customer_id', $customerId)
                ->where('id', $productId)->lockForUpdate()->first(['id', 'category_id', 'status', 'product_type']);
            abort_if(! $product, 404);
            if ($product->status === 'archived') {
                throw ValidationException::withMessages(['version_id' => __('lf.LF_product_status_archived_readonly')]);
            }
            $items = DB::table('core_course_product_items')
                ->where('customer_id', $customerId)->where('product_id', $productId)
                ->orderBy('sort_order')->orderBy('id')->lockForUpdate()
                ->get(['id', 'template_id', 'version_id', 'title_override', 'short_description_override']);
            if ($product->product_type === 'single_course' && $items->count() !== 1) {
                throw ValidationException::withMessages([
                    'version_id' => __('lf.LF_course_product_item_validation_single_item'),
                ]);
            }
            $item = $items->first();
            if (! $item) {
                throw ValidationException::withMessages([
                    'version_id' => __('lf.LF_course_product_item_validation_published_version'),
                ]);
            }
            if ((int) $item->version_id === (int) $validated['version_id']) {
                return;
            }
            $version = DB::table('core_course_template_versions as versions')
                ->join('core_course_templates as templates', function ($join) use ($customerId): void {
                    $join->on('templates.id', '=', 'versions.template_id')
                        ->where('templates.customer_id', '=', $customerId);
                })
                ->where('versions.customer_id', $customerId)
                ->where('versions.id', $validated['version_id'])
                ->where('versions.template_id', $item->template_id)
                ->where('versions.status', 'published')
                ->where('templates.status', 'active')
                ->where('templates.category_id', $product->category_id)
                ->lockForUpdate()->first(['versions.id']);
            if (! $version) {
                throw ValidationException::withMessages([
                    'version_id' => __('lf.LF_course_product_item_validation_published_version'),
                ]);
            }
            DB::table('core_course_product_items')->where('customer_id', $customerId)
                ->where('product_id', $productId)->where('id', $item->id)->update([
                'version_id' => $version->id,
                'title_override' => $validated['title_override'] ?? $item->title_override ?? null,
                'short_description_override' => $validated['short_description_override'] ?? $item->short_description_override ?? null,
                'sort_order' => $product->product_type === 'single_course' ? 0 : $validated['sort_order'],
                'is_required' => $product->product_type === 'single_course' ? true : (bool) $validated['is_required'],
                'status' => $product->product_type === 'single_course' ? 'active' : $validated['status'],
                'created_by' => $request->user()?->id,
                'updated_at' => $now,
            ]);
        });

        return redirect()
            ->route($this->routePrefix($request).'.edit', $productId)
            ->with('success', __('lf.LF_course_product_item_common_attached'));
    }

    public function destroyItem(Request $request, int $productId, int $itemId)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $deactivated = DB::transaction(function () use ($customerId, $productId, $itemId): bool {
            $product = DB::table('core_course_products')->where('customer_id', $customerId)
                ->where('id', $productId)->lockForUpdate()->first(['id', 'status', 'product_type']);
            abort_if(! $product, 404);
            $item = DB::table('core_course_product_items')->where('customer_id', $customerId)
                ->where('product_id', $productId)->where('id', $itemId)->lockForUpdate()->first(['id']);
            abort_if(! $item, 404);

            if ($product->product_type === 'single_course') {
                throw ValidationException::withMessages([
                    'version_id' => __('lf.LF_course_product_item_validation_remove_single'),
                ]);
            }

            DB::table('core_course_product_items')->where('customer_id', $customerId)
                ->where('product_id', $productId)->where('id', $itemId)
                ->update(['version_id' => null, 'updated_at' => now()]);

            $hasActiveVersion = $this->productHasActivePublishedVersion($customerId, $productId);
            if ($product->status === 'active' && ! $hasActiveVersion) {
                DB::table('core_course_products')->where('customer_id', $customerId)
                    ->where('id', $productId)->update(['status' => 'draft', 'updated_at' => now()]);
                return true;
            }

            return false;
        });

        return redirect()
            ->route($this->routePrefix($request).'.edit', $productId)
            ->with('success', $deactivated
                ? __('lf.LF_course_product_item_common_removed_and_deactivated')
                : __('lf.LF_course_product_item_common_removed'));
    }

    public function storeRelation(Request $request, int $productId)
    {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $product = $this->findProduct($customerId, $productId);
        abort_if($product->status === 'archived', 403);
        $validated = $this->validatedRelationData(
            $request,
            $customerId,
            $productId
        );
        try {
            DB::transaction(function () use ($customerId, $productId, $validated, $request): void {
                DB::table('core_course_products')->where('customer_id', $customerId)
                    ->where('id', $productId)->lockForUpdate()->first(['id']);

                if (DB::table('core_course_product_relations')
                    ->where('customer_id', $customerId)->where('product_id', $productId)
                    ->where('related_product_id', $validated['related_product_id'])
                    ->where('relation_type', 'related')->exists()) {
                    throw ValidationException::withMessages([
                        'related_product_id' => __('lf.LF_course_product_relation_validation_duplicate'),
                    ]);
                }

                $sortOrder = ((int) DB::table('core_course_product_relations')
                    ->where('customer_id', $customerId)->where('product_id', $productId)
                    ->where('relation_type', 'related')->max('sort_order')) + 1;
                $now = now();
                DB::table('core_course_product_relations')->insert([
                    'customer_id' => $customerId, 'product_id' => $productId,
                    'related_product_id' => $validated['related_product_id'], 'relation_type' => 'related',
                    'title_override' => null, 'description_override' => null, 'sort_order' => $sortOrder,
                    'is_featured' => false, 'starts_at' => null, 'ends_at' => null, 'status' => 'active',
                    'created_by' => $request->user()?->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages([
                    'related_product_id' => __('lf.LF_course_product_relation_validation_duplicate'),
                ]);
            }
            throw $exception;
        }

        return redirect()
            ->route($this->routePrefix($request).'.edit', [
                'id' => $productId,
                'tab' => 'relations',
                'focus' => 'related_product_search',
            ])
            ->with('success', __('lf.LF_course_product_relation_common_attached'));
    }

    public function destroyRelation(
        Request $request,
        int $productId,
        int $relationId
    ) {
        $this->authorizeAdmin($request);

        $customerId = $this->customerId();
        $product = $this->findProduct($customerId, $productId);
        abort_if($product->status === 'archived', 403);
        $relation = DB::table('core_course_product_relations')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('relation_type', 'related')
            ->where('id', $relationId)
            ->first();

        abort_if(! $relation, 404);

        DB::table('core_course_product_relations')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('relation_type', 'related')
            ->where('id', $relationId)
            ->delete();

        return redirect()
            ->route($this->routePrefix($request).'.edit', [
                'id' => $productId,
                'tab' => 'relations',
                'focus' => 'related_product_search',
            ])
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
            $linkedItem = $productId ? DB::table('core_course_product_items')
                ->where('customer_id', $customerId)->where('product_id', $productId)
                ->whereNotNull('version_id')->first(['template_id', 'version_id']) : null;
            $bindingUnchanged = $this->bindingRequestIsUnchanged($product, $linkedItem, $input);
            if (! $bindingUnchanged) {
                $template = DB::table('core_course_templates')->where('customer_id', $customerId)
                    ->where('id', $templateId)->where('category_id', $categoryId)
                    ->where('status', 'active')->first(['id']);
                if (! $template) {
                    $validator->errors()->add('template_id', __('lf.LF_product_v2_invalid_template'));
                }
                if (empty($input['template_version_id'])) {
                    $validator->errors()->add('template_version_id', __('lf.LF_product_v2_template_change_version_required'));
                }
            }
            if ($productId) {
                if ($linkedItem && (int) $linkedItem->template_id !== $templateId) {
                    $decision = $this->templateChangePolicy->decision(
                        $product,
                        $customerId,
                        $input['status'] ?? $product?->status
                    );
                    if (! $decision['allowed']) {
                        $validator->errors()->add('template_id', __(
                            $decision['reason'] === 'used'
                                ? 'lf.LF_product_v2_template_change_used'
                                : 'lf.LF_product_v2_template_change_draft_required'
                        ));
                    }
                }
            }
            if (! $bindingUnchanged && ! empty($input['template_version_id'])) {
                $validVersion = DB::table('core_course_template_versions')
                    ->where('customer_id', $customerId)
                    ->where('template_id', $templateId)
                    ->where('id', (int) $input['template_version_id'])
                    ->where('status', 'published')
                    ->exists();
                if (! $validVersion) {
                    $validator->errors()->add('template_version_id', __('lf.LF_course_product_item_validation_published_version'));
                }
            }

            if (($input['promotion_enabled'] ?? false) && ($input['discount_type'] ?? null) === 'fixed_amount'
                && (float) ($input['discount_value'] ?? 0) > (float) ($input['price'] ?? 0)) {
                $validator->errors()->add('discount_value', __('lf.LF_product_v2_discount_too_large'));
            }
            if ($product && ($input['status'] ?? null) === 'active' && $product->status !== 'active') {
                $version = DB::table('core_course_product_items as items')
                    ->join('core_course_template_versions as versions', 'versions.id', '=', 'items.version_id')
                    ->where('items.customer_id', $customerId)->where('items.product_id', $productId)
                    ->where('items.template_id', $templateId)->where('items.status', 'active')
                    ->where('versions.customer_id', $customerId)->where('versions.status', 'published')->first();
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
            'template_version_id' => ['nullable', 'integer', 'min:1'],
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
            'category_id', 'template_id', 'template_version_id', 'offering_type',
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
            'intro_video_source', 'intro_video_embed_url',
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
            'sort_order' => ['nullable', 'integer'],
            'is_required' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(self::ITEM_STATUSES)],
        ]);

        $validator->after(function ($validator) use (
            $request,
            $customerId,
            $productId
        ): void {
            $versionId = (int) $request->input('version_id');
            $templateId = (int) DB::table('core_course_product_items')
                ->where('customer_id', $customerId)->where('product_id', $productId)
                ->orderBy('sort_order')->orderBy('id')->value('template_id');

            if ($templateId < 1 || $versionId < 1) {
                return;
            }

            $version = DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->where('id', $versionId)
                ->where('template_id', $templateId)
                ->where('status', 'published')
                ->first();

            if (! $version) {
                $validator->errors()->add(
                    'version_id',
                    __('lf.LF_course_product_item_validation_published_version')
                );

                return;
            }

        });

        $validated = $validator->validate();
        $validated['sort_order'] ??= 0;
        $validated['is_required'] ??= true;
        $validated['status'] ??= 'active';

        return $validated;
    }

    private function validatedRelationData(
        Request $request,
        int $customerId,
        int $productId
    ): array {
        $validator = Validator::make($request->all(), [
            'related_product_id' => ['required', 'integer', 'min:1'],
        ]);

        $validator->after(function ($validator) use (
            $request,
            $customerId,
            $productId
        ): void {
            $relatedProductId = (int) $request->input('related_product_id');
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
                ->where('status', '!=', 'archived')
                ->first();

            if (! $relatedProduct) {
                $validator->errors()->add(
                    'related_product_id',
                    __('lf.LF_course_product_relation_validation_related_product')
                );

                return;
            }

            $duplicateExists = DB::table('core_course_product_relations')
                ->where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->where('related_product_id', $relatedProductId)
                ->where('relation_type', 'related')
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

    private function productHasActivePublishedVersion(int $customerId, int $productId): bool
    {
        return DB::table('core_course_product_items as items')
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'items.version_id')
                    ->where('versions.customer_id', '=', $customerId);
            })
            ->where('items.customer_id', $customerId)->where('items.product_id', $productId)
            ->where('items.status', 'active')->where('versions.status', 'published')
            ->whereColumn('versions.template_id', 'items.template_id')->exists();
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
            ->leftJoin('core_course_templates as templates', function ($join) use ($customerId): void {
                $join->on('templates.id', '=', 'items.template_id')
                    ->where('templates.customer_id', '=', $customerId);
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
                'versions.published_at',
                'versions.is_current',
                'templates.category_id as template_category_id'
            )
            ->get()
            ->each(function (object $item): void {
                $item->version_number_label = $item->version_number === null
                    ? null
                    : $this->versionSummaryPresenter->versionNumberLabel((int) $item->version_number);
            });
    }

    private function publishedVersions(int $customerId, int $productId)
    {
        $items = DB::table('core_course_product_items')->where('customer_id', $customerId)
            ->where('product_id', $productId)->orderBy('sort_order')->orderBy('id')
            ->get(['template_id', 'version_id', 'status']);
        $item = $items->firstWhere('status', 'active') ?? $items->first();
        if (! $item?->template_id) {
            return collect();
        }

        $versions = DB::table('core_course_template_versions as versions')
            ->where('versions.customer_id', $customerId)
            ->where('versions.template_id', $item->template_id)
            ->where('versions.status', 'published')
            ->orderByDesc('versions.version_number')->orderByDesc('versions.published_at')
            ->orderByDesc('versions.id')
            ->select(
                'versions.id',
                'versions.title_snapshot',
                'versions.version_number',
                'versions.version_code',
                'versions.is_current',
                'versions.status',
                'versions.published_at'
            )
            ->get();

        $latestVersionId = $versions->first()?->id;
        $inUseVersionId = $item->status === 'active' ? $item->version_id : null;
        return $versions->each(function ($version) use ($latestVersionId, $inUseVersionId): void {
            $version->is_latest_published = (int) $version->id === (int) $latestVersionId;
            $version->is_in_use = $inUseVersionId !== null
                && (int) $version->id === (int) $inUseVersionId;
        });
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
            ->leftJoin('core_course_categories as categories', function ($join) use ($customerId): void {
                $join->on('categories.id', '=', 'related_products.category_id')
                    ->where('categories.customer_id', '=', $customerId);
            })
            ->where('relations.customer_id', $customerId)
            ->where('relations.product_id', $productId)
            ->where('relations.relation_type', 'related')
            ->orderBy('relations.sort_order')
            ->orderBy('relations.id')
            ->select(
                'relations.*',
                'related_products.title as related_product_title',
                'related_products.product_code as related_product_code',
                'related_products.status as related_product_status',
                'categories.name as related_product_category_name'
            )
            ->get();
    }

    private function relatedProducts(int $customerId, int $productId)
    {
        return DB::table('core_course_products as products')
            ->leftJoin('core_course_categories as categories', function ($join) use ($customerId): void {
                $join->on('categories.id', '=', 'products.category_id')
                    ->where('categories.customer_id', '=', $customerId);
            })
            ->where('products.customer_id', $customerId)
            ->where('products.id', '!=', $productId)
            ->where('products.status', '!=', 'archived')
            ->whereNotExists(function ($query) use ($customerId, $productId): void {
                $query->selectRaw('1')->from('core_course_product_relations as existing_relations')
                    ->whereColumn('existing_relations.related_product_id', 'products.id')
                    ->where('existing_relations.customer_id', $customerId)
                    ->where('existing_relations.product_id', $productId)
                    ->where('existing_relations.relation_type', 'related');
            })
            ->orderBy('products.title')
            ->get([
                'products.id',
                'products.title',
                'products.product_code',
                'products.status',
                'categories.name as category_name',
            ])
            ->each(function ($product): void {
                $product->status_label = __('lf.LF_course_product_common_'.$product->status);
            });
    }

    private function categories(int $customerId)
    {
        return DB::table('core_course_categories')->where('customer_id', $customerId)
            ->where('status', '!=', 'archived')->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
    }

    private function syncPhaseOneItem(int $customerId, int $productId, array $validated, ?int $actorId): void
    {
        $product = DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('id', $productId)
            ->lockForUpdate()
            ->first(['id', 'product_type', 'status']);
        abort_if(! $product, 404);
        $item = DB::table('core_course_product_items')
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->orderBy('sort_order')->orderBy('id')->lockForUpdate()->first();
        if ($item) {
            if ((int) $item->template_id === (int) $validated['template_id']) {
                if (! empty($validated['template_version_id'])
                    && (int) $item->version_id !== (int) $validated['template_version_id']) {
                    DB::table('core_course_product_items')
                        ->where('customer_id', $customerId)
                        ->where('product_id', $productId)
                        ->where('id', $item->id)
                        ->update([
                            'version_id' => (int) $validated['template_version_id'],
                            'updated_at' => now(),
                        ]);
                }
                return;
            }
            $decision = $this->templateChangePolicy->decision($product, $customerId, $validated['status']);
            if (! $decision['allowed']) {
                throw ValidationException::withMessages([
                    'template_id' => __($decision['reason'] === 'used'
                        ? 'lf.LF_product_v2_template_change_used'
                        : 'lf.LF_product_v2_template_change_draft_required'),
                ]);
            }
            $version = DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->where('template_id', $validated['template_id'])
                ->where('id', $validated['template_version_id'] ?? 0)
                ->where('status', 'published')
                ->lockForUpdate()
                ->first(['id']);
            if (! $version) {
                throw ValidationException::withMessages([
                    'template_version_id' => __('lf.LF_course_product_item_validation_published_version'),
                ]);
            }
            DB::table('core_course_product_items')->where('customer_id', $customerId)
                ->where('product_id', $productId)->where('id', $item->id)
                ->update([
                    'template_id' => $validated['template_id'],
                    'version_id' => $version->id,
                    'sort_order' => 0,
                    'is_required' => true,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
            return;
        }

        DB::table('core_course_product_items')->insert(
            ['customer_id' => $customerId, 'product_id' => $productId, 'template_id' => $validated['template_id'],
                'version_id' => $validated['template_version_id'] ?? null,
                'title_override' => null, 'short_description_override' => null,
                'sort_order' => 0, 'is_required' => true, 'status' => 'active', 'created_by' => $actorId,
                'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function bindingRequestIsUnchanged(?object $product, ?object $item, array $validated): bool
    {
        if (! $product || ! $item) {
            return false;
        }

        $submittedVersionId = $validated['template_version_id'] ?? null;

        return (int) $item->template_id === (int) ($validated['template_id'] ?? 0)
            && (int) $product->category_id === (int) ($validated['category_id'] ?? 0)
            && ($submittedVersionId === null || $submittedVersionId === ''
                || (int) $item->version_id === (int) $submittedVersionId);
    }

    private function assertEligibleTemplateVersionBinding(
        int $customerId,
        array $validated,
        bool $lock
    ): void {
        $templateId = (int) ($validated['template_id'] ?? 0);
        $versionId = (int) ($validated['template_version_id'] ?? 0);
        $categoryId = (int) ($validated['category_id'] ?? 0);

        $query = DB::table('core_course_templates as templates')
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.template_id', '=', 'templates.id')
                    ->where('versions.customer_id', '=', $customerId);
            })
            ->where('templates.customer_id', $customerId)
            ->where('templates.id', $templateId)
            ->where('templates.category_id', $categoryId)
            ->where('templates.status', 'active')
            ->where('versions.id', $versionId)
            ->where('versions.status', 'published');

        if ($lock) {
            $query->lockForUpdate();
        }

        if (! $query->first(['templates.id'])) {
            throw ValidationException::withMessages([
                'template_id' => __('lf.LF_product_v2_invalid_template'),
                'template_version_id' => __('lf.LF_course_product_item_validation_published_version'),
            ]);
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
