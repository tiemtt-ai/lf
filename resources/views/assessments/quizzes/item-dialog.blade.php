@php
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_selection')).'"';
    $points = rtrim(rtrim(number_format((float) $item->points, 2, '.', ''), '0'), '.');
@endphp
<form class="ui-modal__form" method="POST" novalidate data-modal-columns="2"
      action="{{ route($routePrefix.'.questions.update', [$quizId, $item->id]) }}">
    @csrf
    @method('PUT')
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-quiz-item-dialog-title">{{ __('lf.LF_assessment_quiz_item_edit') }}</h3>
            <p class="ui-caption ui-passage-prompt">{{ $prompt }}</p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-quiz-item-points"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_question_points') }}</label>
                <input id="assessment-quiz-item-points" type="number" name="points" min="0" step="0.01" inputmode="decimal" required value="{{ $points }}">
                <p class="ui-caption">{{ __('lf.LF_assessment_quiz_item_points_help') }}</p>
            </div>
            @if ($sections->count() > 1)
                <div class="ui-field">
                    <label class="ui-field__label" for="assessment-quiz-item-section"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_quiz_section') }}</label>
                    <select id="assessment-quiz-item-section" name="quiz_section_id" data-ui-kit-select required {!! $selectLabels !!}>
                        @foreach ($sections as $section)
                            <option value="{{ $section->id }}" @selected((int) $item->quiz_section_id === (int) $section->id)>{{ $section->title }}</option>
                        @endforeach
                    </select>
                    <p class="ui-caption">{{ __('lf.LF_assessment_quiz_item_section_help') }}</p>
                </div>
            @else
                <input type="hidden" name="quiz_section_id" value="{{ $item->quiz_section_id }}">
            @endif
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
