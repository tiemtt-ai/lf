@extends('layouts.tenant')

@section('title', __('lf.LF_assessment_learner_result_title').' | '.$version->title)

@section('content')
@php
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i') : '—';
    $lastSection = null;
    $lastPassage = null;
    $graded = $attempt->status === 'graded';
@endphp
    <a class="lf-exam-back" href="{{ route('student.assessments.mine') }}">← {{ __('lf.LF_assessment_learner_tab_mine') }}</a>

    @if (session('success'))
        <div class="student-alert is-success" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="student-alert is-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="student-card lf-exam-result" aria-labelledby="exam-result-title">
        <div class="lf-exam-result__head">
            <div>
                <p class="student-eyebrow">{{ __('lf.LF_assessment_learner_result_title') }} · {{ __('lf.LF_assessment_result_attempt_no', ['number' => $attempt->attempt_no]) }}</p>
                <h1 id="exam-result-title">{{ $version->title }}</h1>
            </div>
            <span @class(['lf-exam-state', 'is-active' => $graded, 'is-pending' => ! $graded])>
                {{ $graded ? __('lf.LF_assessment_learner_graded') : __('lf.LF_assessment_learner_waiting_grading') }}
            </span>
        </div>

        <div class="lf-exam-score">
            @if ($visible['score'])
                <p class="lf-exam-score__value">{{ $trim($attempt->score) }}<span> / {{ $trim($attempt->max_score) }}</span></p>
                <p class="lf-exam-score__meta">
                    {{ $trim($attempt->score_percentage) }}%
                    @if ($attempt->passed !== null)
                        · <strong @class(['is-pass' => $attempt->passed, 'is-fail' => ! $attempt->passed])>{{ $attempt->passed ? __('lf.LF_assessment_result_passed') : __('lf.LF_assessment_result_failed') }}</strong>
                        ({{ __('lf.LF_assessment_result_pass_mark', ['mark' => $trim($version->pass_score_percentage)]) }})
                    @elseif (! $graded)
                        · {{ __('lf.LF_assessment_learner_score_provisional') }}
                    @endif
                </p>
            @else
                <p class="lf-exam-score__hidden">
                    {{ $version->show_result_mode === 'hidden' ? __('lf.LF_assessment_learner_result_hidden') : __('lf.LF_assessment_learner_result_after_grading') }}
                </p>
            @endif
        </div>

        <dl class="tenant-detail-grid lf-exam-result__facts">
            <div><dt>{{ __('lf.LF_assessment_result_started_at') }}</dt><dd>{{ $date($attempt->started_at) }}</dd></div>
            <div><dt>{{ __('lf.LF_assessment_result_submitted_at') }}</dt><dd>{{ $date($attempt->submitted_at) }}@if ($attempt->submit_reason === 'timeout') · {{ __('lf.LF_assessment_result_reason_timeout') }}@endif</dd></div>
            <div><dt>{{ __('lf.LF_assessment_learner_time_spent') }}</dt><dd>{{ __('lf.LF_assessment_learner_duration', ['minutes' => intdiv((int) $attempt->time_spent_seconds, 60), 'seconds' => (int) $attempt->time_spent_seconds % 60]) }}</dd></div>
        </dl>

        <div class="tenant-course-actions">
            <a class="student-button is-outline" href="{{ route('student.assessments.mine') }}">{{ __('lf.LF_assessment_learner_tab_mine') }}</a>
            @if ($canRetry)
                <form method="POST" action="{{ route('student.assessments.start', $attempt->quiz_registration_id) }}">
                    @csrf
                    <button class="student-button" type="submit">{{ __('lf.LF_assessment_learner_retry') }}</button>
                </form>
            @endif
        </div>
    </section>

    @if ($visible['answers'])
        <section class="lf-exam-review" aria-labelledby="exam-review-title">
            <h2 id="exam-review-title" class="student-section-title">{{ __('lf.LF_assessment_learner_review') }}</h2>
            <p class="student-section-copy">{{ __('lf.LF_assessment_learner_review_help') }}</p>

            @foreach ($questions as $question)
                @include('tenant.assessments.partials.passage-and-section')
                @php
                    $lastSection = $question->section_key;
                    $lastPassage = $question->passage_id;
                    $answer = $answers->get($question->id);
                    $chosen = collect(json_decode((string) ($answer?->selected_option_keys ?? '[]'), true) ?: [])->map(fn ($k) => (int) $k);
                    $right = collect($question->correct['option_keys'] ?? [])->map(fn ($k) => (int) $k);
                    $pending = $answer?->grading_status === 'pending';
                @endphp
                <article class="student-card lf-exam-question" aria-labelledby="review-{{ $question->id }}">
                    <header class="lf-exam-question__head">
                        <h3 id="review-{{ $question->id }}" class="lf-exam-question__number">{{ __('lf.LF_assessment_quiz_question_number', ['number' => $question->number]) }}</h3>
                        <span @class([
                            'lf-exam-state',
                            'is-pending' => $pending,
                            'is-active' => ! $pending && $answer && ($question->manual ? true : (bool) $answer->is_correct),
                            'is-wrong' => ! $pending && ! $question->manual && ! ($answer?->is_correct),
                        ])>
                            @if ($pending)
                                {{ __('lf.LF_assessment_learner_waiting_grading') }} · {{ $trim($question->points) }}
                            @else
                                {{ $trim($answer?->score ?? 0) }} / {{ $trim($question->points) }}
                            @endif
                        </span>
                    </header>
                    <p class="lf-exam-text lf-exam-question__prompt">{{ $question->prompt }}</p>
                    @foreach ($question->media as $media)
                        @include('tenant.assessments.partials.media', ['media' => $media, 'url' => $mediaUrls[$media['media_file_id']] ?? null])
                    @endforeach

                    @if ($question->options !== [])
                        <ul class="lf-exam-options">
                            @foreach ($question->options as $option)
                                @php $isChosen = $chosen->contains($option['key']); $isRight = $right->contains($option['key']); @endphp
                                <li @class(['lf-exam-option', 'is-right' => $isRight, 'is-wrong' => $isChosen && ! $isRight])>
                                    <span class="lf-exam-option__label">{{ $option['label'] }}</span>
                                    <span class="lf-exam-option__text">{{ $option['text'] }}</span>
                                    <span class="lf-exam-option__note">{{ collect([
                                        $isChosen ? __('lf.LF_assessment_learner_your_choice') : null,
                                        $isRight ? __('lf.LF_assessment_result_correct_answer') : null,
                                    ])->filter()->implode(' · ') }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($chosen->isEmpty())
                            <p class="student-section-copy">{{ __('lf.LF_assessment_result_no_answer') }}</p>
                        @endif
                    @elseif ($question->type === 'speaking')
                        <div class="lf-exam-answer">
                            <p class="lf-exam-answer__label">{{ __('lf.LF_assessment_learner_your_answer') }}</p>
                            @if ($answer && ($fileUrls[$answer->id] ?? null))
                                <audio class="lf-exam-media" controls preload="metadata" src="{{ $fileUrls[$answer->id] }}"></audio>
                            @elseif ($question->answered)
                                <p class="student-section-copy">{{ __('lf.LF_assessment_passage_media_processing') }}</p>
                            @else
                                <p class="student-section-copy">{{ __('lf.LF_assessment_result_no_recording') }}</p>
                            @endif
                        </div>
                    @else
                        <div class="lf-exam-answer">
                            <p class="lf-exam-answer__label">{{ __('lf.LF_assessment_learner_your_answer') }}</p>
                            @php $answerFiles = $attachments->get($question->id, collect()); @endphp
                            @if (filled($answer?->answer_text) || $answerFiles->isEmpty())
                                <p class="lf-exam-text">{{ filled($answer?->answer_text) ? $answer->answer_text : __('lf.LF_assessment_result_no_answer') }}</p>
                            @endif
                            @if ($answerFiles->isNotEmpty())
                                <ul class="lf-exam-files__list">
                                    @foreach ($answerFiles as $file)
                                        <li class="lf-exam-file">
                                            <span class="lf-exam-file__thumb" aria-hidden="true">
                                                @if ($file['url'] && $file['kind'] === 'image')
                                                    <img src="{{ $file['url'] }}" alt="" loading="lazy">
                                                @else
                                                    {{ mb_substr(pathinfo($file['name'], PATHINFO_EXTENSION), 0, 4) }}
                                                @endif
                                            </span>
                                            @if ($file['url'])
                                                <a class="lf-exam-file__name" href="{{ $file['url'] }}" target="_blank" rel="noopener">{{ $file['name'] }}</a>
                                            @else
                                                <span class="lf-exam-file__name">{{ $file['name'] }}</span>
                                                <span class="lf-exam-file__note">{{ __('lf.LF_assessment_learner_files_checking') }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        @if ($question->type === 'short_answer')
                            <p class="student-section-copy">{{ __('lf.LF_assessment_result_accepted', ['answers' => implode(' / ', $question->correct['accepted'] ?? [])]) }}</p>
                        @endif
                    @endif

                    @if ($question->rubric && $answer && $rubricScores->has($answer->id))
                        @php $criterionScores = $rubricScores->get($answer->id); @endphp
                        <div class="lf-exam-rubric">
                            <p class="lf-exam-answer__label">{{ __('lf.LF_assessment_learner_rubric', ['title' => $question->rubric['title']]) }}</p>
                            <ul class="lf-exam-rubric__list">
                                @foreach ($question->rubric['items'] as $item)
                                    <li>
                                        <span>{{ $item['title'] }}</span>
                                        <strong>{{ $trim($criterionScores[$item['key']] ?? 0) }} / {{ $trim($item['max_points']) }}</strong>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($visible['feedback'] && filled($answer?->feedback))
                        <div class="lf-exam-feedback">
                            <p class="lf-exam-answer__label">{{ __('lf.LF_assessment_learner_teacher_feedback') }}</p>
                            <p class="lf-exam-text">{{ $answer->feedback }}</p>
                        </div>
                    @endif
                    @if (filled($question->explanation))
                        <details class="lf-exam-details">
                            <summary>{{ __('lf.LF_assessment_question_explanation') }}</summary>
                            <p class="lf-exam-text">{{ $question->explanation }}</p>
                        </details>
                    @endif
                </article>
            @endforeach
        </section>
    @endif
@endsection
