@extends('layouts.backend')

@section('title', __('lf.LF_course_product_common_edit'))
@section('page_title', __('lf.LF_course_product_common_edit'))

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
            <div class="admin-card admin-form-card admin-form-surface">
                <form id="course-product-update-form"
                      class="admin-form-standard"
                      method="POST"
                      action="{{ route($routePrefix.'.update', $product->id) }}"
                      enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    @if($product->status === 'archived')<fieldset disabled>@endif
                    @include('course-products.partials.form')
                    @if($product->status === 'archived')</fieldset>@endif

                </form>

                <footer class="admin-form-footer">
                    <div class="admin-form-footer-danger">
                        @if($product->status === 'inactive')
                            <form method="POST"
                                  action="{{ route($routePrefix.'.archive', $product->id) }}"
                                  x-data="{ submitting: false }"
                                  @submit="if (submitting || !window.confirm(@js(__('lf.LF_product_status_archive_confirm')))) { $event.preventDefault(); return } submitting = true">
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
                                  @submit="if (submitting || !window.confirm(@js(__('lf.LF_product_status_restore_confirm')))) { $event.preventDefault(); return } submitting = true">
                                @csrf
                                <button type="submit" class="btn btn-primary" :disabled="submitting" :aria-busy="submitting">
                                    {{ __('lf.LF_product_status_restore_action') }}
                                </button>
                            </form>
                        @endif
                    </div>

                    <div class="admin-form-footer-primary">
                        @if($product->status !== 'archived')
                            <button type="submit" form="course-product-update-form" class="btn btn-primary">
                                {{ __('lf.LF_common_button_save_changes') }}
                            </button>
                        @endif
                        <a href="{{ route($routePrefix.'.index') }}" class="admin-form-cancel">
                            {{ __('lf.LF_common_button_cancel') }}
                        </a>
                    </div>
                </footer>
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
                          @submit="if (submitting || !selected || selected === current) { $event.preventDefault(); return } if (!window.confirm(@js(__('lf.LF_course_product_item_common_replace_confirm')))) { $event.preventDefault(); return } submitting = true">
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
                    <table class="table course-product-relations-table">
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
                                <div class="course-product-relation-actions">
                                <a href="{{ route($routePrefix.'.edit', $relation->related_product_id) }}" class="admin-text-action">
                                    {{ __('lf.LF_course_product_relation_common_view_product') }}
                                </a>
                                @if($product->status !== 'archived')
                                <form method="POST" class="admin-inline-form" x-data="{ submitting: false }"
                                      @submit="if (submitting || !window.confirm(@js(__('lf.LF_course_product_relation_common_remove_confirm')))) { $event.preventDefault(); return } submitting = true"
                                      action="{{ route($routePrefix.'.relations.destroy', [$product->id, $relation->id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="admin-link-button admin-text-action admin-danger-text-action" type="submit" :disabled="submitting" :aria-busy="submitting">
                                        {{ __('lf.LF_course_product_relation_common_remove') }}
                                    </button>
                                </form>
                                @endif
                                </div>
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
