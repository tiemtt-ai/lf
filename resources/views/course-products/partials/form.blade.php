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
        if (this.submittingProduct) { event.preventDefault(); return }
        if (!this.validateTimeWindows()) { event.preventDefault(); return }
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
            event.preventDefault()
            for (const message of confirmationMessages) {
                if (!await window.LFConfirm.open({ message, trigger: event.submitter })) return
            }
            this.productSubmitConfirmed = true
            event.target.requestSubmit(event.submitter || undefined)
            return
        }
        if (this.$refs.priceInput) this.$refs.priceInput.value = this.price
        if (this.$refs.discountInput) this.$refs.discountInput.value = this.discount
        this.submittingProduct = true
        if (event.submitter) { event.submitter.disabled = true; event.submitter.setAttribute('aria-busy', 'true') }
    }
}" x-init="refreshMoneyDisplays(); $nextTick(() => { template = @js($selectedTemplate) }); $el.closest('form')?.addEventListener('submit', event => handleProductSubmit(event))"
   class="admin-form-flow">
    <section class="admin-form-standard-section" aria-labelledby="product-basic">
        <h2 id="product-basic" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_basic') }}</h2>
        <div class="admin-form-subsection" aria-labelledby="product-course-content">
            <h3 id="product-course-content" class="admin-form-subsection-title">{{ __('lf.LF_product_v2_group_course_content') }}</h3>
            <div class="admin-form-field-grid">
                <div id="course-product-category-field" class="lf-form-group">
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

                <div id="course-product-template-field" class="lf-form-group">
                    <x-form-label for="template_id" :value="__('lf.LF_product_v2_template')" :required="true" />
                    @if($canChangeTemplate)
                        <select id="template_id" name="template_id" class="lf-form-control" x-model="template" @change="templateVersion = ''" :class="{ 'lf-select-placeholder': template === '' }" required>
                            <option value="">{{ __('lf.LF_product_v2_select_template') }}</option>
                            @foreach($templates as $templateOption)
                                <option value="{{ $templateOption->id }}"
                                        x-show="String({{ (int) $templateOption->category_id }}) === String(category)"
                                        :disabled="String({{ (int) $templateOption->category_id }}) !== String(category)"
                                        @selected((string) $templateOption->id === $selectedTemplate)>{{ $templateOption->name }}@if($templateOption->is_historical_binding) — {{ $templateOption->status_label }}@endif</option>
                            @endforeach
                        </select>
                        <p class="lf-form-help" x-show="category && !templates.some(item => String(item.category_id) === String(category))" x-cloak>
                            {{ __('lf.LF_product_v2_no_templates_for_category') }}
                        </p>
                        @if($initialTemplateState?->is_historical_binding)
                            <p class="admin-form-inline-notice">
                                {{ __('lf.LF_product_v2_historical_template_status', [
                                    'status' => $initialTemplateState->status_label,
                                ]) }}
                            </p>
                        @endif
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
                    @error('template_id')<p class="lf-form-error">{{ $message }}</p>@enderror
                </div>

                @if(! $formProduct)
                    <div id="course-product-version-field" class="lf-form-group admin-form-field--full">
                        <x-form-label for="template_version_id" :value="__('lf.LF_product_v2_course_version')" :required="true" />
                        <select id="template_version_id"
                                name="template_version_id"
                                class="lf-form-control"
                                x-model="templateVersion"
                                :disabled="!template"
                                :required="Boolean(template)"
                                @error('template_version_id') aria-invalid="true" aria-describedby="template_version_id_error template_version_id_help" @else aria-describedby="template_version_id_help" @enderror>
                            <option value="">{{ __('lf.LF_product_v2_select_published_version') }}</option>
                            @foreach($templates as $templateOption)
                                @foreach($templateOption->published_versions as $versionOption)
                                    <option value="{{ $versionOption['id'] }}"
                                            x-show="String({{ (int) $templateOption->id }}) === String(template)"
                                            :disabled="String({{ (int) $templateOption->id }}) !== String(template)">{{ $versionOption['number_label'] }} · {{ $versionOption['code'] }}@if($versionOption['published_at']) · {{ $versionOption['published_at'] }}@endif</option>
                                @endforeach
                            @endforeach
                        </select>
                        <template x-if="selectedVersion()">
                            <aside class="lf-product-version-summary lf-product-version-summary--selected" aria-live="polite">
                                <div class="lf-product-version-summary-copy">
                                    <p class="lf-product-version-summary-heading">{{ __('lf.LF_product_v2_selected_version') }}</p>
                                    <p class="lf-product-version-summary-primary">
                                        <span class="lf-product-version-summary-main" x-text="selectedVersion().number_label"></span>
                                        <span class="lf-product-version-summary-separator" aria-hidden="true">·</span>
                                        <span x-text="selectedVersion().code"></span>
                                        <template x-if="selectedVersion().published_at">
                                            <span>
                                                <span class="lf-product-version-summary-separator" aria-hidden="true">·</span>
                                                <span x-text="selectedVersion().published_at"></span>
                                            </span>
                                        </template>
                                    </p>
                                </div>
                                <a x-show="selectedVersion().view_url"
                                   :href="selectedVersion().view_url"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="admin-text-action admin-table-action-link lf-product-version-summary-action"
                                   aria-label="{{ __('lf.LF_product_v2_view_version_new_tab') }}">
                                    <x-backend-icon name="external-link" />
                                    {{ __('lf.LF_product_v2_view_version') }}
                                </a>
                            </aside>
                        </template>
                        <p id="template_version_id_help" class="lf-form-help">{{ __('lf.LF_product_v2_course_version_help') }}</p>
                        @error('template_version_id')<p id="template_version_id_error" class="lf-form-error">{{ $message }}</p>@enderror
                    </div>
                @elseif($canChangeTemplate)
                    <div class="lf-form-group admin-form-field--full"
                         x-show="template && template !== persistedTemplate"
                         x-cloak>
                        <x-form-label for="template_version_id" :value="__('lf.LF_product_v2_template_change_version')" :required="true" />
                        <select id="template_version_id" name="template_version_id" class="lf-form-control" x-model="templateVersion" :required="template && template !== persistedTemplate">
                            <option value="">{{ __('lf.LF_course_product_item_common_select_version') }}</option>
                            @foreach($templates as $templateOption)
                                @foreach($templateOption->published_versions as $versionOption)
                                    <option value="{{ $versionOption['id'] }}"
                                            x-show="String({{ (int) $templateOption->id }}) === String(template)"
                                            :disabled="String({{ (int) $templateOption->id }}) !== String(template)">{{ $versionOption['number_label'] }} · {{ $versionOption['code'] }}@if($versionOption['published_at']) · {{ $versionOption['published_at'] }}@endif</option>
                                @endforeach
                            @endforeach
                        </select>
                        <template x-if="selectedVersion()">
                            <aside class="lf-product-version-summary lf-product-version-summary--selected" aria-live="polite">
                                <div class="lf-product-version-summary-copy">
                                    <p class="lf-product-version-summary-heading">{{ __('lf.LF_product_v2_selected_version') }}</p>
                                    <p class="lf-product-version-summary-primary">
                                        <span class="lf-product-version-summary-main" x-text="selectedVersion().number_label"></span>
                                        <span class="lf-product-version-summary-separator" aria-hidden="true">·</span>
                                        <span x-text="selectedVersion().code"></span>
                                        <template x-if="selectedVersion().published_at">
                                            <span>
                                                <span class="lf-product-version-summary-separator" aria-hidden="true">·</span>
                                                <span x-text="selectedVersion().published_at"></span>
                                            </span>
                                        </template>
                                    </p>
                                </div>
                                <a x-show="selectedVersion().view_url"
                                   :href="selectedVersion().view_url"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="admin-text-action admin-table-action-link lf-product-version-summary-action"
                                   aria-label="{{ __('lf.LF_product_v2_view_version_new_tab') }}">
                                    <x-backend-icon name="external-link" />
                                    {{ __('lf.LF_product_v2_view_version') }}
                                </a>
                            </aside>
                        </template>
                        @error('template_version_id')<p class="lf-form-error">{{ $message }}</p>@enderror
                    </div>
                @endif

                @if($formProduct)
                    <div class="admin-form-field--full">
                        <template x-for="item in templates.filter(t => String(t.id) === String(template))" :key="`version-${item.id}`">
                            <div>
                                <template x-if="item.version_summary">
                                    <aside class="lf-product-version-summary lf-product-version-summary--in-use" aria-live="polite">
                                        <div class="lf-product-version-summary-copy">
                                            <p class="lf-product-version-summary-heading">{{ __('lf.LF_product_v2_version_in_use') }}</p>
                                            <p class="lf-product-version-summary-primary">
                                                <span class="lf-product-version-summary-main" x-text="item.version_summary.code"></span>
                                                <span class="lf-product-version-summary-separator" aria-hidden="true">·</span>
                                                <span class="badge badge-success" x-text="item.version_summary.status_label"></span>
                                            </p>
                                            <p class="lf-secondary-text"><span x-text="item.version_summary.number_label"></span> · <span x-text="item.version_summary.lesson_text"></span> · <span x-text="item.version_summary.activity_text"></span></p>
                                        </div>
                                        <a x-show="item.version_summary.view_url"
                                           :href="item.version_summary.view_url"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="admin-text-action admin-table-action-link lf-product-version-summary-action"
                                           aria-label="{{ __('lf.LF_product_v2_view_version_new_tab') }}">
                                            <x-backend-icon name="external-link" />
                                            {{ __('lf.LF_product_v2_view_version') }}
                                        </a>
                                    </aside>
                                </template>
                                <p x-show="!item.version_summary" class="lf-product-version-summary-heading">{{ __('lf.LF_product_v2_version_in_use') }}</p>
                                <p x-show="!item.version_summary && !item.integrity_warning" class="lf-form-help" role="status">{{ __('lf.LF_product_v2_missing_version') }}</p>
                                <p x-show="item.integrity_warning" class="lf-form-error" role="alert" x-text="item.integrity_warning"></p>
                            </div>
                        </template>
                        <noscript>
                            @if($initialTemplateState?->version_summary)
                                <aside class="lf-product-version-summary lf-product-version-summary--in-use">
                                    <div class="lf-product-version-summary-copy">
                                        <p class="lf-product-version-summary-heading">{{ __('lf.LF_product_v2_version_in_use') }}</p>
                                        <p class="lf-product-version-summary-primary">
                                            <span class="lf-product-version-summary-main">{{ $initialTemplateState->version_summary['code'] }}</span>
                                            <span class="lf-product-version-summary-separator" aria-hidden="true">·</span>
                                            <span class="badge badge-success">{{ $initialTemplateState->version_summary['status_label'] }}</span>
                                        </p>
                                        <p class="lf-secondary-text">{{ $initialTemplateState->version_summary['number_label'] }} · {{ $initialTemplateState->version_summary['lesson_text'] }} · {{ $initialTemplateState->version_summary['activity_text'] }}</p>
                                    </div>
                                    @if($initialTemplateState->version_summary['view_url'])
                                        <a href="{{ $initialTemplateState->version_summary['view_url'] }}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="admin-text-action admin-table-action-link lf-product-version-summary-action"
                                           aria-label="{{ __('lf.LF_product_v2_view_version_new_tab') }}">
                                            <x-backend-icon name="external-link" />
                                            {{ __('lf.LF_product_v2_view_version') }}
                                        </a>
                                    @endif
                                </aside>
                            @elseif($initialTemplateState?->integrity_warning)
                                <p class="lf-form-error" role="alert">{{ $initialTemplateState->integrity_warning }}</p>
                            @elseif($selectedTemplate !== '')
                                <p class="lf-form-help" role="status">{{ __('lf.LF_product_v2_missing_version') }}</p>
                            @endif
                        </noscript>
                    </div>
                @endif
            </div>
        </div>

        <div class="admin-form-subsection" aria-labelledby="product-identity">
            <h3 id="product-identity" class="admin-form-subsection-title">{{ __('lf.LF_product_v2_group_identity') }}</h3>
            <div class="admin-form-field-grid">
                <div class="lf-form-group"><x-form-label for="title" :value="__('lf.LF_course_product_common_title_field')" :required="true" /><input id="title" name="title" class="lf-form-control" maxlength="255" required value="{{ old('title', $formProduct?->title) }}" placeholder="{{ __('lf.LF_product_v2_placeholder_name') }}" @input="generatedSlug = slugify($event.target.value)"></div>
                <div class="lf-form-group">
                    <x-form-label for="offering_type" :value="__('lf.LF_product_v2_offering_type')" :required="true" />
                    <select id="offering_type"
                            name="offering_type"
                            class="lf-form-control"
                            x-model="offering"
                            :class="{ 'lf-select-placeholder': offering === '' }"
                            required>
                        <option value="">{{ __('lf.LF_product_v2_select_offering') }}</option>
                        @foreach(\App\Support\CourseProductV2::OFFERING_TYPES as $type)
                            <option value="{{ $type }}" @selected($selectedOffering === $type)>
                                {{ __('lf.LF_product_v2_offering_'.$type) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div @class(['lf-form-group', 'admin-form-field--full' => ! $formProduct])>
                    <div class="admin-form-label-row">
                        <x-form-label for="slug" :value="__('lf.LF_course_product_common_slug')" />
                        <span class="admin-form-label-metadata">{{ __('lf.LF_product_v2_automatic') }}</span>
                    </div>
                    <div class="admin-copy-control" x-data="{ copied: false }">
                        <input id="slug" name="slug" class="lf-form-control admin-form-readonly" readonly x-model="generatedSlug" placeholder="{{ __('lf.LF_product_v2_generated_slug') }}">
                        <button type="button" class="admin-copy-action admin-copy-control__button"
                                x-show="generatedSlug" x-on:click="navigator.clipboard.writeText(generatedSlug).then(() => { copied = true; setTimeout(() => copied = false, 1600) })"
                                x-bind:class="{ 'is-copied': copied }"
                                x-bind:title="copied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_product_v2_copy_slug'))"
                                x-bind:aria-label="copied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_product_v2_copy_slug'))">
                            <span x-show="!copied"><x-backend-icon name="copy" /></span><span x-cloak x-show="copied"><x-backend-icon name="check" /></span>
                        </button>
                    </div>
                </div>
                @if($formProduct)
                    <div class="lf-form-group" x-data="{ copied: false }">
                        <x-form-label for="product_code" :value="__('lf.LF_course_product_common_product_code')" />
                        <div class="admin-copy-control">
                            <input id="product_code" class="lf-form-control admin-form-readonly course-product-code-control" readonly value="{{ $formProduct->product_code }}">
                            <button type="button" class="admin-copy-action admin-copy-control__button"
                                    x-on:click="navigator.clipboard.writeText(@js($formProduct->product_code)).then(() => { copied = true; setTimeout(() => copied = false, 1600) })"
                                    x-bind:class="{ 'is-copied': copied }"
                                    x-bind:title="copied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_product_v2_copy_code'))"
                                    x-bind:aria-label="copied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_product_v2_copy_code'))">
                                <span x-show="!copied"><x-backend-icon name="copy" /></span><span x-cloak x-show="copied"><x-backend-icon name="check" /></span>
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="product-description">
        <h2 id="product-description" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_description_media') }}</h2>
        <div class="admin-form-option-group">
            <input type="hidden" name="uses_custom_description" value="0">
            <label class="admin-form-option-panel admin-form-option-panel--compact">
                <input type="checkbox" name="uses_custom_description" value="1" x-model="customDescription" :aria-expanded="customDescription.toString()" aria-controls="course-product-description-fields">
                <span><strong>{{ __('lf.LF_product_v2_description_option_title') }}</strong><span>{{ __('lf.LF_product_v2_custom_description') }}</span><small>{{ __('lf.LF_product_v2_description_inherited') }}</small></span>
            </label>
            <div id="course-product-description-fields" class="admin-form-conditional" x-show="customDescription" x-cloak>
                <div class="lf-form-group"><x-form-label for="short_description" :value="__('lf.LF_course_product_common_short_description')" /><textarea id="short_description" name="short_description" class="lf-form-control" rows="2" maxlength="500" placeholder="{{ __('lf.LF_product_v2_placeholder_short_description') }}">{{ old('short_description', $formProduct?->short_description) }}</textarea></div>
                <div class="lf-form-group"><x-form-label for="description" :value="__('lf.LF_course_product_common_description')" /><textarea id="description" name="description" rows="4" class="lf-form-control" placeholder="{{ __('lf.LF_product_v2_placeholder_description') }}">{{ old('description', $formProduct?->description) }}</textarea></div>
            </div>
        </div>
        <div class="admin-form-option-group">
            <input type="hidden" name="uses_custom_intro_media" value="0">
            <label class="admin-form-option-panel admin-form-option-panel--compact">
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

    <section class="admin-form-standard-section"
             aria-labelledby="product-config"
             x-show="offering === 'self_paced_course'"
             x-cloak>
        <h2 id="product-config" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_configuration') }}</h2>
        <div class="admin-form-field-grid">
            <div class="lf-form-group">
                <x-form-label for="access_duration_days" :value="__('lf.LF_product_v2_access_days')" />
                <input id="access_duration_days" name="access_duration_days" type="number" min="1"
                       class="lf-form-control"
                       value="{{ old('access_duration_days', $formProduct?->access_duration_days) }}"
                       placeholder="{{ __('lf.LF_product_v2_placeholder_access_days') }}"
                       @error('access_duration_days') aria-invalid="true" aria-describedby="access_duration_days_error" @enderror>
                @error('access_duration_days')<p id="access_duration_days_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>
            <div class="lf-form-group">
                <x-form-label for="review_duration_days" :value="__('lf.LF_product_v2_review_days')" />
                <input id="review_duration_days" name="review_duration_days" type="number" min="0"
                       class="lf-form-control"
                       value="{{ old('review_duration_days', $formProduct?->review_duration_days) }}"
                       placeholder="{{ __('lf.LF_product_v2_placeholder_review_days') }}"
                       @error('review_duration_days') aria-invalid="true" aria-describedby="review_duration_days_error" @enderror>
                @error('review_duration_days')<p id="review_duration_days_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="product-pricing">
        <h2 id="product-pricing" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_pricing') }}</h2>
        <div class="admin-form-field-grid admin-form-field-grid--main-compact">
            <div class="lf-form-group">
                <x-form-label for="price" :value="__('lf.LF_product_v2_list_price')" :required="true" />
                <input id="price" name="price" type="text" inputmode="decimal" autocomplete="off"
                       x-ref="priceInput" x-model="priceDisplay" @input="updatePrice($event.target.value)"
                       class="lf-form-control admin-form-money-input" required>
            </div>
            <div class="lf-form-group">
                <x-form-label for="currency" :value="__('lf.LF_course_product_common_currency')" :required="true" />
                <select id="currency" name="currency" class="lf-form-control" x-model="currency" @change="refreshMoneyDisplays()" required>
                    @foreach(['VND','USD','KRW'] as $currency)<option value="{{ $currency }}" @selected(old('currency',$formProduct?->currency??'VND')===$currency)>{{ $currency }}</option>@endforeach
                </select>
            </div>

            <div class="admin-form-subsection admin-form-field--full" aria-labelledby="product-registration-window">
                <h3 id="product-registration-window" class="admin-form-subsection-title">{{ __('lf.LF_product_v2_group_registration') }}</h3>
                <div class="admin-form-field-grid">
                    <div class="lf-form-group">
                        <x-form-label for="registration_starts_at" :value="__('lf.LF_course_product_common_registration_starts_at')" />
                        <input id="registration_starts_at" name="registration_starts_at" type="datetime-local" class="lf-form-control course-product-date-input"
                               :class="{ 'has-value': registrationStart }"
                               x-ref="registrationStart" x-model="registrationStart" @input="$el.setCustomValidity('')"
                               @error('registration_starts_at') aria-invalid="true" aria-describedby="registration_starts_at_error" @enderror>
                        @error('registration_starts_at')<p id="registration_starts_at_error" class="lf-form-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="registration_ends_at" :value="__('lf.LF_course_product_common_registration_ends_at')" />
                        <input id="registration_ends_at" name="registration_ends_at" type="datetime-local" class="lf-form-control course-product-date-input"
                               :class="{ 'has-value': registrationEnd }"
                               x-ref="registrationEnd" x-model="registrationEnd" @input="$el.setCustomValidity('')"
                               @error('registration_ends_at') aria-invalid="true" aria-describedby="registration_ends_at_error" @enderror>
                        @error('registration_ends_at')<p id="registration_ends_at_error" class="lf-form-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>

            <div id="course-product-promotion-flow"
                 class="admin-form-field--full admin-form-stack">
                <div class="admin-form-option-group">
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
                     class="admin-form-field-grid admin-form-conditional"
                     x-show="promotion"
                     x-cloak>
                    <div class="lf-form-group">
                        <x-form-label for="discount_type" :value="__('lf.LF_product_v2_discount_type')" />
                        <select id="discount_type" name="discount_type" class="lf-form-control course-product-discount-type" x-model="discountType"
                                :class="{ 'has-value': discountType }" @change="refreshMoneyDisplays()"
                                @error('discount_type') aria-invalid="true" aria-describedby="discount_type_error" @enderror>
                            <option value="">{{ __('lf.LF_product_v2_select_discount') }}</option>
                            <option value="percentage">{{ __('lf.LF_product_v2_percentage') }}</option>
                            <option value="fixed_amount">{{ __('lf.LF_product_v2_fixed_amount') }}</option>
                        </select>
                        @error('discount_type')<p id="discount_type_error" class="lf-form-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="discount_value" :value="__('lf.LF_product_v2_discount_value_label')" />
                        <input id="discount_value" name="discount_value" type="text" inputmode="decimal" autocomplete="off"
                               x-ref="discountInput" x-model="discountDisplay" @input="updateDiscount($event.target.value)"
                               class="lf-form-control admin-form-money-input" placeholder="{{ __('lf.LF_product_v2_discount_value') }}"
                               @error('discount_value') aria-invalid="true" aria-describedby="discount_value_error" @enderror>
                        @error('discount_value')<p id="discount_value_error" class="lf-form-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="sale_starts_at" :value="__('lf.LF_product_v2_promotion_starts_at')" />
                        <input id="sale_starts_at" name="sale_starts_at" type="datetime-local" class="lf-form-control course-product-date-input"
                               :class="{ 'has-value': promotionStart }"
                               :min="registrationStart || null" :max="registrationEnd || null"
                               x-ref="promotionStart" x-model="promotionStart" @input="$el.setCustomValidity('')"
                               @error('sale_starts_at') aria-invalid="true" aria-describedby="sale_starts_at_error" @enderror>
                        @error('sale_starts_at')<p id="sale_starts_at_error" class="lf-form-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="sale_ends_at" :value="__('lf.LF_product_v2_promotion_ends_at')" />
                        <input id="sale_ends_at" name="sale_ends_at" type="datetime-local" class="lf-form-control course-product-date-input"
                               :class="{ 'has-value': promotionEnd }"
                               :min="registrationStart || null" :max="registrationEnd || null"
                               x-ref="promotionEnd" x-model="promotionEnd" @input="$el.setCustomValidity('')"
                               @error('sale_ends_at') aria-invalid="true" aria-describedby="sale_ends_at_error" @enderror>
                        @error('sale_ends_at')<p id="sale_ends_at_error" class="lf-form-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <output id="selling_price"
                        class="admin-form-calculated-summary"
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
        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="product-availability">
        <h2 id="product-availability" class="admin-form-section-title">{{ __('lf.LF_product_v2_group_availability') }}</h2>
        <div class="admin-form-field-grid">
            <div id="course-product-featured-field" class="admin-form-option-group course-product-featured-option admin-form-field--full"><input type="hidden" name="is_featured" value="0"><label class="admin-form-option-panel admin-form-option-panel--compact"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured',$formProduct?->is_featured))><span><strong>{{ __('lf.LF_course_product_common_is_featured') }}</strong></span></label></div>
            <div class="lf-form-group admin-form-field--full"><x-form-label for="status" :value="__('lf.LF_course_product_common_status')" :required="true" /><select id="status" name="status" class="lf-form-control" required>@foreach(($allowedStatuses ?? ['draft']) as $status)<option value="{{ $status }}" @selected($selectedStatus===$status) @disabled($status === 'active' && ! ($hasActiveCourseVersion ?? false))>{{ __('lf.LF_course_product_common_'.$status) }}</option>@endforeach</select>@if(! ($hasActiveCourseVersion ?? false))<p class="lf-form-help">{{ __('lf.LF_product_v2_attach_before_activation') }}</p>@endif@if($formProduct && ! in_array('draft', $allowedStatuses ?? [], true) && $formProduct->status !== 'draft')<p class="lf-form-help">{{ __('lf.LF_product_status_used_draft_help') }}</p>@endif</div>
        </div>
    </section>
</div>
