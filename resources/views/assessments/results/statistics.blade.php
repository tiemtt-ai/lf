@extends('layouts.backend')

@section('title', __('lf.LF_assessment_statistics_tab'))
@section('page_title', __('lf.LF_navigation_menu_assessment_results'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
@php
    $percent = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.').'%';
    $levelClass = ['easy' => 'ui-status--active', 'medium' => '', 'hard' => 'ui-status--danger'];
    $summary = $stats['summary'] ?? null;
@endphp
<div id="assessment-result-statistics" class="lf-admin-ui lf-admin-ui--list" data-assessment-section="results">
    <x-ui-list-head :title="__('lf.LF_navigation_menu_assessment_results')" :text="__('lf.LF_assessment_statistics_help')" />

    @include('assessments.results.partials.tabs', ['activeTab' => 'statistics'])

    @if ($quizzes->isEmpty())
        <section class="mk-section">
            <div class="mk-empty" role="status">
                <strong>{{ __('lf.LF_assessment_statistics_empty') }}</strong>
                <p class="ui-caption">{{ __('lf.LF_assessment_statistics_empty_help') }}</p>
            </div>
        </section>
    @else
        <section class="mk-section" aria-labelledby="assessment-statistics-summary-title">
            <div class="mk-tablebar mk-tablebar--filters">
                <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.statistics') }}" data-ui-live-filter>
                    <div class="ui-field mk-search ui-asm-stats-quiz">
                        <label class="ui-field__label ui-sr-only" for="assessment-statistics-quiz">{{ __('lf.LF_assessment_registration_quiz') }}</label>
                        <select id="assessment-statistics-quiz" name="quiz_id" data-ui-kit-select
                                data-ui-empty-label="{{ __('lf.LF_assessment_category_no_options') }}">
                            @foreach ($quizzes as $quiz)
                                <option value="{{ $quiz->id }}" @selected($quizId === (int) $quiz->id)>{{ $quiz->title }}</option>
                            @endforeach
                        </select>
                    </div>
                </form>
            </div>

            <div data-ui-live-region="summary">
                <h2 id="assessment-statistics-summary-title" class="ui-sr-only">{{ __('lf.LF_assessment_statistics_summary') }}</h2>
                <dl class="ui-summary ui-asm-stats">
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_assessment_statistics_submitted') }}</dt>
                        <dd>
                            {{ $summary['submitted'] }}
                            @if ($summary['pending'] > 0)
                                <span class="ui-caption">{{ trans_choice('lf.LF_assessment_statistics_pending', $summary['pending'], ['count' => $summary['pending']]) }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_assessment_statistics_average') }}</dt>
                        <dd>
                            {{ $percent($summary['average']) }}
                            @if ($summary['graded'] === 0)
                                <span class="ui-caption">{{ __('lf.LF_assessment_statistics_none_graded') }}</span>
                            @elseif ($summary['pending'] > 0)
                                <span class="ui-caption">{{ trans_choice('lf.LF_assessment_statistics_based_on', $summary['graded'], ['count' => $summary['graded']]) }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_assessment_statistics_range') }}</dt>
                        <dd>{{ $percent($summary['lowest']) }} – {{ $percent($summary['highest']) }}</dd>
                    </div>
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_assessment_statistics_pass_rate') }}</dt>
                        <dd>
                            {{ $percent($summary['pass_rate']) }}
                            @if ($summary['pass_rate'] === null)
                                <span class="ui-caption">{{ __('lf.LF_assessment_statistics_pass_rate_none') }}</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="mk-section" aria-labelledby="assessment-statistics-questions-title">
            <div class="ui-summary-head">
                <div>
                    <h2 id="assessment-statistics-questions-title">{{ __('lf.LF_assessment_statistics_questions') }}</h2>
                    <p class="ui-caption">{{ __('lf.LF_assessment_statistics_questions_help', ['easy' => (int) \App\Services\Assessment\AssessmentStatisticsService::EASY_AT, 'hard' => (int) \App\Services\Assessment\AssessmentStatisticsService::HARD_AT, 'min' => \App\Services\Assessment\AssessmentStatisticsService::MIN_FOR_LEVEL]) }}</p>
                </div>
            </div>
            <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_assessment_statistics_questions') }}" tabindex="0">
                <table class="ui-asm-table">
                    <colgroup>
                        <col class="mk-col-sequence">
                        <col>
                        <col class="ui-asm-col-attempts">
                        <col class="ui-asm-col-score">
                        <col class="ui-asm-col-status">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                            <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_question_prompt') }}</th>
                            <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_statistics_answered') }}</th>
                            <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_statistics_rate_heading') }}</th>
                            <th scope="col" class="ux-action-cell">{{ __('lf.LF_assessment_statistics_level') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stats['questions'] as $row)
                            <tr>
                                <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                                <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_question_prompt') }}">
                                    <span class="mk-name-line"><span class="mk-name-text" title="{{ $row['prompt'] }}">{{ $row['prompt'] }}</span></span>
                                    <p class="ui-caption">
                                        {{ __('lf.LF_assessment_question_type_'.$row['type']) }}
                                        @unless ($row['in_current']) · {{ __('lf.LF_assessment_statistics_old_version') }} @endunless
                                    </p>
                                </td>
                                <td class="mk-count-cell" data-label="{{ __('lf.LF_assessment_statistics_answered') }}">
                                    @if ($row['manual'])
                                        {{ $row['counted'] }} / {{ $row['answered'] }}
                                        <p class="ui-caption">{{ __('lf.LF_assessment_statistics_graded_short') }}</p>
                                    @else
                                        {{ $row['answered'] }}
                                    @endif
                                </td>
                                <td class="mk-count-cell" data-label="{{ __('lf.LF_assessment_statistics_rate_heading') }}">
                                    {{ $percent($row['rate']) }}
                                    @if ($row['pending'] > 0)
                                        <p class="ui-caption">{{ trans_choice('lf.LF_assessment_statistics_pending', $row['pending'], ['count' => $row['pending']]) }}</p>
                                    @endif
                                </td>
                                <td class="ux-action-cell" data-label="{{ __('lf.LF_assessment_statistics_level') }}">
                                    @if ($row['level'])
                                        <span @class(array_filter(['ui-status', $levelClass[$row['level']]]))>{{ __('lf.LF_assessment_statistics_level_'.$row['level']) }}</span>
                                    @elseif ($row['rate'] !== null)
                                        <span class="mk-na">—</span>
                                        <p class="ui-caption">{{ __('lf.LF_assessment_statistics_level_too_few') }}</p>
                                    @else
                                        <span class="mk-na">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr class="mk-empty-row">
                                <td class="mk-empty" colspan="5">
                                    <div role="status"><strong>{{ __('lf.LF_assessment_statistics_empty') }}</strong></div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
@endsection
