@php
    $formProduct = $product ?? null;
    $selectedCategory = (string) old('category_id', $formProduct?->category_id);
    $persistedTemplate = (string) ($selectedTemplateId ?? '');
    $selectedTemplate = $formProduct && $errors->has('template_id')
        ? $persistedTemplate
        : (string) old('template_id', $persistedTemplate);
    $canChangeTemplate = ! $formProduct || ($canChangeTemplate ?? $templateChange['allowed'] ?? true);
    $selectedOffering = old('offering_type', $formProduct?->offering_type ?? '');
    $selectedStatus = old('status', $formProduct?->status ?? 'draft');
    if ($selectedStatus === 'active' && ! ($hasActiveCourseVersion ?? false)) {
        $selectedStatus = 'draft';
    }
    $customDescription = (bool) old('uses_custom_description', $formProduct?->uses_custom_description ?? false);
    $customMedia = (bool) old('uses_custom_intro_media', $formProduct?->uses_custom_intro_media ?? false);
    $promotion = (bool) old('promotion_enabled', $formProduct?->promotion_enabled ?? false);
    $generatedSlug = old('slug', $formProduct?->slug ?? '');
    $initialCategoryState = $categories->firstWhere('id', (int) $selectedCategory);
    $initialTemplateState = $templates->firstWhere('id', (int) $selectedTemplate);
    $dateValue = static fn ($field) => old($field, $formProduct?->{$field})
        ? str_replace(' ', 'T', substr((string) old($field, $formProduct?->{$field}), 0, 16)) : null;
@endphp

