@php
    $isEdit = $quiz !== null;
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_selection')).'"';
    $passScore = $quiz?->pass_score_percentage !== null ? rtrim(rtrim((string) $quiz->pass_score_percentage, '0'), '.') : '';
@endphp
<form class="ui-modal__form" method="POST" novalidate data-modal-columns="3"
      action="{{ $isEdit ? route($routePrefix.'.update', $quiz->id) : route($routePrefix.'.store') }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-quiz-dialog-title">{{ $isEdit ? __('lf.LF_assessment_quiz_edit') : __('lf.LF_assessment_quiz_create') }}</h3>
            @if ($isEdit && $quiz->status !== 'draft')
                <p class="ui-caption">{{ __('lf.LF_assessment_quiz_edit_published_note') }}</p>
            @endif
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-quiz-form-title"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_quiz_title') }}</label>
                <input id="assessment-quiz-form-title" type="text" name="title" value="{{ $quiz?->title }}" required maxlength="255"
                       placeholder="{{ __('lf.LF_assessment_quiz_title_placeholder') }}">
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-form-type"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_quiz_type') }}</label>
                <select id="assessment-quiz-form-type" name="assessment_type" data-ui-kit-select required {!! $selectLabels !!}>
                    @foreach (\App\Services\Assessment\AssessmentQuizService::TYPES as $type)
                        <option value="{{ $type }}" @selected(($quiz?->assessment_type ?? 'exercise') === $type)>{{ __('lf.LF_assessment_quiz_type_'.$type) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-form-category">{{ __('lf.LF_assessment_question_category') }}</label>
                <select id="assessment-quiz-form-category" name="category_id" data-ui-kit-select {!! $selectLabels !!}>
                    <option value="">{{ __('lf.LF_assessment_question_no_category') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) $quiz?->category_id === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-form-result"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_quiz_show_result') }}</label>
                <select id="assessment-quiz-form-result" name="show_result_mode" data-ui-kit-select required {!! $selectLabels !!}>
                    @foreach (\App\Services\Assessment\AssessmentQuizService::RESULT_MODES as $mode)
                        <option value="{{ $mode }}" @selected(($quiz?->show_result_mode ?? 'after_grading') === $mode)>{{ __('lf.LF_assessment_quiz_result_'.$mode) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-form-duration">{{ __('lf.LF_assessment_quiz_duration') }}</label>
                <input id="assessment-quiz-form-duration" type="number" name="duration_minutes" min="1" max="1440" step="1" inputmode="numeric"
                       value="{{ $quiz?->duration_minutes }}" placeholder="{{ __('lf.LF_assessment_quiz_no_time_limit') }}">
                <p class="ui-caption">{{ __('lf.LF_assessment_quiz_duration_help') }}</p>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-form-attempts"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_quiz_max_attempts') }}</label>
                <input id="assessment-quiz-form-attempts" type="number" name="max_attempts" min="1" max="100" step="1" inputmode="numeric" required
                       value="{{ $quiz?->max_attempts ?? 1 }}">
                <p class="ui-caption">{{ __('lf.LF_assessment_quiz_max_attempts_help') }}</p>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-form-pass">{{ __('lf.LF_assessment_quiz_pass_score') }}</label>
                <input id="assessment-quiz-form-pass" type="number" name="pass_score_percentage" min="0" max="100" step="0.01" inputmode="decimal"
                       value="{{ $passScore }}" placeholder="{{ __('lf.LF_assessment_quiz_no_pass_score') }}">
                <p class="ui-caption">{{ __('lf.LF_assessment_quiz_pass_score_help') }}</p>
            </div>
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-quiz-form-description">{{ __('lf.LF_assessment_quiz_description') }}</label>
                <textarea id="assessment-quiz-form-description" name="description" rows="4" maxlength="5000"
                          placeholder="{{ __('lf.LF_assessment_quiz_description_placeholder') }}">{{ $quiz?->description }}</textarea>
            </div>
        </div>
    </div>
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
