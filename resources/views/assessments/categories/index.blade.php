@extends('layouts.backend')

@section('title', __('lf.LF_navigation_menu_assessment_categories'))
@section('page_title', __('lf.LF_navigation_menu_assessment_categories'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
<div id="assessment-category-list" class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope data-ui-row-actions
     data-assessment-section="categories"
     data-csrf="{{ csrf_token() }}"
     data-msg-delete-error="{{ __('lf.LF_assessment_category_delete_error') }}"
     data-msg-session-expired="{{ __('lf.LF_assessment_category_session_expired') }}">
    @php
        $hasActiveFilters = $keyword !== '' || $status;
    @endphp

    <x-ui-list-toasts />

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-ui-list-head :title="__('lf.LF_navigation_menu_assessment_categories')" :text="__('lf.LF_assessment_categories_help')" />

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters">
            <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.index') }}" role="search" data-ui-live-filter>
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-category-keyword">{{ __('lf.LF_common_button_search') }}</label>
                    <div class="ui-search">
                        <input id="assessment-category-keyword" type="search" name="keyword" maxlength="255" value="{{ $keyword }}"
                               placeholder="{{ __('lf.LF_assessment_category_search_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <x-backend-icon name="search" class="ui-icon" />
                        </button>
                    </div>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-category-status">{{ __('lf.LF_assessment_category_status') }}</label>
                    <select id="assessment-category-status" name="status" data-ui-kit-select
                            data-ui-empty-label="{{ __('lf.LF_assessment_category_no_options') }}"
                            data-ui-clear-label="{{ __('lf.LF_assessment_category_clear_filters') }}">
                        <option value="">{{ __('lf.LF_assessment_category_all_statuses') }}</option>
                        @foreach (\App\Services\Assessment\AssessmentCategoryService::STATUSES as $statusOption)
                            <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ __('lf.LF_assessment_category_status_label_'.$statusOption) }}</option>
                        @endforeach
                    </select>
                </div>

                <span class="ui-live-slot" data-ui-live-region="clear-filters">
                    @if ($hasActiveFilters)
                        <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.index') }}">
                            {{ __('lf.LF_assessment_category_clear_filters') }}
                        </a>
                    @endif
                </span>
            </form>

            <x-ui-list-toolbar :url="route($routePrefix.'.bulk-destroy')" name="ids[]"
                :label="__('lf.LF_assessment_category_bulk_delete')"
                :confirm-title="__('lf.LF_assessment_category_bulk_delete_title')"
                :confirm-message="__('lf.LF_assessment_category_bulk_delete_confirm', ['count' => ':count'])"
                :confirm-label="__('lf.LF_assessment_category_bulk_delete')">
                <x-slot:create>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                            data-ui-dialog-form-open data-ui-dialog-form-for="assessment-category-list"
                            data-url="{{ route($routePrefix.'.create') }}" aria-haspopup="dialog">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_assessment_category_create_short') }}
                    </button>
                </x-slot:create>
            </x-ui-list-toolbar>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_navigation_menu_assessment_categories') }}" tabindex="0">
            <table aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col>
                    <col class="mk-col-parent">
                    <col class="mk-col-count">
                    <col class="mk-col-status">
                    <col class="mk-col-action">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="ui-check-cell">
                            <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all aria-label="{{ __('lf.LF_assessment_category_select_all') }}">
                        </th>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_category_name') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_category_parent') }}</th>
                        <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_category_question_count') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_assessment_category_status') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        @php
                            $deleteBlocker = collect([
                                'children' => (int) $category->child_count,
                                'questions' => (int) $category->question_count,
                                'passages' => (int) $category->passage_count,
                                'quizzes' => (int) $category->quiz_count,
                            ])->filter()->map(fn (int $count, string $reason) => trans_choice('lf.LF_assessment_category_delete_blocked_'.$reason, $count, ['count' => $count]))->first();
                            $isActive = $category->status === 'active';
                        @endphp
                        <tr>
                            <td class="ui-check-cell" data-label="{{ __('lf.LF_assessment_category_select') }}">
                                @if ($deleteBlocker)
                                    <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $deleteBlocker }}" data-tooltip-align="start">
                                        <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ $deleteBlocker }}">
                                    </span>
                                @else
                                    <input type="checkbox" class="ui-bulk-box" data-ui-bulk-item value="{{ $category->id }}"
                                           aria-label="{{ __('lf.LF_assessment_category_select') }}: {{ $category->name }}">
                                @endif
                            </td>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $categories->firstItem() + $loop->index }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_category_name') }}">
                                <span class="mk-name-line"><span class="mk-name-text">{{ $category->name }}</span></span>
                                <p class="ui-caption">{{ $category->slug }}</p>
                            </td>
                            <td @class(['mk-text-cell', 'mk-na' => ! $category->parent_name]) data-label="{{ __('lf.LF_assessment_category_parent') }}">
                                {{ $category->parent_name ?? __('lf.LF_assessment_category_root') }}
                            </td>
                            <td @class(['mk-count-cell', 'mk-na' => (int) $category->question_count === 0]) data-label="{{ __('lf.LF_assessment_category_question_count') }}">
                                {{ number_format((int) $category->question_count) }}
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_assessment_category_status') }}">
                                <form method="POST" action="{{ route($routePrefix.'.toggle-status', $category->id) }}">
                                    @csrf
                                    <button type="submit" class="ux-row-switch cursor-interaction" role="switch"
                                            aria-checked="{{ $isActive ? 'true' : 'false' }}"
                                            aria-label="{{ __('lf.LF_assessment_category_toggle_status') }}: {{ $category->name }}"
                                            title="{{ __('lf.LF_assessment_category_status_label_'.$category->status) }}"></button>
                                </form>
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open
                                        data-url="{{ route($routePrefix.'.edit', $category->id) }}" aria-haspopup="dialog"
                                        aria-label="{{ __('lf.LF_assessment_category_edit') }}: {{ $category->name }}"
                                        data-tooltip="{{ __('lf.LF_assessment_category_edit') }}">
                                    <x-backend-icon name="square-pen" class="ui-icon" />
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="7">
                                <div role="status">
                                    <strong>{{ $hasActiveFilters ? __('lf.LF_assessment_category_filter_empty') : __('lf.LF_assessment_category_empty') }}</strong>
                                    <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_assessment_category_filter_empty_help') : __('lf.LF_assessment_category_empty_help') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$categories"
            :label="__('lf.LF_navigation_menu_assessment_categories')"
            :page-size-label="__('lf.LF_assessment_category_page_size')"
            :page-size-option="__('lf.LF_assessment_category_page_size_option', ['count' => $categories->perPage()])" />
    </section>

    <dialog class="ui-modal" data-columns="2" aria-labelledby="assessment-category-dialog-title" data-ui-dialog-form
            data-msg-load-error="{{ __('lf.LF_assessment_category_dialog_load_error') }}"
            data-msg-discard-title="{{ __('lf.LF_assessment_category_dialog_discard_title') }}"
            data-msg-discard-text="{{ __('lf.LF_assessment_category_dialog_discard_text') }}"
            data-msg-discard="{{ __('lf.LF_assessment_category_dialog_discard') }}"
            data-msg-save-error="{{ __('lf.LF_assessment_category_dialog_save_error') }}"
            data-msg-session-expired="{{ __('lf.LF_assessment_category_session_expired') }}"
            data-msg-required-input="{{ __('lf.LF_assessment_category_dialog_required_input') }}"
            data-msg-required-select="{{ __('lf.LF_assessment_category_dialog_required_select') }}"></dialog>
</div>
@endsection
