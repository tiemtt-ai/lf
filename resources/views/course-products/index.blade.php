@extends('layouts.backend')

@section('title', __('lf.LF_course_product_common_title'))
@section('page_title', __('lf.LF_course_product_common_title'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
<div id="product-list" class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope data-ui-row-actions
     data-csrf="{{ csrf_token() }}"
     data-msg-delete-error="{{ __('lf.LF_course_product_delete_error') }}"
     data-msg-session-expired="{{ __('lf.LF_course_product_session_expired') }}">
    @php
        $hasActiveFilters = $keyword !== '' || $status !== null || $visibility !== null;
    @endphp

    <x-ui-list-toasts />

    @if (session('success'))
        <div class="admin-alert admin-alert-success" role="status">
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

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters">
            <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.index') }}" data-ui-live-filter>
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="keyword">
                        {{ __('lf.LF_course_product_common_keyword') }}
                    </label>
                    <div class="ui-search">
                        <input id="keyword" type="search" name="keyword" value="{{ $keyword }}"
                               placeholder="{{ __('lf.LF_course_product_common_keyword_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                        </button>
                    </div>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="status">
                        {{ __('lf.LF_course_product_common_status') }}
                    </label>
                    <select id="status" name="status" data-ui-kit-select
                            data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}"
                            data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}">
                        <option value="">{{ __('lf.LF_course_product_common_all_statuses') }}</option>
                        @foreach (['draft', 'active', 'inactive', 'archived'] as $productStatus)
                            <option value="{{ $productStatus }}" @selected($status === $productStatus)>
                                {{ __('lf.LF_course_product_common_'.$productStatus) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="visibility">
                        {{ __('lf.LF_course_product_common_visibility') }}
                    </label>
                    <select id="visibility" name="visibility" data-ui-kit-select
                            data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}"
                            data-ui-clear-label="{{ __('lf.LF_course_product_common_clear_filters') }}">
                        <option value="">{{ __('lf.LF_course_product_common_all_visibility') }}</option>
                        @foreach (['public', 'private', 'hidden'] as $productVisibility)
                            <option value="{{ $productVisibility }}" @selected($visibility === $productVisibility)>
                                {{ __('lf.LF_course_product_common_visibility_'.$productVisibility) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <span class="ui-live-slot" data-ui-live-region="clear-filters">
                    @if ($hasActiveFilters)
                        <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.index') }}">
                            {{ __('lf.LF_course_product_common_clear_filters') }}
                        </a>
                    @endif
                </span>
            </form>

            <x-ui-list-toolbar :url="route($routePrefix.'.bulk-destroy')" name="ids[]"
                :label="__('lf.LF_course_product_bulk_delete')"
                :confirm-title="__('lf.LF_course_product_bulk_delete_title')" :confirm-message="__('lf.LF_course_product_bulk_delete_confirm', ['count' => ':count'])"
                :confirm-label="__('lf.LF_course_product_bulk_delete')">
                <x-slot:create>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                            data-ui-dialog-form-open data-ui-dialog-form-for="product-list" data-url="{{ route($routePrefix.'.create') }}" aria-haspopup="dialog">
                        <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                        {{ __('lf.LF_course_product_common_create_short') }}
                    </button>
                </x-slot:create>
            </x-ui-list-toolbar>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_course_product_common_title') }}" tabindex="0">
            <table aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col>
                    <col>
                    <col>
                    <col>
                    <col class="mk-col-status">
                    <col class="mk-col-action-wide">
                </colgroup>
                <thead>
                <tr>
                    <th scope="col" class="ui-check-cell">
                        <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all aria-label="{{ __('lf.LF_course_product_select_all') }}">
                    </th>
                    <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_product_common_title_field') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_product_common_product_type') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_product_common_price') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_product_common_visibility') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_product_common_status') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td class="ui-check-cell" data-label="{{ __('lf.LF_course_product_select') }}">
                            @if ($product->delete_blocker)
                                <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $product->delete_blocker }}" data-tooltip-align="start">
                                    <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ $product->delete_blocker }}">
                                </span>
                            @else
                                <input type="checkbox" class="ui-bulk-box" data-ui-bulk-item value="{{ $product->id }}"
                                       aria-label="{{ __('lf.LF_course_product_select') }}: {{ $product->title }}">
                            @endif
                        </td>
                        <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">
                            {{ $products->firstItem() + $loop->index }}
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_product_common_title_field') }}">
                            <span class="mk-name-text">{{ $product->title }}</span>
                            <br><span class="ui-caption">{{ $product->product_code }}</span>
                        </td>
                        <td @class(['mk-text-cell', 'mk-na' => ! $product->offering_type])
                            data-label="{{ __('lf.LF_course_product_common_product_type') }}">
                            {{ $product->offering_type
                                ? __('lf.LF_product_v2_offering_'.$product->offering_type)
                                : '—' }}
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_product_common_price') }}">
                            {{ number_format((float) $product->price, 0) }} {{ $product->currency }}
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_product_common_visibility') }}">
                            {{ __('lf.LF_course_product_common_visibility_'.$product->visibility) }}
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_course_product_common_status') }}">
                            <span class="ui-status ui-status--{{ $product->status }}">{{ __('lf.LF_course_product_common_'.$product->status) }}</span>
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                            <a class="ux-row-edit cursor-interaction" href="{{ route($routePrefix.'.edit', $product->id) }}"
                               aria-label="{{ __('lf.LF_course_template_action_manage') }}: {{ $product->title }}"
                               data-tooltip="{{ __('lf.LF_course_template_action_manage') }}">
                                <x-backend-icon name="sliders" class="ui-icon" />
                            </a>
                            <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open
                                    data-url="{{ route($routePrefix.'.form', $product->id) }}" aria-haspopup="dialog"
                                    aria-label="{{ __('lf.LF_course_template_action_edit_info') }}: {{ $product->title }}"
                                    data-tooltip="{{ __('lf.LF_course_template_action_edit_info') }}">
                                <x-backend-icon name="pencil" class="ui-icon" />
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr class="mk-empty-row">
                        <td class="mk-empty" colspan="8">
                            <div role="status">
                                <strong>{{ $hasActiveFilters ? __('lf.LF_course_product_filter_empty') : __('lf.LF_course_product_common_empty') }}</strong>
                                <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_course_product_filter_empty_help') : __('lf.LF_course_product_empty_help') }}</p>
                                @unless ($hasActiveFilters)
                                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                                            data-ui-dialog-form-open data-url="{{ route($routePrefix.'.create') }}" aria-haspopup="dialog">
                                        {{ __('lf.LF_course_product_common_create_short') }}
                                    </button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$products"
            :label="__('lf.LF_course_product_common_title')"
            :page-size-label="__('lf.LF_course_template_index_page_size')"
            :page-size-option="__('lf.LF_course_template_index_page_size_option', ['count' => $products->perPage()])" />
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
@endsection
