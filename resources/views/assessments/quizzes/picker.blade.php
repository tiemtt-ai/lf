@php
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_selection')).'"';
    $chosenSection = $sections->firstWhere('id', $sectionId) ?? $sections->first();
    $searchText = fn (...$parts) => mb_strtolower(implode(' ', array_filter($parts)));
@endphp
{{-- Picks ready questions (single ones, or whole passages) to append to one section of the quiz draft. --}}
<form class="ui-modal__form" method="POST" novalidate data-modal-columns="3"
      action="{{ route($routePrefix.'.questions.add', $quizId) }}"
      x-data="{ tab: 'questions', q: '', show(text) { return this.q.trim() === '' || text.includes(this.q.trim().toLowerCase()); } }">
    @csrf
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-quiz-picker-title">{{ __('lf.LF_assessment_quiz_add_questions') }}</h3>
            <p class="ui-caption">{{ __('lf.LF_assessment_quiz_picker_help') }}</p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            @if ($sections->count() > 1)
                <div class="ui-field">
                    <label class="ui-field__label" for="assessment-quiz-picker-section"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_quiz_section') }}</label>
                    <select id="assessment-quiz-picker-section" name="quiz_section_id" data-ui-kit-select required {!! $selectLabels !!}>
                        @foreach ($sections as $section)
                            <option value="{{ $section->id }}" @selected((int) $chosenSection->id === (int) $section->id)>{{ $section->title }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <input type="hidden" name="quiz_section_id" value="{{ $chosenSection->id }}">
            @endif
            <div class="ui-field">
                <span class="ui-field__label ui-field__label--tip">
                    <span id="assessment-quiz-picker-source">{{ __('lf.LF_assessment_quiz_picker_source') }}</span>
                    <span class="ui-source-icon" tabindex="0" role="img" data-tooltip-align="start" data-tooltip-placement="bottom"
                          data-tooltip="{{ __('lf.LF_assessment_quiz_picker_passages_tip') }}" aria-label="{{ __('lf.LF_assessment_quiz_picker_passages_tip') }}">
                        <x-backend-icon name="info" class="ui-icon" />
                    </span>
                </span>
                <div class="ui-chip-list" role="radiogroup" aria-labelledby="assessment-quiz-picker-source">
                    <label class="ui-chip cursor-interaction">
                        <input type="radio" value="questions" x-model="tab">
                        <x-backend-icon name="check" class="ui-chip__check" />
                        {{ __('lf.LF_assessment_quiz_picker_questions', ['count' => $questions->count()]) }}
                    </label>
                    <label class="ui-chip cursor-interaction">
                        <input type="radio" value="passages" x-model="tab">
                        <x-backend-icon name="check" class="ui-chip__check" />
                        {{ __('lf.LF_assessment_quiz_picker_passages', ['count' => $passages->count()]) }}
                    </label>
                </div>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-picker-search">{{ __('lf.LF_common_button_search') }}</label>
                <input id="assessment-quiz-picker-search" type="search" x-model="q" placeholder="{{ __('lf.LF_assessment_quiz_picker_search') }}">
            </div>

            <div class="ui-field ui-field--full" data-ui-error-for="question_ids">
                <div class="ui-pick-list" x-show="tab === 'questions'" role="group" aria-label="{{ __('lf.LF_assessment_quiz_picker_questions', ['count' => $questions->count()]) }}">
                    @forelse ($questions as $question)
                        @php $text = $searchText($question->prompt, $question->category_name, __('lf.LF_assessment_question_type_'.$question->question_type)); @endphp
                        <label class="ui-pick-row cursor-interaction" data-search="{{ $text }}" x-show="show($el.dataset.search)">
                            <input type="checkbox" class="ui-bulk-box" name="question_ids[]" value="{{ $question->id }}">
                            <span>
                                <span class="ui-passage-prompt">{{ $question->prompt }}</span>
                                <span class="ui-caption">{{ __('lf.LF_assessment_question_type_'.$question->question_type) }} · {{ $question->category_name ?? __('lf.LF_assessment_question_no_category') }}</span>
                            </span>
                            <span class="ui-caption">{{ __('lf.LF_assessment_quiz_points_value', ['points' => rtrim(rtrim(number_format((float) $question->default_points, 2, '.', ''), '0'), '.')]) }}</span>
                        </label>
                    @empty
                        <p class="ui-caption ui-pick-empty">{{ __('lf.LF_assessment_quiz_picker_no_questions') }}</p>
                    @endforelse
                </div>
                <div class="ui-pick-list" x-show="tab === 'passages'" x-cloak role="group" aria-label="{{ __('lf.LF_assessment_quiz_picker_passages', ['count' => $passages->count()]) }}">
                    @forelse ($passages as $passage)
                        @php $text = $searchText($passage->title, $passage->category_name); @endphp
                        <label class="ui-pick-row cursor-interaction" data-search="{{ $text }}" x-show="show($el.dataset.search)">
                            <input type="checkbox" class="ui-bulk-box" name="passage_ids[]" value="{{ $passage->id }}">
                            <span>
                                <span class="ui-passage-prompt">{{ $passage->title }}</span>
                                <span class="ui-caption">{{ $passage->skill_type ? __('lf.LF_assessment_question_skill_'.$passage->skill_type) : __('lf.LF_assessment_question_not_set') }} · {{ $passage->category_name ?? __('lf.LF_assessment_question_no_category') }}</span>
                            </span>
                            <span class="ui-caption">{{ trans_choice('lf.LF_assessment_quiz_picker_ready_count', (int) $passage->ready_count, ['count' => $passage->ready_count]) }}</span>
                        </label>
                    @empty
                        <p class="ui-caption ui-pick-empty">{{ __('lf.LF_assessment_quiz_picker_no_passages') }}</p>
                    @endforelse
                </div>
                <div x-show="tab === 'passages'" x-cloak>
                    @include('assessments.passages.partials.demo')
                </div>
            </div>
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_assessment_quiz_add_selected') }}
        </button>
    </footer>
</form>
