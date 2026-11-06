@php
    $isEdit = $section !== null;
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_selection')).'"';
@endphp
<form class="ui-modal__form" method="POST" novalidate data-modal-columns="2"
      action="{{ $isEdit ? route($routePrefix.'.sections.update', [$quizId, $section->id]) : route($routePrefix.'.sections.store', $quizId) }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-quiz-section-dialog-title">{{ $isEdit ? __('lf.LF_assessment_quiz_section_edit') : __('lf.LF_assessment_quiz_section_create') }}</h3>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-section-title"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_quiz_section_title') }}</label>
                <input id="assessment-quiz-section-title" type="text" name="title" value="{{ $section?->title }}" required maxlength="255"
                       placeholder="{{ __('lf.LF_assessment_quiz_section_title_placeholder') }}">
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-section-skill">{{ __('lf.LF_assessment_question_skill') }}</label>
                <select id="assessment-quiz-section-skill" name="skill_type" data-ui-kit-select {!! $selectLabels !!}>
                    <option value="">{{ __('lf.LF_assessment_question_not_set') }}</option>
                    @foreach (\App\Services\Assessment\AssessmentQuestionService::SKILLS as $skill)
                        <option value="{{ $skill }}" @selected($section?->skill_type === $skill)>{{ __('lf.LF_assessment_question_skill_'.$skill) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-quiz-section-description">{{ __('lf.LF_assessment_quiz_section_description') }}</label>
                <textarea id="assessment-quiz-section-description" name="description" rows="3" maxlength="5000"
                          placeholder="{{ __('lf.LF_assessment_quiz_section_description_placeholder') }}">{{ $section?->description }}</textarea>
            </div>
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_common_button_confirm') }}
        </button>
    </footer>
</form>
