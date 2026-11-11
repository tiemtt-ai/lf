@extends('layouts.tenant')

@php
    $title = $course->product_title ?: $course->version_title;
    $day = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : null;
    $clock = function (int $seconds): string {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds % 60);
    };
    $lockLabel = function (object $lesson) use ($day): string {
        return match ($lesson->reason) {
            'prerequisite_incomplete', 'prerequisites_incomplete' => __('lf.LF_my_learning_lock_prerequisite'),
            'unlock_time_pending' => __('lf.LF_my_learning_lock_time', ['date' => \Illuminate\Support\Carbon::parse($lesson->unlock_at)->setTimezone(config('app.timezone'))->format('d/m/Y H:i')]),
            'outside_enrollment_access_window' => __('lf.LF_my_learning_lock_window'),
            'inactive_or_invalid_enrollment' => __('lf.LF_my_learning_lock_enrollment'),
            default => __('lf.LF_my_learning_lock_other'),
        };
    };
    $windowEnd = match ($course->phase) {
        'access' => $course->access_ends_at,
        'review' => $course->review_ends_at,
        default => null,
    };
    $daysLeft = $windowEnd ? max(0, (int) ceil(now()->diffInMinutes(\Illuminate\Support\Carbon::parse($windowEnd), false) / 1440)) : null;
@endphp

@section('title', $title.' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))

@section('content')
    <a class="lf-learn-back" href="{{ route('student.courses.index') }}"><span aria-hidden="true">←</span> {{ __('lf.LF_my_learning_back') }}</a>

    <section class="student-card lf-learn-summary" aria-labelledby="learn-course-title">
        <h1 id="learn-course-title">{{ $title }}</h1>
        @if ($course->teachers !== [])
            <p class="lf-learn-summary__meta">{{ __('lf.LF_my_learning_teacher') }}: <strong>{{ implode(', ', $course->teachers) }}</strong></p>
        @endif

        <div class="lf-learn-stats">
            <div class="lf-learn-stat">
                <span>{{ __('lf.LF_my_learning_course_duration') }}</span>
                <strong>{{ $clock($course->duration_seconds) }}</strong>
                <small>{{ __('lf.LF_my_learning_lessons_count', ['count' => $course->lesson_total]) }}</small>
            </div>
            <div class="lf-learn-stat">
                <span>{{ __('lf.LF_my_learning_course_lessons_open') }}</span>
                <strong>{{ $course->lesson_open }}/{{ $course->lesson_total }}</strong>
            </div>
            <div class="lf-learn-stat">
                <span>{{ __('lf.LF_my_learning_course_lessons_done') }}</span>
                <strong>{{ $course->lesson_completed }}/{{ $course->lesson_total }}</strong>
                <span class="lf-learn-bar"><span style="width: {{ $course->progress }}%"></span></span>
            </div>
        </div>

        <p class="lf-learn-summary__window">
            {{ __('lf.LF_my_learning_access_period') }}:
            @if ($course->phase === 'open')
                {{ __('lf.LF_my_learning_no_time_limit') }}
            @else
                {{ $day($course->access_starts_at) }} → {{ $day($course->access_ends_at) }}
                @if ($course->review_ends_at) · {{ __('lf.LF_my_learning_review_until', ['date' => $day($course->review_ends_at)]) }} @endif
            @endif
            <span @class(['lf-learn-badge', 'is-green' => in_array($course->phase, ['open', 'access'], true) && $course->status === 'active', 'is-orange' => $course->phase === 'review', 'is-muted' => $course->phase === 'ended' || $course->status !== 'active'])>
                {{ in_array($course->status, ['pending', 'suspended', 'completed', 'expired'], true) ? __('lf.LF_my_learning_status_'.$course->status) : __('lf.LF_my_learning_phase_'.$course->phase) }}
                @if ($daysLeft !== null) · {{ __('lf.LF_my_learning_days_left', ['days' => $daysLeft]) }} @endif
            </span>
        </p>

        @if ($course->resume_lesson_id)
            <p class="lf-learn-summary__resume">
                <a class="student-button" href="{{ route('student.courses.lessons.show', [$course->id, $course->resume_lesson_id]) }}" data-learn-resume>
                    {{ $course->progress > 0 ? __('lf.LF_my_learning_resume') : __('lf.LF_my_learning_start') }} <span aria-hidden="true">→</span>
                </a>
            </p>
        @endif

        @if ($course->is_class && $course->cohort_id)
            <p class="lf-learn-summary__class">
                {{ __('lf.LF_my_learning_course_is_class') }}
                <a class="student-text-link" href="{{ route('student.courses.classes.show', $course->cohort_id) }}">{{ __('lf.LF_my_learning_course_open_class') }}</a>
            </p>
        @endif
    </section>

    <section class="lf-learn-outline" aria-labelledby="learn-outline-title">
        <header class="lf-learn-outline__head">
            <h2 id="learn-outline-title">{{ __('lf.LF_my_learning_course_outline') }}</h2>
            <p>{{ __('lf.LF_my_learning_course_outline_help') }}</p>
        </header>

        @if ($course->lesson_total === 0)
            <div class="student-card lf-learn-empty" role="status"><p>{{ __('lf.LF_my_learning_course_outline_empty') }}</p></div>
        @endif

        @foreach ($course->sections as $section)
            <div class="lf-learn-section">
                @if ($course->sections->count() > 1 || $section->title)
                    <h3 class="lf-learn-section__title">{{ $section->title ?? __('lf.LF_my_learning_course_untitled_section') }}</h3>
                @endif
                <ol class="lf-learn-lessons">
                    @foreach ($section->lessons as $lesson)
                        <li @class(['student-card', 'lf-learn-lesson', 'is-locked' => ! $lesson->allowed]) data-learn-lesson="{{ $lesson->id }}" data-learn-lesson-state="{{ $lesson->allowed ? 'open' : 'locked' }}">
                            <span class="lf-learn-lesson__no"><small>{{ __('lf.LF_my_learning_course_lesson_no') }}</small>{{ sprintf('%02d', $lesson->number) }}</span>
                            <div class="lf-learn-lesson__body">
                                @if ($lesson->allowed)
                                    <a class="lf-learn-lesson__link" href="{{ route('student.courses.lessons.show', [$course->id, $lesson->id]) }}"><strong>{{ $lesson->title }}</strong></a>
                                @else
                                    <strong>{{ $lesson->title }}</strong>
                                @endif
                                <span class="lf-learn-lesson__meta">
                                    @if ($lesson->duration_seconds) {{ $clock((int) $lesson->duration_seconds) }} · @endif
                                    {{ __('lf.LF_my_learning_course_activities', ['count' => (int) $lesson->activity_count]) }}
                                    @if ($lesson->is_preview) · {{ __('lf.LF_my_learning_lesson_preview') }} @endif
                                </span>
                            </div>
                            <span class="lf-learn-lesson__state">
                                @if ($lesson->progress_status === 'completed')
                                    <span class="lf-learn-badge is-green">{{ __('lf.LF_my_learning_lesson_completed') }}</span>
                                @elseif (! $lesson->allowed)
                                    <span class="lf-learn-badge is-muted">
                                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                                        {{ $lockLabel($lesson) }}
                                    </span>
                                @elseif ($lesson->progress_status === 'in_progress')
                                    <span class="lf-learn-badge is-orange">{{ __('lf.LF_my_learning_lesson_in_progress') }} · {{ $lesson->progress }}%</span>
                                @else
                                    <span class="lf-learn-badge is-blue">{{ __('lf.LF_my_learning_lesson_open') }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endforeach
    </section>
@endsection
