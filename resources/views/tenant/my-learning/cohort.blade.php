@extends('layouts.tenant')

@php
    $day = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : '—';
    $courseTitle = $class->product_title ?: $class->version_title;
@endphp

@section('title', $class->name.' | '.($tenant?->name ?? 'LF'))

@section('content')
    <a class="lf-learn-back" href="{{ route('student.courses.index', ['tab' => 'classes']) }}"><span aria-hidden="true">←</span> {{ __('lf.LF_my_learning_back_classes') }}</a>

    <section class="student-card lf-learn-summary" aria-labelledby="learn-class-title">
        <div class="lf-learn-badges">
            @if ($class->level || $class->category)
                <span class="lf-learn-badge is-blue">{{ \App\Services\LearnerMyLearningService::levelLabel($class->level) ?: $class->category }}</span>
            @endif
            @foreach ($class->delivery_modes as $mode)
                <span class="lf-learn-badge">{{ __('lf.LF_course_cohort_session_mode_'.$mode) }}</span>
            @endforeach
            <span @class(['lf-learn-badge', 'is-green' => $class->cohort_status === 'active', 'is-muted' => $class->cohort_status !== 'active'])>{{ __('lf.LF_my_learning_class_status_'.$class->cohort_status) }}</span>
        </div>
        <h1 id="learn-class-title">{{ $class->name }}</h1>
        <p class="lf-learn-summary__meta">
            @if ($courseTitle){{ $courseTitle }} · @endif
            @if ($class->teachers !== []){{ __('lf.LF_my_learning_teacher') }}: <strong>{{ implode(', ', $class->teachers) }}</strong> · @endif
            {{ __('lf.LF_my_learning_class_code') }}: {{ $class->code }} · {{ $day($class->start_date) }} → {{ $day($class->end_date) }}
        </p>

        <div class="lf-learn-class-panel">
            <div class="lf-learn-class-panel__progress">
                <div class="lf-learn-progress is-wide">
                    <span>{{ __('lf.LF_my_learning_class_attendance') }}</span>
                    <span class="lf-learn-bar"><span style="width: {{ $class->attendance_rate }}%"></span></span>
                    <strong>{{ $class->attendance_rate }}%</strong>
                </div>
                <div class="lf-learn-progress is-wide is-secondary">
                    <span>{{ __('lf.LF_my_learning_class_schedule_progress') }}</span>
                    <span class="lf-learn-bar"><span style="width: {{ $class->schedule_rate }}%"></span></span>
                    <strong>{{ $class->schedule_rate }}%</strong>
                </div>
                <p class="lf-learn-class-panel__note">
                    {{ __('lf.LF_my_learning_class_attendance_detail', ['attended' => $class->session_attended, 'held' => $class->session_held]) }}
                    · {{ __('lf.LF_my_learning_class_schedule_detail', ['held' => $class->session_held, 'total' => $class->session_total]) }}
                </p>
            </div>
            <div class="lf-learn-class-panel__next" data-learn-next>
                @if ($class->next_session)
                    <span>{{ __('lf.LF_my_learning_class_next') }}</span>
                    <strong>{{ $class->next_session->title }}</strong>
                    <small>{{ mb_convert_case($class->next_session->starts_at->translatedFormat('l'), MB_CASE_TITLE) }} {{ $class->next_session->starts_at->format('d/m/Y') }} · {{ $class->next_session->starts_at->format('H:i') }}–{{ $class->next_session->ends_at->format('H:i') }}</small>
                @else
                    <strong>{{ __('lf.LF_my_learning_class_next_none') }}</strong>
                @endif
            </div>
        </div>
    </section>

    @if ($liveSessions->isNotEmpty())
        @include('tenant.my-learning.partials.live-sessions')
    @endif

    <section class="lf-learn-outline" aria-labelledby="learn-schedule-title">
        <header class="lf-learn-outline__head">
            <h2 id="learn-schedule-title">{{ __('lf.LF_my_learning_schedule') }}</h2>
        </header>

        @if ($class->sessions->isEmpty())
            <div class="student-card lf-learn-empty" role="status"><p>{{ __('lf.LF_my_learning_schedule_empty') }}</p></div>
        @else
            <ol class="lf-learn-lessons">
                @foreach ($class->sessions as $session)
                    @php
                        $void = in_array($session->status, ['cancelled', 'no_show'], true);
                        // The mode already says "online"; a class in a room names the room and the facility.
                        $place = $session->delivery_mode === 'online'
                            ? ''
                            : collect([$session->room_name_snapshot, $session->facility_name_snapshot])->filter()->implode(' · ');
                        $attendance = $session->attendance_status ?? 'none';
                    @endphp
                    <li @class(['student-card', 'lf-learn-lesson', 'lf-learn-session', 'is-locked' => $void, 'is-live' => $session->status === 'live'])
                        data-learn-session="{{ $session->id }}" data-learn-session-status="{{ $session->status }}">
                        <span class="lf-learn-lesson__no lf-learn-session__date">
                            <small>{{ $session->starts_at->translatedFormat('D') }}</small>{{ $session->starts_at->format('d/m') }}
                        </span>
                        <div class="lf-learn-lesson__body">
                            <strong>{{ $session->session_no ? __('lf.LF_my_learning_session_no', ['no' => $session->session_no]).' · ' : '' }}{{ $session->title }}</strong>
                            <span class="lf-learn-lesson__meta">
                                {{ $session->starts_at->format('H:i') }}–{{ $session->ends_at->format('H:i') }}
                                · {{ __('lf.LF_course_cohort_session_mode_'.$session->delivery_mode) }}
                                @if ($place !== '') · {{ $place }} @endif
                                @if ($session->status === 'cancelled' && $session->cancellation_reason) · {{ $session->cancellation_reason }} @endif
                            </span>
                        </div>
                        <span class="lf-learn-lesson__state">
                            <span @class(['lf-learn-badge', 'is-live' => $session->status === 'live', 'is-blue' => $session->status === 'scheduled', 'is-muted' => $void || $session->status === 'completed'])>
                                {{ __('lf.LF_my_learning_session_status_'.$session->status) }}
                            </span>
                            @if (! $void && $session->status !== 'scheduled' || $session->attendance_status)
                                <span @class(['lf-learn-badge', 'is-green' => $attendance === 'present', 'is-orange' => in_array($attendance, ['late', 'excused'], true), 'is-danger' => $attendance === 'absent'])
                                      data-learn-attendance="{{ $attendance }}">{{ __('lf.LF_my_learning_attendance_'.$attendance) }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endsection
