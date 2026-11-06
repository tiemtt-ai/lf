@extends('layouts.backend')

@section('title', __('lf.LF_navigation_menu_assessment_rubrics'))
@section('page_title', __('lf.LF_navigation_menu_assessment_rubrics'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
<div id="assessment-rubric-list" class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope data-ui-row-actions
     data-assessment-section="rubrics"
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

    <x-ui-list-head :title="__('lf.LF_navigation_menu_assessment_rubrics')" :text="__('lf.LF_assessment_rubrics_help')" />

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters">
            <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.index') }}" role="search" data-ui-live-filter>
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-rubric-keyword">{{ __('lf.LF_common_button_search') }}</label>
                    <div class="ui-search">
                        <input id="assessment-rubric-keyword" type="search" name="keyword" maxlength="255" value="{{ $keyword }}"
                               placeholder="{{ __('lf.LF_assessment_rubric_search_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <x-backend-icon name="search" class="ui-icon" />
                        </button>
                    </div>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-rubric-status">{{ __('lf.LF_assessment_category_status') }}</label>
                    <select id="assessment-rubric-status" name="status" data-ui-kit-select
                            data-ui-empty-label="{{ __('lf.LF_assessment_category_no_options') }}"
                            data-ui-clear-label="{{ __('lf.LF_assessment_category_clear_filters') }}">
                        <option value="">{{ __('lf.LF_assessment_category_all_statuses') }}</option>
                        @foreach (\App\Services\Assessment\AssessmentRubricService::STATUSES as $statusOption)
                            <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ __('lf.LF_assessment_rubric_status_label_'.$statusOption) }}</option>
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
                :confirm-title="__('lf.LF_assessment_rubric_bulk_delete_title')"
                :confirm-message="__('lf.LF_assessment_rubric_bulk_delete_confirm', ['count' => ':count'])"
                :confirm-label="__('lf.LF_assessment_category_bulk_delete')">
                <x-slot:create>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                            data-ui-dialog-form-open data-ui-dialog-form-for="assessment-rubric-list"
                            data-url="{{ route($routePrefix.'.create') }}" aria-haspopup="dialog">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_assessment_rubric_create') }}
                    </button>
                </x-slot:create>
            </x-ui-list-toolbar>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_navigation_menu_assessment_rubrics') }}" tabindex="0">
            <table class="ui-asm-table" aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col>
                    <col class="ui-asm-col-author">
                    <col class="ui-asm-col-count">
                    <col class="ui-asm-col-count">
                    <col class="ui-asm-col-status">
                    <col class="mk-col-action">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="ui-check-cell">
                            <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all aria-label="{{ __('lf.LF_assessment_category_select_all') }}">
                        </th>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_rubric_title') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_question_author') }}</th>
                        <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_rubric_total_short') }}</th>
                        <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_category_question_count') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_assessment_category_status') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rubrics as $rubric)
                        @php
                            $editable = $canEdit($rubric);
                            $isActive = $rubric->status === 'active';
                            $lockReason = ! $editable
                                ? __('lf.LF_assessment_rubric_admin_owned')
                                : ((int) $rubric->question_count > 0 ? trans_choice('lf.LF_assessment_rubric_delete_blocked', (int) $rubric->question_count, ['count' => (int) $rubric->question_count]) : null);
                        @endphp
                        <tr>
                            <td class="ui-check-cell" data-label="{{ __('lf.LF_assessment_category_select') }}">
                                @if ($lockReason)
                                    <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $lockReason }}" data-tooltip-align="start">
                                        <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ $lockReason }}">
                                    </span>
                                @else
                                    <input type="checkbox" class="ui-bulk-box" data-ui-bulk-item value="{{ $rubric->id }}"
                                           aria-label="{{ __('lf.LF_assessment_category_select') }}: {{ $rubric->title }}">
                                @endif
                            </td>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $rubrics->firstItem() + $loop->index }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_rubric_title') }}">
                                <span class="mk-name-line"><span class="mk-name-text" title="{{ $rubric->title }}">{{ $rubric->title }}</span></span>
                                <p class="ui-caption">
                                    {{ trans_choice('lf.LF_assessment_rubric_item_count', (int) $rubric->item_count, ['count' => (int) $rubric->item_count]) }}@if ($rubric->skill_type) · {{ __('lf.LF_assessment_question_skill_'.$rubric->skill_type) }}@endif
                                </p>
                            </td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_question_author') }}">{{ $rubric->author_name }}</td>
                            <td class="mk-count-cell" data-label="{{ __('lf.LF_assessment_rubric_total_short') }}">{{ \App\Services\Assessment\AssessmentRubricService::points((float) $rubric->total_points) }}</td>
                            <td @class(['mk-count-cell', 'mk-na' => (int) $rubric->question_count === 0]) data-label="{{ __('lf.LF_assessment_category_question_count') }}">
                                {{ number_format((int) $rubric->question_count) }}
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_assessment_category_status') }}">
                                @if ($editable)
                                    <form method="POST" action="{{ route($routePrefix.'.toggle-status', $rubric->id) }}">
                                        @csrf
                                        <button type="submit" class="ux-row-switch cursor-interaction" role="switch"
                                                aria-checked="{{ $isActive ? 'true' : 'false' }}"
                                                aria-label="{{ __('lf.LF_assessment_rubric_toggle_status') }}: {{ $rubric->title }}"
                                                title="{{ __('lf.LF_assessment_rubric_status_label_'.$rubric->status) }}"></button>
                                    </form>
                                @else
                                    <button type="button" class="ux-row-switch" role="switch" disabled
                                            aria-checked="{{ $isActive ? 'true' : 'false' }}"
                                            aria-label="{{ __('lf.LF_assessment_rubric_status_label_'.$rubric->status) }}: {{ $rubric->title }}"
                                            title="{{ __('lf.LF_assessment_rubric_admin_owned') }}"></button>
                                @endif
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                @if ($editable)
                                    <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open
                                            data-url="{{ route($routePrefix.'.edit', $rubric->id) }}" aria-haspopup="dialog"
                                            aria-label="{{ __('lf.LF_assessment_rubric_edit') }}: {{ $rubric->title }}"
                                            data-tooltip="{{ __('lf.LF_assessment_rubric_edit') }}">
                                        <x-backend-icon name="square-pen" class="ui-icon" />
                                    </button>
                                @else
                                    <span class="mk-na">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="8">
                                <div role="status">
                                    <strong>{{ $hasActiveFilters ? __('lf.LF_assessment_category_filter_empty') : __('lf.LF_assessment_rubric_empty') }}</strong>
                                    <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_assessment_category_filter_empty_help') : __('lf.LF_assessment_rubric_empty_help') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$rubrics"
            :label="__('lf.LF_navigation_menu_assessment_rubrics')"
            :page-size-label="__('lf.LF_assessment_category_page_size')"
            :page-size-option="__('lf.LF_assessment_category_page_size_option', ['count' => $rubrics->perPage()])" />
    </section>

    <dialog class="ui-modal" data-columns="2" aria-labelledby="assessment-rubric-dialog-title" data-ui-dialog-form
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
