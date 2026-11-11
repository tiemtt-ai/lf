@extends('layouts.tenant')

@section('title', __('lf.LF_student_site_my_results').' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))

{{-- "Kết quả học tập" (ADR-0031): one summary and one table per group, read only. --}}
@section('content')
@php
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i') : '—';
    $duration = function (int $seconds): string {
        $minutes = intdiv($seconds, 60);

        return $minutes >= 60
            ? __('lf.LF_my_learning_results_hours', ['hours' => intdiv($minutes, 60), 'minutes' => $minutes % 60])
            : __('lf.LF_my_learning_results_minutes', ['minutes' => $minutes]);
    };
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $score = fn (object $attempt) => $trim($attempt->score).' / '.$trim($attempt->max_score).' ('.$trim($attempt->score_percentage).'%)';
@endphp
    <section class="student-products student-results">
        @include('tenant.my-learning.partials.hub-nav', ['tab' => $tab])

        @if ($liveSessions->isNotEmpty())
            @include('tenant.my-learning.partials.live-sessions')
        @endif

        <h1>{{ __('lf.LF_student_site_my_results') }}</h1>
        <p class="student-products__description">{{ __('lf.LF_my_learning_results_help') }}</p>

        <div class="student-products__tabbar">
            <nav class="student-products__tabs" aria-label="{{ __('lf.LF_student_site_my_results') }}">
                @foreach (\App\Services\LearnerResultsService::VIEWS as $subView)
                    <a href="{{ route('student.courses.index', ['tab' => $tab, 'view' => $subView]) }}" @class(['is-active' => $view === $subView])
                       @if ($view === $subView) aria-current="page" @endif data-results-view="{{ $subView }}">
                        {{ __('lf.LF_my_learning_results_view_'.$subView) }}
                        <span class="student-products__tab-count">{{ $counts[$subView] }}</span>
                    </a>
                @endforeach
            </nav>
        </div>

        @if ($rows->isEmpty())
            <div class="student-products__grid">
                <div class="student-products__empty" role="status">
                    {!! \App\Support\LearnerHubIcon::svg($view, 48) !!}
                    <p>{{ __('lf.LF_my_learning_results_empty_'.$view) }}</p>
                </div>
            </div>
        @else
            <dl class="student-results__summary" data-results-summary="{{ $view }}">
                @if ($view === 'courses')
                    <div>
                        <dt>{{ __('lf.LF_my_learning_results_courses_completed') }}</dt>
                        <dd><strong>{{ $summary['completed'] }}/{{ $summary['total'] }}</strong>
                            <span>{{ __('lf.LF_my_learning_results_courses_split', ['current' => $summary['current'], 'expired' => $summary['expired']]) }}</span></dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_my_learning_results_lessons_done') }}</dt>
                        <dd><strong>{{ $summary['lessons_done'] }}/{{ $summary['lessons_total'] }}</strong> <span>{{ $summary['lessons_rate'] }}%</span>
                            <span class="lf-learn-bar" aria-hidden="true"><span style="width: {{ $summary['lessons_rate'] }}%"></span></span></dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_my_learning_results_learning_time') }}</dt>
                        <dd><strong>{{ $duration($summary['learning_seconds']) }}</strong></dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_my_learning_results_last_studied') }}</dt>
                        <dd><strong>{{ $date($summary['last_accessed_at']) }}</strong></dd>
                    </div>
                @elseif ($view === 'classes')
                    <div>
                        <dt>{{ __('lf.LF_my_learning_results_attendance') }}</dt>
                        <dd><strong>{{ $summary['attendance_rate'] }}%</strong>
                            <span class="lf-learn-bar" aria-hidden="true"><span style="width: {{ $summary['attendance_rate'] }}%"></span></span></dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_my_learning_results_attended') }}</dt>
                        <dd><strong>{{ $summary['attended'] }}</strong> <span>{{ __('lf.LF_my_learning_results_absent_count', ['count' => $summary['absent']]) }}</span></dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_my_learning_results_sessions_held') }}</dt>
                        <dd><strong>{{ $summary['sessions_held'] }}/{{ $summary['sessions_total'] }}</strong></dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_my_learning_results_classes') }}</dt>
                        <dd><strong>{{ $summary['total'] }}</strong>
                            <span>{{ __('lf.LF_my_learning_results_classes_split', ['current' => $summary['current'], 'completed' => $summary['completed']]) }}</span></dd>
                    </div>
                @else
                    <div>
                        <dt>{{ __('lf.LF_my_learning_results_exams_submitted') }}</dt>
                        <dd><strong>{{ $summary['submitted'] }}/{{ $summary['total'] }}</strong></dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_my_learning_results_attempts') }}</dt>
                        <dd><strong>{{ $summary['attempts'] }}</strong></dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_my_learning_results_average_latest') }}</dt>
                        <dd><strong>{{ $summary['average'] === null ? '—' : $trim($summary['average']).'%' }}</strong>
                            <span>{{ __('lf.LF_my_learning_results_average_note') }}</span></dd>
                    </div>
                @endif
            </dl>

            <div class="student-results__table-wrap" tabindex="0" role="region" aria-label="{{ __('lf.LF_my_learning_results_view_'.$view) }}">
                <table class="student-results__table" data-results-table="{{ $view }}">
                    @if ($view === 'courses')
                        <thead><tr>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_course') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_status') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_lessons') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_progress') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_learning_time') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_last_studied') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr data-results-row="{{ $row->id }}">
                                    <th scope="row"><a href="{{ route('student.courses.show', $row->id) }}">{{ $row->product_title ?: $row->version_title }}</a></th>
                                    <td>{{ __('lf.LF_my_learning_view_courses_'.$row->group) }}</td>
                                    <td>{{ $row->lessons_done }}/{{ $row->lessons_total }}</td>
                                    <td><span class="student-results__progress"><span class="lf-learn-bar" aria-hidden="true"><span style="width: {{ $row->progress }}%"></span></span>{{ $row->progress }}%</span></td>
                                    <td>{{ $duration($row->learning_seconds) }}</td>
                                    <td>{{ $date($row->last_accessed_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    @elseif ($view === 'classes')
                        <thead><tr>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_class') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_course') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_status') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_sessions_held') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_attended') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_absent') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_attendance') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr data-results-row="{{ $row->cohort_id }}">
                                    <th scope="row"><a href="{{ route('student.courses.classes.show', $row->cohort_id) }}">{{ $row->name }}</a></th>
                                    <td>{{ $row->product_title ?: $row->version_title }}</td>
                                    <td>{{ __('lf.LF_my_learning_view_classes_'.$row->group) }}</td>
                                    <td>{{ $row->session_held }}/{{ $row->session_total }}</td>
                                    <td>{{ min($row->session_attended, $row->session_held) }}</td>
                                    <td>{{ $row->session_absent }}</td>
                                    <td><span class="student-results__progress"><span class="lf-learn-bar" aria-hidden="true"><span style="width: {{ $row->attendance_rate }}%"></span></span>{{ $row->attendance_rate }}%</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    @else
                        <thead><tr>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_exam') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_status') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_attempts') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_latest') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_best') }}</th>
                            <th scope="col">{{ __('lf.LF_my_learning_results_col_submitted_at') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr data-results-row="{{ $row->id }}">
                                    <th scope="row">{{ $row->title ?? '—' }}
                                        @if ($row->course_title) <span class="student-results__note">{{ $row->course_title }}</span> @endif</th>
                                    <td>{{ $row->status_label }}</td>
                                    <td>{{ $row->submitted->count() }}/{{ (int) $row->max_attempts }}</td>
                                    <td>
                                        @if (! $row->latest)
                                            —
                                        @elseif ($row->latest_shown)
                                            <a href="{{ route('student.assessments.attempts.result', $row->latest->id) }}">{{ $score($row->latest) }}</a>
                                            @if ($row->latest->status !== 'graded') <span class="student-results__note">{{ __('lf.LF_my_learning_results_waiting') }}</span> @endif
                                        @else
                                            <a href="{{ route('student.assessments.attempts.result', $row->latest->id) }}" class="student-results__note">{{ __('lf.LF_my_learning_results_not_published') }}</a>
                                        @endif
                                    </td>
                                    <td>{{ $row->best ? $score($row->best) : '—' }}</td>
                                    <td>{{ $date($row->last_submitted_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endif
                </table>
            </div>
            @if ($view === 'exams')
                <p class="student-products__description student-results__more">
                    <a class="sb2b-learn-browse" href="{{ route('student.assessments.mine') }}">{{ __('lf.LF_my_learning_results_history') }} <span aria-hidden="true">→</span></a>
                </p>
            @endif
        @endif
    </section>
@endsection
