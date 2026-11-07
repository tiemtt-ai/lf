@php
    $isEdit = $question !== null;
    $service = \App\Services\Assessment\AssessmentQuestionService::class;
    $selectedType = $question?->question_type ?? 'single_choice';
    $selectedStatus = $question?->status ?? 'draft';
    $points = $question ? rtrim(rtrim(number_format((float) $question->default_points, 2, '.', ''), '0'), '.') : '1';
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_selection')).'"';
@endphp
<form class="ui-modal__form" method="POST" enctype="multipart/form-data" novalidate data-modal-columns="3"
      action="{{ $isEdit ? route($routePrefix.'.update', $question->id) : route($routePrefix.'.store') }}"
      x-data="{
          type: @js($selectedType),
          options: @js($choiceOptions),
          answers: @js($acceptedAnswers),
          get isChoice() { return this.type === 'single_choice' || this.type === 'multiple_choice'; },
          letter(index) { return String.fromCharCode(65 + index); },
          markCorrect(index) {
              if (this.type === 'single_choice') this.options.forEach((option, i) => option.correct = i === index);
              else this.options[index].correct = ! this.options[index].correct;
          },
      }">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-question-dialog-title">
                {{ $isEdit ? __('lf.LF_assessment_question_edit') : __('lf.LF_assessment_question_create') }}
            </h3>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        @if ($passage)
            @unless ($isEdit)
                <input type="hidden" name="passage_id" value="{{ $passage->id }}">
            @endunless
            <p class="ui-caption ui-question-passage-note">{{ __('lf.LF_assessment_question_in_passage', ['title' => $passage->title]) }}</p>
        @endif
        <div class="ui-modal__grid">
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-question-form-type"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_question_type') }}</label>
                <select id="assessment-question-form-type" name="question_type" data-ui-kit-select required x-model="type" {!! $selectLabels !!}>
                    @foreach ($service::TYPES as $type)
                        <option value="{{ $type }}" @selected($selectedType === $type)>{{ __('lf.LF_assessment_question_type_'.$type) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-question-form-category">{{ __('lf.LF_assessment_question_category') }}</label>
                <select id="assessment-question-form-category" name="category_id" data-ui-kit-select {!! $selectLabels !!}>
                    <option value="">{{ __('lf.LF_assessment_question_no_category') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) $question?->category_id === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-question-form-status"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_category_status') }}</label>
                <select id="assessment-question-form-status" name="status" data-ui-kit-select required {!! $selectLabels !!}>
                    @foreach ($service::STATUSES as $statusOption)
                        <option value="{{ $statusOption }}" @selected($selectedStatus === $statusOption)>{{ __('lf.LF_assessment_question_status_'.$statusOption) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-question-form-skill">{{ __('lf.LF_assessment_question_skill') }}</label>
                <select id="assessment-question-form-skill" name="skill_type" data-ui-kit-select {!! $selectLabels !!}>
                    <option value="">{{ __('lf.LF_assessment_question_not_set') }}</option>
                    @foreach ($service::SKILLS as $skill)
                        <option value="{{ $skill }}" @selected($question?->skill_type === $skill)>{{ __('lf.LF_assessment_question_skill_'.$skill) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-question-form-level">{{ __('lf.LF_assessment_question_level') }}</label>
                <select id="assessment-question-form-level" name="difficulty_level" data-ui-kit-select {!! $selectLabels !!}>
                    <option value="">{{ __('lf.LF_assessment_question_not_set') }}</option>
                    @foreach ($service::LEVELS as $level)
                        <option value="{{ $level }}" @selected($question?->difficulty_level === $level)>{{ __('lf.LF_assessment_question_level_'.$level) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-question-form-points"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_question_points') }}</label>
                <input id="assessment-question-form-points" type="number" name="default_points" value="{{ $points }}" min="0" max="999999.99" step="0.01" required>
            </div>

            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-question-form-prompt"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_question_prompt') }}</label>
                <textarea id="assessment-question-form-prompt" name="prompt" rows="4" maxlength="20000" required
                          placeholder="{{ __('lf.LF_assessment_question_prompt_placeholder') }}">{{ $content?->prompt }}</textarea>
            </div>

            <div class="ui-field">
                <p class="ui-field__label" id="assessment-question-image-label">{{ __('lf.LF_assessment_question_prompt_image') }}</p>
                <x-ui-media-slot id="prompt_image_file" name="prompt_image_file" accept="image/*" kind="image"
                    labelledby="assessment-question-image-label"
                    :presentation="$promptMedia['prompt_image']['presentation'] ?? null"
                    :display-name="$promptMedia['prompt_image']['name'] ?? null"
                    :preview-url="$promptMedia['prompt_image']['url'] ?? null"
                    remove-name="remove_prompt_image"
                    library-name="prompt_image_library_id" :library-caption="__('lf.LF_assessment_question_prompt_image')">
                    <x-upload-hint ui-kit :formats="['JPG', 'PNG', 'GIF', 'WEBP']" />
                </x-ui-media-slot>
            </div>
            <div class="ui-field">
                <p class="ui-field__label" id="assessment-question-audio-label">{{ __('lf.LF_assessment_question_prompt_audio') }}</p>
                <x-ui-media-slot id="prompt_audio_file" name="prompt_audio_file" accept=".mp3,.wav,.m4a,.aac,.ogg" kind="audio"
                    labelledby="assessment-question-audio-label"
                    :presentation="$promptMedia['prompt_audio']['presentation'] ?? null"
                    :display-name="$promptMedia['prompt_audio']['name'] ?? null"
                    :preview-url="$promptMedia['prompt_audio']['url'] ?? null"
                    remove-name="remove_prompt_audio"
                    library-name="prompt_audio_library_id" :library-caption="__('lf.LF_assessment_question_prompt_audio')">
                    <x-upload-hint ui-kit :formats="['MP3', 'WAV', 'M4A', 'AAC', 'OGG']" />
                </x-ui-media-slot>
            </div>
            <div class="ui-modal__row-break" aria-hidden="true"></div>

            {{-- Answers: the part shown follows the question type; hidden parts are disabled so they are not sent. --}}
            <div class="ui-field ui-field--full" :data-ui-error-for="isChoice ? 'options' : null" x-show="isChoice">
                <span class="ui-field__label">{{ __('lf.LF_assessment_question_choices') }}</span>
                <p class="ui-caption" x-text="type === 'single_choice' ? @js(__('lf.LF_assessment_question_choices_help_single')) : @js(__('lf.LF_assessment_question_choices_help_multiple'))"></p>
                <div class="ui-question-answers" role="group" aria-label="{{ __('lf.LF_assessment_question_choices') }}">
                    <template x-for="(option, index) in options" :key="index">
                        <div class="ui-question-answers__row">
                            <label class="ui-check ui-question-answers__correct">
                                <input :type="type === 'single_choice' ? 'radio' : 'checkbox'" name="choice_correct_marker"
                                       :checked="option.correct" x-on:change="markCorrect(index)" :disabled="! isChoice"
                                       :aria-label="@js(__('lf.LF_assessment_question_correct')) + ' ' + letter(index)">
                                <span x-text="letter(index)"></span>
                            </label>
                            <input type="hidden" :name="'options[' + index + '][correct]'" :value="option.correct ? 1 : 0" :disabled="! isChoice">
                            <input type="text" :id="'assessment-question-option-' + index" :name="'options[' + index + '][text]'" x-model="option.text"
                                   maxlength="2000" :disabled="! isChoice" :placeholder="@js(__('lf.LF_assessment_question_choice_placeholder')) + ' ' + letter(index)">
                            <button type="button" class="ux-row-delete cursor-interaction" x-on:click="options.splice(index, 1)" :disabled="options.length <= 2"
                                    :aria-label="@js(__('lf.LF_assessment_question_choice_remove')) + ' ' + letter(index)">
                                <x-backend-icon name="trash" class="ui-icon" />
                            </button>
                        </div>
                    </template>
                </div>
                <div>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction" x-on:click="options.push({ text: '', correct: false })" :disabled="options.length >= 20">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_assessment_question_choice_add') }}
                    </button>
                </div>
            </div>

            <fieldset class="ui-field ui-field--full" :data-ui-error-for="type === 'true_false' ? 'options' : null" x-show="type === 'true_false'">
                <legend class="ui-field__label">{{ __('lf.LF_assessment_question_correct_answer') }}</legend>
                <div class="ui-radio-group__options">
                    <label class="ui-check"><input type="radio" name="true_false_answer" value="true" @checked($trueFalseAnswer === 'true') :disabled="type !== 'true_false'"> {{ __('lf.LF_assessment_question_true') }}</label>
                    <label class="ui-check"><input type="radio" name="true_false_answer" value="false" @checked($trueFalseAnswer === 'false') :disabled="type !== 'true_false'"> {{ __('lf.LF_assessment_question_false') }}</label>
                </div>
            </fieldset>

            <div class="ui-field ui-field--full" :data-ui-error-for="type === 'short_answer' ? 'options' : null" x-show="type === 'short_answer'">
                <span class="ui-field__label">{{ __('lf.LF_assessment_question_accepted_answers') }}</span>
                <p class="ui-caption">{{ __('lf.LF_assessment_question_accepted_answers_help') }}</p>
                <div class="ui-question-answers" role="group" aria-label="{{ __('lf.LF_assessment_question_accepted_answers') }}">
                    <template x-for="(answer, index) in answers" :key="index">
                        <div class="ui-question-answers__row ui-question-answers__row--text">
                            <input type="text" :id="'assessment-question-answer-' + index" name="accepted_answers[]" x-model="answers[index]" maxlength="500"
                                   :disabled="type !== 'short_answer'" :aria-label="@js(__('lf.LF_assessment_question_accepted_answers')) + ' ' + (index + 1)">
                            <button type="button" class="ux-row-delete cursor-interaction" x-on:click="answers.splice(index, 1)" :disabled="answers.length <= 1"
                                    :aria-label="@js(__('lf.LF_assessment_question_choice_remove')) + ' ' + (index + 1)">
                                <x-backend-icon name="trash" class="ui-icon" />
                            </button>
                        </div>
                    </template>
                </div>
                <div>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction" x-on:click="answers.push('')" :disabled="answers.length >= 20">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_assessment_question_accepted_answer_add') }}
                    </button>
                </div>
            </div>

            <div class="ui-field ui-field--full" x-show="type === 'essay' || type === 'speaking'">
                <label class="ui-field__label" for="assessment-question-form-rubric">{{ __('lf.LF_assessment_question_rubric') }}</label>
                <select id="assessment-question-form-rubric" name="rubric_id" data-ui-kit-select {!! $selectLabels !!}
                        :disabled="type !== 'essay' && type !== 'speaking'">
                    <option value="">{{ __('lf.LF_assessment_question_rubric_none') }}</option>
                    @foreach ($rubrics as $rubric)
                        <option value="{{ $rubric->id }}" @selected((string) $question?->rubric_id === (string) $rubric->id)>{{ $rubric->title }} · {{ __('lf.LF_assessment_rubric_points', ['points' => \App\Services\Assessment\AssessmentRubricService::points((float) $rubric->total_points)]) }}@if ($rubric->status === 'archived') ({{ __('lf.LF_assessment_rubric_status_label_archived') }})@endif</option>
                    @endforeach
                </select>
                <p class="ui-caption">{{ __('lf.LF_assessment_question_rubric_help') }}</p>
            </div>

            <div class="ui-field ui-field--full" x-show="type === 'essay' || type === 'speaking'">
                <label class="ui-field__label" for="assessment-question-form-reference">{{ __('lf.LF_assessment_question_reference_answer') }}</label>
                <textarea id="assessment-question-form-reference" name="reference_answer" rows="3" maxlength="20000"
                          :disabled="type !== 'essay' && type !== 'speaking'"
                          placeholder="{{ __('lf.LF_assessment_question_reference_answer_placeholder') }}">{{ $content?->reference_answer }}</textarea>
            </div>

            {{-- Cho nộp tệp (ADR-0027 D5): essay only; another type sends nothing, so the flag is cleared. --}}
            <div class="ui-field ui-field--full" x-show="type === 'essay'" x-cloak>
                <label class="ui-switch-row" for="assessment-question-form-file-answer">
                    <span class="ui-switch-row__text">
                        <b>{{ __('lf.LF_assessment_question_allow_file_answer') }}</b>
                        <small>{{ __('lf.LF_assessment_question_allow_file_answer_help') }}</small>
                    </span>
                    <span class="ui-switch">
                        <input id="assessment-question-form-file-answer" type="checkbox" role="switch" name="allow_file_answer" value="1"
                               :disabled="type !== 'essay'" @checked($question?->allow_file_answer)>
                        <span></span>
                    </span>
                </label>
            </div>

            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-question-form-explanation">{{ __('lf.LF_assessment_question_explanation') }}</label>
                <textarea id="assessment-question-form-explanation" name="explanation" rows="3" maxlength="20000"
                          placeholder="{{ __('lf.LF_assessment_question_explanation_placeholder') }}">{{ $content?->explanation }}</textarea>
            </div>
        </div>
    </div>
    <x-media-library-picker />
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>
            {{ __('lf.LF_common_button_cancel') }}
        </button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_common_button_confirm') }}
        </button>
    </footer>
</form>
