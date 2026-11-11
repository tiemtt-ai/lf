@extends('layouts.backend')

@section('title', __('lf.LF_navigation_menu_assessment_results'))
@section('page_title', __('lf.LF_navigation_menu_assessment_results'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
@php
    $isAdmin = auth()->user()->role === 'customer_admin';
    $defaultGrader = ! $isAdmin && $filters['tab'] === 'pending' ? 'mine_unassigned' : 'all';
    $hasActiveFilters = $filters['keyword'] !== '' || $filters['quiz_id'] || $filters['status'] || $filters['grader'] !== $defaultGrader;
    $graderOptions = $isAdmin
        ? ['all' => __('lf.LF_assessment_grader_filter_all'), 'unassigned' => __('lf.LF_assessment_grader_filter_unassigned')] + $teachers->pluck('name', 'id')->all()
        : ['all' => __('lf.LF_assessment_grader_filter_all'), 'mine_unassigned' => __('lf.LF_assessment_grader_filter_mine_unassigned'),
           'mine' => __('lf.LF_assessment_grader_filter_mine'), 'unassigned' => __('lf.LF_assessment_grader_filter_unassigned')];
    $columns = 8;
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_filters')).'"';
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $gradingClass = ['pending' => 'ui-status--inactive', 'completed' => 'ui-status--active', 'not_required' => 'ui-status--active'];
@endphp
<div id="assessment-result-list" class="lf-admin-ui lf-admin-ui--list" data-assessment-section="results" data-ui-dialog-form-scope data-ui-row-actions
     data-csrf="{{ csrf_token() }}"
     data-msg-session-expired="{{ __('lf.LF_assessment_category_session_expired') }}">
    <x-ui-list-toasts />

    <x-ui-list-head :title="__('lf.LF_navigation_menu_assessment_results')" :text="__('lf.LF_assessment_results_help')" />

    <div class="ui-result-tabbar">
        @include('assessments.results.partials.tabs', ['activeTab' => $filters['tab']])
        {{-- Live slot: the export follows the current tab and filters after a live search. --}}
        <span class="ui-live-slot" data-ui-live-region="export">
            <a class="ui-button ui-button--outline cursor-interaction" download
               href="{{ route($routePrefix.'.export', array_filter($filters, fn ($value, $key) => match ($key) { 'tab' => $value === 'pending', 'grader' => $value !== $defaultGrader, default => $value !== null && $value !== '' }, ARRAY_FILTER_USE_BOTH)) }}">
                <x-backend-icon name="file-output" class="ui-icon" />
                {{ __('lf.LF_export_excel') }}
            </a>
        </span>
    </div>

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters ui-result-filterbar">
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
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-result-grader">{{ __('lf.LF_assessment_grader_column') }}</label>
                    <select id="assessment-result-grader" name="grader" data-ui-kit-select {!! $selectLabels !!}>
                        @foreach ($graderOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['grader'] === (string) $value)>{{ $label }}</option>
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
            <table class="ui-asm-table ui-asm-table--wide ui-result-table" aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="ui-result-col-sequence">
                    <col class="ui-result-col-student">
                    <col>
                    <col class="ui-asm-col-date">
                    <col class="ui-asm-col-score">
                    <col class="ui-result-col-status">
                    <col class="ui-result-col-grader">
                    <col class="ui-result-col-action">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_registration_student') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_registration_quiz') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_result_submitted_at') }}</th>
                        <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_result_score') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_assessment_category_status') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_grader_column') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($attempts as $attempt)
                        @php
                            $held = $attempt->holder_id !== null && $attempt->holder_status === 'active';
                            $mine = $held && (int) $attempt->holder_id === (int) auth()->id();
                        @endphp
                        <tr>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $attempts->firstItem() + $loop->index }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_registration_student') }}">
                                <span class="mk-name-line"><span class="mk-name-text">{{ $attempt->student_name }}</span></span>
                                <p class="ui-caption ui-result-email" title="{{ $attempt->student_email }}">{{ $attempt->student_email }}</p>
                            </td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_registration_quiz') }}">
                                {{ $attempt->quiz_title }}
                                <p class="ui-caption">{{ __('lf.LF_assessment_quiz_version_number', ['number' => $attempt->version_no]) }} · {{ __('lf.LF_assessment_result_attempt_no', ['number' => $attempt->attempt_no]) }}</p>
                                @if ($attempt->enrollment_id)
                                    <p class="ui-caption" data-result-course>{{ __('lf.LF_assessment_result_course_source', ['course' => $attempt->course_title ?? '—']) }}</p>
                                @endif
                            </td>
                            <td class="mk-text-cell ui-asm-nowrap" data-label="{{ __('lf.LF_assessment_result_submitted_at') }}">
                                {{ $attempt->submitted_at ? \Illuminate\Support\Carbon::parse($attempt->submitted_at)->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td class="mk-count-cell" data-label="{{ __('lf.LF_assessment_result_score') }}">
                                @if ($attempt->score !== null)
                                    <strong class="ui-result-score">{{ $trim($attempt->score) }} / {{ $trim($attempt->max_score) }}</strong>
                                    <span class="ui-result-score-meta">
                                        <span class="ui-caption">{{ $trim($attempt->score_percentage) }}%</span>
                                        @if ($attempt->passed !== null)
                                            <span @class(['ui-status', 'ui-result-verdict', 'ui-status--active' => $attempt->passed, 'ui-status--danger' => ! $attempt->passed])>{{ $attempt->passed ? __('lf.LF_assessment_result_passed') : __('lf.LF_assessment_result_failed') }}</span>
                                        @elseif ($attempt->grading_status === 'pending')
                                            <span class="ui-caption ui-result-provisional" title="{{ __('lf.LF_assessment_result_score_provisional') }}">{{ __('lf.LF_assessment_result_provisional_short') }}</span>
                                        @endif
                                    </span>
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
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_grader_column') }}">
                                @if ($attempt->holder_id !== null)
                                    <span @class(['ui-result-grader', 'is-mine' => $mine])>{{ $mine ? __('lf.LF_assessment_grader_you') : $attempt->holder_name }}</span>
                                    @if ($held && $attempt->grading_status === 'pending')
                                        <p class="ui-caption">{{ __('lf.LF_assessment_grader_holding') }}</p>
                                    @endif
                                @elseif ($attempt->status !== 'in_progress' && $attempt->grading_status === 'pending')
                                    <span class="ui-caption">{{ __('lf.LF_assessment_grader_unassigned') }}</span>
                                @else
                                    <span class="ui-caption">—</span>
                                @endif
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                @if (! $isAdmin && ! $held && $attempt->grading_status === 'pending' && $attempt->status !== 'in_progress')
                                    <form method="POST" action="{{ route($routePrefix.'.claim', $attempt->id) }}" class="ui-inline-form">
                                        @csrf
                                        <button type="submit" class="ux-row-edit cursor-interaction"
                                                aria-label="{{ __('lf.LF_assessment_grader_claim') }}: {{ $attempt->student_name }}"
                                                data-tooltip="{{ __('lf.LF_assessment_grader_claim') }}">
                                            <x-backend-icon name="clipboard-check" class="ui-icon" />
                                        </button>
                                    </form>
                                @endif
                                @if ($attempt->grading_status === 'pending' && $attempt->status !== 'in_progress')
                                    <a class="ux-row-edit cursor-interaction" href="{{ route($routePrefix.'.show', $attempt->id) }}"
                                       aria-label="{{ __('lf.LF_assessment_result_grade') }}: {{ $attempt->student_name }}"
                                       data-tooltip="{{ __('lf.LF_assessment_result_grade') }}">
                                        <x-backend-icon name="square-pen" class="ui-icon" />
                                    </a>
                                @else
                                    <a class="ux-row-edit cursor-interaction" href="{{ route($routePrefix.'.show', $attempt->id) }}"
                                       aria-label="{{ __('lf.LF_assessment_result_view') }}: {{ $attempt->student_name }}"
                                       data-tooltip="{{ __('lf.LF_assessment_result_view') }}">
                                        <x-backend-icon name="eye" class="ui-icon" />
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="{{ $columns }}">
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
