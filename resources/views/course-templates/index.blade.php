@extends('layouts.backend')

@section('title', __('lf.LF_course_template_common_title'))
@section('page_title', __('lf.LF_course_template_common_title'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
<div id="template-list" class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope data-ui-row-actions
     data-csrf="{{ csrf_token() }}"
     data-msg-delete-error="{{ __('lf.LF_course_template_delete_error') }}"
     data-msg-session-expired="{{ __('lf.LF_course_template_session_expired') }}">
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

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters">
        <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.index') }}" data-ui-live-filter>
            <div class="ui-field mk-search">
                <label class="ui-field__label ui-sr-only" for="keyword">
                    {{ __('lf.LF_course_template_common_keyword') }}
                </label>
                <div class="ui-search">
                    <input id="keyword" type="search" name="keyword" value="{{ $keyword }}"
                           placeholder="{{ __('lf.LF_course_template_common_keyword_placeholder') }}">
                    <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                        <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                    </button>
                </div>
            </div>

            <div class="ui-field mk-search">
                <label class="ui-field__label ui-sr-only" for="status">
                    {{ __('lf.LF_course_template_common_status') }}
                </label>
                <select id="status" name="status" data-ui-kit-select
                        data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}"
                        data-ui-clear-label="{{ __('lf.LF_course_template_common_clear_filters') }}">
                    <option value="">{{ __('lf.LF_course_template_common_all_statuses') }}</option>
                    @foreach (\App\Support\CourseTemplateStatus::VALUES as $templateStatus)
                        <option value="{{ $templateStatus }}" @selected($status === $templateStatus)>
                            {{ __('lf.LF_course_template_common_'.$templateStatus) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <span class="ui-live-slot" data-ui-live-region="clear-filters">
                @if ($hasActiveFilters)
                    <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.index') }}">
                        {{ __('lf.LF_course_template_common_clear_filters') }}
                    </a>
                @endif
            </span>
        </form>

        <x-ui-list-toolbar :url="route($routePrefix.'.bulk-destroy')" name="ids[]"
            :label="__('lf.LF_course_template_bulk_delete')"
            :confirm-title="__('lf.LF_course_template_bulk_delete_title')" :confirm-message="__('lf.LF_course_template_bulk_delete_confirm', ['count' => ':count'])"
            :confirm-label="__('lf.LF_course_template_bulk_delete')">
            <x-slot:create>
                <button type="button" class="ui-button ui-button--outline cursor-interaction"
                data-ui-dialog-form-open data-ui-dialog-form-for="template-list" data-url="{{ route($routePrefix.'.create') }}" aria-haspopup="dialog">
            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
            {{ __('lf.LF_course_template_common_create_short') }}
        </button>
            </x-slot:create>
        </x-ui-list-toolbar>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_course_template_common_title') }}" tabindex="0">
            <table aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col>
                    <col class="mk-col-secondary">
                    <col class="mk-col-status">
                    <col class="mk-col-action-wide">
                </colgroup>
                <thead>
                <tr>
                    <th scope="col" class="ui-check-cell">
                        <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all aria-label="{{ __('lf.LF_course_template_select_all') }}">
                    </th>
                    <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_template_common_name') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_template_common_category') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_template_common_status') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($templates as $template)
                    <tr>
                        <td class="ui-check-cell" data-label="{{ __('lf.LF_course_template_select') }}">
                            @if ($template->delete_blocker)
                                <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $template->delete_blocker }}" data-tooltip-align="start">
                                    <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ $template->delete_blocker }}">
                                </span>
                            @else
                                <input type="checkbox" class="ui-bulk-box" data-ui-bulk-item value="{{ $template->id }}"
                                       aria-label="{{ __('lf.LF_course_template_select') }}: {{ $template->title }}">
                            @endif
                        </td>
                        <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">
                            {{ $templates->firstItem() + $loop->index }}
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_template_common_name') }}">
                            <span class="mk-name-text">{{ $template->title }}</span>
                        </td>
                        <td @class(['mk-text-cell', 'mk-na' => ! $template->category_name])
                            data-label="{{ __('lf.LF_course_template_common_category') }}">
                            {{ $template->category_name ?? '—' }}
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_course_template_common_status') }}">
                            <span class="ui-status ui-status--{{ $template->status }}">{{ __('lf.LF_course_template_common_'.$template->status) }}</span>
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                            <a class="ux-row-edit cursor-interaction" href="{{ route($routePrefix.'.edit', $template->id) }}"
                               aria-label="{{ __('lf.LF_course_template_action_manage') }}: {{ $template->title }}"
                               data-tooltip="{{ __('lf.LF_course_template_action_manage') }}">
                                <x-backend-icon name="sliders" class="ui-icon" />
                            </a>
                            <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open
                                    data-url="{{ route($routePrefix.'.form', $template->id) }}" aria-haspopup="dialog"
                                    aria-label="{{ __('lf.LF_course_template_action_edit_info') }}: {{ $template->title }}"
                                    data-tooltip="{{ __('lf.LF_course_template_action_edit_info') }}">
                                <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z" /></svg>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr class="mk-empty-row">
                        <td class="mk-empty" colspan="6">
                            <div role="status">
                                <strong>{{ $hasActiveFilters ? __('lf.LF_course_template_filter_empty') : __('lf.LF_course_template_common_empty') }}</strong>
                                <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_course_template_filter_empty_help') : __('lf.LF_course_template_empty_help') }}</p>
                                @unless ($hasActiveFilters)
                                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                                            data-ui-dialog-form-open data-url="{{ route($routePrefix.'.create') }}" aria-haspopup="dialog">
                                        {{ __('lf.LF_course_template_common_create_short') }}
                                    </button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$templates"
            :label="__('lf.LF_course_template_common_title')"
            :page-size-label="__('lf.LF_course_template_index_page_size')"
            :page-size-option="__('lf.LF_course_template_index_page_size_option', ['count' => $templates->perPage()])" />
    </section>

    <dialog class="ui-modal" data-columns="2" aria-labelledby="template-dialog-title" data-ui-dialog-form data-field-id-prefix="template-form-"
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
