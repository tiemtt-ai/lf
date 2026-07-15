@php
    $formProduct = $product ?? null;
    $selectedCategory = (string) old('category_id', $formProduct?->category_id);
    $selectedTemplate = (string) old('template_id', $selectedTemplateId ?? '');
    $selectedOffering = old('offering_type', $formProduct?->offering_type ?? '');
    $selectedStatus = old('status', $formProduct?->status ?? 'draft');
    if ($selectedStatus === 'active' && ! ($hasActiveCourseVersion ?? false)) {
        $selectedStatus = 'draft';
    }
    $customDescription = (bool) old('uses_custom_description', $formProduct?->uses_custom_description ?? false);
    $customMedia = (bool) old('uses_custom_intro_media', $formProduct?->uses_custom_intro_media ?? false);
    $promotion = (bool) old('promotion_enabled', $formProduct?->promotion_enabled ?? false);
    $generatedSlug = old('slug', $formProduct?->slug ?? '');
    $initialTemplateState = $templates->firstWhere('id', (int) $selectedTemplate);
    $dateValue = static fn ($field) => old($field, $formProduct?->{$field})
        ? str_replace(' ', 'T', substr((string) old($field, $formProduct?->{$field}), 0, 16)) : null;
@endphp

<div x-data="{
    category: @js($selectedCategory), template: @js($selectedTemplate), offering: @js($selectedOffering),
    customDescription: @js($customDescription), customMedia: @js($customMedia), promotion: @js($promotion),
    discountType: @js(old('discount_type', $formProduct?->discount_type)),
    price: @js((string) old('price', $formProduct?->price ?? '0')),
    discount: @js((string) old('discount_value', $formProduct?->discount_value ?? '')),
    generatedSlug: @js($generatedSlug),
    templates: @js($templates),
    slugify(v) { return v.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') },
    sellingPrice() { let p=Number(this.price||0), d=Number(this.discount||0); if(!this.promotion) return p.toFixed(2); return Math.max(0,this.discountType==='percentage'?p-(p*d/100):p-d).toFixed(2) }
}" x-init="$nextTick(() => { template = @js($selectedTemplate) })" class="backend-form-columns">
    <section class="admin-form-section" aria-labelledby="product-basic">
        <h2 id="product-basic" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_basic') }}</h2>
        @if($formProduct)<div class="lf-form-group"><x-form-label for="product_code" :value="__('lf.LF_course_product_common_product_code')" /><input id="product_code" class="lf-form-control" readonly value="{{ $formProduct->product_code }}"></div>@endif
        <div class="lf-form-group"><x-form-label for="category_id" :value="__('lf.LF_product_v2_category')" :required="true" /><select id="category_id" name="category_id" class="lf-form-control" x-model="category" :class="{ 'lf-select-placeholder': category === '' }" @change="if(!templates.some(t=>String(t.id)===template && String(t.category_id)===category)) template=''" required><option value="">{{ __('lf.LF_product_v2_select_category') }}</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>@error('category_id')<p class="lf-form-error">{{ $message }}</p>@enderror</div>
        <div class="lf-form-group">
            <x-form-label for="template_id" :value="__('lf.LF_product_v2_template')" :required="true" />
            <select id="template_id" name="template_id" class="lf-form-control" x-model="template" :class="{ 'lf-select-placeholder': template === '' }" required>
                <option value="">{{ __('lf.LF_product_v2_select_template') }}</option>
                <template x-for="item in templates.filter(t => String(t.category_id) === category)" :key="item.id">
                    <option :value="item.id" :selected="String(item.id) === String(template)" x-text="item.name"></option>
                </template>
            </select>
            <template x-for="item in templates.filter(t => String(t.id) === String(template))" :key="`version-${item.id}`">
                <div>
                    <template x-if="item.version_summary">
                        <aside class="lf-product-version-summary" aria-live="polite">
                            <div class="lf-product-version-summary-copy">
                                <p class="lf-product-version-summary-heading">{{ __('lf.LF_product_v2_version_in_use') }}</p>
                                <p class="lf-product-version-summary-main"><span x-text="item.version_summary.code"></span> · <span x-text="item.version_summary.status_label"></span></p>
                                <p class="lf-secondary-text"><span x-text="item.version_summary.lesson_text"></span> · <span x-text="item.version_summary.activity_text"></span></p>
                            </div>
                            <a x-show="item.version_summary.view_url" :href="item.version_summary.view_url" target="_blank" rel="noopener noreferrer" class="admin-text-action">{{ __('lf.LF_product_v2_view_version') }}</a>
                        </aside>
                    </template>
                    <p x-show="!item.version_summary && !item.integrity_warning" class="lf-form-help" role="status">{{ __('lf.LF_product_v2_missing_version') }}</p>
                    <p x-show="item.integrity_warning" class="lf-form-error" role="alert" x-text="item.integrity_warning"></p>
                </div>
            </template>
            <noscript>
                @if($initialTemplateState?->version_summary)
                    <aside class="lf-product-version-summary">
                        <div class="lf-product-version-summary-copy">
                            <p class="lf-product-version-summary-heading">{{ __('lf.LF_product_v2_version_in_use') }}</p>
                            <p class="lf-product-version-summary-main">{{ $initialTemplateState->version_summary['code'] }} · {{ $initialTemplateState->version_summary['status_label'] }}</p>
                            <p class="lf-secondary-text">{{ $initialTemplateState->version_summary['lesson_text'] }} · {{ $initialTemplateState->version_summary['activity_text'] }}</p>
                        </div>
                        @if($initialTemplateState->version_summary['view_url'])<a href="{{ $initialTemplateState->version_summary['view_url'] }}" target="_blank" rel="noopener noreferrer" class="admin-text-action">{{ __('lf.LF_product_v2_view_version') }}</a>@endif
                    </aside>
                @elseif($initialTemplateState?->integrity_warning)
                    <p class="lf-form-error" role="alert">{{ $initialTemplateState->integrity_warning }}</p>
                @elseif($selectedTemplate !== '')
                    <p class="lf-form-help" role="status">{{ __('lf.LF_product_v2_missing_version') }}</p>
                @endif
            </noscript>
            @error('template_id')<p class="lf-form-error">{{ $message }}</p>@enderror
        </div>
        <div class="lf-form-group"><x-form-label for="title" :value="__('lf.LF_course_product_common_title_field')" :required="true" /><input id="title" name="title" class="lf-form-control" maxlength="255" required value="{{ old('title', $formProduct?->title) }}" placeholder="{{ __('lf.LF_product_v2_placeholder_name') }}" @input="generatedSlug = slugify($event.target.value)"></div>
        <div class="lf-form-group"><x-form-label for="slug" :value="__('lf.LF_course_product_common_slug')" /><input id="slug" name="slug" class="lf-form-control" readonly x-model="generatedSlug" placeholder="{{ __('lf.LF_product_v2_generated_slug') }}"></div>
        <div class="lf-form-group"><x-form-label for="offering_type" :value="__('lf.LF_product_v2_offering_type')" :required="true" /><select id="offering_type" name="offering_type" class="lf-form-control" x-model="offering" :class="{ 'lf-select-placeholder': offering === '' }" required><option value="">{{ __('lf.LF_product_v2_select_offering') }}</option>@foreach(\App\Support\CourseProductV2::OFFERING_TYPES as $type)<option value="{{ $type }}">{{ __('lf.LF_product_v2_offering_'.$type) }}</option>@endforeach</select></div>
    </section>

    <section class="admin-form-section" aria-labelledby="product-description">
        <h2 id="product-description" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_description_media') }}</h2>
        <div class="lf-form-group"><input type="hidden" name="uses_custom_description" value="0"><div class="admin-radio-group"><label><input type="checkbox" name="uses_custom_description" value="1" x-model="customDescription"> {{ __('lf.LF_product_v2_custom_description') }}</label></div></div>
        <p x-show="!customDescription" class="lf-form-help">{{ __('lf.LF_product_v2_description_inherited') }}</p>
        <div x-show="customDescription"><div class="lf-form-group"><x-form-label for="short_description" :value="__('lf.LF_course_product_common_short_description')" /><textarea id="short_description" name="short_description" class="lf-form-control" maxlength="500" placeholder="{{ __('lf.LF_product_v2_placeholder_short_description') }}">{{ old('short_description', $formProduct?->short_description) }}</textarea></div><div class="lf-form-group"><x-form-label for="description" :value="__('lf.LF_course_product_common_description')" /><textarea id="description" name="description" rows="6" class="lf-form-control" placeholder="{{ __('lf.LF_product_v2_placeholder_description') }}">{{ old('description', $formProduct?->description) }}</textarea></div></div>
        <div class="lf-form-group"><input type="hidden" name="uses_custom_intro_media" value="0"><div class="admin-radio-group"><label><input type="checkbox" name="uses_custom_intro_media" value="1" x-model="customMedia"> {{ __('lf.LF_product_v2_custom_media') }}</label></div></div>
        <p x-show="!customMedia" class="lf-form-help">{{ __('lf.LF_product_v2_media_inherited') }}</p>
        <div x-show="customMedia">
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
        <div hidden aria-hidden="true"><span>Cover image upload</span>@if($coverImageMedia ?? null)<a href="{{ $coverImageMedia->signed_url }}">{{ $coverImageMedia->display_name }}</a>@endif<input type="file" name="cover_image_file" accept="image/*"><x-upload-hint :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" /></div>
    </section>

    <section class="admin-form-section" aria-labelledby="product-config"><h2 id="product-config" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_configuration') }}</h2><div x-show="offering==='self_paced_course'"><div class="lf-form-group"><x-form-label for="access_duration_days" :value="__('lf.LF_product_v2_access_days')" /><input id="access_duration_days" name="access_duration_days" type="number" min="1" class="lf-form-control" value="{{ old('access_duration_days', $formProduct?->access_duration_days) }}" placeholder="{{ __('lf.LF_product_v2_placeholder_access_days') }}" @error('access_duration_days') aria-invalid="true" aria-describedby="access_duration_days_error" @enderror>@error('access_duration_days')<p id="access_duration_days_error" class="lf-form-error">{{ $message }}</p>@enderror</div><div class="lf-form-group"><x-form-label for="review_duration_days" :value="__('lf.LF_product_v2_review_days')" /><input id="review_duration_days" name="review_duration_days" type="number" min="0" class="lf-form-control" value="{{ old('review_duration_days', $formProduct?->review_duration_days) }}" placeholder="{{ __('lf.LF_product_v2_placeholder_review_days') }}" @error('review_duration_days') aria-invalid="true" aria-describedby="review_duration_days_error" @enderror>@error('review_duration_days')<p id="review_duration_days_error" class="lf-form-error">{{ $message }}</p>@enderror</div></div><p x-show="offering && offering!=='self_paced_course'" class="lf-form-help">{{ __('lf.LF_product_v2_configuration_deferred') }}</p></section>

    <section class="admin-form-section" aria-labelledby="product-pricing"><h2 id="product-pricing" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_pricing') }}</h2><div class="lf-form-group"><x-form-label for="price" :value="__('lf.LF_product_v2_list_price')" :required="true" /><input id="price" name="price" type="number" min="0" step="0.01" x-model="price" class="lf-form-control" required></div><div class="lf-form-group"><x-form-label for="currency" :value="__('lf.LF_course_product_common_currency')" :required="true" /><select id="currency" name="currency" class="lf-form-control" required>@foreach(['VND','USD','KRW'] as $currency)<option value="{{ $currency }}" @selected(old('currency',$formProduct?->currency??'VND')===$currency)>{{ $currency }}</option>@endforeach</select></div><div class="lf-form-group"><input type="hidden" name="promotion_enabled" value="0"><div class="admin-radio-group"><label><input type="checkbox" name="promotion_enabled" value="1" x-model="promotion"> {{ __('lf.LF_product_v2_apply_promotion') }}</label></div></div><div x-show="promotion"><select name="discount_type" class="lf-form-control" x-model="discountType"><option value="">{{ __('lf.LF_product_v2_select_discount') }}</option><option value="percentage">{{ __('lf.LF_product_v2_percentage') }}</option><option value="fixed_amount">{{ __('lf.LF_product_v2_fixed_amount') }}</option></select><input name="discount_value" type="number" min="0.01" step="0.01" x-model="discount" class="lf-form-control" placeholder="{{ __('lf.LF_product_v2_discount_value') }}"><input name="sale_starts_at" type="datetime-local" class="lf-form-control" value="{{ $dateValue('sale_starts_at') }}"><input name="sale_ends_at" type="datetime-local" class="lf-form-control" value="{{ $dateValue('sale_ends_at') }}"></div><div class="lf-form-group"><x-form-label for="selling_price" :value="__('lf.LF_product_v2_selling_price')" /><input id="selling_price" readonly class="lf-form-control" :value="sellingPrice()"></div></section>

    <section class="admin-form-section" aria-labelledby="product-registration"><h2 id="product-registration" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_registration') }}</h2><div class="lf-form-group"><x-form-label for="registration_starts_at" :value="__('lf.LF_course_product_common_registration_starts_at')" /><input id="registration_starts_at" name="registration_starts_at" type="datetime-local" class="lf-form-control" value="{{ $dateValue('registration_starts_at') }}" @error('registration_starts_at') aria-invalid="true" aria-describedby="registration_starts_at_error" @enderror>@error('registration_starts_at')<p id="registration_starts_at_error" class="lf-form-error">{{ $message }}</p>@enderror</div><div class="lf-form-group"><x-form-label for="registration_ends_at" :value="__('lf.LF_course_product_common_registration_ends_at')" /><input id="registration_ends_at" name="registration_ends_at" type="datetime-local" class="lf-form-control" value="{{ $dateValue('registration_ends_at') }}" @error('registration_ends_at') aria-invalid="true" aria-describedby="registration_ends_at_error" @enderror>@error('registration_ends_at')<p id="registration_ends_at_error" class="lf-form-error">{{ $message }}</p>@enderror</div></section>
    <section class="admin-form-section" aria-labelledby="product-display"><h2 id="product-display" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_display') }}</h2><input type="hidden" name="is_featured" value="0"><label><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured',$formProduct?->is_featured))> {{ __('lf.LF_course_product_common_is_featured') }}</label><div class="lf-form-group"><x-form-label for="sort_order" :value="__('lf.LF_course_product_common_sort_order')" /><input id="sort_order" name="sort_order" type="number" min="0" class="lf-form-control" value="{{ old('sort_order',$formProduct?->sort_order) }}" placeholder="{{ __('lf.LF_product_v2_auto_order') }}"></div><div class="lf-form-group"><x-form-label for="status" :value="__('lf.LF_course_product_common_status')" :required="true" /><select id="status" name="status" class="lf-form-control" required>@foreach(\App\Support\CourseProductV2::STATUSES as $status)<option value="{{ $status }}" @selected($selectedStatus===$status) @disabled($status === 'active' && ! ($hasActiveCourseVersion ?? false))>{{ __('lf.LF_course_product_common_'.$status) }}</option>@endforeach</select>@if(! ($hasActiveCourseVersion ?? false))<p class="lf-form-help">{{ __('lf.LF_product_v2_attach_before_activation') }}</p>@endif</div></section>
</div>
