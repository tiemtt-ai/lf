@extends('layouts.backend')

@section('title', __('lf.LF_course_product_common_edit'))
@section('page_title', __('lf.LF_course_product_common_edit'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js'])
@endsection

@section('content')
    @php
        $linkedProductItems = $productItems->whereNotNull('version_id');
        $productItemCount = $linkedProductItems->count();
        $productRelationCount = $productRelations->count();
        $initialItem = $productItems->firstWhere('status', 'active') ?? $productItems->first();
        $itemVersionId = (string) old('version_id', $initialItem?->version_id ?? '');
        $currentItemTemplate = $templates->firstWhere('id', (int) ($initialItem?->template_id ?? 0));
        $hasItemErrors = $errors->has('version_id');
        $hasRelationErrors = $errors->has('related_product_id');
        $requestedTab = request()->query('tab');
        $initialTab = $hasRelationErrors || $requestedTab === 'relations'
            ? 'relations'
            : ($hasItemErrors || $requestedTab === 'versions' ? 'versions' : 'general');
        $focusRelationSearch = request()->query('focus') === 'related_product_search' || $hasRelationErrors;
        $templateRoutePrefix = str_replace('course-products', 'course-templates', $routePrefix);
    @endphp

    @if (session('success'))
        <div class="admin-alert admin-alert-success admin-form-card">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="course-product-editor"
         x-data="{ activeTab: @js($initialTab) }"
         x-init="if (@js($focusRelationSearch)) $nextTick(() => document.getElementById('related_product_search')?.focus())">
        <header class="admin-card course-product-edit-header">
            <a href="{{ route($routePrefix.'.index') }}" class="course-product-back-link">
                ← {{ __('lf.LF_course_product_common_back_to_products') }}
            </a>

            <div class="course-product-edit-title-row">
                <div>
                    <h1 class="course-product-edit-title">
                        {{ $product->title }}
                    </h1>
                    <p class="course-product-edit-code">
                        {{ $product->product_code }}
                    </p>
                </div>

                <span @class([
                    'badge',
                    'badge-success' => $product->status === 'active',
                    'badge-danger' => $product->status === 'archived',
                ])>
                    {{ __('lf.LF_course_product_common_'.$product->status) }}
                </span>
            </div>
        </header>

        <nav class="course-product-tabs"
             role="tablist"
             aria-label="{{ __('lf.LF_course_product_common_tabs_label') }}">
            <button type="button"
                    id="course-product-tab-general"
                    class="course-product-tab"
                    x-bind:class="{ 'is-active': activeTab === 'general' }"
                    x-bind:aria-selected="activeTab === 'general'"
                    x-on:click="activeTab = 'general'"
                    role="tab"
                    aria-controls="course-product-panel-general">
                {{ __('lf.LF_course_product_tab_general') }}
            </button>
            <button type="button"
                    id="course-product-tab-versions"
                    class="course-product-tab"
                    x-bind:class="{ 'is-active': activeTab === 'versions' }"
                    x-bind:aria-selected="activeTab === 'versions'"
                    x-on:click="activeTab = 'versions'"
                    role="tab"
                    aria-controls="course-product-panel-versions">
                {{ __('lf.LF_course_product_tab_course_versions', ['count' => $productItemCount]) }}
            </button>
            <button type="button"
                    id="course-product-tab-relations"
                    class="course-product-tab"
                    x-bind:class="{ 'is-active': activeTab === 'relations' }"
                    x-bind:aria-selected="activeTab === 'relations'"
                    x-on:click="activeTab = 'relations'"
                    role="tab"
                    aria-controls="course-product-panel-relations">
                {{ __('lf.LF_course_product_tab_related_products', ['count' => $productRelationCount]) }}
            </button>
        </nav>

        <section id="course-product-panel-general"
                 class="course-product-tab-panel"
                 x-show="activeTab === 'general'"
                 role="tabpanel"
                 aria-labelledby="course-product-tab-general">
            <div class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope
                 x-data="{
                     previewOpen: false,
                     preview: { name: '', url: '', mimeType: '', mediaType: '' },
                     openPreview(detail) {
                         if (! ['image', 'video', 'embed'].includes(detail.kind)) { window.open(detail.url, '_blank', 'noopener'); return }
                         this.preview = { name: detail.name, url: detail.url, mimeType: detail.mimeType || '', mediaType: detail.kind }
                         this.previewOpen = true
                     },
                     closePreview() { this.previewOpen = false; this.preview = { name: '', url: '', mimeType: '', mediaType: '' } },
                 }">
                <x-ui-list-toasts />
                @php
                    $dash = '—';
                    $summaryCategory = $categories->firstWhere('id', (int) $product->category_id)?->name;
                    $summaryTemplate = $templates->firstWhere('id', (int) ($selectedTemplateId ?? 0));
                    $money = static fn ($value): string => number_format((float) $value, $product->currency === 'USD' ? 2 : 0).' '.$product->currency;
                    $dateTime = static fn ($value): ?string => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i') : null;
                    $window = static fn ($from, $to): ?string => $from || $to ? trim(($from ?? $dash).' → '.($to ?? $dash)) : null;
                    $summaryGroups = [
                        'basic' => [__('lf.LF_product_v2_group_basic'), [
                            ['title', __('lf.LF_course_product_common_title_field'), $product->title],
                            ['category', __('lf.LF_product_v2_category'), $summaryCategory],
                            ['template', __('lf.LF_product_v2_template'), $summaryTemplate?->name],
                            ['version', __('lf.LF_product_v2_version_in_use'), null],
                            ['offering_type', __('lf.LF_product_v2_offering_type'), $product->offering_type ? __('lf.LF_product_v2_offering_'.$product->offering_type) : null],
                            ['product_code', __('lf.LF_course_product_common_product_code'), $product->product_code],
                            ['slug', __('lf.LF_course_product_common_slug'), $product->slug],
                        ]],
                        'pricing' => [__('lf.LF_product_v2_group_pricing'), [
                            ['price', __('lf.LF_product_v2_list_price'), $money($product->price)],
                            ['registration', __('lf.LF_product_v2_group_registration'), $window($dateTime($product->registration_starts_at), $dateTime($product->registration_ends_at))],
                            ['promotion', __('lf.LF_product_v2_apply_promotion'), $product->promotion_enabled
                                ? trim(($product->discount_type === 'percentage' ? rtrim(rtrim((string) $product->discount_value, '0'), '.').'%' : ($product->discount_value !== null ? $money($product->discount_value) : '')).' · '.($window($dateTime($product->sale_starts_at), $dateTime($product->sale_ends_at)) ?? ''), ' ·')
                                : null],
                            ['access', __('lf.LF_product_v2_access_days'), $product->access_duration_days],
                        ]],
                        'availability' => [__('lf.LF_product_v2_group_availability'), [
                            ['status', __('lf.LF_course_product_common_status'), null],
                            ['featured', __('lf.LF_course_product_common_is_featured'), $product->is_featured ? '✓' : null],
                            ['short_description', __('lf.LF_course_product_common_short_description'), $product->uses_custom_description ? $product->short_description : null],
                            ['description', __('lf.LF_course_product_common_description'), $product->uses_custom_description ? $product->description : null],
                        ]],
                    ];
                @endphp

                <section class="mk-section" aria-labelledby="course-product-information-title">
                    <div class="ui-summary-head">
                        <h2 id="course-product-information-title">{{ __('lf.LF_course_product_tab_general') }}</h2>
                        <button type="button" class="ui-button ui-button--primary cursor-interaction"
                                data-ui-dialog-form-open data-ui-dialog-form-hash-open aria-haspopup="dialog"
                                data-url="{{ route($routePrefix.'.form', ['id' => $product->id, 'context' => 'workspace']) }}">
                            {{ __('lf.LF_course_template_action_edit_info') }}
                        </button>
                    </div>

                    @foreach ($summaryGroups as $groupKey => [$groupTitle, $items])
                        <section class="ui-summary-group" aria-labelledby="course-product-summary-{{ $groupKey }}">
                            <h3 id="course-product-summary-{{ $groupKey }}">{{ $groupTitle }}</h3>
                            <dl class="ui-summary">
                                @foreach ($items as [$field, $label, $value])
                                    <div data-summary-field="{{ $field }}" @class(['ui-summary__item', 'ui-summary__item--wide' => in_array($field, ['short_description', 'description'], true)])>
                                        <dt class="ui-field__label">{{ $label }}</dt>
                                        <dd>
                                            @if ($field === 'version')
                                                @if ($summaryTemplate?->version_summary)
                                                    @php($versionSummary = $summaryTemplate->version_summary)
                                                    <div class="ui-summary__version">
                                                        <div class="ui-summary__version-main">
                                                            <strong>{{ $versionSummary['code'] }}</strong>
                                                            <span class="ui-status ui-status--active">{{ $versionSummary['status_label'] }}</span>
                                                        </div>
                                                        <div class="ui-summary__version-meta">
                                                            <span class="ui-caption">{{ $versionSummary['number_label'] }} · {{ $versionSummary['lesson_text'] }} · {{ $versionSummary['activity_text'] }}</span>
                                                            @if ($versionSummary['view_url'])
                                                                <a class="ui-caption" href="{{ $versionSummary['view_url'] }}" target="_blank" rel="noopener noreferrer"
                                                                   aria-label="{{ __('lf.LF_product_v2_view_version_new_tab') }}">{{ __('lf.LF_product_v2_view_version') }}</a>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @elseif ($summaryTemplate?->integrity_warning)
                                                    <span class="ui-field__error" role="alert">{{ $summaryTemplate->integrity_warning }}</span>
                                                @elseif ($summaryTemplate)
                                                    <span class="ui-caption" role="status">{{ __('lf.LF_product_v2_missing_version') }}</span>
                                                @else
                                                    {{ $dash }}
                                                @endif
                                            @elseif ($field === 'status')
                                                <span class="ui-status ui-status--{{ $product->status }}">{{ __('lf.LF_course_product_common_'.$product->status) }}</span>
                                            @else
                                                @if ($value !== null && $value !== '')
                                                    {{ $value }}
                                                @else
                                                    <span class="ui-summary__empty" aria-label="{{ __('lf.LF_course_template_summary_empty') }}">{{ $dash }}</span>
                                                @endif
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>
                    @endforeach

                    @if (in_array($product->status, ['inactive', 'archived'], true))
                        <div class="ui-summary-foot">
                        @if($product->status === 'inactive')
                            <form method="POST"
                                  action="{{ route($routePrefix.'.archive', $product->id) }}"
                                  x-data="{ submitting: false }"
                                  data-lf-confirm="{{ __('lf.LF_product_status_archive_confirm') }}"
                                  data-lf-confirm-tone="danger"
                                  @submit="if (submitting) { $event.preventDefault(); return } submitting = true">
                                @csrf
                                <button type="submit"
                                        class="btn course-product-danger-outline-action"
                                        :disabled="submitting"
                                        :aria-busy="submitting">
                                    {{ __('lf.LF_product_status_archive_action') }}
                                </button>
                            </form>
                        @elseif($product->status === 'archived')
                            <form method="POST"
                                  action="{{ route($routePrefix.'.restore', $product->id) }}"
                                  x-data="{ submitting: false }"
                                  data-lf-confirm="{{ __('lf.LF_product_status_restore_confirm') }}"
                                  @submit="if (submitting) { $event.preventDefault(); return } submitting = true">
                                @csrf
                                <button type="submit" class="btn btn-primary" :disabled="submitting" :aria-busy="submitting">
                                    {{ __('lf.LF_product_status_restore_action') }}
                                </button>
                            </form>
                        @endif
                        </div>
                    @endif
                </section>

                <dialog class="ui-modal" data-columns="3" aria-labelledby="product-dialog-title" data-ui-dialog-form data-field-id-prefix="product-form-"
                        data-msg-load-error="{{ __('lf.LF_course_template_dialog_load_error') }}"
                        data-msg-discard-title="{{ __('lf.LF_course_template_dialog_discard_title') }}"
                        data-msg-discard-text="{{ __('lf.LF_course_template_dialog_discard_text') }}"
                        data-msg-discard="{{ __('lf.LF_course_template_dialog_discard') }}"
                        data-msg-save-error="{{ __('lf.LF_course_template_dialog_save_error') }}"
                        data-msg-session-expired="{{ __('lf.LF_course_template_session_expired') }}"
                        data-msg-required-input="{{ __('lf.LF_course_template_dialog_required_input') }}"
                        data-msg-required-select="{{ __('lf.LF_course_template_dialog_required_select') }}"></dialog>
            </div>
        </section>

        <section id="course-product-panel-versions"
                 class="course-product-tab-panel"
                 x-show="activeTab === 'versions'"
                 x-cloak
                 role="tabpanel"
                 aria-labelledby="course-product-tab-versions">
            <div class="admin-card course-product-version-card"
                 x-data="{ replacing: @js($hasItemErrors || ! $initialItem?->version_id), submitting: false, selected: @js($itemVersionId), current: @js((string) ($initialItem?->version_id ?? '')) }">
                <section aria-labelledby="course-product-items-title">
                    <header class="course-product-version-card-header">
                        <h2 id="course-product-items-title" class="admin-form-section-title">
                            {{ __('lf.LF_course_product_item_common_title') }}
                        </h2>
                        <p>{{ __('lf.LF_course_product_item_common_current_enrollment_help') }}</p>
                    </header>

                    @if ($currentItemTemplate)
                        <div class="course-product-version-details">
                            <div class="course-product-version-detail">
                                <span class="course-product-version-label">{{ __('lf.LF_course_product_item_common_current_template') }}</span>
                                <p class="course-product-version-value">{{ $currentItemTemplate->name }}</p>
                            </div>
                            @if ($initialItem?->version_id)
                                <div class="course-product-version-detail">
                                    <span class="course-product-version-label">{{ __('lf.LF_course_product_item_common_current_version') }}</span>
                                    <p class="course-product-version-value">
                                        {{ $initialItem->version_number_label }}
                                        <span aria-hidden="true">·</span>
                                        {{ $initialItem->version_code }}
                                    </p>
                                    <div class="course-product-version-meta">
                                        <span>{{ __('lf.LF_course_product_item_common_released_on', ['date' => $initialItem->published_at ? date('d/m/Y', strtotime($initialItem->published_at)) : '—']) }}</span>
                                        <span class="badge badge-success">{{ __('lf.LF_course_product_item_common_published') }}</span>
                                    </div>
                                </div>
                            @endif
                        </div>
                        @if ($initialItem?->version_id)
                            <div class="course-product-version-actions">
                                <a class="btn admin-primary-outline-action" href="{{ route($templateRoutePrefix.'.versions.show', [$initialItem->template_id, $initialItem->version_id]) }}">{{ __('lf.LF_course_product_item_common_view_version') }}</a>
                                <button type="button" class="btn btn-primary"
                                        x-show="!replacing"
                                        x-on:click="replacing = true; $nextTick(() => document.getElementById('version_id')?.focus())"
                                        x-bind:aria-expanded="replacing"
                                        aria-controls="course-product-version-replace-form">
                                    {{ __('lf.LF_course_product_item_common_replace') }}
                                </button>
                            </div>
                        @endif
                    <form id="course-product-version-replace-form"
                          class="course-product-version-replace-form"
                          method="POST" action="{{ route($routePrefix.'.items.store', $product->id) }}"
                          x-show="replacing" x-cloak
                          data-lf-confirm="{{ __('lf.LF_course_product_item_common_replace_confirm') }}"
                          @submit="if (submitting || !selected || selected === current) { $event.preventDefault(); return } submitting = true">
                        @csrf
                        <div class="lf-form-group">
                            <x-form-label for="version_id"
                                          :value="__('lf.LF_course_product_item_common_template_version')"
                                          :required="true" />
                            <select id="version_id"
                                    name="version_id"
                                    class="lf-form-control"
                                    required x-model="selected">
                                <option value="">{{ __('lf.LF_course_product_item_common_select_version') }}</option>
                                @foreach ($publishedVersions as $version)
                                    <option value="{{ $version->id }}" @selected((string) $version->id === $itemVersionId)>
                                        {{ __('lf.LF_course_product_item_common_version_number', ['number' => $version->version_number]) }} · {{ $version->version_code }} · {{ $version->published_at ? date('d/m/Y', strtotime($version->published_at)) : '—' }}
                                        @if ($version->is_in_use) · {{ __('lf.LF_course_product_item_common_in_use') }} @endif
                                    </option>
                                @endforeach
                            </select>
                            @if ($publishedVersions->isEmpty())
                                <p class="lf-form-help" role="status">{{ __('lf.LF_course_product_item_common_no_versions') }}</p>
                            @endif
                            @error('version_id')<p class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="course-product-version-form-actions">
                            <button type="submit" class="btn btn-primary" :disabled="submitting || !selected || selected === current" :aria-busy="submitting">
                                {{ __('lf.LF_course_product_item_common_confirm_replace') }}
                            </button>
                            <button type="button" class="btn btn-secondary" :disabled="submitting"
                                    x-on:click="replacing = false; selected = current">
                                {{ __('lf.LF_common_button_cancel') }}
                            </button>
                        </div>
                    </form>
                    @else
                        <p class="lf-form-help" role="status">{{ __('lf.LF_course_product_item_common_select_template_overview') }}</p>
                    @endif
                </section>
            </div>

        </section>

        <section id="course-product-panel-relations"
                 class="course-product-tab-panel"
                 x-show="activeTab === 'relations'"
                 x-cloak
                 role="tabpanel"
                 aria-labelledby="course-product-tab-relations">
            <div class="admin-card admin-form-card">
                <section class="admin-form-section course-product-relations-section" aria-labelledby="course-product-relations-title">
                    <h2 id="course-product-relations-title" class="admin-form-section-title">
                        {{ __('lf.LF_course_product_relation_common_title') }}
                    </h2>

                    <p class="course-product-relations-help">
                        {{ __('lf.LF_course_product_relation_common_help') }}
                    </p>

                    @if($product->status !== 'archived')
                        <form method="POST"
                              class="course-product-relation-add-form"
                              action="{{ route($routePrefix.'.relations.store', $product->id) }}"
                              x-data="{
                                  products: @js($relatedProducts), query: '', selected: @js((string) old('related_product_id', '')),
                                  open: false, loading: true, submitting: false, activeIndex: 0,
                                  init() { const chosen = this.products.find(item => String(item.id) === this.selected); if (chosen) this.query = `${chosen.product_code} — ${chosen.title}`; this.$nextTick(() => { this.loading = false }) },
                                  matches() { const q = this.query.trim().toLocaleLowerCase(); return this.products.filter(item => !q || `${item.product_code} ${item.title}`.toLocaleLowerCase().includes(q)) },
                                  choose(item) { this.selected = String(item.id); this.query = `${item.product_code} — ${item.title}`; this.open = false },
                                  move(step) { const count = this.matches().length; if (count) this.activeIndex = (this.activeIndex + step + count) % count },
                                  chooseActive() { const item = this.matches()[this.activeIndex]; if (item) this.choose(item) }
                              }"
                              @submit="if (submitting || !selected) { $event.preventDefault(); return } submitting = true">
                            @csrf

                            <x-form-label for="related_product_search"
                                          :value="__('lf.LF_course_product_relation_common_related_product')" />
                            <div class="course-product-relation-add-row">
                                <div class="lf-combobox" @click.outside="open = false">
                                    <input id="related_product_search" type="search" class="lf-form-control"
                                           x-model="query" role="combobox" aria-autocomplete="list"
                                           :aria-expanded="open" aria-controls="related-product-options"
                                           placeholder="{{ __('lf.LF_course_product_relation_common_search_placeholder') }}"
                                           @focus="open = true" @input="selected = ''; activeIndex = 0; open = true"
                                           @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                                           @keydown.enter.prevent="chooseActive()" @keydown.escape="open = false">
                                    <input type="hidden" name="related_product_id" x-model="selected">
                                    <div id="related-product-options" x-show="open" x-cloak role="listbox" class="lf-combobox-options">
                                        <p x-show="loading" class="course-product-relation-combobox-state" role="status">{{ __('lf.LF_course_product_relation_common_loading') }}</p>
                                        <template x-for="(item, index) in matches()" :key="item.id">
                                            <button type="button" role="option" class="lf-combobox-option course-product-relation-option"
                                                    :id="`related-product-option-${item.id}`"
                                                    :aria-selected="String(item.id) === selected" :class="{ 'is-active': index === activeIndex, 'is-selected': String(item.id) === selected }"
                                                    @mouseenter="activeIndex = index" @click="choose(item)">
                                                <span class="course-product-relation-option-title" x-text="item.title"></span>
                                                <span class="course-product-relation-option-meta">
                                                    <span x-text="item.product_code"></span>
                                                    <span aria-hidden="true">·</span>
                                                    <span x-text="item.category_name || '—'"></span>
                                                    <span aria-hidden="true">·</span>
                                                    <span x-text="item.status_label"></span>
                                                </span>
                                            </button>
                                        </template>
                                        <p x-show="!loading && matches().length === 0" class="course-product-relation-combobox-state" role="status">{{ __('lf.LF_course_product_relation_common_no_results') }}</p>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary" :disabled="submitting || !selected" :aria-busy="submitting">
                                    {{ __('lf.LF_course_product_relation_common_attach') }}
                                </button>
                            </div>
                            @error('related_product_id')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </form>
                    @endif
                </section>
            </div>

            <section class="course-product-linked-products" aria-labelledby="course-product-linked-products-title">
                <h2 id="course-product-linked-products-title" class="admin-form-section-title">
                    {{ __('lf.LF_course_product_relation_common_linked_heading', ['count' => $productRelationCount]) }}
                </h2>
                @if($productRelations->isEmpty())
                    <div class="course-product-relation-empty" role="status">
                        <p>{{ __('lf.LF_course_product_relation_common_empty') }}</p>
                        <span>{{ __('lf.LF_course_product_relation_common_empty_help') }}</span>
                    </div>
                @else
                <div class="admin-table-wrap course-product-relations-table-wrap">
                    <table class="table course-product-relations-table admin-table-has-actions">
                    <caption class="sr-only">{{ __('lf.LF_course_product_relation_common_linked_heading', ['count' => $productRelationCount]) }}</caption>
                    <thead>
                    <tr>
                        <th scope="col">{{ __('lf.LF_course_product_relation_common_related_product') }}</th>
                        <th scope="col">{{ __('lf.LF_product_v2_category') }}</th>
                        <th scope="col">{{ __('lf.LF_course_product_relation_common_target_status') }}</th>
                        <th scope="col" class="course-product-relation-order">{{ __('lf.LF_course_product_relation_common_sort_order') }}</th>
                        <th scope="col">{{ __('lf.table_actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($productRelations as $relation)
                        <tr>
                            <td data-label="{{ __('lf.LF_course_product_relation_common_related_product') }}">
                                <span class="course-product-relation-name">{{ $relation->related_product_title }}</span>
                                <span class="course-product-relation-code">{{ $relation->related_product_code }}</span>
                            </td>
                            <td data-label="{{ __('lf.LF_product_v2_category') }}">{{ $relation->related_product_category_name ?: '—' }}</td>
                            <td data-label="{{ __('lf.LF_course_product_relation_common_target_status') }}">
                                <span @class(['badge', 'badge-success' => $relation->related_product_status === 'active', 'badge-danger' => $relation->related_product_status === 'archived'])>
                                    {{ __('lf.LF_course_product_common_'.$relation->related_product_status) }}
                                </span>
                            </td>
                            <td data-label="{{ __('lf.LF_course_product_relation_common_sort_order') }}" class="course-product-relation-order">{{ $relation->sort_order }}</td>
                            <td data-label="{{ __('lf.table_actions') }}">
                                <x-admin-action-menu :label="__('lf.table_actions').': '.$relation->related_product_title">
                                <a href="{{ route($routePrefix.'.edit', $relation->related_product_id) }}" class="admin-text-action">
                                    <x-admin-action-icon name="view" />
                                    {{ __('lf.LF_course_product_relation_common_view_product') }}
                                </a>
                                @if($product->status !== 'archived')
                                <form method="POST" class="admin-inline-form" x-data="{ submitting: false }"
                                      data-lf-confirm="{{ __('lf.LF_course_product_relation_common_remove_confirm') }}"
                                      data-lf-confirm-tone="danger"
                                      data-lf-confirm-label="{{ __('lf.LF_common_button_remove') }}"
                                      @submit="if (submitting) { $event.preventDefault(); return } submitting = true"
                                      action="{{ route($routePrefix.'.relations.destroy', [$product->id, $relation->id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="admin-link-button admin-text-action admin-danger-text-action" type="submit" :disabled="submitting" :aria-busy="submitting">
                                        <x-admin-action-icon name="remove" />
                                        {{ __('lf.LF_course_product_relation_common_remove') }}
                                    </button>
                                </form>
                                @endif
                                </x-admin-action-menu>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                    </table>
                </div>
                @endif
            </section>
        </section>
    </div>
@endsection
