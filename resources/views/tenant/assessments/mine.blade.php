@extends('layouts.tenant')

@section('title', __('lf.LF_assessment_learner_tab_mine').' | '.($tenant?->name ?? 'LF'))

@section('content')
@php
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i') : '—';
@endphp
    <header class="student-page-heading tenant-page-heading">
        <p class="student-eyebrow">{{ __('lf.LF_assessment_learner_eyebrow') }}</p>
        <h1>{{ __('lf.LF_assessment_learner_tab_mine') }}</h1>
        <p>{{ __('lf.LF_assessment_learner_mine_help') }}</p>
    </header>

    @include('tenant.assessments.partials.tabs')

    @if (session('success'))
        <div class="student-alert is-success" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="student-alert is-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    @if ($registrations->isEmpty())
        <section class="student-card lf-exam-empty" role="status">
            <h2 class="student-section-title">{{ __('lf.LF_assessment_learner_mine_empty') }}</h2>
            <p class="student-section-copy">{{ __('lf.LF_assessment_learner_mine_empty_help') }}</p>
            <a class="student-button" href="{{ route('tenant.assessments') }}">{{ __('lf.LF_assessment_learner_browse') }}</a>
        </section>
    @else
        @php $activeQuizIds = $registrations->where('status', 'active')->pluck('quiz_id')->map(fn ($id) => (int) $id); @endphp
        <div class="lf-exam-list">
            @foreach ($registrations as $registration)
                @php
                    $open = $registration->attempts->firstWhere('status', 'in_progress');
                    $left = max(0, (int) $registration->max_attempts - (int) $registration->attempts_used);
                    $canStart = $registration->status === 'active' && $registration->quiz_status !== 'archived' && $registration->title !== null && $left > 0;
                    $canRegisterAgain = $registration->status !== 'active' && ! $activeQuizIds->contains((int) $registration->quiz_id) && $registration->registration_open && $registration->quiz_status === 'published';
                @endphp
                <article class="student-card lf-exam-row" aria-labelledby="registration-{{ $registration->id }}">
                    <div class="lf-exam-row__main">
                        <div class="lf-exam-card__top">
                            <span @class(['student-badge', 'is-green' => $registration->assessment_type === 'exercise'])>{{ __('lf.LF_assessment_quiz_type_'.($registration->assessment_type ?? 'exam')) }}</span>
                            <span @class(['lf-exam-state', 'is-'.$registration->status])>{{ __('lf.LF_assessment_registration_status_'.$registration->status) }}</span>
                        </div>
                        <h2 id="registration-{{ $registration->id }}" class="lf-exam-card__title">{{ $registration->title ?? '—' }}</h2>
                        <ul class="lf-exam-facts">
                            <li>{{ __('lf.LF_assessment_learner_fact_questions', ['count' => (int) $registration->question_count]) }}</li>
                            <li>{{ $registration->duration_minutes ? __('lf.LF_assessment_learner_fact_minutes', ['minutes' => $registration->duration_minutes]) : __('lf.LF_assessment_learner_fact_untimed') }}</li>
                            <li>{{ __('lf.LF_assessment_learner_attempts_used', ['used' => $registration->attempts_used, 'max' => (int) $registration->max_attempts]) }}</li>
                            <li>{{ __('lf.LF_assessment_learner_source_'.$registration->source) }} · {{ $date($registration->registered_at) }}</li>
                        </ul>

                        @if ($registration->attempts->isNotEmpty())
                            <ol class="lf-exam-attempts">
                                @foreach ($registration->attempts as $attempt)
                                    <li>
                                        <span class="lf-exam-attempts__no">{{ __('lf.LF_assessment_result_attempt_no', ['number' => $attempt->attempt_no]) }}</span>
                                        <span>
                                            @if ($attempt->status === 'in_progress')
                                                {{ __('lf.LF_assessment_result_status_in_progress') }}
                                            @elseif ($attempt->visible['score'])
                                                {{ $trim($attempt->score) }} / {{ $trim($attempt->max_score) }}
                                                @if ($attempt->status !== 'graded') · {{ __('lf.LF_assessment_learner_waiting_grading') }}
                                                @elseif ($attempt->passed !== null) · {{ $attempt->passed ? __('lf.LF_assessment_result_passed') : __('lf.LF_assessment_result_failed') }}
                                                @endif
                                            @else
                                                {{ $attempt->status === 'graded' ? __('lf.LF_assessment_learner_submitted_short') : __('lf.LF_assessment_learner_waiting_grading') }}
                                            @endif
                                        </span>
                                        <span class="lf-exam-attempts__date">{{ $date($attempt->submitted_at ?? $attempt->started_at) }}</span>
                                        @if ($attempt->status !== 'in_progress')
                                            <a class="student-text-link" href="{{ route('student.assessments.attempts.result', $attempt->id) }}">{{ __('lf.LF_assessment_learner_view_result') }}</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>

                    <div class="lf-exam-row__actions">
                        @if ($open)
                            <a class="student-button" href="{{ route('student.assessments.attempts.show', $open->id) }}">{{ __('lf.LF_assessment_learner_continue') }}</a>
                        @elseif ($canStart)
                            <form method="POST" action="{{ route('student.assessments.start', $registration->id) }}">
                                @csrf
                                <button class="student-button" type="submit">
                                    {{ $registration->attempts->isEmpty() ? __('lf.LF_assessment_learner_start') : __('lf.LF_assessment_learner_retry') }}
                                </button>
                            </form>
                            @if ($registration->duration_minutes)
                                <p class="student-section-copy">{{ __('lf.LF_assessment_learner_timer_note', ['minutes' => $registration->duration_minutes]) }}</p>
                            @endif
                        @elseif ($canRegisterAgain)
                            <form method="POST" action="{{ route('student.assessments.register', $registration->quiz_id) }}">
                                @csrf
                                <button class="student-button is-outline" type="submit">{{ __('lf.LF_assessment_learner_register_again') }}</button>
                            </form>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
@endsection
