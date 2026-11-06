@extends('layouts.backend')

@section('title', __('lf.LF_assessment_result_detail'))
@section('page_title', __('lf.LF_assessment_result_detail'))

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="route($routePrefix.'.index', $attempt->grading_status === 'pending' ? ['tab' => 'pending'] : [])" :label="__('lf.LF_navigation_menu_assessment_results')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
@php
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i') : '—';
    $multiSection = $sections->count() > 1;
    $lastSection = null;
    $lastPassage = null;
    $pendingAnswers = $answers->where('grading_status', 'pending')->count();
@endphp
<div id="assessment-result-page" class="lf-admin-ui lf-admin-ui--list" data-assessment-section="results" data-assessment-attempt="{{ $attempt->id }}">
    <x-ui-list-toasts />

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-ui-list-head :title="$attempt->student_name.' · '.$version->title" :text="__('lf.LF_assessment_result_detail_help')" />

    <section class="mk-section ui-result-summary-section" aria-labelledby="assessment-result-summary-title">
        <div class="ui-summary-head">
            <div>
                <h2 id="assessment-result-summary-title">{{ __('lf.LF_assessment_result_summary') }}</h2>
                <p class="ui-caption">{{ __('lf.LF_assessment_quiz_version_number', ['number' => $version->version_no]) }} · {{ __('lf.LF_assessment_result_attempt_no', ['number' => $attempt->attempt_no]) }}</p>
            </div>
            @if ($attempt->status === 'in_progress')
                <span class="ui-status ui-status--draft">{{ __('lf.LF_assessment_result_status_in_progress') }}</span>
            @else
                <span @class(['ui-status', 'ui-status--inactive' => $attempt->grading_status === 'pending', 'ui-status--active' => $attempt->grading_status !== 'pending'])>
                    {{ __('lf.LF_assessment_result_grading_'.$attempt->grading_status) }}
                    @if ($pendingAnswers > 0) · {{ trans_choice('lf.LF_assessment_result_pending_count', $pendingAnswers, ['count' => $pendingAnswers]) }} @endif
                </span>
            @endif
        </div>
        <dl class="ui-summary ui-quiz-summary ui-result-summary">
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_registration_student') }}</dt>
                <dd>{{ $attempt->student_name }} <span class="ui-caption ui-framework-description" title="{{ $attempt->student_email }}">{{ $attempt->student_email }}</span></dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_result_score') }}</dt>
                <dd>
                    @if ($attempt->score !== null)
                        <span class="ui-result-summary__score">{{ $trim($attempt->score) }} / {{ $trim($attempt->max_score) }}</span> ({{ $trim($attempt->score_percentage) }}%)
                        <span class="ui-caption ui-framework-description">
                            @if ($attempt->passed !== null)
                                {{ $attempt->passed ? __('lf.LF_assessment_result_passed') : __('lf.LF_assessment_result_failed') }}
                                ({{ __('lf.LF_assessment_result_pass_mark', ['mark' => $trim($version->pass_score_percentage)]) }})
                            @elseif ($attempt->status !== 'graded')
                                {{ __('lf.LF_assessment_result_score_provisional') }}
                            @endif
                        </span>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_result_started_at') }}</dt>
                <dd>{{ $date($attempt->started_at) }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_result_submitted_at') }}</dt>
                <dd>
                    {{ $date($attempt->submitted_at) }}
                    @if ($attempt->submit_reason)
                        <span class="ui-caption ui-framework-description">
                            {{ __('lf.LF_assessment_result_reason_'.$attempt->submit_reason) }}
                            @if ($attempt->time_spent_seconds !== null) · {{ __('lf.LF_assessment_result_time_spent', ['minutes' => intdiv((int) $attempt->time_spent_seconds, 60), 'seconds' => (int) $attempt->time_spent_seconds % 60]) }} @endif
                        </span>
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    <section class="mk-section ui-result-questions" aria-labelledby="assessment-result-answers-title">
        <div class="ui-summary-head">
            <div>
                <h2 id="assessment-result-answers-title">{{ __('lf.LF_assessment_result_answers') }}</h2>
                <p class="ui-caption">{{ __('lf.LF_assessment_result_answers_help') }}</p>
            </div>
        </div>

        @foreach ($questions as $question)
            @php
                $snapshot = json_decode((string) $question->question_snapshot, true) ?: [];
                $options = collect(json_decode((string) $question->options_snapshot, true) ?: []);
                $correct = json_decode((string) $question->correct_answer_snapshot, true) ?: [];
                $answer = $answers->get($question->id);
                $chosen = collect(json_decode((string) ($answer?->selected_option_keys ?? '[]'), true) ?: [])->map(fn ($k) => (int) $k);
                $correctKeys = collect($correct['option_keys'] ?? [])->map(fn ($k) => (int) $k);
                $manual = (bool) $question->requires_manual_grading;
                $rubric = json_decode((string) $question->rubric_snapshot, true);
                $currentGrading = $answer ? $history->get($answer->id)?->firstWhere('is_current', true) : null;
                $rubricScores = collect(json_decode((string) ($currentGrading?->rubric_result ?? '[]'), true) ?: [])->pluck('score', 'key');
            @endphp

            @if ($multiSection && $question->section_key !== $lastSection)
                @php $lastSection = $question->section_key; $section = $sections->get($question->section_key); @endphp
                <h3 class="ui-result-section-title">{{ $section['title'] ?? '' }}</h3>
                @if (filled($section['description'] ?? null))
                    <p class="ui-caption ui-passage-content">{{ $section['description'] }}</p>
                @endif
            @endif

            @if ($question->passage_id && $question->passage_id !== $lastPassage)
                @php $passage = $passages->get((string) $question->passage_id); @endphp
                @if ($passage)
                    <div class="ui-result-passage">
                        <p class="ui-result-passage__title">{{ $passage['title'] }}</p>
                        @foreach ($passage['media'] ?? [] as $media)
                            @include('assessments.results.partials.media', ['media' => $media, 'url' => $mediaUrls[$media['media_file_id']] ?? null])
                        @endforeach
                        @if (filled($passage['content'] ?? null))
                            <p class="ui-passage-content">{{ $passage['content'] }}</p>
                        @endif
                    </div>
                @endif
            @endif
            @php $lastPassage = $question->passage_id; @endphp

            <article id="answer-{{ $answer?->id }}" class="ui-result-question" aria-labelledby="result-question-{{ $question->id }}">
                <header class="ui-result-question__head">
                    <h3 id="result-question-{{ $question->id }}">
                        {{ __('lf.LF_assessment_quiz_question_number', ['number' => $question->sort_order]) }}
                        <span class="ui-caption">· {{ __('lf.LF_assessment_question_type_'.$question->question_type) }}</span>
                    </h3>
                    <span @class([
                        'ui-status',
                        'ui-status--active' => ! $manual && $answer?->is_correct,
                        'ui-status--danger' => ! $manual && $answer && ! $answer->is_correct && $answer->grading_status !== 'pending',
                        'ui-status--inactive' => $answer?->grading_status === 'pending',
                        'ui-status--completed' => $manual && $answer?->grading_status === 'graded',
                    ])>
                        @if ($answer?->grading_status === 'pending')
                            {{ __('lf.LF_assessment_result_grading_pending') }} · {{ $trim($question->points) }}
                        @elseif ($answer && $answer->score !== null)
                            {{ $trim($answer->score) }} / {{ $trim($question->points) }}
                        @else
                            — / {{ $trim($question->points) }}
                        @endif
                    </span>
                </header>

                <p class="ui-passage-content ui-result-question__prompt">{{ $snapshot['prompt'] ?? '' }}</p>
                @foreach ($snapshot['media'] ?? [] as $media)
                    @include('assessments.results.partials.media', ['media' => $media, 'url' => $mediaUrls[$media['media_file_id']] ?? null])
                @endforeach

                @if ($options->isNotEmpty())
                    <ul class="ui-result-options">
                        @foreach ($options as $option)
                            @php $isChosen = $chosen->contains((int) $option['key']); $isRight = $correctKeys->contains((int) $option['key']); @endphp
                            <li @class(['ui-result-option', 'is-chosen' => $isChosen, 'is-right' => $isRight, 'is-wrong' => $isChosen && ! $isRight])>
                                <span class="ui-result-option__label">{{ $option['label'] }}</span>
                                <span>{{ $option['text'] }}</span>
                                <span class="ui-caption">
                                    @if ($isChosen) {{ __('lf.LF_assessment_result_chosen') }} @endif
                                    @if ($isRight) {{ __('lf.LF_assessment_result_correct_answer') }} @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($chosen->isEmpty())
                        <p class="ui-caption">{{ __('lf.LF_assessment_result_no_answer') }}</p>
                    @endif
                @elseif ($question->question_type === 'speaking')
                    <div class="ui-result-answer">
                        <p class="ui-result-answer__label">{{ __('lf.LF_assessment_result_student_answer') }}</p>
                        @if ($answer && ($fileUrls[$answer->id] ?? null))
                            <audio class="ui-passage-media" controls preload="metadata" src="{{ $fileUrls[$answer->id] }}"></audio>
                        @else
                            <p class="ui-caption">{{ __('lf.LF_assessment_result_no_recording') }}</p>
                        @endif
                    </div>
                @else
                    <div class="ui-result-answer">
                        <p class="ui-result-answer__label">{{ __('lf.LF_assessment_result_student_answer') }}</p>
                        @if (filled($answer?->answer_text))
                            <p class="ui-passage-content">{{ $answer->answer_text }}</p>
                        @else
                            <p class="ui-caption">{{ __('lf.LF_assessment_result_no_answer') }}</p>
                        @endif
                    </div>
                    @if ($question->question_type === 'short_answer')
                        <p class="ui-caption">{{ __('lf.LF_assessment_result_accepted', ['answers' => implode(' / ', $correct['accepted'] ?? [])]) }}</p>
                    @endif
                @endif

                @if (filled($snapshot['reference_answer'] ?? null))
                    <details class="ui-details">
                        <summary>{{ __('lf.LF_assessment_question_reference_answer') }}</summary>
                        <p class="ui-passage-content">{{ $snapshot['reference_answer'] }}</p>
                    </details>
                @endif
                @if (filled($snapshot['explanation'] ?? null))
                    <details class="ui-details">
                        <summary>{{ __('lf.LF_assessment_question_explanation') }}</summary>
                        <p class="ui-passage-content">{{ $snapshot['explanation'] }}</p>
                    </details>
                @endif

                @if ($manual && $answer && $attempt->status !== 'in_progress')
                    <form @class(['ui-result-grade', 'ui-result-grade--rubric' => is_array($rubric)]) method="POST" action="{{ route($routePrefix.'.grade', [$attempt->id, $answer->id]) }}"
                          @if (is_array($rubric))
                              x-data="{ scores: @js($rubricScores->map(fn ($v) => $trim($v))->all()), total: {{ (float) $rubric['total_points'] }}, points: {{ (float) $question->points }},
                                  get sum() { return Object.values(this.scores).reduce((a, v) => a + (parseFloat(v) || 0), 0); },
                                  get converted() { return this.total > 0 ? Math.round(this.sum / this.total * this.points * 100) / 100 : 0; } }"
                          @endif>
                        @csrf
                        @if (is_array($rubric))
                        <fieldset class="ui-field ui-result-rubric">
                            <legend class="ui-field__label">{{ __('lf.LF_assessment_result_rubric', ['title' => $rubric['title']]) }}</legend>
                            @foreach ($rubric['items'] as $item)
                                <div class="ui-result-rubric__row">
                                    <label for="grade-criterion-{{ $answer->id }}-{{ $item['key'] }}">
                                        <span class="ui-result-rubric__name"><span class="ui-field__required" aria-hidden="true">*</span>{{ $item['title'] }}</span>
                                        @if (filled($item['description'] ?? null))
                                            <span class="ui-caption">{{ $item['description'] }}</span>
                                        @endif
                                    </label>
                                    <span class="ui-result-rubric__input">
                                        <input id="grade-criterion-{{ $answer->id }}-{{ $item['key'] }}" type="number" name="criteria[{{ $item['key'] }}]"
                                               min="0" max="{{ $trim($item['max_points']) }}" step="0.01" inputmode="decimal" required
                                               x-model="scores[{{ $item['key'] }}]">
                                        <span class="ui-caption">/ {{ $trim($item['max_points']) }}</span>
                                    </span>
                                </div>
                            @endforeach
                            <p class="ui-result-rubric__total" aria-live="polite">
                                {{ __('lf.LF_assessment_result_rubric_total') }}
                                <strong><span x-text="sum.toLocaleString()"></span> / {{ $trim($rubric['total_points']) }}</strong>
                                → <strong><span x-text="converted.toLocaleString()"></span> / {{ $trim($question->points) }}</strong>
                            </p>
                        </fieldset>
                        @else
                        <div class="ui-field">
                            <label class="ui-field__label" for="grade-score-{{ $answer->id }}"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_result_score_label', ['max' => $trim($question->points)]) }}</label>
                            <input id="grade-score-{{ $answer->id }}" type="number" name="score" min="0" max="{{ $trim($question->points) }}" step="0.01" inputmode="decimal" required
                                   value="{{ $answer->score !== null ? $trim($answer->score) : '' }}">
                        </div>
                        @endif
                        <div class="ui-field ui-result-grade__feedback">
                            <label class="ui-field__label" for="grade-feedback-{{ $answer->id }}">{{ __('lf.LF_assessment_result_feedback') }}</label>
                            <textarea id="grade-feedback-{{ $answer->id }}" name="feedback" rows="2" maxlength="5000"
                                      placeholder="{{ __('lf.LF_assessment_result_feedback_placeholder') }}">{{ $answer->feedback }}</textarea>
                        </div>
                        <button type="submit" class="ui-button ui-button--primary cursor-interaction">
                            <x-backend-icon name="check" class="ui-icon" />
                            {{ $answer->grading_status === 'graded' ? __('lf.LF_assessment_result_regrade') : __('lf.LF_assessment_result_save_grade') }}
                        </button>
                    </form>
                    @if ($history->has($answer->id))
                        <details class="ui-details">
                            <summary>{{ __('lf.LF_assessment_result_history', ['count' => $history[$answer->id]->count()]) }}</summary>
                            @foreach ($history[$answer->id] as $entry)
                                @php $entryScores = collect(json_decode((string) $entry->rubric_result, true) ?: [])->pluck('score', 'key'); @endphp
                                <p>{{ $date($entry->graded_at) }} · {{ $entry->grader_name }} · {{ $trim($entry->score) }}@if (is_array($rubric) && $entryScores->isNotEmpty()) ({{ collect($rubric['items'])->map(fn ($item) => $item['title'].' '.$trim($entryScores[$item['key']] ?? 0))->implode(', ') }})@endif @if ($entry->feedback) · {{ $entry->feedback }}@endif @if ($entry->is_current) ({{ __('lf.LF_assessment_quiz_version_current') }}) @endif</p>
                            @endforeach
                        </details>
                    @endif
                @elseif (filled($answer?->feedback))
                    <p class="ui-caption">{{ __('lf.LF_assessment_result_feedback') }}: {{ $answer->feedback }}</p>
                @endif
            </article>
        @endforeach
    </section>
</div>
@endsection
