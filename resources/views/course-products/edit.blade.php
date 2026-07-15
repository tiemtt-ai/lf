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

    <div class="course-product-editor" x-data="{ activeTab: @js($hasItemErrors ? 'versions' : 'general') }">
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
            <div class="admin-card admin-form-card">
                <form method="POST"
                      action="{{ route($routePrefix.'.update', $product->id) }}"
                      enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    @include('course-products.partials.form')

                    <div class="admin-form-actions">
                        <button type="submit" class="btn btn-primary">
                            {{ __('lf.LF_common_button_save_changes') }}
                        </button>
                        <a href="{{ route($routePrefix.'.index') }}">
                            {{ __('lf.LF_common_button_cancel') }}
                        </a>
                    </div>
                </form>
            </div>
        </section>

        <section id="course-product-panel-versions"
                 class="course-product-tab-panel"
                 x-show="activeTab === 'versions'"
                 x-cloak
                 role="tabpanel"
                 aria-labelledby="course-product-tab-versions">
            <div class="admin-card admin-form-card">
                <section class="admin-form-section" aria-labelledby="course-product-items-title">
                    <h2 id="course-product-items-title" class="admin-form-section-title">
                        {{ __('lf.LF_course_product_item_common_title') }}
                    </h2>

                    <p>
                        {{ __('lf.LF_course_product_item_common_help') }}
                    </p>

                    @if ($currentItemTemplate)
                    <form method="POST" action="{{ route($routePrefix.'.items.store', $product->id) }}">
                        @csrf

                        <div class="lf-form-group">
                            <span class="lf-form-label">{{ __('lf.LF_course_product_item_common_current_template') }}</span>
                            <p class="lf-form-help">{{ $currentItemTemplate->name }}</p>
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="version_id"
                                          :value="__('lf.LF_course_product_item_common_template_version')"
                                          :required="true" />
                            <select id="version_id"
                                    name="version_id"
                                    class="lf-form-control"
                                    required>
                                <option value="">{{ __('lf.LF_course_product_item_common_select_version') }}</option>
                                @foreach ($publishedVersions as $version)
                                    <option value="{{ $version->id }}" @selected((string) $version->id === $itemVersionId)>
                                        {{ $version->version_code }} — {{ __('lf.LF_course_product_item_common_version_number', ['number' => $version->version_number]) }} — {{ $version->published_at ? date('d/m/Y', strtotime($version->published_at)) : '—' }}
                                        @if ($version->is_latest_published) · {{ __('lf.LF_course_product_item_common_latest') }} @endif
                                        @if ($version->is_in_use) · {{ __('lf.LF_course_product_item_common_in_use') }} @endif
                                    </option>
                                @endforeach
                            </select>
                            @if ($publishedVersions->isEmpty())
                                <p class="lf-form-help" role="status">{{ __('lf.LF_course_product_item_common_no_versions') }}</p>
                            @endif
                            @error('version_id')<p class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="title_override"
                                          :value="__('lf.LF_course_product_item_common_title_override')" />
                            <input id="title_override"
                                   type="text"
                                   name="title_override"
                                   class="lf-form-control"
                                   value="{{ old('title_override', $initialItem?->title_override) }}"
                                   maxlength="255">
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="short_description_override"
                                          :value="__('lf.LF_course_product_item_common_short_description_override')" />
                            <textarea id="short_description_override"
                                      name="short_description_override"
                                      class="lf-form-control"
                                      rows="2"
                                      maxlength="500">{{ old('short_description_override', $initialItem?->short_description_override) }}</textarea>
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="item_sort_order"
                                          :value="__('lf.LF_course_product_item_common_sort_order')"
                                          :required="true" />
                            <input id="item_sort_order"
                                   type="number"
                                   name="sort_order"
                                   class="lf-form-control"
                                   value="{{ old('sort_order', $initialItem?->sort_order ?? 0) }}"
                                   required>
                        </div>

                        <div class="lf-form-group">
                            <input type="hidden" name="is_required" value="0">
                            <div class="admin-radio-group">
                                <input id="is_required"
                                       type="checkbox"
                                       name="is_required"
                                       value="1"
                                       @checked((bool) old('is_required', $initialItem?->is_required ?? true))>
                                <label for="is_required">
                                    {{ __('lf.LF_course_product_item_common_is_required') }}
                                </label>
                            </div>
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="item_status"
                                          :value="__('lf.LF_course_product_item_common_status')"
                                          :required="true" />
                            <select id="item_status" name="status" class="lf-form-control" required>
                                @foreach (['active', 'inactive'] as $itemStatus)
                                    <option value="{{ $itemStatus }}"
                                            @selected(old('status', $initialItem?->status ?? 'active') === $itemStatus)>
                                        {{ __('lf.LF_course_product_item_common_'.$itemStatus) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="admin-form-actions">
                            <button type="submit" class="btn btn-primary">
                                {{ $initialItem?->version_id
                                    ? __('lf.LF_course_product_item_common_replace')
                                    : __('lf.LF_course_product_item_common_attach') }}
                            </button>
                        </div>
                    </form>
                    @else
                        <p class="lf-form-help" role="status">{{ __('lf.LF_course_product_item_common_select_template_overview') }}</p>
                    @endif
                </section>
            </div>

            <div class="admin-table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                        <th>{{ __('lf.LF_course_product_item_common_template_version') }}</th>
                        <th>{{ __('lf.LF_course_product_item_common_display_title') }}</th>
                        <th>{{ __('lf.LF_course_product_item_common_sort_order') }}</th>
                        <th>{{ __('lf.LF_course_product_item_common_required') }}</th>
                        <th>{{ __('lf.LF_course_product_item_common_status') }}</th>
                        <th>{{ __('lf.table_actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($linkedProductItems as $item)
                        <tr>
                            <td class="admin-table-sequence">{{ $loop->iteration }}</td>
                            <td>
                                {{ $item->title_snapshot }}
                                <br>
                                <small>
                                    {{ __('lf.LF_course_product_item_common_version_number', ['number' => $item->version_number]) }}
                                    · {{ $item->version_code }}
                                    @if ($item->is_current)
                                        · {{ __('lf.LF_course_product_item_common_current') }}
                                    @endif
                                </small>
                            </td>
                            <td>{{ $item->title_override ?: $item->title_snapshot }}</td>
                            <td>{{ $item->sort_order }}</td>
                            <td>
                                {{ $item->is_required
                                    ? __('lf.LF_course_product_item_common_yes')
                                    : __('lf.LF_course_product_item_common_no') }}
                            </td>
                            <td>
                                <span @class([
                                    'badge',
                                    'badge-success' => $item->status === 'active',
                                ])>
                                    {{ __('lf.LF_course_product_item_common_'.$item->status) }}
                                </span>
                            </td>
                            <td>
                                <div class="admin-table-actions">
                                    <form method="POST"
                                          action="{{ route($routePrefix.'.items.destroy', [$product->id, $item->id]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="admin-link-button admin-text-action" type="submit">
                                            {{ __('lf.LF_course_product_item_common_remove') }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                {{ __('lf.LF_course_product_item_common_empty') }}
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section id="course-product-panel-relations"
                 class="course-product-tab-panel"
                 x-show="activeTab === 'relations'"
                 x-cloak
                 role="tabpanel"
                 aria-labelledby="course-product-tab-relations">
            <div class="admin-card admin-form-card">
                <section class="admin-form-section" aria-labelledby="course-product-relations-title">
                    <h2 id="course-product-relations-title" class="admin-form-section-title">
                        {{ __('lf.LF_course_product_relation_common_title') }}
                    </h2>

                    <p>
                        {{ __('lf.LF_course_product_relation_common_help') }}
                    </p>

                    <form method="POST"
                          action="{{ route($routePrefix.'.relations.store', $product->id) }}"
                          x-data="{
                              products: @js($relatedProducts), query: '', selected: @js((string) old('related_product_id', '')),
                              open: false, loading: true, submitting: false, activeIndex: 0,
                              init() { this.$nextTick(() => { this.loading = false }) },
                              matches() { const q = this.query.trim().toLocaleLowerCase(); return this.products.filter(p => !q || `${p.product_code} ${p.title}`.toLocaleLowerCase().includes(q)) },
                              choose(product) { this.selected = String(product.id); this.query = `${product.product_code} — ${product.title}`; this.open = false },
                              move(step) { const count = this.matches().length; if (count) this.activeIndex = (this.activeIndex + step + count) % count },
                              chooseActive() { const product = this.matches()[this.activeIndex]; if (product) this.choose(product) }
                          }"
                          @submit="if (submitting || !selected) { $event.preventDefault(); return } submitting = true">
                        @csrf

                        <div class="lf-form-group">
                            <x-form-label for="related_product_search"
                                          :value="__('lf.LF_course_product_relation_common_related_product')"
                                          :required="true" />
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
                                    <p x-show="loading" class="lf-form-help" role="status">{{ __('lf.LF_course_product_relation_common_loading') }}</p>
                                    <template x-for="(item, index) in matches()" :key="item.id">
                                        <button type="button" role="option" class="lf-combobox-option"
                                                :aria-selected="String(item.id) === selected" :class="{ 'is-active': index === activeIndex }"
                                                @mouseenter="activeIndex = index" @click="choose(item)">
                                            <span x-text="`${item.product_code} — ${item.title}`"></span>
                                        </button>
                                    </template>
                                    <p x-show="!loading && matches().length === 0" class="lf-form-help" role="status">{{ __('lf.LF_course_product_relation_common_no_results') }}</p>
                                </div>
                            </div>
                            @error('related_product_id')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="admin-form-actions">
                            <button type="submit" class="btn btn-primary" :disabled="submitting || !selected" :aria-busy="submitting">
                                {{ __('lf.LF_course_product_relation_common_attach') }}
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <div class="admin-table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>{{ __('lf.LF_course_product_relation_common_related_product') }}</th>
                        <th>{{ __('lf.LF_course_product_common_product_code') }}</th>
                        <th>{{ __('lf.LF_product_v2_category') }}</th>
                        <th>{{ __('lf.LF_course_product_relation_common_target_status') }}</th>
                        <th>{{ __('lf.LF_course_product_relation_common_sort_order') }}</th>
                        <th>{{ __('lf.table_actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($productRelations as $relation)
                        <tr>
                            <td>{{ $relation->related_product_title }}</td>
                            <td>{{ $relation->related_product_code }}</td>
                            <td>{{ $relation->related_product_category_name ?: '—' }}</td>
                            <td>{{ __('lf.LF_course_product_common_'.$relation->related_product_status) }}</td>
                            <td>{{ $relation->sort_order }}</td>
                            <td>
                                <a href="{{ route($routePrefix.'.edit', $relation->related_product_id) }}" class="admin-text-action">
                                    {{ __('lf.LF_course_product_relation_common_view_product') }}
                                </a>
                                <span aria-hidden="true"> | </span>
                                <form method="POST" class="admin-inline-form"
                                      action="{{ route($routePrefix.'.relations.destroy', [$product->id, $relation->id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="admin-link-button admin-text-action" type="submit">
                                        {{ __('lf.LF_course_product_relation_common_remove') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                {{ __('lf.LF_course_product_relation_common_empty') }}
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
