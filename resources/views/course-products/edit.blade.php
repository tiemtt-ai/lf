@extends('layouts.backend')

@section('title', __('lf.LF_course_product_common_edit'))
@section('page_title', __('lf.LF_course_product_common_edit'))

@section('content')
    @php
        $productItemCount = $productItems->count();
        $productRelationCount = $productRelations->count();
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

    <div class="course-product-editor" x-data="{ activeTab: 'general' }">
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
                <form method="POST" action="{{ route($routePrefix.'.update', $product->id) }}">
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

                    <form method="POST" action="{{ route($routePrefix.'.items.store', $product->id) }}">
                        @csrf

                        <div class="lf-form-group">
                            <x-form-label for="template_version_id"
                                          :value="__('lf.LF_course_product_item_common_template_version')"
                                          :required="true" />
                            <select id="template_version_id"
                                    name="template_version_id"
                                    class="lf-form-control"
                                    required>
                                <option value="">
                                    {{ __('lf.LF_course_product_item_common_select_version') }}
                                </option>
                                @foreach ($publishedVersions as $version)
                                    <option value="{{ $version->id }}"
                                            @selected((int) old('template_version_id') === $version->id)>
                                        {{ $version->title_snapshot }}
                                        —
                                        {{ __('lf.LF_course_product_item_common_version_number', ['number' => $version->version_number]) }}
                                        ({{ $version->version_code }})
                                        @if ($version->is_current)
                                            · {{ __('lf.LF_course_product_item_common_current') }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="title_override"
                                          :value="__('lf.LF_course_product_item_common_title_override')" />
                            <input id="title_override"
                                   type="text"
                                   name="title_override"
                                   class="lf-form-control"
                                   value="{{ old('title_override') }}"
                                   maxlength="255">
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="short_description_override"
                                          :value="__('lf.LF_course_product_item_common_short_description_override')" />
                            <textarea id="short_description_override"
                                      name="short_description_override"
                                      class="lf-form-control"
                                      rows="2"
                                      maxlength="500">{{ old('short_description_override') }}</textarea>
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="item_sort_order"
                                          :value="__('lf.LF_course_product_item_common_sort_order')"
                                          :required="true" />
                            <input id="item_sort_order"
                                   type="number"
                                   name="sort_order"
                                   class="lf-form-control"
                                   value="{{ old('sort_order', 0) }}"
                                   required>
                        </div>

                        <div class="lf-form-group">
                            <input type="hidden" name="is_required" value="0">
                            <div class="admin-radio-group">
                                <input id="is_required"
                                       type="checkbox"
                                       name="is_required"
                                       value="1"
                                       @checked((bool) old('is_required', true))>
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
                                            @selected(old('status', 'active') === $itemStatus)>
                                        {{ __('lf.LF_course_product_item_common_'.$itemStatus) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="admin-form-actions">
                            <button type="submit" class="btn btn-primary">
                                {{ __('lf.LF_course_product_item_common_attach') }}
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <div class="admin-table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>{{ __('lf.LF_common_label_common_id') }}</th>
                        <th>{{ __('lf.LF_course_product_item_common_template_version') }}</th>
                        <th>{{ __('lf.LF_course_product_item_common_display_title') }}</th>
                        <th>{{ __('lf.LF_course_product_item_common_sort_order') }}</th>
                        <th>{{ __('lf.LF_course_product_item_common_required') }}</th>
                        <th>{{ __('lf.LF_course_product_item_common_status') }}</th>
                        <th>{{ __('lf.LF_common_label_common_action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($productItems as $item)
                        <tr>
                            <td>{{ $item->id }}</td>
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
                                <form method="POST"
                                      action="{{ route($routePrefix.'.items.destroy', [$product->id, $item->id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="admin-link-button" type="submit">
                                        {{ __('lf.LF_course_product_item_common_remove') }}
                                    </button>
                                </form>
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

                    <form method="POST" action="{{ route($routePrefix.'.relations.store', $product->id) }}">
                        @csrf

                        <div class="lf-form-group">
                            <x-form-label for="related_product_id"
                                          :value="__('lf.LF_course_product_relation_common_related_product')"
                                          :required="true" />
                            <select id="related_product_id"
                                    name="related_product_id"
                                    class="lf-form-control"
                                    required>
                                <option value="">
                                    {{ __('lf.LF_course_product_relation_common_select_product') }}
                                </option>
                                @foreach ($relatedProducts as $relatedProduct)
                                    <option value="{{ $relatedProduct->id }}"
                                            @selected((int) old('related_product_id') === $relatedProduct->id)>
                                        {{ $relatedProduct->title }}
                                        ({{ $relatedProduct->product_code }})
                                        · {{ __('lf.LF_course_product_common_'.$relatedProduct->status) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="relation_type"
                                          :value="__('lf.LF_course_product_relation_common_relation_type')"
                                          :required="true" />
                            <select id="relation_type" name="relation_type" class="lf-form-control" required>
                                @foreach (['gift', 'related', 'upsell', 'cross_sell', 'recommended'] as $relationType)
                                    <option value="{{ $relationType }}"
                                            @selected(old('relation_type', 'related') === $relationType)>
                                        {{ __('lf.LF_course_product_relation_common_type_'.$relationType) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="relation_title_override"
                                          :value="__('lf.LF_course_product_relation_common_title_override')" />
                            <input id="relation_title_override"
                                   type="text"
                                   name="title_override"
                                   class="lf-form-control"
                                   value="{{ old('title_override') }}"
                                   maxlength="255">
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="description_override"
                                          :value="__('lf.LF_course_product_relation_common_description_override')" />
                            <textarea id="description_override"
                                      name="description_override"
                                      class="lf-form-control"
                                      rows="2"
                                      maxlength="500">{{ old('description_override') }}</textarea>
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="relation_sort_order"
                                          :value="__('lf.LF_course_product_relation_common_sort_order')"
                                          :required="true" />
                            <input id="relation_sort_order"
                                   type="number"
                                   name="sort_order"
                                   class="lf-form-control"
                                   value="{{ old('sort_order', 0) }}"
                                   required>
                        </div>

                        <div class="lf-form-group">
                            <input type="hidden" name="is_featured" value="0">
                            <div class="admin-radio-group">
                                <input id="relation_is_featured"
                                       type="checkbox"
                                       name="is_featured"
                                       value="1"
                                       @checked((bool) old('is_featured', false))>
                                <label for="relation_is_featured">
                                    {{ __('lf.LF_course_product_relation_common_is_featured') }}
                                </label>
                            </div>
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="starts_at"
                                          :value="__('lf.LF_course_product_relation_common_starts_at')" />
                            <input id="starts_at"
                                   type="datetime-local"
                                   name="starts_at"
                                   class="lf-form-control"
                                   value="{{ old('starts_at') }}">
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="ends_at"
                                          :value="__('lf.LF_course_product_relation_common_ends_at')" />
                            <input id="ends_at"
                                   type="datetime-local"
                                   name="ends_at"
                                   class="lf-form-control"
                                   value="{{ old('ends_at') }}">
                        </div>

                        <div class="lf-form-group">
                            <x-form-label for="relation_status"
                                          :value="__('lf.LF_course_product_relation_common_status')"
                                          :required="true" />
                            <select id="relation_status" name="status" class="lf-form-control" required>
                                @foreach (['active', 'inactive'] as $relationStatus)
                                    <option value="{{ $relationStatus }}"
                                            @selected(old('status', 'active') === $relationStatus)>
                                        {{ __('lf.LF_course_product_relation_common_'.$relationStatus) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="admin-form-actions">
                            <button type="submit" class="btn btn-primary">
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
                        <th>{{ __('lf.LF_common_label_common_id') }}</th>
                        <th>{{ __('lf.LF_course_product_relation_common_related_product') }}</th>
                        <th>{{ __('lf.LF_course_product_relation_common_relation_type') }}</th>
                        <th>{{ __('lf.LF_course_product_relation_common_display_title') }}</th>
                        <th>{{ __('lf.LF_course_product_relation_common_sort_order') }}</th>
                        <th>{{ __('lf.LF_course_product_relation_common_featured') }}</th>
                        <th>{{ __('lf.LF_course_product_relation_common_status') }}</th>
                        <th>{{ __('lf.LF_common_label_common_action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($productRelations as $relation)
                        <tr>
                            <td>{{ $relation->id }}</td>
                            <td>
                                {{ $relation->related_product_title }}
                                <br>
                                <small>
                                    {{ $relation->related_product_code }}
                                    · {{ __('lf.LF_course_product_common_'.$relation->related_product_status) }}
                                </small>
                            </td>
                            <td>
                                {{ __('lf.LF_course_product_relation_common_type_'.$relation->relation_type) }}
                            </td>
                            <td>{{ $relation->title_override ?: $relation->related_product_title }}</td>
                            <td>{{ $relation->sort_order }}</td>
                            <td>
                                {{ $relation->is_featured
                                    ? __('lf.LF_course_product_relation_common_yes')
                                    : __('lf.LF_course_product_relation_common_no') }}
                            </td>
                            <td>
                                <span @class([
                                    'badge',
                                    'badge-success' => $relation->status === 'active',
                                ])>
                                    {{ __('lf.LF_course_product_relation_common_'.$relation->status) }}
                                </span>
                            </td>
                            <td>
                                <form method="POST"
                                      action="{{ route($routePrefix.'.relations.destroy', [$product->id, $relation->id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="admin-link-button" type="submit">
                                        {{ __('lf.LF_course_product_relation_common_remove') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
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