<div x-data="{
    category: @js($selectedCategory), template: @js($selectedTemplate), offering: @js($selectedOffering),
    customDescription: @js($customDescription), customMedia: @js($customMedia), promotion: @js($promotion),
    discountType: @js(old('discount_type', $formProduct?->discount_type)),
    currency: @js(old('currency', $formProduct?->currency ?? 'VND')),
    price: @js((string) old('price', $formProduct?->price ?? '0')),
    discount: @js((string) old('discount_value', $formProduct?->discount_value ?? '')),
    generatedSlug: @js($generatedSlug),
    persistedStatus: @js((string) ($formProduct?->status ?? '')),
    persistedTemplate: @js($persistedTemplate), templateVersion: @js((string) old('template_version_id', '')), submittingProduct: false,
    templates: @js($templates),
    slugify(v) { return v.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') },
    sellingPrice() { let p=Number(this.price||0), d=Number(this.discount||0); if(!this.promotion) return p.toFixed(2); return Math.max(0,this.discountType==='percentage'?p-(p*d/100):p-d).toFixed(2) },
    discountAmount() { return Math.max(0, Number(this.price||0) - Number(this.sellingPrice())) },
    formatMoney(value) { return new Intl.NumberFormat(document.documentElement.lang === 'vi' ? 'vi-VN' : 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0)) },
    handleProductSubmit(event) {
        if (this.submittingProduct) { event.preventDefault(); return }
        if (this.persistedTemplate && this.template !== this.persistedTemplate && !window.confirm(@js(__('lf.LF_product_v2_template_change_confirm')))) { event.preventDefault(); return }
        const targetStatus = event.target.querySelector('[name=status]')?.value
        const transitionKey = `${this.persistedStatus}:${targetStatus}`
        const confirmations = @js([
            'active:inactive' => __('lf.LF_product_status_deactivate_confirm'),
            'active:draft' => __('lf.LF_product_status_draft_confirm'),
            'inactive:draft' => __('lf.LF_product_status_draft_confirm'),
            'draft:active' => __('lf.LF_product_status_activate_confirm'),
            'inactive:active' => __('lf.LF_product_status_activate_confirm'),
        ]);
        if (this.persistedStatus && targetStatus !== this.persistedStatus && confirmations[transitionKey] && !window.confirm(confirmations[transitionKey])) { event.preventDefault(); return }
        this.submittingProduct = true
        if (event.submitter) { event.submitter.disabled = true; event.submitter.setAttribute('aria-busy', 'true') }
    }
}" x-init="$nextTick(() => { template = @js($selectedTemplate) }); $el.closest('form')?.addEventListener('submit', event => handleProductSubmit(event))"
   class="admin-form-flow">
    <section class="admin-form-standard-section" aria-labelledby="product-basic">
        <h2 id="product-basic" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_basic') }}</h2>
        <div class="admin-form-subsection" aria-labelledby="product-course-content">
            <h3 id="product-course-content" class="admin-form-subsection-title">{{ __('lf.LF_product_v2_group_course_content') }}</h3>
            <div class="admin-form-field-grid">
                <div class="lf-form-group">
                    <x-form-label for="category_id" :value="__('lf.LF_product_v2_category')" :required="true" />
                    @if($canChangeTemplate)
                        <select id="category_id" name="category_id" class="lf-form-control" x-model="category" :class="{ 'lf-select-placeholder': category === '' }" @change="if(!templates.some(t=>String(t.id)===template && String(t.category_id)===category)) { template=''; templateVersion='' }" required>
                            <option value="">{{ __('lf.LF_product_v2_select_category') }}</option>
                            @foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                        </select>
                    @else
                        <input type="hidden" name="category_id" value="{{ $selectedCategory }}">
                        <input id="category_id" class="lf-form-control admin-form-readonly" readonly value="{{ $initialCategoryState?->name ?? '—' }}">
                    @endif
                    @error('category_id')<p class="lf-form-error">{{ $message }}</p>@enderror
                </div>

                <div class="lf-form-group">
                    <x-form-label for="template_id" :value="__('lf.LF_product_v2_template')" :required="true" />
                    @if($canChangeTemplate)
                        <select id="template_id" name="template_id" class="lf-form-control" x-model="template" @change="templateVersion = ''" :class="{ 'lf-select-placeholder': template === '' }" required>
                            <option value="">{{ __('lf.LF_product_v2_select_template') }}</option>
                            @foreach($templates as $templateOption)
                                <option value="{{ $templateOption->id }}"
                                        x-show="String({{ (int) $templateOption->category_id }}) === String(category)"
                                        :disabled="String({{ (int) $templateOption->category_id }}) !== String(category)"
                                        @selected((string) $templateOption->id === $selectedTemplate)>{{ $templateOption->name }}</option>
                            @endforeach
                        </select>
                        <p class="lf-form-help" x-show="category && !templates.some(item => String(item.category_id) === String(category))" x-cloak>
                            {{ __('lf.LF_product_v2_no_templates_for_category') }}
                        </p>
                    @else
                        <input type="hidden" name="template_id" value="{{ $persistedTemplate }}">
                        <input id="template_id" class="lf-form-control admin-form-readonly" readonly
                               aria-describedby="template-policy-notice"
                               value="{{ $initialTemplateState?->name ?? '—' }}">
                    @endif
                    @if(! $canChangeTemplate && $formProduct)
                        <p id="template-policy-notice" class="admin-form-inline-notice course-product-template-lock-help">
                            <span class="admin-form-inline-notice-icon" aria-hidden="true">i</span>
                            <span>{{ __(match($templateLockReason ?? $templateChange['reason'] ?? null) {
                                'used' => 'lf.LF_product_v2_template_change_used',
                                'archived' => 'lf.LF_product_v2_template_change_archived',
                                default => 'lf.LF_product_v2_template_change_draft_required',
                            }) }}</span>
                        </p>
                    @endif
                    @if($canChangeTemplate)
                        <div class="lf-form-group" x-show="template && (!persistedTemplate || template !== persistedTemplate)" x-cloak>
                            <x-form-label for="template_version_id" :value="__('lf.LF_product_v2_template_change_version')" :required="true" />
                            <select id="template_version_id" name="template_version_id" class="lf-form-control" x-model="templateVersion" :required="template && (!persistedTemplate || template !== persistedTemplate)">
                                <option value="">{{ __('lf.LF_course_product_item_common_select_version') }}</option>
                                @foreach($templates as $templateOption)
                                    @foreach($templateOption->published_versions as $versionOption)
                                        <option value="{{ $versionOption['id'] }}"
                                                x-show="String({{ (int) $templateOption->id }}) === String(template)"
                                                :disabled="String({{ (int) $templateOption->id }}) !== String(template)">{{ $versionOption['number_label'] }} · {{ $versionOption['code'] }}@if($versionOption['published_at']) · {{ $versionOption['published_at'] }}@endif</option>
                                    @endforeach
                                @endforeach
                            </select>
                            @error('template_version_id')<p class="lf-form-error">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    @error('template_id')<p class="lf-form-error">{{ $message }}</p>@enderror
                </div>

                <div class="admin-form-field--full">
                    <template x-for="item in templates.filter(t => String(t.id) === String(template))" :key="`version-${item.id}`">
                        <div>
                            <template x-if="item.version_summary">
                                <aside class="lf-product-version-summary" aria-live="polite">
                                    <div class="lf-product-version-summary-copy">
                                        <p class="lf-product-version-summary-heading">{{ __('lf.LF_product_v2_version_in_use') }}</p>
                                        <p class="lf-product-version-summary-primary">
                                            <span class="lf-product-version-summary-main" x-text="item.version_summary.code"></span>
                                            <span class="lf-product-version-summary-separator" aria-hidden="true">·</span>
                                            <span class="badge badge-success" x-text="item.version_summary.status_label"></span>
                                        </p>
                                        <p class="lf-secondary-text"><span x-text="item.version_summary.number_label"></span> · <span x-text="item.version_summary.lesson_text"></span> · <span x-text="item.version_summary.activity_text"></span></p>
                                    </div>
                                    <a x-show="item.version_summary.view_url" :href="item.version_summary.view_url" target="_blank" rel="noopener noreferrer" class="admin-text-action">{{ __('lf.LF_product_v2_view_version') }}</a>
                                </aside>
                            </template>
                            <p x-show="!item.version_summary" class="lf-product-version-summary-heading">{{ __('lf.LF_product_v2_version_in_use') }}</p>
                            <p x-show="!item.version_summary && !item.integrity_warning" class="lf-form-help" role="status">{{ __('lf.LF_product_v2_missing_version') }}</p>
                            <p x-show="item.integrity_warning" class="lf-form-error" role="alert" x-text="item.integrity_warning"></p>
                        </div>
                    </template>
                    <noscript>
                        @if($initialTemplateState?->version_summary)
                            <aside class="lf-product-version-summary">
                                <div class="lf-product-version-summary-copy">
                                    <p class="lf-product-version-summary-heading">{{ __('lf.LF_product_v2_version_in_use') }}</p>
                                    <p class="lf-product-version-summary-primary">
                                        <span class="lf-product-version-summary-main">{{ $initialTemplateState->version_summary['code'] }}</span>
                                        <span class="lf-product-version-summary-separator" aria-hidden="true">·</span>
                                        <span class="badge badge-success">{{ $initialTemplateState->version_summary['status_label'] }}</span>
                                    </p>
                                    <p class="lf-secondary-text">{{ $initialTemplateState->version_summary['number_label'] }} · {{ $initialTemplateState->version_summary['lesson_text'] }} · {{ $initialTemplateState->version_summary['activity_text'] }}</p>
                                </div>
                                @if($initialTemplateState->version_summary['view_url'])<a href="{{ $initialTemplateState->version_summary['view_url'] }}" target="_blank" rel="noopener noreferrer" class="admin-text-action">{{ __('lf.LF_product_v2_view_version') }}</a>@endif
                            </aside>
                        @elseif($initialTemplateState?->integrity_warning)
                            <p class="lf-form-error" role="alert">{{ $initialTemplateState->integrity_warning }}</p>
                        @elseif($selectedTemplate !== '')
                            <p class="lf-form-help" role="status">{{ __('lf.LF_product_v2_missing_version') }}</p>
                        @endif
                    </noscript>
                </div>
            </div>
        </div>

        <div class="admin-form-subsection" aria-labelledby="product-identity">
            <h3 id="product-identity" class="admin-form-subsection-title">{{ __('lf.LF_product_v2_group_identity') }}</h3>
            <div class="admin-form-field-grid">
                <div class="lf-form-group"><x-form-label for="title" :value="__('lf.LF_course_product_common_title_field')" :required="true" /><input id="title" name="title" class="lf-form-control" maxlength="255" required value="{{ old('title', $formProduct?->title) }}" placeholder="{{ __('lf.LF_product_v2_placeholder_name') }}" @input="generatedSlug = slugify($event.target.value)"></div>
                <div class="lf-form-group"><x-form-label for="offering_type" :value="__('lf.LF_product_v2_offering_type')" :required="true" /><select id="offering_type" name="offering_type" class="lf-form-control" x-model="offering" :class="{ 'lf-select-placeholder': offering === '' }" required><option value="">{{ __('lf.LF_product_v2_select_offering') }}</option>@foreach(\App\Support\CourseProductV2::OFFERING_TYPES as $type)<option value="{{ $type }}">{{ __('lf.LF_product_v2_offering_'.$type) }}</option>@endforeach</select></div>
                <div @class(['lf-form-group', 'admin-form-field--full' => ! $formProduct])>
                    <div class="admin-form-label-row">
                        <x-form-label for="slug" :value="__('lf.LF_course_product_common_slug')" />
                        <span class="admin-form-label-metadata">{{ __('lf.LF_product_v2_automatic') }}</span>
                    </div>
                    <input id="slug" name="slug" class="lf-form-control admin-form-readonly" readonly x-model="generatedSlug" placeholder="{{ __('lf.LF_product_v2_generated_slug') }}">
                </div>
                @if($formProduct)<div class="lf-form-group"><x-form-label for="product_code" :value="__('lf.LF_course_product_common_product_code')" /><input id="product_code" class="lf-form-control admin-form-readonly course-product-code-control" readonly value="{{ $formProduct->product_code }}"></div>@endif
            </div>
        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="product-description">
        <h2 id="product-description" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_description_media') }}</h2>
        <div class="admin-form-option-group">
            <input type="hidden" name="uses_custom_description" value="0">
            <label class="admin-form-option-panel">
                <input type="checkbox" name="uses_custom_description" value="1" x-model="customDescription" :aria-expanded="customDescription.toString()" aria-controls="course-product-description-fields">
                <span><strong>{{ __('lf.LF_product_v2_description_option_title') }}</strong><span>{{ __('lf.LF_product_v2_custom_description') }}</span><small>{{ __('lf.LF_product_v2_description_inherited') }}</small></span>
            </label>
            <div id="course-product-description-fields" class="admin-form-conditional" x-show="customDescription" x-cloak>
                <div class="lf-form-group"><x-form-label for="short_description" :value="__('lf.LF_course_product_common_short_description')" /><textarea id="short_description" name="short_description" class="lf-form-control" maxlength="500" placeholder="{{ __('lf.LF_product_v2_placeholder_short_description') }}">{{ old('short_description', $formProduct?->short_description) }}</textarea></div>
                <div class="lf-form-group"><x-form-label for="description" :value="__('lf.LF_course_product_common_description')" /><textarea id="description" name="description" rows="6" class="lf-form-control" placeholder="{{ __('lf.LF_product_v2_placeholder_description') }}">{{ old('description', $formProduct?->description) }}</textarea></div>
            </div>
        </div>
        <div class="admin-form-option-group">
            <input type="hidden" name="uses_custom_intro_media" value="0">
            <label class="admin-form-option-panel">
                <input type="checkbox" name="uses_custom_intro_media" value="1" x-model="customMedia" :aria-expanded="customMedia.toString()" aria-controls="course-product-media-fields">
                <span><strong>{{ __('lf.LF_product_v2_media_option_title') }}</strong><span>{{ __('lf.LF_product_v2_custom_media') }}</span><small>{{ __('lf.LF_product_v2_media_inherited') }}</small></span>
            </label>
        <div id="course-product-media-fields" class="admin-form-conditional" x-show="customMedia" x-cloak>
            <x-introduction-media-fields
                :image-media="$introMedia['intro_image'] ?? null"
                :video-media="$introMedia['intro_video'] ?? null"
                :document-media="$introMedia['intro_document'] ?? null"
                :image-thumbnail="$introImageThumbnail"
                :video-thumbnail="$introVideoThumbnail"
                :document-thumbnail="$introDocumentThumbnail"
                :video-embed-url="$introVideoEmbedUrl"
                :embed-value="$formProduct?->intro_video_embed_url"
                :selected-video-source="old('intro_video_source', $formProduct?->intro_video_source)" />
        </div>
        </div>
        <div hidden aria-hidden="true"><span>Cover image upload</span>@if($coverImageMedia ?? null)<a href="{{ $coverImageMedia->signed_url }}">{{ $coverImageMedia->display_name }}</a>@endif<input type="file" name="cover_image_file" accept="image/*"><x-upload-hint :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" /></div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="product-config"><h2 id="product-config" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_configuration') }}</h2><p x-show="!offering" class="admin-form-empty-state">{{ __('lf.LF_product_v2_select_type_configuration') }}</p><div class="admin-form-field-grid" x-show="offering==='self_paced_course'"><div class="lf-form-group"><x-form-label for="access_duration_days" :value="__('lf.LF_product_v2_access_days')" /><input id="access_duration_days" name="access_duration_days" type="number" min="1" class="lf-form-control" value="{{ old('access_duration_days', $formProduct?->access_duration_days) }}" placeholder="{{ __('lf.LF_product_v2_placeholder_access_days') }}" @error('access_duration_days') aria-invalid="true" aria-describedby="access_duration_days_error" @enderror>@error('access_duration_days')<p id="access_duration_days_error" class="lf-form-error">{{ $message }}</p>@enderror</div><div class="lf-form-group"><x-form-label for="review_duration_days" :value="__('lf.LF_product_v2_review_days')" /><input id="review_duration_days" name="review_duration_days" type="number" min="0" class="lf-form-control" value="{{ old('review_duration_days', $formProduct?->review_duration_days) }}" placeholder="{{ __('lf.LF_product_v2_placeholder_review_days') }}" @error('review_duration_days') aria-invalid="true" aria-describedby="review_duration_days_error" @enderror>@error('review_duration_days')<p id="review_duration_days_error" class="lf-form-error">{{ $message }}</p>@enderror</div></div><p x-show="offering && offering!=='self_paced_course'" class="admin-form-empty-state">{{ __('lf.LF_product_v2_configuration_deferred') }}</p></section>

    <section class="admin-form-standard-section" aria-labelledby="product-pricing">
        <h2 id="product-pricing" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_pricing') }}</h2>
        <div class="admin-form-field-grid admin-form-field-grid--main-compact">
            <div class="lf-form-group">
                <x-form-label for="price" :value="__('lf.LF_product_v2_list_price')" :required="true" />
                <input id="price" name="price" type="number" min="0" step="0.01" x-model="price" class="lf-form-control" required>
            </div>
            <div class="lf-form-group">
                <x-form-label for="currency" :value="__('lf.LF_course_product_common_currency')" :required="true" />
                <select id="currency" name="currency" class="lf-form-control" x-model="currency" required>
                    @foreach(['VND','USD','KRW'] as $currency)<option value="{{ $currency }}" @selected(old('currency',$formProduct?->currency??'VND')===$currency)>{{ $currency }}</option>@endforeach
                </select>
            </div>

            <div class="admin-form-field--full admin-form-option-group">
                <input type="hidden" name="promotion_enabled" value="0">
                <label class="admin-form-option-panel admin-form-option-panel--compact">
                    <input type="checkbox" name="promotion_enabled" value="1" x-model="promotion" :aria-expanded="promotion.toString()" aria-controls="course-product-promotion-fields">
                    <span>
                        <strong>{{ __('lf.LF_product_v2_apply_promotion') }}</strong>
                        <small>{{ __('lf.LF_product_v2_promotion_help') }}</small>
                    </span>
                </label>
            </div>

            <div id="course-product-promotion-fields"
                 class="admin-form-field-grid admin-form-field--full admin-form-conditional"
                 x-show="promotion"
                 x-cloak>
                <div class="lf-form-group">
                    <x-form-label for="discount_type" :value="__('lf.LF_product_v2_discount_type')" />
                    <select id="discount_type" name="discount_type" class="lf-form-control" x-model="discountType"
                            @error('discount_type') aria-invalid="true" aria-describedby="discount_type_error" @enderror>
                        <option value="">{{ __('lf.LF_product_v2_select_discount') }}</option>
                        <option value="percentage">{{ __('lf.LF_product_v2_percentage') }}</option>
                        <option value="fixed_amount">{{ __('lf.LF_product_v2_fixed_amount') }}</option>
                    </select>
                    @error('discount_type')<p id="discount_type_error" class="lf-form-error">{{ $message }}</p>@enderror
                </div>
                <div class="lf-form-group">
                    <x-form-label for="discount_value" :value="__('lf.LF_product_v2_discount_value_label')" />
                    <input id="discount_value" name="discount_value" type="number" min="0.01" step="0.01" x-model="discount" class="lf-form-control" placeholder="{{ __('lf.LF_product_v2_discount_value') }}"
                           @error('discount_value') aria-invalid="true" aria-describedby="discount_value_error" @enderror>
                    @error('discount_value')<p id="discount_value_error" class="lf-form-error">{{ $message }}</p>@enderror
                </div>
                <div class="lf-form-group">
                    <x-form-label for="sale_starts_at" :value="__('lf.LF_product_v2_promotion_starts_at')" />
                    <input id="sale_starts_at" name="sale_starts_at" type="datetime-local" class="lf-form-control" value="{{ $dateValue('sale_starts_at') }}"
                           @error('sale_starts_at') aria-invalid="true" aria-describedby="sale_starts_at_error" @enderror>
                    @error('sale_starts_at')<p id="sale_starts_at_error" class="lf-form-error">{{ $message }}</p>@enderror
                </div>
                <div class="lf-form-group">
                    <x-form-label for="sale_ends_at" :value="__('lf.LF_product_v2_promotion_ends_at')" />
                    <input id="sale_ends_at" name="sale_ends_at" type="datetime-local" class="lf-form-control" value="{{ $dateValue('sale_ends_at') }}"
                           @error('sale_ends_at') aria-invalid="true" aria-describedby="sale_ends_at_error" @enderror>
                    @error('sale_ends_at')<p id="sale_ends_at_error" class="lf-form-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <output id="selling_price"
                    class="admin-form-field--full admin-form-calculated-summary"
                    aria-live="polite">
                <span class="admin-form-calculated-summary-label">{{ __('lf.LF_product_v2_selling_price') }}</span>
                <strong class="admin-form-calculated-summary-value">
                    <span x-text="formatMoney(sellingPrice())"></span>
                    <span x-text="currency"></span>
                </strong>
                <span class="admin-form-calculated-summary-meta"
                      x-show="promotion && discountType && Number(discount || 0) > 0"
                      x-cloak>
                    {{ __('lf.LF_product_v2_save') }}
                    <span x-text="formatMoney(discountAmount())"></span>
                    <span x-text="currency"></span>
                    <span x-show="discountType === 'percentage'">
                        · <span x-text="`${Number(discount || 0)}%`"></span>
                    </span>
                </span>
            </output>
        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="product-availability">
        <h2 id="product-availability" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_availability') }}</h2>
        <div class="admin-form-field-grid">
            <div class="lf-form-group"><x-form-label for="registration_starts_at" :value="__('lf.LF_course_product_common_registration_starts_at')" /><input id="registration_starts_at" name="registration_starts_at" type="datetime-local" class="lf-form-control" value="{{ $dateValue('registration_starts_at') }}" @error('registration_starts_at') aria-invalid="true" aria-describedby="registration_starts_at_error" @enderror>@error('registration_starts_at')<p id="registration_starts_at_error" class="lf-form-error">{{ $message }}</p>@enderror</div>
            <div class="admin-form-option-group course-product-featured-option"><input type="hidden" name="is_featured" value="0"><label class="admin-form-option-panel admin-form-option-panel--compact"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured',$formProduct?->is_featured))><span><strong>{{ __('lf.LF_course_product_common_is_featured') }}</strong></span></label></div>
            <div class="lf-form-group"><x-form-label for="registration_ends_at" :value="__('lf.LF_course_product_common_registration_ends_at')" /><input id="registration_ends_at" name="registration_ends_at" type="datetime-local" class="lf-form-control" value="{{ $dateValue('registration_ends_at') }}" @error('registration_ends_at') aria-invalid="true" aria-describedby="registration_ends_at_error" @enderror>@error('registration_ends_at')<p id="registration_ends_at_error" class="lf-form-error">{{ $message }}</p>@enderror</div>
            <div class="lf-form-group"><x-form-label for="sort_order" :value="__('lf.LF_course_product_common_sort_order')" /><input id="sort_order" name="sort_order" type="number" min="0" class="lf-form-control" value="{{ old('sort_order',$formProduct?->sort_order) }}" placeholder="{{ __('lf.LF_product_v2_auto_order') }}"></div>
            <div class="lf-form-group admin-form-field--full"><x-form-label for="status" :value="__('lf.LF_course_product_common_status')" :required="true" /><select id="status" name="status" class="lf-form-control" required>@foreach(($allowedStatuses ?? ['draft']) as $status)<option value="{{ $status }}" @selected($selectedStatus===$status) @disabled($status === 'active' && ! ($hasActiveCourseVersion ?? false))>{{ __('lf.LF_course_product_common_'.$status) }}</option>@endforeach</select>@if(! ($hasActiveCourseVersion ?? false))<p class="lf-form-help">{{ __('lf.LF_product_v2_attach_before_activation') }}</p>@endif@if($formProduct && ! in_array('draft', $allowedStatuses ?? [], true) && $formProduct->status !== 'draft')<p class="lf-form-help">{{ __('lf.LF_product_status_used_draft_help') }}</p>@endif</div>
        </div>
    </section>
</div>
