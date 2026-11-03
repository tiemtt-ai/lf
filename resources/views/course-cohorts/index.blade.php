@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_title'))
@section('page_title', __('lf.LF_course_cohort_common_title'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
    @php
        $hasActiveFilters = $keyword !== '' || $status;
        $cohortStatusTone = fn (string $value): string => match ($value) {
            'active' => 'active',
            'draft' => 'draft',
            'completed' => 'completed',
            default => 'archived',
        };
    @endphp
<div class="lf-admin-ui lf-admin-ui--list" data-ui-row-actions
     data-csrf="{{ csrf_token() }}"
     data-msg-delete-error="{{ __('lf.LF_learning_delete_error') }}"
     data-msg-session-expired="{{ __('lf.LF_learning_session_expired') }}">
    <x-ui-list-toasts />

    @if ($errors->has('lifecycle'))
        <div class="admin-alert admin-alert-danger" role="alert">{{ $errors->first('lifecycle') }}</div>
    @endif

    <x-ui-list-head :title="__('lf.LF_course_cohort_index_heading')" :text="__('lf.LF_course_cohort_index_description')" />

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters ui-enroll-filterbar">
            <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.index') }}" data-ui-live-filter>
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="keyword">{{ __('lf.LF_course_cohort_common_keyword') }}</label>
                    <div class="ui-search">
                        <input id="keyword" type="search" name="keyword" value="{{ $keyword }}"
                               placeholder="{{ __('lf.LF_course_cohort_common_keyword_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                        </button>
                    </div>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="status">{{ __('lf.LF_course_cohort_common_status') }}</label>
                    <select id="status" name="status" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_enrollment_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_cohort_common_clear_filters') }}">
                        <option value="">{{ __('lf.LF_course_cohort_common_all_statuses') }}</option>
                        @foreach (['draft', 'active', 'completed', 'archived'] as $cohortStatus)
                            <option value="{{ $cohortStatus }}" @selected($status === $cohortStatus)>
                                {{ __('lf.LF_course_cohort_common_'.$cohortStatus) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <span class="ui-live-slot" data-ui-live-region="clear-filters">
                    @if ($hasActiveFilters)
                        <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.index') }}">{{ __('lf.LF_course_cohort_common_clear_filters') }}</a>
                    @endif
                </span>
            </form>

            <x-ui-list-toolbar :url="route($routePrefix.'.bulk-destroy')" name="ids[]"
                :label="__('lf.LF_course_cohort_bulk_delete')"
                :confirm-title="__('lf.LF_course_cohort_bulk_delete_title')" :confirm-message="__('lf.LF_course_cohort_bulk_delete_confirm', ['count' => ':count'])"
                :confirm-label="__('lf.LF_course_cohort_bulk_delete')">
                <x-slot:create>
                    <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.create') }}">
                        <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                        {{ __('lf.LF_course_cohort_common_create') }}
                    </a>
                </x-slot:create>
            </x-ui-list-toolbar>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_course_cohort_common_title') }}" tabindex="0">
            <table aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col>
                    <col>
                    <col class="mk-col-status">
                    <col class="mk-col-action">
                </colgroup>
                <thead>
                <tr>
                    <th scope="col" class="ui-check-cell">
                        <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all aria-label="{{ __('lf.LF_course_cohort_select_all') }}">
                    </th>
                    <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_common_name') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_common_product') }} / {{ __('lf.LF_course_cohort_common_version') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_cohort_common_status') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($cohorts as $cohort)
                    <tr>
                        <td class="ui-check-cell" data-label="{{ __('lf.LF_course_cohort_select') }}">
                            @if ($cohort->delete_blocker)
                                <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $cohort->delete_blocker }}" data-tooltip-align="start">
                                    <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ $cohort->delete_blocker }}">
                                </span>
                            @else
                                <input type="checkbox" class="ui-bulk-box" data-ui-bulk-item value="{{ $cohort->id }}"
                                       aria-label="{{ __('lf.LF_course_cohort_select') }}: {{ $cohort->name }}">
                            @endif
                        </td>
                        <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $cohorts->firstItem() + $loop->index }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_common_name') }}">
                            <span class="mk-name-text">{{ $cohort->name }}</span>
                            <span class="ui-caption ui-framework-description">{{ $cohort->code ?? '—' }}</span>
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_common_product') }}">
                            @if ($cohort->product_id)
                                <span class="mk-name-text">{{ $cohort->product_title }}</span>
                                <span class="ui-caption ui-framework-description">{{ $cohort->product_code }}</span>
                                @if ($cohort->version_id)
                                    <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_product_item_common_version_number', ['number' => $cohort->version_number]) }} · {{ $cohort->version_code }}</span>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_course_cohort_common_status') }}">
                            <span class="ui-status ui-status--{{ $cohortStatusTone($cohort->status) }}">{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</span>
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                            <a class="ux-row-edit cursor-interaction" href="{{ route($routePrefix.'.show', $cohort->id) }}"
                               aria-label="{{ __('lf.LF_course_template_action_manage') }}: {{ $cohort->name }}"
                               data-tooltip="{{ __('lf.LF_course_template_action_manage') }}">
                                <x-backend-icon name="sliders" class="ui-icon" />
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr class="mk-empty-row">
                        <td class="mk-empty" colspan="6">
                            <div role="status">
                                <strong>{{ $hasActiveFilters ? __('lf.LF_course_cohort_filter_empty') : __('lf.LF_course_cohort_common_empty') }}</strong>
                                <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_course_cohort_filter_empty_help') : __('lf.LF_course_cohort_empty_help') }}</p>
                                @unless ($hasActiveFilters)
                                    <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.create') }}">{{ __('lf.LF_course_cohort_common_create') }}</a>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$cohorts"
            :label="__('lf.LF_course_cohort_common_title')"
            :page-size-label="__('lf.LF_course_template_index_page_size')"
            :page-size-option="__('lf.LF_course_template_index_page_size_option', ['count' => $cohorts->perPage()])" />
    </section>
</div>
@endsection
