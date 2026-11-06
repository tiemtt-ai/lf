@extends('layouts.tenant')

@section('title', $version->title.' | '.($tenant?->name ?? 'LF'))

@section('content')
@php
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $lastSection = null;
    $lastPassage = null;
    $answeredCount = $questions->where('answered', true)->count();
@endphp
<div class="lf-exam-take" data-exam-attempt
     data-save-url="{{ route('student.assessments.attempts.save', $attempt->id) }}"
     data-record-url="{{ route('student.assessments.attempts.record', [$attempt->id, '__QUESTION__']) }}"
     data-remaining="{{ $remainingSeconds ?? '' }}"
     data-upload-limit="{{ $uploadLimitBytes }}"
     data-label-saving="{{ __('lf.LF_assessment_learner_saving') }}"
     data-label-saved="{{ __('lf.LF_assessment_learner_saved_at') }}"
     data-label-save-failed="{{ __('lf.LF_assessment_learner_save_failed') }}"
     data-label-recording="{{ __('lf.LF_assessment_learner_recording') }}"
     data-label-uploading="{{ __('lf.LF_assessment_learner_uploading') }}"
     data-label-recorded="{{ __('lf.LF_assessment_learner_recorded') }}"
     data-label-mic-error="{{ __('lf.LF_assessment_learner_mic_error') }}"
     data-label-upload-failed="{{ __('lf.LF_assessment_learner_upload_failed') }}"
     data-label-too-large="{{ __('lf.LF_assessment_learner_too_large') }}"
     data-label-confirm-all="{{ __('lf.LF_assessment_learner_confirm_all') }}"
     data-label-confirm-missing="{{ __('lf.LF_assessment_learner_confirm_missing') }}"
     data-label-time-up="{{ __('lf.LF_assessment_learner_time_up') }}">

    <header class="lf-exam-take__head">
        <div>
            <p class="student-eyebrow">{{ __('lf.LF_assessment_quiz_type_'.$version->assessment_type) }} · {{ __('lf.LF_assessment_result_attempt_no', ['number' => $attempt->attempt_no]) }}</p>
            <h1>{{ $version->title }}</h1>
            <p class="student-section-copy">
                {{ __('lf.LF_assessment_learner_fact_questions', ['count' => $questions->count()]) }} ·
                {{ __('lf.LF_assessment_learner_fact_points', ['points' => $trim($version->total_points)]) }}
                · {{ __('lf.LF_assessment_learner_autosave_note') }}
            </p>
        </div>
    </header>

    @if ($errors->any())
        <div class="student-alert is-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="lf-exam-take__layout">
        <form id="exam-form" class="lf-exam-paper" method="POST" action="{{ route('student.assessments.attempts.submit', $attempt->id) }}" novalidate>
            @csrf
            @foreach ($questions as $question)
                @include('tenant.assessments.partials.passage-and-section')
                @php
                    $lastSection = $question->section_key;
                    $lastPassage = $question->passage_id;
                    $answer = $answers->get($question->id);
                    $chosen = collect(json_decode((string) ($answer?->selected_option_keys ?? '[]'), true) ?: [])->map(fn ($k) => (int) $k);
                    $name = 'answers['.$question->id.']';
                @endphp

                <fieldset id="q-{{ $question->id }}" class="student-card lf-exam-question" data-question="{{ $question->id }}" data-type="{{ $question->type }}"
                          data-answered="{{ $question->answered ? '1' : '0' }}">
                    <legend class="lf-exam-question__head">
                        <span class="lf-exam-question__number">{{ __('lf.LF_assessment_quiz_question_number', ['number' => $question->number]) }}</span>
                        <span class="lf-exam-question__meta">{{ __('lf.LF_assessment_question_type_'.$question->type) }} · {{ __('lf.LF_assessment_learner_fact_points', ['points' => $trim($question->points)]) }}</span>
                    </legend>
                    <p class="lf-exam-text lf-exam-question__prompt">{{ $question->prompt }}</p>
                    @foreach ($question->media as $media)
                        @include('tenant.assessments.partials.media', ['media' => $media, 'url' => $mediaUrls[$media['media_file_id']] ?? null])
                    @endforeach

                    @if ($question->type !== 'speaking')
                        <input type="hidden" name="{{ $name }}[present]" value="1">
                    @endif

                    @if (in_array($question->type, ['single_choice', 'true_false', 'multiple_choice'], true))
                        @if ($question->type === 'multiple_choice')
                            <p class="student-section-copy">{{ __('lf.LF_assessment_learner_choose_many') }}</p>
                        @endif
                        <div class="lf-exam-options">
                            @foreach ($question->options as $option)
                                <label class="lf-exam-option">
                                    <input type="{{ $question->type === 'multiple_choice' ? 'checkbox' : 'radio' }}" name="{{ $name }}[option_keys][]"
                                           value="{{ $option['key'] }}" @checked($chosen->contains($option['key']))>
                                    <span class="lf-exam-option__label">{{ $option['label'] }}</span>
                                    <span class="lf-exam-option__text">{{ $option['text'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    @elseif ($question->type === 'short_answer')
                        <label class="sr-only" for="answer-{{ $question->id }}">{{ __('lf.LF_assessment_learner_your_answer') }}</label>
                        <input id="answer-{{ $question->id }}" class="student-form-control" type="text" name="{{ $name }}[text]" maxlength="500"
                               value="{{ $answer?->answer_text }}" placeholder="{{ __('lf.LF_assessment_learner_your_answer') }}" autocomplete="off">
                    @elseif ($question->type === 'essay')
                        <label class="sr-only" for="answer-{{ $question->id }}">{{ __('lf.LF_assessment_learner_your_answer') }}</label>
                        <textarea id="answer-{{ $question->id }}" class="student-form-control lf-exam-essay" name="{{ $name }}[text]" rows="7" maxlength="20000"
                                  placeholder="{{ __('lf.LF_assessment_learner_essay_placeholder') }}">{{ $answer?->answer_text }}</textarea>
                    @else
                        <div class="lf-exam-recorder" data-recorder>
                            <div class="lf-exam-recorder__controls">
                                <button type="button" class="student-button is-small" data-record-start>{{ $question->answered ? __('lf.LF_assessment_learner_record_again') : __('lf.LF_assessment_learner_record') }}</button>
                                <button type="button" class="student-button is-outline is-small" data-record-stop hidden>{{ __('lf.LF_assessment_learner_record_stop') }}</button>
                                <span class="lf-exam-recorder__time" data-record-time aria-live="off" hidden>0:00</span>
                            </div>
                            <audio class="lf-exam-media" controls data-record-preview hidden></audio>
                            <p class="student-section-copy" data-record-status role="status">{{ $question->answered ? __('lf.LF_assessment_learner_recorded') : __('lf.LF_assessment_learner_record_help') }}</p>
                            <label class="lf-exam-recorder__file">
                                {{ __('lf.LF_assessment_learner_record_file') }}
                                <input type="file" accept="audio/*" data-record-file>
                            </label>
                        </div>
                    @endif
                </fieldset>
            @endforeach
        </form>

        <aside class="lf-exam-panel" aria-label="{{ __('lf.LF_assessment_learner_panel') }}">
            <div class="student-card lf-exam-panel__card">
                @if ($remainingSeconds !== null)
                    <p class="lf-exam-panel__label">{{ __('lf.LF_assessment_learner_time_left') }}</p>
                    <p class="lf-exam-timer" data-exam-timer role="timer" aria-live="off">{{ sprintf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60) }}</p>
                @else
                    <p class="lf-exam-panel__label">{{ __('lf.LF_assessment_learner_fact_untimed') }}</p>
                @endif
                <p class="lf-exam-panel__label lf-exam-panel__progress">
                    {{ __('lf.LF_assessment_learner_progress') }}
                    <strong><span data-exam-answered>{{ $answeredCount }}</span> / {{ $questions->count() }}</strong>
                </p>
                <nav class="lf-exam-nav" aria-label="{{ __('lf.LF_assessment_learner_jump') }}">
                    @foreach ($questions as $question)
                        <a href="#q-{{ $question->id }}" @class(['lf-exam-nav__item', 'is-answered' => $question->answered]) data-nav="{{ $question->id }}">{{ $question->number }}</a>
                    @endforeach
                </nav>
                <p class="lf-exam-save" data-exam-save role="status" aria-live="polite"></p>
                <button type="submit" form="exam-form" class="student-button lf-exam-submit" data-exam-submit>{{ __('lf.LF_assessment_learner_submit') }}</button>
            </div>
        </aside>
    </div>

    <dialog class="lf-exam-dialog" data-exam-confirm aria-labelledby="exam-confirm-title">
        <h2 id="exam-confirm-title">{{ __('lf.LF_assessment_learner_submit') }}</h2>
        <p data-exam-confirm-message></p>
        <div class="lf-exam-dialog__actions">
            <button type="button" class="student-button is-outline" data-exam-confirm-cancel>{{ __('lf.LF_assessment_learner_keep_working') }}</button>
            <button type="button" class="student-button" data-exam-confirm-accept>{{ __('lf.LF_assessment_learner_submit') }}</button>
        </div>
    </dialog>
</div>
@endsection
