@extends('layouts.backend')

@section('title', __('lf.LF_course_product_common_title'))
@section('page_title', __('lf.LF_course_product_common_title'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-card admin-form-card">
        <form method="GET" action="{{ route($routePrefix.'.index') }}">
            <div class="lf-form-group">
                <label class="lf-form-label" for="keyword">
                    {{ __('lf.LF_course_product_common_keyword') }}
                </label>
                <input id="keyword" type="search" name="keyword" class="lf-form-control"
                       value="{{ $keyword }}"
                       placeholder="{{ __('lf.LF_course_product_common_keyword_placeholder') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="status">
                    {{ __('lf.LF_course_product_common_status') }}
                </label>
                <select id="status" name="status" class="lf-form-control">
                    <option value="all" @selected($status === 'all')>{{ __('lf.LF_course_product_common_all_statuses') }}</option>
                    @foreach (['draft', 'active', 'inactive', 'archived'] as $productStatus)
                        <option value="{{ $productStatus }}" @selected($status === $productStatus)>
                            {{ __('lf.LF_course_product_common_'.$productStatus) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="visibility">
                    {{ __('lf.LF_course_product_common_visibility') }}
                </label>
                <select id="visibility" name="visibility" class="lf-form-control">
                    <option value="">{{ __('lf.LF_course_product_common_all_visibility') }}</option>
                    @foreach (['public', 'private', 'hidden'] as $productVisibility)
                        <option value="{{ $productVisibility }}" @selected($visibility === $productVisibility)>
                            {{ __('lf.LF_course_product_common_visibility_'.$productVisibility) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_search') }}
                </button>
                <a href="{{ route($routePrefix.'.index') }}">
                    {{ __('lf.LF_course_product_common_clear_filters') }}
                </a>
            </div>
        </form>
    </div>

    <div class="course-product-index-toolbar">
        <a href="{{ route($routePrefix.'.create') }}" class="btn btn-primary">
            {{ __('lf.LF_course_product_common_create') }}
        </a>
    </div>

    <div class="admin-table-wrap course-product-index-table-wrap">
        <table class="table course-product-index-table">
            <thead>
            <tr>
                <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                <th class="course-product-index-code">{{ __('lf.table_code') }}</th>
                <th>{{ __('lf.LF_course_product_common_title_field') }}</th>
                <th>{{ __('lf.LF_course_product_common_product_type') }}</th>
                <th>{{ __('lf.LF_course_product_common_price') }}</th>
                <th>{{ __('lf.LF_course_product_common_visibility') }}</th>
                <th class="course-product-index-status">{{ __('lf.LF_course_product_common_status') }}</th>
                <th class="course-product-index-actions">{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($products as $product)
                <tr>
                    <td class="admin-table-sequence">{{ $products->firstItem() + $loop->index }}</td>
                    <td class="course-product-index-code"><span class="course-product-index-code-value">{{ $product->product_code }}</span></td>
                    <td><strong class="course-product-index-primary">{{ $product->title }}</strong></td>
                    <td>
                        {{ $product->offering_type
                            ? __('lf.LF_product_v2_offering_'.$product->offering_type)
                            : '—' }}
                    </td>
                    <td>{{ number_format((float) $product->price, 0) }} {{ $product->currency }}</td>
                    <td>{{ __('lf.LF_course_product_common_visibility_'.$product->visibility) }}</td>
                    <td class="course-product-index-status">
                        <span @class([
                            'badge',
                            'course-product-status-badge',
                            'admin-status-badge--neutral' => ! in_array($product->status, ['active', 'archived'], true),
                            'badge-success' => $product->status === 'active',
                            'badge-danger' => $product->status === 'archived',
                        ])>
                            {{ __('lf.LF_course_product_common_'.$product->status) }}
                        </span>
                    </td>
                    <td class="course-product-index-actions">
                        <div class="admin-table-actions course-product-index-action-list">
                            <a class="admin-table-action-link admin-text-action" href="{{ route($routePrefix.'.edit', $product->id) }}">
                                {{ __('lf.action_edit') }}
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        {{ __('lf.LF_course_product_common_empty') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($products->hasPages())
        <div class="admin-pagination">
            {{ $products->links() }}
        </div>
    @endif
@endsection
