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
    $star = '<span class="ui-field__required" aria-hidden="true">*</span>';
@endphp

<div x-on:ui-media-preview="openPreview($event.detail)" x-data="{
    savedVideoSource: @js($formProduct?->intro_video_source), videoFileChosen: false,
    embedUrl: @js($formProduct?->intro_video_source === 'embed' ? (string) $formProduct?->intro_video_embed_url : ''),
    previewOpen: false, preview: { name: '', url: '', mimeType: '', mediaType: '' },
    openPreview(detail) {
        if (! ['image', 'video', 'embed'].includes(detail.kind)) { window.open(detail.url, '_blank', 'noopener'); return }
        this.preview = { name: detail.name, url: detail.url, mimeType: detail.mimeType || '', mediaType: detail.kind }
        this.previewOpen = true
    },
    closePreview() { this.previewOpen = false; this.preview = { name: '', url: '', mimeType: '', mediaType: '' } },
    resetSelect(ref) { const el = this.$refs[ref]; if (! el) return; el.value = ''; el.dispatchEvent(new Event('change', { bubbles: true })) },
    category: @js($selectedCategory), template: @js($selectedTemplate), offering: @js($selectedOffering),
    customDescription: @js($customDescription), customMedia: @js($customMedia), promotion: @js($promotion),
    discountType: @js(old('discount_type', $formProduct?->discount_type)),
    currency: @js(old('currency', $formProduct?->currency ?? 'VND')),
    price: @js((string) old('price', $formProduct?->price ?? '0')),
    discount: @js((string) old('discount_value', $formProduct?->discount_value ?? '')),
    registrationStart: @js($dateValue('registration_starts_at')),
    registrationEnd: @js($dateValue('registration_ends_at')),
    promotionStart: @js($dateValue('sale_starts_at')),
    promotionEnd: @js($dateValue('sale_ends_at')),
    timeMessages: @js([
        'registrationPair' => __('lf.LF_product_v2_registration_pair_required'),
        'registrationOrder' => __('lf.LF_product_v2_registration_end_after_start'),
        'promotionPair' => __('lf.LF_product_v2_promotion_pair_required'),
        'promotionOrder' => __('lf.LF_product_v2_promotion_end_after_start'),
        'promotionOutside' => __('lf.LF_product_v2_promotion_outside_registration'),
    ]),
    priceDisplay: '', discountDisplay: '',
    generatedSlug: @js($generatedSlug),
    persistedStatus: @js((string) ($formProduct?->status ?? '')),
    persistedTemplate: @js($persistedTemplate), templateVersion: @js((string) old('template_version_id', '')), submittingProduct: false,
    templates: @js($templates),
    selectedVersion() {
        const selectedTemplate = this.templates.find(item => String(item.id) === String(this.template))
        return selectedTemplate?.published_versions?.find(version => String(version.id) === String(this.templateVersion)) ?? null
    },
    slugify(v) { return v.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') },
    sellingPrice() { let p=Number(this.price||0), d=Number(this.discount||0); if(!this.promotion) return p.toFixed(2); return Math.max(0,this.discountType==='percentage'?p-(p*d/100):p-d).toFixed(2) },
    discountAmount() { return Math.max(0, Number(this.price||0) - Number(this.sellingPrice())) },
    moneyLocale() { return document.documentElement.lang === 'vi' ? 'vi-VN' : 'en-US' },
    moneyFractionDigits() { return this.currency === 'USD' ? 2 : 0 },
    formatMoney(value) {
        return new Intl.NumberFormat(this.moneyLocale(), {
            minimumFractionDigits: this.moneyFractionDigits(),
            maximumFractionDigits: this.moneyFractionDigits(),
        }).format(Number(value || 0))
    },
    normalizeMoney(value) {
        const text = String(value ?? '').trim()
        if (!text) return ''
        if (this.moneyFractionDigits() === 0) return text.replace(/\D/g, '')
        const decimalSeparator = this.moneyLocale() === 'vi-VN' ? ',' : '.'
        const groupingSeparator = decimalSeparator === ',' ? '.' : ','
        const normalized = text.replace(new RegExp(`\\${groupingSeparator}`, 'g'), '').replace(decimalSeparator, '.').replace(/[^\d.]/g, '')
        const [whole = '', ...fraction] = normalized.split('.')
        return fraction.length ? `${whole || '0'}.${fraction.join('').slice(0, 2)}` : whole
    },
    formatMoneyInput(value) { return value === '' ? '' : this.formatMoney(value) },
    updatePrice(value) { this.price = this.normalizeMoney(value); this.priceDisplay = this.formatMoneyInput(this.price) },
    updateDiscount(value) {
        if (this.discountType === 'fixed_amount') {
            this.discount = this.normalizeMoney(value)
            this.discountDisplay = this.formatMoneyInput(this.discount)
            return
        }
        this.discount = String(value ?? '').replace(/[^\d.]/g, '')
        if (this.discountType === 'percentage' && Number(this.discount) > 100) this.discount = '100'
        this.discountDisplay = this.discount
    },
    refreshMoneyDisplays() {
        this.priceDisplay = this.formatMoneyInput(this.price)
        this.discountDisplay = this.discountType === 'fixed_amount' ? this.formatMoneyInput(this.discount) : this.discount
    },
    formatDateTime(value) {
        if (!value) return ''
        return new Intl.DateTimeFormat(this.moneyLocale(), {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: false,
        }).format(new Date(value))
    },
    validateTimeWindows() {
        const fields = [this.$refs.registrationStart, this.$refs.registrationEnd, this.$refs.promotionStart, this.$refs.promotionEnd]
        fields.forEach(field => field?.setCustomValidity(''))
        const registrationStart = this.registrationStart ? new Date(this.registrationStart) : null
        const registrationEnd = this.registrationEnd ? new Date(this.registrationEnd) : null
        const promotionStart = this.promotion && this.promotionStart ? new Date(this.promotionStart) : null
        const promotionEnd = this.promotion && this.promotionEnd ? new Date(this.promotionEnd) : null

        if (!!registrationStart !== !!registrationEnd) {
            const field = registrationStart ? this.$refs.registrationEnd : this.$refs.registrationStart
            field.setCustomValidity(this.timeMessages.registrationPair); field.reportValidity(); return false
        }
        if (registrationStart && registrationStart >= registrationEnd) {
            this.$refs.registrationEnd.setCustomValidity(this.timeMessages.registrationOrder); this.$refs.registrationEnd.reportValidity(); return false
        }
        if (!!promotionStart !== !!promotionEnd) {
            const field = promotionStart ? this.$refs.promotionEnd : this.$refs.promotionStart
            field.setCustomValidity(this.timeMessages.promotionPair); field.reportValidity(); return false
        }
        if (promotionStart && promotionStart >= promotionEnd) {
            this.$refs.promotionEnd.setCustomValidity(this.timeMessages.promotionOrder); this.$refs.promotionEnd.reportValidity(); return false
        }
        if (registrationStart && promotionStart && (promotionStart < registrationStart || promotionEnd > registrationEnd)) {
            const message = this.timeMessages.promotionOutside
                .replace(':start', this.formatDateTime(this.registrationStart))
                .replace(':end', this.formatDateTime(this.registrationEnd))
            this.$refs.promotionStart.setCustomValidity(message); this.$refs.promotionStart.reportValidity(); return false
        }
        return true
    },
    productSubmitConfirmed: false,
    async handleProductSubmit(event) {
        if (!this.validateTimeWindows()) { event.preventDefault(); event.stopPropagation(); return }
        const targetStatus = event.target.querySelector('[name=status]')?.value
        const transitionKey = `${this.persistedStatus}:${targetStatus}`
        const confirmations = @js([
            'active:inactive' => __('lf.LF_product_status_deactivate_confirm'),
            'active:draft' => __('lf.LF_product_status_draft_confirm'),
            'inactive:draft' => __('lf.LF_product_status_draft_confirm'),
            'draft:active' => __('lf.LF_product_status_activate_confirm'),
            'inactive:active' => __('lf.LF_product_status_activate_confirm'),
        ]);
        const confirmationMessages = []
        if (this.persistedTemplate && this.template !== this.persistedTemplate) confirmationMessages.push(@js(__('lf.LF_product_v2_template_change_confirm')))
        if (this.persistedStatus && targetStatus !== this.persistedStatus && confirmations[transitionKey]) confirmationMessages.push(confirmations[transitionKey])
        if (!this.productSubmitConfirmed && confirmationMessages.length) {
            event.preventDefault(); event.stopPropagation()
            const form = event.target; const submitter = event.submitter
            for (const message of confirmationMessages) {
                if (!await window.LFConfirm.open({ message, trigger: submitter })) return
            }
            this.productSubmitConfirmed = true
            form.requestSubmit(submitter || undefined)
            return
        }
        if (this.$refs.priceInput) this.$refs.priceInput.value = this.price
        if (this.$refs.discountInput) this.$refs.discountInput.value = this.discount
        this.productSubmitConfirmed = false
    }
}" x-init="refreshMoneyDisplays(); $nextTick(() => { template = @js($selectedTemplate) }); $el.closest('form')?.addEventListener('submit', event => handleProductSubmit(event))"
   class="ui-fieldset-reset">
    <fieldset class="ui-fieldset-reset" @disabled($formProduct?->status === 'archived')>
        <div class="ui-modal__grid">

            <div class="ui-field">
                <label class="ui-field__label" for="product-form-category_id">{!! $star !!}{{ __('lf.LF_product_v2_category') }}</label>
                @if($canChangeTemplate)
                    <select id="product-form-category_id" name="category_id" data-ui-kit-select required x-ref="categorySelect"
                            data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}"
                            x-on:change="category = $event.target.value; if (! templates.some(t => String(t.id) === template && String(t.category_id) === category)) { template = ''; templateVersion = ''; resetSelect('templateSelect'); resetSelect('versionSelect') }">
                        <option value="">{{ __('lf.LF_product_v2_select_category') }}</option>
                        @foreach($categories as $categoryOption)
                            <option value="{{ $categoryOption->id }}" @selected((string) $categoryOption->id === $selectedCategory)>{{ $categoryOption->name }}</option>
                        @endforeach
                    </select>
                @else
                    <input type="hidden" name="category_id" value="{{ $selectedCategory }}">
                    <input id="product-form-category_id" type="text" readonly value="{{ $initialCategoryState?->name ?? '—' }}">
                @endif
            </div>

            <div class="ui-field">
                <label class="ui-field__label" for="product-form-template_id">{!! $star !!}{{ __('lf.LF_product_v2_template') }}</label>
                @if($canChangeTemplate)
                    <select id="product-form-template_id" name="template_id" data-ui-kit-select required x-ref="templateSelect"
                            data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}"
                            x-on:change="template = $event.target.value; templateVersion = ''; resetSelect('versionSelect')">
                        <option value="">{{ __('lf.LF_product_v2_select_template') }}</option>
                        @foreach($templates as $templateOption)
                            <option value="{{ $templateOption->id }}"
                                    :disabled="String({{ (int) $templateOption->category_id }}) !== String(category)"
                                    @disabled((string) $templateOption->category_id !== $selectedCategory)
                                    @selected((string) $templateOption->id === $selectedTemplate)>{{ $templateOption->name }}@if($templateOption->is_historical_binding) — {{ $templateOption->status_label }}@endif</option>
                        @endforeach
                    </select>
                    <span class="ui-field__hint" x-show="category && !templates.some(item => String(item.category_id) === String(category))" x-cloak>{{ __('lf.LF_product_v2_no_templates_for_category') }}</span>
                    @if($initialTemplateState?->is_historical_binding)
                        <span class="ui-field__hint">{{ __('lf.LF_product_v2_historical_template_status', ['status' => $initialTemplateState->status_label]) }}</span>
                    @endif
                @else
                    <input type="hidden" name="template_id" value="{{ $persistedTemplate }}">
                    <input id="product-form-template_id" type="text" readonly aria-describedby="product-form-template-policy"
                           value="{{ $initialTemplateState?->name ?? '—' }}">
                    <span id="product-form-template-policy" class="ui-field__hint">{{ __(match($templateLockReason ?? $templateChange['reason'] ?? null) {
                        'used' => 'lf.LF_product_v2_template_change_used',
                        'archived' => 'lf.LF_product_v2_template_change_archived',
                        default => 'lf.LF_product_v2_template_change_draft_required',
                    }) }}</span>
                @endif
            </div>

            @if(! $formProduct || $canChangeTemplate)
                <div class="ui-field" @if($formProduct) x-show="template && template !== persistedTemplate" x-cloak @endif>
                    <label class="ui-field__label" for="product-form-template_version_id">{!! $star !!}{{ $formProduct ? __('lf.LF_product_v2_template_change_version') : __('lf.LF_product_v2_course_version') }}</label>
                    <select id="product-form-template_version_id" name="template_version_id" data-ui-kit-select x-ref="versionSelect"
                            data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}"
                            x-on:change="templateVersion = $event.target.value"
                            @if($formProduct) x-bind:required="template && template !== persistedTemplate" @else x-bind:required="Boolean(template)" @endif>
                        <option value="">{{ $formProduct ? __('lf.LF_course_product_item_common_select_version') : __('lf.LF_product_v2_select_published_version') }}</option>
                        @foreach($templates as $templateOption)
                            @foreach($templateOption->published_versions as $versionOption)
                                <option value="{{ $versionOption['id'] }}"
                                        :disabled="String({{ (int) $templateOption->id }}) !== String(template)"
                                        @disabled((string) $templateOption->id !== $selectedTemplate)>{{ $versionOption['number_label'] }} · {{ $versionOption['code'] }}@if($versionOption['published_at']) · {{ $versionOption['published_at'] }}@endif</option>
                            @endforeach
                        @endforeach
                    </select>
                    <span class="ui-field__hint">{{ __('lf.LF_product_v2_course_version_help') }}</span>
                </div>
            @endif

            @if($formProduct)
                <div class="ui-field ui-field--full">
                    <template x-for="item in templates.filter(t => String(t.id) === String(template))" :key="`version-${item.id}`">
                        <div class="ui-note-card" aria-live="polite">
                            <template x-if="item.version_summary">
                                <div>
                                    <p class="ui-note-card__title">{{ __('lf.LF_product_v2_version_in_use') }}</p>
                                    <p class="ui-note-card__text"><span x-text="item.version_summary.code"></span> · <span x-text="item.version_summary.status_label"></span></p>
                                    <p class="ui-caption"><span x-text="item.version_summary.number_label"></span> · <span x-text="item.version_summary.lesson_text"></span> · <span x-text="item.version_summary.activity_text"></span></p>
                                </div>
                            </template>
                            <p x-show="!item.version_summary" class="ui-note-card__title">{{ __('lf.LF_product_v2_version_in_use') }}</p>
                            <p x-show="!item.version_summary && !item.integrity_warning" class="ui-caption" role="status">{{ __('lf.LF_product_v2_missing_version') }}</p>
                            <p x-show="item.integrity_warning" class="ui-field__error" role="alert" x-text="item.integrity_warning"></p>
                        </div>
                    </template>
                </div>
            @endif

            <div class="ui-modal__row-break" aria-hidden="true"></div>
            <div class="ui-field">
                <label class="ui-field__label" for="product-form-title">{!! $star !!}{{ __('lf.LF_course_product_common_title_field') }}</label>
                <input id="product-form-title" type="text" name="title" value="{{ $formProduct?->title }}" required maxlength="255"
                       placeholder="{{ __('lf.LF_product_v2_placeholder_name') }}">
            </div>

            <div class="ui-field">
                <label class="ui-field__label" for="product-form-offering_type">{!! $star !!}{{ __('lf.LF_product_v2_offering_type') }}</label>
                <select id="product-form-offering_type" name="offering_type" data-ui-kit-select required
                        data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}"
                        x-on:change="offering = $event.target.value">
                    <option value="">{{ __('lf.LF_product_v2_select_offering') }}</option>
                    @foreach(\App\Support\CourseProductV2::OFFERING_TYPES as $type)
                        <option value="{{ $type }}" @selected($selectedOffering === $type)>{{ __('lf.LF_product_v2_offering_'.$type) }}</option>
                    @endforeach
                </select>
            </div>

            @if($formProduct)
                <div class="ui-field">
                    <label class="ui-field__label" for="product-form-product_code">{{ __('lf.LF_course_product_common_product_code') }}</label>
                    <input id="product-form-product_code" type="text" readonly value="{{ $formProduct->product_code }}">
                </div>
            @endif

            <div class="ui-field" x-show="offering === 'self_paced_course'" x-cloak>
                <label class="ui-field__label" for="product-form-access_duration_days"><span x-show="offering === 'self_paced_course'" x-cloak>{!! $star !!}</span>{{ __('lf.LF_product_v2_access_days') }}</label>
                <input id="product-form-access_duration_days" type="number" min="1" max="36500" step="1" inputmode="numeric" name="access_duration_days"
                       x-bind:required="offering === 'self_paced_course'"
                       value="{{ $formProduct?->access_duration_days }}" placeholder="{{ __('lf.LF_product_v2_placeholder_access_days') }}">
            </div>
            <div class="ui-field" x-show="offering === 'self_paced_course'" x-cloak>
                <label class="ui-field__label" for="product-form-review_duration_days">{{ __('lf.LF_product_v2_review_days') }}</label>
                <input id="product-form-review_duration_days" type="number" min="0" max="36500" step="1" inputmode="numeric" name="review_duration_days"
                       value="{{ $formProduct?->review_duration_days }}" placeholder="{{ __('lf.LF_product_v2_placeholder_review_days') }}">
            </div>

            <div class="ui-modal__row-break" aria-hidden="true"></div>
            <div class="ui-field">
                <input type="hidden" name="uses_custom_description" value="0">
                <label class="ui-check">
                    <input type="checkbox" name="uses_custom_description" value="1" x-model="customDescription">
                    <span>{{ __('lf.LF_product_v2_custom_description') }}</span>
                </label>
                <span class="ui-field__hint">{{ __('lf.LF_product_v2_description_inherited') }}</span>
            </div>
            <div class="ui-field ui-field--full" x-show="customDescription" x-cloak>
                <label class="ui-field__label" for="product-form-short_description">{{ __('lf.LF_course_product_common_short_description') }}</label>
                <textarea id="product-form-short_description" name="short_description" rows="2" maxlength="500"
                          placeholder="{{ __('lf.LF_product_v2_placeholder_short_description') }}">{{ $formProduct?->short_description }}</textarea>
            </div>
            <div class="ui-field ui-field--full" x-show="customDescription" x-cloak>
                <label class="ui-field__label" for="product-form-description">{{ __('lf.LF_course_product_common_description') }}</label>
                <textarea id="product-form-description" name="description" rows="4"
                          placeholder="{{ __('lf.LF_product_v2_placeholder_description') }}">{{ $formProduct?->description }}</textarea>
            </div>

            <div class="ui-field">
                <input type="hidden" name="uses_custom_intro_media" value="0">
                <label class="ui-check">
                    <input type="checkbox" name="uses_custom_intro_media" value="1" x-model="customMedia">
                    <span>{{ __('lf.LF_product_v2_custom_media') }}</span>
                </label>
                <span class="ui-field__hint">{{ __('lf.LF_product_v2_media_inherited') }}</span>
            </div>

            <div class="ui-field ui-field--full" x-show="customMedia" x-cloak
                 x-on:change="if ($event.target.name === 'intro_video_file') { videoFileChosen = ($event.target.files?.length ?? 0) > 0; if (videoFileChosen) $root.querySelector('[data-ui-embed-slot]')?.dispatchEvent(new CustomEvent('ui-embed-clear')); }"
                 x-on:ui-embed-change="embedUrl = $event.detail.url; if (embedUrl) { videoFileChosen = false; $root.querySelector('[data-ui-media-slot][data-kind=video]')?.dispatchEvent(new CustomEvent('ui-media-clear')); }">
                <input type="hidden" name="intro_video_source" :value="embedUrl ? 'embed' : (videoFileChosen ? 'upload' : (savedVideoSource === 'upload' ? 'upload' : ''))">
                <div class="ui-field-row">
                    <div class="ui-field">
                        <p class="ui-field__label" id="product-intro-image-label">{{ __('lf.LF_course_template_intro_image') }}</p>
                        <x-ui-media-slot id="intro_image_file" name="intro_image_file" accept="image/*" kind="image"
                            labelledby="product-intro-image-label"
                            :presentation="($introMedia['intro_image'] ?? null) ? $introImageThumbnail : null"
                            :display-name="($introMedia['intro_image'] ?? null)?->display_name"
                            :preview-url="($introMedia['intro_image'] ?? null)?->signed_url"
                            :mime="($introMedia['intro_image'] ?? null)?->mime_type"
                            remove-name="remove_intro_image"
                            :view-label="__('lf.LF_media_file_common_preview_action')"
                            :remove-label="__('lf.LF_course_template_remove_current_image')">
                            <x-upload-hint ui-kit :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
                        </x-ui-media-slot>
                    </div>
                    <div class="ui-field">
                        <p class="ui-field__label" id="product-intro-video-label">{{ __('lf.LF_course_template_intro_video') }}</p>
                        <x-ui-media-slot id="intro_video_file" name="intro_video_file" accept="video/*" kind="video" focus-alias="intro_video_source"
                            labelledby="product-intro-video-label"
                            :presentation="($introMedia['intro_video'] ?? null) ? $introVideoThumbnail : null"
                            :display-name="($introMedia['intro_video'] ?? null)?->display_name"
                            :preview-url="($introMedia['intro_video'] ?? null)?->signed_url"
                            :mime="($introMedia['intro_video'] ?? null)?->mime_type"
                            remove-name="remove_intro_video"
                            :view-label="__('lf.LF_media_file_common_preview_action')"
                            :remove-label="__('lf.LF_course_template_remove_current_video')">
                            <x-upload-hint ui-kit :formats="['MP4', 'WEBM', 'MOV', 'AVI']" :max-size="\App\Support\UploadLimit::effectiveVideoKilobytes()" />
                        </x-ui-media-slot>
                    </div>
                    <div class="ui-field">
                        <p class="ui-field__label" id="product-intro-embed-label">{{ __('lf.LF_course_template_video_embed') }}</p>
                        <x-ui-embed-slot id="product-form-intro_video_embed_url" name="intro_video_embed_url"
                            labelledby="product-intro-embed-label"
                            :value="$formProduct?->intro_video_source === 'embed' ? $formProduct?->intro_video_embed_url : null"
                            :thumbnail="($introVideoThumbnail['state'] ?? null) === 'provider_video_thumbnail' ? ($introVideoThumbnail['url'] ?? null) : null"
                            :preview-url="$introVideoEmbedUrl"
                            :display-name="ucfirst((string) $formProduct?->intro_video_provider)">
                            {{ __('lf.LF_course_template_embed_hint') }}
                        </x-ui-embed-slot>
                    </div>
                    <div class="ui-field">
                        <p class="ui-field__label" id="product-intro-document-label">{{ __('lf.LF_course_template_intro_document') }}</p>
                        <x-ui-media-slot id="intro_document_file" name="intro_document_file"
                            accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx" :kind="$introDocumentThumbnail['kind'] ?? 'document'"
                            labelledby="product-intro-document-label"
                            :presentation="($introMedia['intro_document'] ?? null) ? $introDocumentThumbnail : null"
                            :display-name="($introMedia['intro_document'] ?? null)?->display_name"
                            :preview-url="($introMedia['intro_document'] ?? null)?->signed_url"
                            :mime="($introMedia['intro_document'] ?? null)?->mime_type"
                            remove-name="remove_intro_document"
                            :view-label="__('lf.LF_media_file_common_preview_action')"
                            :remove-label="__('lf.LF_course_template_remove_current_document')">
                            <x-upload-hint ui-kit :formats="['PDF', 'DOC', 'DOCX', 'PPT', 'PPTX', 'XLS', 'XLSX']" />
                        </x-ui-media-slot>
                    </div>
                </div>
            </div>

            <div class="ui-modal__row-break" aria-hidden="true"></div>
            <div class="ui-field">
                <label class="ui-field__label" for="product-form-price">{!! $star !!}{{ __('lf.LF_product_v2_list_price') }}</label>
                <input id="product-form-price" name="price" type="text" inputmode="decimal" autocomplete="off" required maxlength="22"
                       x-ref="priceInput" x-model="priceDisplay" x-on:input="updatePrice($event.target.value)">
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="product-form-currency">{!! $star !!}{{ __('lf.LF_course_product_common_currency') }}</label>
                <select id="product-form-currency" name="currency" data-ui-kit-select required
                        data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}"
                        x-on:change="currency = $event.target.value; refreshMoneyDisplays()">
                    @foreach(['VND', 'USD', 'KRW'] as $currencyOption)
                        <option value="{{ $currencyOption }}" @selected(($formProduct?->currency ?? 'VND') === $currencyOption)>{{ $currencyOption }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ui-note-card" aria-live="polite">
                <p class="ui-note-card__title">{{ __('lf.LF_product_v2_selling_price') }}</p>
                <p class="ui-note-card__text"><strong><span x-text="formatMoney(sellingPrice())"></span> <span x-text="currency"></span></strong><span x-show="promotion && discountType && Number(discount || 0) > 0" x-cloak> · {{ __('lf.LF_product_v2_save') }} <span x-text="formatMoney(discountAmount())"></span> <span x-text="currency"></span><span x-show="discountType === 'percentage'"> · <span x-text="`${Number(discount || 0)}%`"></span></span></span></p>
            </div>

            <div class="ui-field">
                <label class="ui-field__label" for="product-form-registration_starts_at"><span x-show="registrationStart || registrationEnd" x-cloak>{!! $star !!}</span>{{ __('lf.LF_course_product_common_registration_starts_at') }}</label>
                <x-ui-date-input time id="product-form-registration_starts_at" name="registration_starts_at" :value="$dateValue('registration_starts_at')"
                                 x-ref="registrationStart" x-model="registrationStart" x-on:input="$el.setCustomValidity('')" />
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="product-form-registration_ends_at"><span x-show="registrationStart || registrationEnd" x-cloak>{!! $star !!}</span>{{ __('lf.LF_course_product_common_registration_ends_at') }}</label>
                <x-ui-date-input time id="product-form-registration_ends_at" name="registration_ends_at" :value="$dateValue('registration_ends_at')"
                                 x-ref="registrationEnd" x-model="registrationEnd" x-on:input="$el.setCustomValidity('')" />
            </div>

            <div class="ui-field ui-field--full">
                <input type="hidden" name="promotion_enabled" value="0">
                <label class="ui-check">
                    <input type="checkbox" name="promotion_enabled" value="1" x-model="promotion">
                    <span>{{ __('lf.LF_product_v2_apply_promotion') }}</span>
                </label>
                <span class="ui-field__hint">{{ __('lf.LF_product_v2_promotion_help') }}</span>
            </div>

            <div class="ui-field" x-show="promotion" x-cloak>
                <label class="ui-field__label" for="product-form-discount_type">{!! $star !!}{{ __('lf.LF_product_v2_discount_type') }}</label>
                <select id="product-form-discount_type" name="discount_type" data-ui-kit-select x-bind:required="promotion"
                        data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}"
                        x-on:change="discountType = $event.target.value; refreshMoneyDisplays()">
                    <option value="" @selected(! $formProduct?->discount_type)>{{ __('lf.LF_product_v2_select_discount') }}</option>
                    <option value="percentage" @selected($formProduct?->discount_type === 'percentage')>{{ __('lf.LF_product_v2_percentage') }}</option>
                    <option value="fixed_amount" @selected($formProduct?->discount_type === 'fixed_amount')>{{ __('lf.LF_product_v2_fixed_amount') }}</option>
                </select>
            </div>
            <div class="ui-field" x-show="promotion" x-cloak>
                <label class="ui-field__label" for="product-form-discount_value">{!! $star !!}{{ __('lf.LF_product_v2_discount_value_label') }}</label>
                <input id="product-form-discount_value" name="discount_value" type="text" inputmode="decimal" autocomplete="off" maxlength="22" x-bind:required="promotion"
                       x-ref="discountInput" x-model="discountDisplay" x-on:input="updateDiscount($event.target.value)"
                       placeholder="{{ __('lf.LF_product_v2_discount_value') }}">
            </div>
            <div x-show="promotion" x-cloak aria-hidden="true"></div>
            <div class="ui-field" x-show="promotion" x-cloak>
                <label class="ui-field__label" for="product-form-sale_starts_at"><span x-show="promotionStart || promotionEnd" x-cloak>{!! $star !!}</span>{{ __('lf.LF_product_v2_promotion_starts_at') }}</label>
                <x-ui-date-input time id="product-form-sale_starts_at" name="sale_starts_at" :value="$dateValue('sale_starts_at')"
                                 x-bind:min="registrationStart || null" x-bind:max="registrationEnd || null"
                                 x-ref="promotionStart" x-model="promotionStart" x-on:input="$el.setCustomValidity('')" />
            </div>
            <div class="ui-field" x-show="promotion" x-cloak>
                <label class="ui-field__label" for="product-form-sale_ends_at"><span x-show="promotionStart || promotionEnd" x-cloak>{!! $star !!}</span>{{ __('lf.LF_product_v2_promotion_ends_at') }}</label>
                <x-ui-date-input time id="product-form-sale_ends_at" name="sale_ends_at" :value="$dateValue('sale_ends_at')"
                                 x-bind:min="registrationStart || null" x-bind:max="registrationEnd || null"
                                 x-ref="promotionEnd" x-model="promotionEnd" x-on:input="$el.setCustomValidity('')" />
            </div>

            <div class="ui-modal__row-break" aria-hidden="true"></div>
            <div class="ui-field">
                <label class="ui-field__label" for="product-form-status">{!! $star !!}{{ __('lf.LF_course_product_common_status') }}</label>
                <select id="product-form-status" name="status" data-ui-kit-select required
                        data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}">
                    @foreach(($allowedStatuses ?? ['draft']) as $statusOption)
                        <option value="{{ $statusOption }}" @selected($selectedStatus === $statusOption) @disabled($statusOption === 'active' && ! ($hasActiveCourseVersion ?? false))>{{ __('lf.LF_course_product_common_'.$statusOption) }}</option>
                    @endforeach
                </select>
                @if(! ($hasActiveCourseVersion ?? false))
                    <span class="ui-field__hint">{{ __('lf.LF_product_v2_attach_before_activation') }}</span>
                @endif
                @if($formProduct && ! in_array('draft', $allowedStatuses ?? [], true) && $formProduct->status !== 'draft')
                    <span class="ui-field__hint">{{ __('lf.LF_product_status_used_draft_help') }}</span>
                @endif
            </div>

            <div class="ui-field">
                <input type="hidden" name="is_featured" value="0">
                <label class="ui-check ui-switch">
                    <input id="product-form-is_featured" type="checkbox" role="switch" name="is_featured" value="1" @checked($formProduct?->is_featured)>
                    <span>{{ __('lf.LF_course_product_common_is_featured') }}</span>
                </label>
            </div>
        </div>
    </fieldset>

    <x-media-preview-modal ui-kit id="course-product-preview-title" show="previewOpen" close="closePreview()" title="preview.name" compact="false">
        <template x-if="previewOpen && preview.mediaType === 'image'"><img x-bind:src="preview.url" x-bind:alt="preview.name"></template>
        <template x-if="previewOpen && preview.mediaType === 'video'"><video controls preload="metadata" autoplay x-bind:src="preview.url"></video></template>
        <template x-if="previewOpen && preview.mediaType === 'embed'">
            <iframe x-bind:src="preview.url" x-bind:title="preview.name" loading="lazy"
                    sandbox="allow-scripts allow-same-origin allow-presentation"
                    allow="fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
        </template>
    </x-media-preview-modal>
</div>
