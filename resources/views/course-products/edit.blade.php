@extends('layouts.backend')

@section('title', __('lf.LF_course_product_common_edit'))
@section('page_title', __('lf.LF_course_product_common_edit'))

@section('page_back')
    <x-page-back-link :href="route($routePrefix.'.index')" :label="__('lf.LF_course_product_common_back_to_products')" />
@endsection

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

    {{-- The one success message, whichever tab opens: a fixed toast, so it stays out of the page flow. --}}
    <div class="lf-admin-ui ui-page-toasts"><x-ui-list-toasts /></div>

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="course-product-editor lf-admin-ui lf-admin-ui--list"
         x-data="{ activeTab: @js($initialTab) }"
         x-init="if (@js($focusRelationSearch)) $nextTick(() => document.getElementById('related_product_search-ui-kit')?.focus())">
        {{-- Same identity card as the Version detail page: code, name, then status and course type. --}}
        <section class="mk-section ui-product-identity" aria-labelledby="course-product-identity-title">
            <div class="ui-version__identity">
                <p class="ui-version__eyebrow">{{ $product->product_code }}</p>
                <h2 id="course-product-identity-title">{{ $product->title }}</h2>
                <div class="ui-version__chips">
                    <span class="ui-status ui-status--{{ $product->status }}">{{ __('lf.LF_course_product_common_'.$product->status) }}</span>
                    @if ($boundCourseType ?? null)
                        <span class="ui-course-type ui-course-type--{{ $boundCourseType }}" data-course-type="{{ $boundCourseType }}">{{ __(\App\Support\CourseType::labelKey($boundCourseType)) }}</span>
                    @endif
                </div>
            </div>
        </section>

        <nav class="course-template-tabs"
             role="tablist"
             aria-label="{{ __('lf.LF_course_product_common_tabs_label') }}">
            <button type="button"
                    id="course-product-tab-general"
                    class="course-template-tab cursor-interaction"
                    x-bind:class="{ 'is-active': activeTab === 'general' }"
                    x-bind:aria-selected="activeTab === 'general'"
                    x-on:click="activeTab = 'general'"
                    role="tab"
                    aria-controls="course-product-panel-general">
                {{ __('lf.LF_course_product_tab_general') }}
            </button>
            <button type="button"
                    id="course-product-tab-versions"
                    class="course-template-tab cursor-interaction"
                    x-bind:class="{ 'is-active': activeTab === 'versions' }"
                    x-bind:aria-selected="activeTab === 'versions'"
                    x-on:click="activeTab = 'versions'"
                    role="tab"
                    aria-controls="course-product-panel-versions">
                {{ __('lf.LF_course_product_tab_course_versions') }}
                <span class="ui-tab-count">{{ $productItemCount }}</span>
            </button>
            <button type="button"
                    id="course-product-tab-relations"
                    class="course-template-tab cursor-interaction"
                    x-bind:class="{ 'is-active': activeTab === 'relations' }"
                    x-bind:aria-selected="activeTab === 'relations'"
                    x-on:click="activeTab = 'relations'"
                    role="tab"
                    aria-controls="course-product-panel-relations">
                {{ __('lf.LF_course_product_tab_related_products') }}
                <span class="ui-tab-count">{{ $productRelationCount }}</span>
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
                @php
                    $dash = '—';
                    $summaryCategory = $categories->firstWhere('id', (int) $product->category_id)?->name;
                    $summaryTemplate = $templates->firstWhere('id', (int) ($selectedTemplateId ?? 0));
                    $money = static fn ($value): string => number_format((float) $value, $product->currency === 'USD' ? 2 : 0).' '.$product->currency;
                    $dateTime = static fn ($value): ?string => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i') : null;
                    $window = static fn ($from, $to): ?string => $from || $to ? trim(($from ?? $dash).' → '.($to ?? $dash)) : null;
                    $summaryGroups = [
                        'basic' => [__('lf.LF_product_v2_group_basic'), [
                            ['title', __('lf.LF_product_commercial_name'), $product->title],
                            ['category', __('lf.LF_product_v2_category'), $summaryCategory],
                            ['template', __('lf.LF_product_v2_template'), $summaryTemplate?->name],
                            ['version', __('lf.LF_product_v2_version_in_use'), null],
                            ['course_type', __('lf.LF_course_type_label'), ($boundCourseType ?? null) ? __(\App\Support\CourseType::labelKey($boundCourseType)) : null],
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
                                        class="ui-button ui-button--danger-outline cursor-interaction"
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
                                <button type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="submitting" :aria-busy="submitting">
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
            <div class="lf-admin-ui lf-admin-ui--list"
                 x-data="{ replacing: @js($hasItemErrors || ! $initialItem?->version_id), submitting: false, selected: @js($itemVersionId), current: @js((string) ($initialItem?->version_id ?? '')) }">
                <section class="mk-section" aria-labelledby="course-product-items-title">
                    <div class="ui-summary-head">
                        <div>
                            <h2 id="course-product-items-title">{{ __('lf.LF_course_product_item_common_title') }}</h2>
                            <p class="ui-caption">{{ __('lf.LF_course_product_item_common_current_enrollment_help') }}</p>
                        </div>
                        @if ($currentItemTemplate && $initialItem?->version_id)
                            <div class="ui-product-version__actions">
                                <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($templateRoutePrefix.'.versions.show', [$initialItem->template_id, $initialItem->version_id]) }}">
                                    <x-backend-icon name="eye" class="ui-icon" />{{ __('lf.LF_course_product_item_common_view_version') }}
                                </a>
                                <button type="button" class="ui-button ui-button--primary cursor-interaction"
                                        x-show="!replacing"
                                        x-on:click="replacing = true; $nextTick(() => document.getElementById('version_id')?.focus())"
                                        x-bind:aria-expanded="replacing"
                                        aria-controls="course-product-version-replace-form">
                                    {{ __('lf.LF_course_product_item_common_replace') }}
                                </button>
                            </div>
                        @endif
                    </div>

                    @if ($currentItemTemplate)
                        <dl class="ui-summary ui-product-version__summary">
                            <div class="ui-summary__item">
                                <dt>{{ __('lf.LF_course_product_item_common_current_template') }}</dt>
                                <dd>{{ $currentItemTemplate->name }}</dd>
                            </div>
                            @if ($initialItem?->version_id)
                                <div class="ui-summary__item">
                                    <dt>{{ __('lf.LF_course_product_item_common_current_version') }}</dt>
                                    <dd>
                                        <span class="ui-summary__version-main">
                                            <span>{{ $initialItem->version_number_label }} · {{ $initialItem->version_code }}</span>
                                            <span class="ui-status ui-status--active">{{ __('lf.LF_course_product_item_common_published') }}</span>
                                        </span>
                                        <span class="ui-caption">{{ __('lf.LF_course_product_item_common_released_on', ['date' => $initialItem->published_at ? date('d/m/Y', strtotime($initialItem->published_at)) : '—']) }}</span>
                                    </dd>
                                </div>
                            @endif
                        </dl>
                        <form id="course-product-version-replace-form"
                              class="ui-product-version__form"
                              method="POST" action="{{ route($routePrefix.'.items.store', $product->id) }}"
                              x-show="replacing" x-cloak
                              data-lf-confirm="{{ __('lf.LF_course_product_item_common_replace_confirm') }}"
                              @submit="if (submitting || !selected || selected === current) { $event.preventDefault(); return } submitting = true">
                            @csrf
                            <div class="ui-field">
                                <label class="ui-field__label" for="version_id"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_product_item_common_template_version') }}</label>
                                <select id="version_id" name="version_id" data-ui-kit-select required x-on:change="selected = $event.target.value"
                                        data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}">
                                    <option value="">{{ __('lf.LF_course_product_item_common_select_version') }}</option>
                                    @foreach ($publishedVersions as $version)
                                        <option value="{{ $version->id }}" @selected((string) $version->id === $itemVersionId)>{{ __('lf.LF_course_product_item_common_version_number', ['number' => $version->version_number]) }} · {{ $version->version_code }} · {{ $version->published_at ? date('d/m/Y', strtotime($version->published_at)) : '—' }}@if ($version->is_in_use) · {{ __('lf.LF_course_product_item_common_in_use') }}@endif</option>
                                    @endforeach
                                </select>
                                @if ($publishedVersions->isEmpty())
                                    <span class="ui-field__hint" role="status">{{ __('lf.LF_course_product_item_common_no_versions') }}</span>
                                @endif
                                @error('version_id')<span class="ui-field__error" role="alert">{{ $message }}</span>@enderror
                            </div>
                            <div class="ui-product-version__form-actions">
                                <button type="button" class="ui-button cursor-interaction" :disabled="submitting"
                                        x-on:click="replacing = false; selected = current">
                                    {{ __('lf.LF_common_button_cancel') }}
                                </button>
                                <button type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="submitting || !selected || selected === current" :aria-busy="submitting">
                                    {{ __('lf.LF_course_product_item_common_confirm_replace') }}
                                </button>
                            </div>
                        </form>
                    @else
                        <p class="ui-product-version__empty ui-caption" role="status">{{ __('lf.LF_course_product_item_common_select_template_overview') }}</p>
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
            <div class="lf-admin-ui lf-admin-ui--list ui-product-relations">
                {{-- One list card, as on the list pages: the add control is the toolbar, the linked Products the table. --}}
                <section class="mk-section" aria-labelledby="course-product-relations-title">
                    <div class="ui-summary-head">
                        <div>
                            <h2 id="course-product-relations-title">{{ __('lf.LF_course_product_relation_common_title') }}</h2>
                            <p class="ui-caption">{{ __('lf.LF_course_product_relation_common_help') }}</p>
                        </div>
                    </div>
                    @if($product->status !== 'archived')
                        <form method="POST" class="mk-tablebar ui-product-relations__form"
                              action="{{ route($routePrefix.'.relations.store', $product->id) }}"
                              x-data="{ selected: @js((string) old('related_product_id', '')), submitting: false }"
                              @submit="if (submitting || !selected) { $event.preventDefault(); return } submitting = true">
                            @csrf
                            <div class="ui-field ui-product-relations__picker">
                                <label class="ui-field__label ui-sr-only" for="related_product_search">{{ __('lf.LF_course_product_relation_common_related_product') }}</label>
                                <select id="related_product_search" name="related_product_id" data-ui-kit-select x-on:change="selected = $event.target.value"
                                        data-ui-empty-label="{{ __('lf.LF_course_product_relation_common_no_results') }}" data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}">
                                    <option value="">{{ __('lf.LF_course_product_relation_common_search_placeholder') }}</option>
                                    @foreach ($relatedProducts as $relatedOption)
                                        <option value="{{ $relatedOption->id }}" @selected((string) old('related_product_id') === (string) $relatedOption->id)>{{ $relatedOption->title }} · {{ $relatedOption->product_code }}@if ($relatedOption->category_name ?? null) · {{ $relatedOption->category_name }}@endif · {{ $relatedOption->status_label }}</option>
                                    @endforeach
                                </select>
                                @error('related_product_id')<span class="ui-field__error" role="alert">{{ $message }}</span>@enderror
                            </div>
                            <button type="submit" class="ui-button ui-button--outline cursor-interaction" :disabled="submitting || !selected" :aria-busy="submitting">
                                <x-backend-icon name="plus" class="ui-icon" />{{ __('lf.LF_course_product_relation_common_attach') }}
                            </button>
                        </form>
                    @endif
                    <div class="mk-table-scroll">
                        <table aria-labelledby="course-product-relations-title">
                            <colgroup>
                                <col class="mk-col-sequence">
                                <col>
                                <col>
                                <col class="mk-col-status">
                                <col class="mk-col-sequence">
                                <col class="mk-col-action-wide">
                            </colgroup>
                            <thead>
                            <tr>
                                <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_product_relation_common_related_product') }}</th>
                                <th scope="col" class="mk-text-cell">{{ __('lf.LF_product_v2_category') }}</th>
                                <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_product_relation_common_target_status') }}</th>
                                <th scope="col" class="mk-sequence">{{ __('lf.LF_course_product_relation_common_sort_order') }}</th>
                                <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($productRelations as $relation)
                                <tr>
                                    <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                                    <td class="mk-text-cell" data-label="{{ __('lf.LF_course_product_relation_common_related_product') }}">
                                        <span class="mk-name-text">{{ $relation->related_product_title }}</span>
                                        <br><span class="ui-caption">{{ $relation->related_product_code }}</span>
                                    </td>
                                    <td class="mk-text-cell" data-label="{{ __('lf.LF_product_v2_category') }}">{{ $relation->related_product_category_name ?: '—' }}</td>
                                    <td class="ux-action-cell" data-label="{{ __('lf.LF_course_product_relation_common_target_status') }}">
                                        <span class="ui-status ui-status--{{ $relation->related_product_status }}">{{ __('lf.LF_course_product_common_'.$relation->related_product_status) }}</span>
                                    </td>
                                    <td class="mk-sequence" data-label="{{ __('lf.LF_course_product_relation_common_sort_order') }}">{{ $relation->sort_order }}</td>
                                    <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                        <a class="ux-row-edit cursor-interaction" href="{{ route($routePrefix.'.edit', $relation->related_product_id) }}"
                                           aria-label="{{ __('lf.LF_course_product_relation_common_view_product') }}: {{ $relation->related_product_title }}"
                                           data-tooltip="{{ __('lf.LF_course_product_relation_common_view_product') }}">
                                            <x-backend-icon name="eye" class="ui-icon" />
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
                                                <button class="ux-row-delete cursor-interaction" type="submit" :disabled="submitting" :aria-busy="submitting"
                                                        aria-label="{{ __('lf.LF_course_product_relation_common_remove') }}: {{ $relation->related_product_title }}"
                                                        data-tooltip="{{ __('lf.LF_course_product_relation_common_remove') }}">
                                                    <x-backend-icon name="trash" class="ui-icon" />
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr class="mk-empty-row">
                                    <td class="mk-empty" colspan="6">
                                        <div role="status">
                                            <strong>{{ __('lf.LF_course_product_relation_common_empty') }}</strong>
                                            <p class="ui-caption">{{ __('lf.LF_course_product_relation_common_empty_help') }}</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </section>
    </div>
@endsection
