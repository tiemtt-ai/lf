@extends('layouts.backend')

@section('title', __('lf.LF_navigation_menu_assessment_results'))
@section('page_title', __('lf.LF_navigation_menu_assessment_results'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
@php
    $hasActiveFilters = $filters['keyword'] !== '' || $filters['quiz_id'] || $filters['status'];
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_filters')).'"';
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $gradingClass = ['pending' => 'ui-status--inactive', 'completed' => 'ui-status--active', 'not_required' => 'ui-status--active'];
@endphp
<div id="assessment-result-list" class="lf-admin-ui lf-admin-ui--list" data-assessment-section="results">
    <x-ui-list-toasts />

    <x-ui-list-head :title="__('lf.LF_navigation_menu_assessment_results')" :text="__('lf.LF_assessment_results_help')" />

    @include('assessments.results.partials.tabs', ['activeTab' => $filters['tab']])

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters">
            <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.index') }}" role="search" data-ui-live-filter>
                @if ($filters['tab'] === 'pending')
                    <input type="hidden" name="tab" value="pending">
                @endif
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-result-keyword">{{ __('lf.LF_common_button_search') }}</label>
                    <div class="ui-search">
                        <input id="assessment-result-keyword" type="search" name="keyword" maxlength="255" value="{{ $filters['keyword'] }}"
                               placeholder="{{ __('lf.LF_assessment_registration_search_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <x-backend-icon name="search" class="ui-icon" />
                        </button>
                    </div>
                </div>
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-result-quiz">{{ __('lf.LF_assessment_registration_quiz') }}</label>
                    <select id="assessment-result-quiz" name="quiz_id" data-ui-kit-select {!! $selectLabels !!}>
                        <option value="">{{ __('lf.LF_assessment_registration_all_quizzes') }}</option>
                        @foreach ($quizzes as $quiz)
                            <option value="{{ $quiz->id }}" @selected($filters['quiz_id'] === (int) $quiz->id)>{{ $quiz->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-result-status">{{ __('lf.LF_assessment_category_status') }}</label>
                    <select id="assessment-result-status" name="status" data-ui-kit-select {!! $selectLabels !!}>
                        <option value="">{{ __('lf.LF_assessment_category_all_statuses') }}</option>
                        @foreach (['in_progress', 'submitted', 'graded'] as $statusOption)
                            <option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>{{ __('lf.LF_assessment_result_status_'.$statusOption) }}</option>
                        @endforeach
                    </select>
                </div>
                <span class="ui-live-slot" data-ui-live-region="clear-filters">
                    @if ($hasActiveFilters)
                        <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.index', $filters['tab'] === 'pending' ? ['tab' => 'pending'] : []) }}">
                            {{ __('lf.LF_assessment_category_clear_filters') }}
                        </a>
                    @endif
                </span>
            </form>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_navigation_menu_assessment_results') }}" tabindex="0">
            <table class="ui-asm-table ui-asm-table--wide" aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="mk-col-sequence">
                    <col>
                    <col>
                    <col class="ui-asm-col-date">
                    <col class="ui-asm-col-score">
                    <col class="ui-asm-col-status">
                    <col class="mk-col-action">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_registration_student') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_registration_quiz') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_result_submitted_at') }}</th>
                        <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_result_score') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_assessment_category_status') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($attempts as $attempt)
                        <tr>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $attempts->firstItem() + $loop->index }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_registration_student') }}">
                                <span class="mk-name-line"><span class="mk-name-text">{{ $attempt->student_name }}</span></span>
                                <p class="ui-caption">{{ $attempt->student_email }}</p>
                            </td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_registration_quiz') }}">
                                {{ $attempt->quiz_title }}
                                <p class="ui-caption">{{ __('lf.LF_assessment_quiz_version_number', ['number' => $attempt->version_no]) }} · {{ __('lf.LF_assessment_result_attempt_no', ['number' => $attempt->attempt_no]) }}</p>
                            </td>
                            <td class="mk-text-cell ui-asm-nowrap" data-label="{{ __('lf.LF_assessment_result_submitted_at') }}">
                                {{ $attempt->submitted_at ? \Illuminate\Support\Carbon::parse($attempt->submitted_at)->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td class="mk-count-cell" data-label="{{ __('lf.LF_assessment_result_score') }}">
                                @if ($attempt->score !== null)
                                    {{ $trim($attempt->score) }} / {{ $trim($attempt->max_score) }}
                                    <p class="ui-caption">
                                        {{ $trim($attempt->score_percentage) }}%
                                        @if ($attempt->passed !== null) · {{ $attempt->passed ? __('lf.LF_assessment_result_passed') : __('lf.LF_assessment_result_failed') }} @endif
                                    </p>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_assessment_category_status') }}">
                                @if ($attempt->status === 'in_progress')
                                    <span class="ui-status ui-status--draft">{{ __('lf.LF_assessment_result_status_in_progress') }}</span>
                                @else
                                    <span class="ui-status {{ $gradingClass[$attempt->grading_status] ?? '' }}">{{ __('lf.LF_assessment_result_grading_'.$attempt->grading_status) }}</span>
                                @endif
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                <a class="ux-row-edit cursor-interaction" href="{{ route($routePrefix.'.show', $attempt->id) }}"
                                   aria-label="{{ $attempt->grading_status === 'pending' ? __('lf.LF_assessment_result_grade') : __('lf.LF_assessment_result_view') }}: {{ $attempt->student_name }}"
                                   data-tooltip="{{ $attempt->grading_status === 'pending' ? __('lf.LF_assessment_result_grade') : __('lf.LF_assessment_result_view') }}">
                                    <x-backend-icon name="sliders" class="ui-icon" />
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="7">
                                <div role="status">
                                    <strong>{{ $filters['tab'] === 'pending' ? __('lf.LF_assessment_result_pending_empty') : ($hasActiveFilters ? __('lf.LF_assessment_result_filter_empty') : __('lf.LF_assessment_result_empty')) }}</strong>
                                    <p class="ui-caption">{{ __('lf.LF_assessment_result_empty_help') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$attempts"
            :label="__('lf.LF_navigation_menu_assessment_results')"
            :page-size-label="__('lf.LF_assessment_category_page_size')"
            :page-size-option="__('lf.LF_assessment_category_page_size_option', ['count' => $attempts->perPage()])" />
    </section>
</div>
@endsection
