@extends('layouts.tenant')

@php
    $courseTitle = $course->product_title ?: $course->version_title;
    $clock = function (int $seconds): string {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    };
    $lockLabel = fn (string $reason): string => match ($reason) {
        'prerequisite_incomplete' => __('lf.LF_my_learning_activity_lock_prerequisite'),
        'outside_cohort_period' => __('lf.LF_my_learning_activity_lock_period'),
        'no_active_cohort' => __('lf.LF_my_learning_activity_lock_class'),
        'outside_enrollment_access_window' => __('lf.LF_my_learning_lock_window'),
        default => __('lf.LF_my_learning_lock_other'),
    };
    $messages = ['failed' => __('lf.LF_my_learning_activity_failed')];
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
@endphp

@section('title', $lesson->title.' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))

@section('content')
    <a class="lf-learn-back" href="{{ route('student.courses.show', $course->id) }}"><span aria-hidden="true">←</span> {{ $courseTitle }}</a>

    <header class="student-card lf-learn-summary lf-learn-lesson-head">
        <p class="lf-learn-summary__meta">
            {{ __('lf.LF_my_learning_course_lesson_no') }} {{ sprintf('%02d', $lesson->number) }}
            @if ($lesson->section_title) · {{ $lesson->section_title }} @endif
        </p>
        <h1>{{ $lesson->title }}</h1>
        @if ($lesson->summary)
            <p class="lf-learn-summary__meta">{{ $lesson->summary }}</p>
        @endif
    </header>

    @if ($liveSessions->isNotEmpty())
        @include('tenant.my-learning.partials.live-sessions')
    @endif

    <section class="lf-learn-outline" aria-labelledby="learn-activities-title">
        <header class="lf-learn-outline__head">
            <h2 id="learn-activities-title">{{ __('lf.LF_my_learning_activities') }}</h2>
        </header>

        @if ($lesson->activities->isEmpty())
            <div class="student-card lf-learn-empty" role="status"><p>{{ __('lf.LF_my_learning_activities_empty') }}</p></div>
        @endif

        <ol class="lf-learn-activities">
            @foreach ($lesson->activities as $activity)
                @php
                    $config = [
                        'status' => $activity->progress_status,
                        'percentage' => $activity->progress,
                        'resumeAt' => $activity->resume_at,
                        'tracksPosition' => in_array($activity->progress_rule, ['watch_percentage', 'listen_percentage'], true),
                        'durationKnown' => (int) $activity->duration_seconds > 0,
                        'progressUrl' => route('student.courses.activities.progress', [$course->id, $activity->id]),
                        'mediaUrl' => route('student.courses.activities.media', [$course->id, $activity->id]),
                        'csrf' => csrf_token(),
                        'messages' => $messages,
                    ];
                    $seconds = (int) ($activity->duration_seconds ?: $activity->estimated_seconds);
                @endphp
                <li @class(['student-card', 'lf-learn-activity', 'is-locked' => ! $activity->allowed])
                    data-learn-activity="{{ $activity->id }}" data-learn-activity-type="{{ $activity->activity_type }}"
                    x-data="lfLearnerActivity(@js($config))">
                    <div class="lf-learn-activity__head">
                        <div class="lf-learn-activity__title">
                            <span class="lf-learn-badge is-blue">{{ __('lf.LF_my_learning_activity_type_'.$activity->activity_type) }}</span>
                            <strong>{{ $activity->title }}</strong>
                            <span class="lf-learn-lesson__meta">
                                @if ($seconds > 0) {{ $clock($seconds) }} · @endif
                                {{ $activity->is_required ? __('lf.LF_my_learning_activity_required') : __('lf.LF_my_learning_activity_optional') }}
                                · {{ __('lf.LF_my_learning_rule_'.$activity->progress_rule, ['percent' => (int) ($activity->completion_threshold ?? 100)]) }}
                            </span>
                        </div>
                        <span class="lf-learn-activity__state" data-learn-activity-state>
                            <span class="lf-learn-badge is-green" x-show="completed" @if ($activity->progress_status !== 'completed') x-cloak @endif>{{ __('lf.LF_my_learning_lesson_completed') }}</span>
                            <span class="lf-learn-badge is-orange" x-show="! completed && status !== 'not_started'" x-cloak>
                                {{ __('lf.LF_my_learning_lesson_in_progress') }}<template x-if="percentage > 0"><span x-text="' · ' + percentage + '%'"></span></template>
                            </span>
                        </span>
                    </div>
                    @if ($activity->description)
                        <p class="lf-learn-activity__description">{{ $activity->description }}</p>
                    @endif

                    @if (! $activity->allowed)
                        <p class="lf-learn-activity__locked">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                            {{ $lockLabel($activity->reason) }}
                        </p>
                    @elseif (in_array($activity->activity_type, ['video', 'audio'], true))
                        <div @class(['lf-learn-player', 'is-audio' => $activity->activity_type === 'audio'])>
                            <button type="button" class="student-button" x-show="! started" x-on:click="play()" x-bind:disabled="busy" data-learn-play>
                                <span aria-hidden="true">▶</span> {{ $activity->resume_at > 0 ? __('lf.LF_my_learning_activity_resume') : __('lf.LF_my_learning_activity_play') }}
                            </button>
                            @if ($activity->activity_type === 'video')
                                <video x-ref="player" x-show="started" x-cloak controls playsinline preload="none" x-bind:src="src || false"
                                       x-on:timeupdate="onTime()" x-on:pause="onPause()" x-on:ended="onEnded()" x-on:error="onError()"></video>
                            @else
                                <audio x-ref="player" x-show="started" x-cloak controls preload="none" x-bind:src="src || false"
                                       x-on:timeupdate="onTime()" x-on:pause="onPause()" x-on:ended="onEnded()" x-on:error="onError()"></audio>
                            @endif
                        </div>
                    @elseif ($activity->activity_type === 'embedded_video')
                        @if ($activity->embed_url)
                            <div class="lf-learn-player">
                                <button type="button" class="student-button" x-show="! started" x-on:click="showEmbed()" data-learn-play>
                                    <span aria-hidden="true">▶</span> {{ __('lf.LF_my_learning_activity_play') }}
                                </button>
                                <template x-if="started">
                                    <div class="lf-learn-embed">
                                        <iframe src="{{ $activity->embed_url }}" title="{{ $activity->title }}" allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                                                allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
                                    </div>
                                </template>
                            </div>
                        @else
                            <p class="lf-learn-activity__locked">{{ __('lf.LF_my_learning_activity_unavailable') }}</p>
                        @endif
                    @elseif ($activity->activity_type === 'document')
                        <button type="button" class="student-button is-outline" x-on:click="openDocument()" data-learn-open-document>
                            {{ __('lf.LF_my_learning_activity_open_document') }} <span aria-hidden="true">↗</span>
                        </button>
                    @elseif ($activity->activity_type === 'live_class')
                        @if ($activity->sessions->isEmpty())
                            <p class="lf-learn-activity__note">{{ __('lf.LF_my_learning_activity_no_session') }}</p>
                        @else
                            <ul class="lf-learn-activity__sessions">
                                @foreach ($activity->sessions as $session)
                                    <li>
                                        <strong>{{ $session->starts_at->format('d/m/Y') }} · {{ $session->starts_at->format('H:i') }}–{{ $session->ends_at->format('H:i') }}</strong>
                                        <span class="lf-learn-badge @if ($session->status === 'live') is-live @endif">{{ __('lf.LF_my_learning_session_status_'.$session->status) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($course->cohort_id)
                                <a class="student-text-link" href="{{ route('student.courses.classes.show', $course->cohort_id) }}">{{ __('lf.LF_my_learning_course_open_class') }}</a>
                            @endif
                        @endif
                    @elseif ($activity->activity_type === 'quiz' && ($card = $quizCards->get($activity->id)) && $card->version)
                        {{-- ADR-0032 D4: the frozen quiz version, attempts and what the learner may see of each. --}}
                        <div class="lf-learn-quiz" data-learn-quiz="{{ $activity->id }}">
                            <p class="lf-learn-lesson__meta">
                                {{ __('lf.LF_my_learning_quiz_questions', ['count' => (int) $card->version->question_count]) }}
                                · {{ $card->version->duration_minutes ? __('lf.LF_my_learning_quiz_minutes', ['minutes' => (int) $card->version->duration_minutes]) : __('lf.LF_my_learning_quiz_untimed') }}
                                · <span data-learn-quiz-attempts>{{ __('lf.LF_my_learning_quiz_attempts', ['used' => $card->used, 'max' => $card->max]) }}</span>
                            </p>
                            @if ($card->open)
                                <form method="POST" action="{{ route('student.courses.activities.attempts.store', [$course->id, $activity->id]) }}">
                                    @csrf
                                    <button type="submit" class="student-button" data-learn-quiz-start>{{ __('lf.LF_my_learning_quiz_resume') }} <span aria-hidden="true">→</span></button>
                                </form>
                            @elseif ($card->left > 0)
                                <form method="POST" action="{{ route('student.courses.activities.attempts.store', [$course->id, $activity->id]) }}">
                                    @csrf
                                    <button type="submit" class="student-button" data-learn-quiz-start>{{ $card->used > 0 ? __('lf.LF_my_learning_quiz_retry') : __('lf.LF_my_learning_quiz_start') }} <span aria-hidden="true">→</span></button>
                                </form>
                            @else
                                <p class="lf-learn-activity__note">{{ __('lf.LF_my_learning_quiz_no_attempts') }}</p>
                            @endif
                            @error('attempt')
                                <p class="student-alert is-danger" role="alert">{{ $message }}</p>
                            @enderror
                            @if ($card->finished->isNotEmpty())
                                <ul class="lf-learn-quiz__attempts" aria-label="{{ __('lf.LF_my_learning_quiz_history') }}">
                                    @foreach ($card->finished as $attempt)
                                        <li data-learn-quiz-attempt="{{ $attempt->id }}">
                                            <span>{{ __('lf.LF_my_learning_quiz_attempt_no', ['no' => $attempt->attempt_no]) }}</span>
                                            @if ($attempt->visible['score'])
                                                <strong>{{ $trim($attempt->score) }} / {{ $trim($attempt->max_score) }} ({{ $trim($attempt->score_percentage) }}%)</strong>
                                                @if ($attempt->status !== 'graded')
                                                    <span class="lf-learn-badge is-orange">{{ __('lf.LF_my_learning_results_waiting') }}</span>
                                                @endif
                                            @elseif ($attempt->status !== 'graded')
                                                <span class="lf-learn-badge is-orange">{{ __('lf.LF_my_learning_results_waiting') }}</span>
                                            @else
                                                <span class="lf-learn-badge">{{ __('lf.LF_my_learning_results_not_published') }}</span>
                                            @endif
                                            <a class="student-text-link" href="{{ route('student.assessments.attempts.result', $attempt->id) }}">{{ __('lf.LF_my_learning_quiz_view_result') }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @else
                        <p class="lf-learn-activity__locked">{{ __('lf.LF_my_learning_activity_unavailable') }}</p>
                    @endif

                    @if ($activity->allowed && $activity->progress_rule === 'manual')
                        <div class="lf-learn-activity__actions" x-show="! completed" @if ($activity->progress_status === 'completed') x-cloak @endif>
                            <button type="button" class="student-button is-outline" x-on:click="markDone()" x-bind:disabled="busy" data-learn-mark-done>
                                {{ __('lf.LF_my_learning_activity_mark_done') }}
                            </button>
                        </div>
                    @endif
                    <p class="student-alert is-danger lf-learn-activity__error" x-show="error" x-text="error" x-cloak role="alert"></p>
                </li>
            @endforeach
        </ol>
    </section>

    <nav class="lf-learn-pager" aria-label="{{ __('lf.LF_my_learning_course_outline') }}">
        @if ($lesson->previous_id)
            <a class="student-button is-outline" href="{{ route('student.courses.lessons.show', [$course->id, $lesson->previous_id]) }}"><span aria-hidden="true">←</span> {{ __('lf.LF_my_learning_previous_lesson') }}</a>
        @endif
        <span></span>
        @if ($lesson->next_id && $lesson->next_allowed)
            <a class="student-button" href="{{ route('student.courses.lessons.show', [$course->id, $lesson->next_id]) }}">{{ __('lf.LF_my_learning_next_lesson') }} <span aria-hidden="true">→</span></a>
        @endif
    </nav>
@endsection
