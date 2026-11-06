@extends('layouts.backend')

@section('title', __('lf.LF_navigation_menu_assessment_registrations'))
@section('page_title', __('lf.LF_navigation_menu_assessment_registrations'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
@php
    $hasActiveFilters = $filters['keyword'] !== '' || $filters['quiz_id'] || $filters['source'] || $filters['status'];
    $statusClass = ['active' => 'ui-status--active', 'exhausted' => 'ui-status--completed', 'cancelled' => 'ui-status--archived'];
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_filters')).'"';
@endphp
<div id="assessment-registration-list" class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope data-ui-row-actions
     data-assessment-section="registrations"
     data-csrf="{{ csrf_token() }}"
     data-msg-delete-error="{{ __('lf.LF_assessment_registration_cancel_error') }}"
     data-msg-session-expired="{{ __('lf.LF_assessment_category_session_expired') }}">
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

    <x-ui-list-head :title="__('lf.LF_navigation_menu_assessment_registrations')" :text="__('lf.LF_assessment_registrations_help')" />

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters">
            <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.index') }}" role="search" data-ui-live-filter>
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-registration-keyword">{{ __('lf.LF_common_button_search') }}</label>
                    <div class="ui-search">
                        <input id="assessment-registration-keyword" type="search" name="keyword" maxlength="255" value="{{ $filters['keyword'] }}"
                               placeholder="{{ __('lf.LF_assessment_registration_search_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <x-backend-icon name="search" class="ui-icon" />
                        </button>
                    </div>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-registration-filter-quiz">{{ __('lf.LF_assessment_registration_quiz') }}</label>
                    <select id="assessment-registration-filter-quiz" name="quiz_id" data-ui-kit-select {!! $selectLabels !!}>
                        <option value="">{{ __('lf.LF_assessment_registration_all_quizzes') }}</option>
                        @foreach ($quizzes as $quiz)
                            <option value="{{ $quiz->id }}" @selected($filters['quiz_id'] === (int) $quiz->id)>{{ $quiz->title }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-registration-filter-source">{{ __('lf.LF_assessment_registration_source') }}</label>
                    <select id="assessment-registration-filter-source" name="source" data-ui-kit-select {!! $selectLabels !!}>
                        <option value="">{{ __('lf.LF_assessment_registration_all_sources') }}</option>
                        @foreach (\App\Services\Assessment\AssessmentRegistrationService::SOURCES as $source)
                            <option value="{{ $source }}" @selected($filters['source'] === $source)>{{ __('lf.LF_assessment_registration_source_'.$source) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-registration-filter-status">{{ __('lf.LF_assessment_category_status') }}</label>
                    <select id="assessment-registration-filter-status" name="status" data-ui-kit-select {!! $selectLabels !!}>
                        <option value="">{{ __('lf.LF_assessment_category_all_statuses') }}</option>
                        @foreach (\App\Services\Assessment\AssessmentRegistrationService::STATUSES as $statusOption)
                            <option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>{{ __('lf.LF_assessment_registration_status_'.$statusOption) }}</option>
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

            <x-ui-list-toolbar :url="route($routePrefix.'.bulk-cancel')" name="ids[]"
                :label="__('lf.LF_assessment_registration_cancel')"
                :confirm-title="__('lf.LF_assessment_registration_cancel_title')"
                :confirm-message="__('lf.LF_assessment_registration_cancel_confirm', ['count' => ':count'])"
                :confirm-label="__('lf.LF_assessment_registration_cancel')">
                <x-slot:create>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                            data-ui-dialog-form-open data-ui-dialog-form-for="assessment-registration-list"
                            data-url="{{ route($routePrefix.'.create') }}" aria-haspopup="dialog">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_assessment_registration_create') }}
                    </button>
                </x-slot:create>
            </x-ui-list-toolbar>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_navigation_menu_assessment_registrations') }}" tabindex="0">
            <table aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col>
                    <col>
                    <col class="mk-col-secondary">
                    <col class="mk-col-count">
                    <col class="mk-col-secondary">
                    <col class="mk-col-status">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="ui-check-cell">
                            <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all aria-label="{{ __('lf.LF_assessment_registration_select_all') }}">
                        </th>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_registration_student') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_registration_quiz') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_registration_source') }}</th>
                        <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_registration_attempts') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_registration_registered_at') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_assessment_category_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($registrations as $registration)
                        <tr>
                            <td class="ui-check-cell" data-label="{{ __('lf.LF_assessment_registration_select') }}">
                                @if ($registration->status === 'active')
                                    <input type="checkbox" class="ui-bulk-box" data-ui-bulk-item value="{{ $registration->id }}"
                                           aria-label="{{ __('lf.LF_assessment_registration_select') }}: {{ $registration->student_name }} · {{ $registration->quiz_title }}">
                                @else
                                    <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ __('lf.LF_assessment_registration_cancel_blocked') }}" data-tooltip-align="start">
                                        <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ __('lf.LF_assessment_registration_cancel_blocked') }}">
                                    </span>
                                @endif
                            </td>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $registrations->firstItem() + $loop->index }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_registration_student') }}">
                                <span class="mk-name-line"><span class="mk-name-text">{{ $registration->student_name }}</span></span>
                                <p class="ui-caption">{{ $registration->student_email }}</p>
                            </td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_registration_quiz') }}">
                                <a href="{{ route($quizRoute.'.show', $registration->quiz_id) }}">{{ $registration->quiz_title }}</a>
                                @if (filled($registration->note))
                                    <p class="ui-caption">{{ $registration->note }}</p>
                                @endif
                            </td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_registration_source') }}">
                                {{ __('lf.LF_assessment_registration_source_'.$registration->source) }}
                                @if ($registration->granter_name)
                                    <p class="ui-caption">{{ __('lf.LF_assessment_registration_granted_by', ['name' => $registration->granter_name]) }}</p>
                                @endif
                            </td>
                            <td class="mk-count-cell" data-label="{{ __('lf.LF_assessment_registration_attempts') }}">
                                {{ $registration->attempts_used }}@if ($registration->max_attempts) / {{ $registration->max_attempts }}@endif
                            </td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_registration_registered_at') }}">
                                {{ \Illuminate\Support\Carbon::parse($registration->registered_at)->format('d/m/Y H:i') }}
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_assessment_category_status') }}">
                                <span class="ui-status {{ $statusClass[$registration->status] ?? '' }}">{{ __('lf.LF_assessment_registration_status_'.$registration->status) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="8">
                                <div role="status">
                                    <strong>{{ $hasActiveFilters ? __('lf.LF_assessment_registration_filter_empty') : __('lf.LF_assessment_registration_empty') }}</strong>
                                    <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_assessment_category_filter_empty_help') : __('lf.LF_assessment_registration_empty_help') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$registrations"
            :label="__('lf.LF_navigation_menu_assessment_registrations')"
            :page-size-label="__('lf.LF_assessment_category_page_size')"
            :page-size-option="__('lf.LF_assessment_category_page_size_option', ['count' => $registrations->perPage()])" />
    </section>

    @include('assessments.passages.partials.dialog-host', ['dialogTitleId' => 'assessment-registration-dialog-title'])
</div>
@endsection
