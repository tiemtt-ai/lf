@php
    $formProduct = $product ?? null;
    $selectedCategory = (string) old('category_id', $formProduct?->category_id);
    $persistedTemplate = (string) ($selectedTemplateId ?? '');
    $selectedTemplate = $formProduct && $errors->has('template_id')
        ? $persistedTemplate
        : (string) old('template_id', $persistedTemplate);
    $canChangeTemplate = ! $formProduct || ($canChangeTemplate ?? $templateChange['allowed'] ?? true);
    $pickerTemplates ??= [];
    $boundCourseType ??= null;
    $selectedStatus = old('status', $formProduct?->status ?? 'draft');
    if ($selectedStatus === 'active' && ! ($hasActiveCourseVersion ?? false)) {
        $selectedStatus = 'draft';
    }
    $customDescription = (bool) old('uses_custom_description', $formProduct?->uses_custom_description ?? false);
    $customMedia = (bool) old('uses_custom_intro_media', $formProduct?->uses_custom_intro_media ?? false);
    $promotion = (bool) old('promotion_enabled', $formProduct?->promotion_enabled ?? false);
    $generatedSlug = old('slug', $formProduct?->slug ?? '');
    $pickerCategories = collect($pickerTemplates)->filter(fn (array $row) => $row['category_id'] !== null)
        ->unique('category_id')->sortBy('category_name')->values();
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
    category: @js($selectedCategory), template: @js($selectedTemplate),
    pickerTemplates: @js($pickerTemplates), pickerTab: 'all', pickerQuery: '', pickerCategory: '', templateMissing: false, boundType: @js($boundCourseType),
    categoryNames: @js($categories->pluck('name', 'id')),
    courseTypeLabels: @js(collect(\App\Support\CourseType::VALUES)->mapWithKeys(fn ($type) => [$type => __(\App\Support\CourseType::labelKey($type))])),
    pickedTemplate() { return this.pickerTemplates.find(item => String(item.id) === String(this.template)) ?? null },
    // ADR-0022: the Product course type is the type of the Version it binds (a Draft without one shows the Template type).
    courseType() {
        const version = this.selectedVersion()
        if (version?.course_type) return version.course_type
        if (this.template === this.persistedTemplate && this.boundType) return this.boundType
        return this.pickedTemplate()?.course_type ?? ''
    },
    pickerRows() {
        const query = this.pickerQuery.trim().toLocaleLowerCase()
        return this.pickerTemplates.filter(item => (this.pickerTab === 'all' || item.course_type === this.pickerTab)
            && (! this.pickerCategory || String(item.category_id) === this.pickerCategory)
            && (! query || [item.title, item.version_code, item.category_name].some(text => String(text ?? '').toLocaleLowerCase().includes(query))))
    },
    pickerCount(tab) { return this.pickerTemplates.filter(item => tab === 'all' || item.course_type === tab).length },
    openPicker() { this.pickerQuery = ''; this.$refs.templatePicker.showModal(); this.$nextTick(() => this.$refs.pickerSearch?.focus()) },
    chooseTemplate(row) {
        if (! row.eligible) return
        // The category and the version follow the Template; a typed commercial name is never overwritten.
        this.category = String(row.category_id)
        this.template = String(row.id)
        this.templateMissing = false
        const latest = this.templates.find(item => String(item.id) === this.template)?.published_versions?.[0]
        this.templateVersion = latest && this.template !== this.persistedTemplate ? String(latest.id) : ''
        this.$nextTick(() => {
            const versionSelect = this.$refs.versionSelect
            if (versionSelect) { versionSelect.value = this.templateVersion; versionSelect.dispatchEvent(new Event('change', { bubbles: true })) }
        })
        const title = this.$refs.titleInput
        if (title && ! title.value.trim()) { title.value = row.title; title.dispatchEvent(new Event('input', { bubbles: true })) }
        this.$refs.templatePicker.close()
    },
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
        if (!this.template) { event.preventDefault(); event.stopPropagation(); this.templateMissing = true; this.$root.querySelector('[data-template-picker-open]')?.focus(); return }
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

            {{-- The Template comes first: the category, the course type and the version follow from it. --}}
            <input type="hidden" name="category_id" value="{{ $selectedCategory }}" x-model="category">

            <div class="ui-field ui-field--full" data-ui-error-for="template_id">
                <label class="ui-field__label" for="product-form-template_id">{!! $star !!}{{ __('lf.LF_product_v2_template') }}</label>
                @if($canChangeTemplate)
                    {{-- ADR-0022: the Template is chosen in a picker filtered by course type; the field only shows the choice. --}}
                    <input type="hidden" name="template_id" x-model="template">
                    <button id="product-form-template_id" type="button" class="ui-template-picker-trigger cursor-interaction"
                            x-on:click="openPicker()" aria-haspopup="dialog" data-template-picker-open>
                        <span class="ui-template-picker-trigger__text" x-text="pickedTemplate()?.title ?? templates.find(item => String(item.id) === String(template))?.name ?? @js(__('lf.LF_product_v2_select_template'))"
                              x-bind:class="{ 'is-placeholder': ! template }">{{ __('lf.LF_product_v2_select_template') }}</span>
                        <x-backend-icon name="search" class="ui-icon" />
                    </button>
                    <span class="ui-field__error" role="alert" x-show="templateMissing" x-cloak>{{ __('lf.LF_product_template_required') }}</span>
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

            @if($canChangeTemplate)
                <dialog x-ref="templatePicker" class="ui-modal ui-template-picker" data-columns="2" aria-labelledby="product-template-picker-title"
                        x-on:click="if ($event.target === $el) $el.close()">
                    <header class="ui-modal__header">
                        <div>
                            <h3 id="product-template-picker-title">{{ __('lf.LF_product_template_picker_title') }}</h3>
                            <span class="ui-caption">{{ __('lf.LF_product_template_picker_help') }}</span>
                        </div>
                        <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.templatePicker.close()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
                    </header>
                    <div class="ui-modal__body">
                        <div class="ui-tabs ui-template-picker__tabs" role="tablist">
                            @foreach (['all', ...\App\Support\CourseType::VALUES] as $pickerTab)
                                <button type="button" role="tab" class="ui-tabs__tab cursor-interaction" data-template-picker-tab="{{ $pickerTab }}"
                                        x-bind:aria-selected="(pickerTab === '{{ $pickerTab }}').toString()" x-on:click="pickerTab = '{{ $pickerTab }}'">
                                    {{ $pickerTab === 'all' ? __('lf.LF_course_type_all_short') : __(\App\Support\CourseType::labelKey($pickerTab)) }}
                                    <span class="ui-tab-count" x-text="pickerCount('{{ $pickerTab }}')"></span>
                                </button>
                            @endforeach
                        </div>
                        <div class="ui-template-picker__filters">
                            <div class="ui-search ui-template-picker__search">
                                <input type="search" x-ref="pickerSearch" x-model="pickerQuery" aria-label="{{ __('lf.LF_product_template_picker_search') }}"
                                       placeholder="{{ __('lf.LF_product_template_picker_search') }}">
                            </div>
                            <select aria-label="{{ __('lf.LF_product_v2_category') }}" data-ui-kit-select data-template-picker-category
                                    data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}"
                                    x-on:change="pickerCategory = $event.target.value">
                                <option value="">{{ __('lf.LF_product_template_picker_all_categories') }}</option>
                                @foreach ($pickerCategories as $pickerCategoryOption)
                                    <option value="{{ $pickerCategoryOption['category_id'] }}">{{ $pickerCategoryOption['category_name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <ul class="ui-template-picker__list" role="listbox" aria-labelledby="product-template-picker-title">
                            <template x-for="row in pickerRows()" :key="row.id">
                                <li role="option" class="ui-template-picker__row" data-template-picker-row
                                    x-bind:data-template-id="row.id" x-bind:aria-selected="(String(row.id) === String(template)).toString()"
                                    x-bind:aria-disabled="(! row.eligible).toString()"
                                    x-bind:class="{ 'is-disabled': ! row.eligible, 'is-selected': String(row.id) === String(template) }"
                                    x-bind:tabindex="row.eligible ? 0 : -1"
                                    x-on:click="chooseTemplate(row)" x-on:keydown.enter.prevent="chooseTemplate(row)">
                                    <span class="ui-template-picker__main">
                                        <b x-text="row.title"></b>
                                        {{-- One line of facts, so every row has the same shape. --}}
                                        <span class="ui-caption" x-text="[row.version_code, row.category_name, row.version_label ?? @js(__('lf.LF_product_template_picker_no_version')), @js(__('lf.LF_product_template_picker_products')).replace(':count', row.product_count)].filter(Boolean).join(' · ')"></span>
                                        <span class="ui-template-picker__reason" x-show="row.reason" x-text="row.reason"></span>
                                    </span>
                                    <span class="ui-course-type" x-bind:class="'ui-course-type--' + row.course_type" x-text="row.course_type_label"></span>
                                    <span class="ui-template-picker__check" aria-hidden="true" x-bind:class="{ 'is-on': String(row.id) === String(template) }"><x-backend-icon name="check" class="ui-icon" /></span>
                                </li>
                            </template>
                            {{-- Rows that cannot be chosen sit under this heading (CSS order), after the choosable ones. --}}
                            <li class="ui-template-picker__group" role="presentation" x-show="pickerRows().some(row => ! row.eligible)">{{ __('lf.LF_product_template_picker_blocked') }}</li>
                            <li class="ui-template-picker__empty" x-show="pickerRows().length === 0">{{ __('lf.LF_product_template_picker_empty') }}</li>
                        </ul>
                    </div>
                </dialog>
            @endif

            <div class="ui-field ui-field--full" x-show="template" x-cloak data-product-binding>
                <div class="ui-product-binding">
                    <div class="ui-product-binding__item" data-ui-error-for="category_id">
                        <span class="ui-field__label">{{ __('lf.LF_product_v2_category') }}</span>
                        <span class="ui-product-binding__value" x-text="categoryNames[category] ?? '—'"></span>
                    </div>
                    <div class="ui-product-binding__item">
                        <span class="ui-field__label">{{ __('lf.LF_course_type_label') }}</span>
                        <span class="ui-course-type-readonly" data-product-course-type>
                            <template x-if="courseType()">
                                <span class="ui-course-type" x-bind:class="'ui-course-type--' + courseType()" x-bind:data-course-type="courseType()" x-text="courseTypeLabels[courseType()]"></span>
                            </template>
                        </span>
                    </div>
                    <div class="ui-product-binding__item ui-product-binding__version">
                        @if(! $formProduct || $canChangeTemplate)
                            <div class="ui-field" x-show="template !== persistedTemplate" x-cloak>
                                <span class="ui-field__label ui-field__label--tip">
                                    <label for="product-form-template_version_id">{!! $star !!}{{ $formProduct ? __('lf.LF_product_v2_template_change_version') : __('lf.LF_product_v2_course_version') }}</label>
                                    <x-info-tip align="end" :text="__('lf.LF_product_v2_course_version_help')" />
                                </span>
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
                            </div>
                        @endif
                        @if($formProduct)
                            <div x-show="template === persistedTemplate" x-cloak>
                                <template x-for="item in templates.filter(t => String(t.id) === String(template))" :key="`version-${item.id}`">
                                    <div aria-live="polite">
                                        <span class="ui-field__label">{{ __('lf.LF_product_v2_version_in_use') }}</span>
                                        <template x-if="item.version_summary">
                                            <div>
                                                <span class="ui-product-binding__value"><span x-text="item.version_summary.code"></span> · <span x-text="item.version_summary.status_label"></span></span>
                                                <span class="ui-caption"><span x-text="item.version_summary.number_label"></span> · <span x-text="item.version_summary.lesson_text"></span> · <span x-text="item.version_summary.activity_text"></span></span>
                                            </div>
                                        </template>
                                        <span x-show="!item.version_summary && !item.integrity_warning" class="ui-caption" role="status">{{ __('lf.LF_product_v2_missing_version') }}</span>
                                        <span x-show="item.integrity_warning" class="ui-field__error" role="alert" x-text="item.integrity_warning"></span>
                                    </div>
                                </template>
                            </div>
                        @endif
                    </div>
                    <span class="ui-field__hint ui-product-binding__hint">{{ __('lf.LF_course_type_product_help') }}</span>
                </div>
            </div>

            <div class="ui-modal__row-break" aria-hidden="true"></div>
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="product-form-title">{!! $star !!}{{ __('lf.LF_product_commercial_name') }}</label>
                <input id="product-form-title" type="text" name="title" value="{{ $formProduct?->title }}" required maxlength="255" x-ref="titleInput"
                       placeholder="{{ __('lf.LF_product_v2_placeholder_name') }}">
                <span class="ui-field__hint">{{ __('lf.LF_product_commercial_name_help') }}</span>
            </div>

            <div class="ui-field" x-show="courseType() === 'self_paced'" x-cloak>
                <label class="ui-field__label" for="product-form-access_duration_days"><span x-show="courseType() === 'self_paced'" x-cloak>{!! $star !!}</span>{{ __('lf.LF_product_v2_access_days') }}</label>
                <input id="product-form-access_duration_days" type="number" min="1" max="36500" step="1" inputmode="numeric" name="access_duration_days"
                       x-bind:required="courseType() === 'self_paced'"
                       value="{{ $formProduct?->access_duration_days }}" placeholder="{{ __('lf.LF_product_v2_placeholder_access_days') }}">
            </div>
            <div class="ui-field" x-show="courseType() === 'self_paced'" x-cloak>
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
                    {{-- Filled slots come first; each keeps its fixed order within its group. Real DOM order, so Tab follows what is seen. --}}
                    @php
                        $mediaSlots = [];
                        $mediaFilled = [
                            'image' => (bool) ($introMedia['intro_image'] ?? null),
                            'video' => (bool) ($introMedia['intro_video'] ?? null),
                            'embed' => $formProduct?->intro_video_source === 'embed' && filled($formProduct?->intro_video_embed_url),
                            'document' => (bool) ($introMedia['intro_document'] ?? null),
                        ];
                    @endphp
                    @php ob_start(); @endphp
                    <div class="ui-field" data-media-slot-field="image">
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
                    @php $mediaSlots['image'] = ob_get_clean(); @endphp
                    @php ob_start(); @endphp
                    <div class="ui-field" data-media-slot-field="video">
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
                    @php $mediaSlots['video'] = ob_get_clean(); @endphp
                    @php ob_start(); @endphp
                    <div class="ui-field" data-media-slot-field="embed">
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
                    @php $mediaSlots['embed'] = ob_get_clean(); @endphp
                    @php ob_start(); @endphp
                    <div class="ui-field" data-media-slot-field="document">
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
                    @php $mediaSlots['document'] = ob_get_clean(); @endphp
                    @foreach (collect($mediaSlots)->sortBy(fn ($html, $slot) => $mediaFilled[$slot] ? 0 : 1) as $slotHtml)
                        {!! $slotHtml !!}
                    @endforeach
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

            <div class="ui-note-card ui-field--full" aria-live="polite">
                <p class="ui-note-card__title">{{ __('lf.LF_product_v2_selling_price') }}</p>
                <p class="ui-note-card__text"><strong><span x-text="formatMoney(sellingPrice())"></span> <span x-text="currency"></span></strong><span x-show="promotion && discountType && Number(discount || 0) > 0" x-cloak> · {{ __('lf.LF_product_v2_save') }} <span x-text="formatMoney(discountAmount())"></span> <span x-text="currency"></span><span x-show="discountType === 'percentage'"> · <span x-text="`${Number(discount || 0)}%`"></span></span></span></p>
            </div>

            <div class="ui-modal__row-break" aria-hidden="true"></div>
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

            <label class="ui-switch-row ui-product-featured">
                <span class="ui-switch-row__text"><b>{{ __('lf.LF_course_product_common_is_featured') }}</b></span>
                <span class="ui-switch">
                    <input type="hidden" name="is_featured" value="0">
                    <input id="product-form-is_featured" type="checkbox" role="switch" name="is_featured" value="1" @checked($formProduct?->is_featured)>
                    <span></span>
                </span>
            </label>
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
