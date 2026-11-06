@php
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_selection')).'"';
@endphp
{{-- Grants one published quiz to several students; who already holds it is skipped by the server. --}}
<form class="ui-modal__form" method="POST" novalidate data-modal-columns="2" action="{{ route($routePrefix.'.store') }}"
      x-data="{ q: '', chosen: 0, show(text) { return this.q.trim() === '' || text.includes(this.q.trim().toLowerCase()); } }"
      x-on:change="chosen = $el.querySelectorAll('input[name=&quot;student_ids[]&quot;]:checked').length">
    @csrf
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-registration-dialog-title">{{ __('lf.LF_assessment_registration_create') }}</h3>
            <p class="ui-caption">{{ __('lf.LF_assessment_registration_create_help') }}</p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-registration-quiz"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_registration_quiz') }}</label>
                <select id="assessment-registration-quiz" name="quiz_id" data-ui-kit-select required {!! $selectLabels !!}>
                    <option value="">{{ __('lf.LF_assessment_registration_choose_quiz') }}</option>
                    @foreach ($quizzes as $quiz)
                        <option value="{{ $quiz->id }}" @selected($quizId === (int) $quiz->id)>{{ $quiz->title }}</option>
                    @endforeach
                </select>
                @if ($quizzes->isEmpty())
                    <p class="ui-caption">{{ __('lf.LF_assessment_registration_no_quizzes') }}</p>
                @endif
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="assessment-registration-search">{{ __('lf.LF_common_button_search') }}</label>
                <input id="assessment-registration-search" type="search" x-model="q" placeholder="{{ __('lf.LF_assessment_registration_student_search') }}">
            </div>
            <div class="ui-field ui-field--full" data-ui-error-for="student_ids">
                <span class="ui-field__label" id="assessment-registration-students">
                    <span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_registration_students') }}
                    <span class="ui-caption" x-show="chosen > 0" x-text="@js(__('lf.LF_assessment_registration_chosen')).replace(':count', chosen)"></span>
                </span>
                <div class="ui-pick-list" role="group" aria-labelledby="assessment-registration-students">
                    @forelse ($students as $student)
                        <label class="ui-pick-row cursor-interaction" data-search="{{ mb_strtolower($student->name.' '.$student->email) }}" x-show="show($el.dataset.search)">
                            <input type="checkbox" class="ui-bulk-box" name="student_ids[]" value="{{ $student->id }}">
                            <span>
                                <span>{{ $student->name }}</span>
                                <span class="ui-caption">{{ $student->email }}</span>
                            </span>
                            <span></span>
                        </label>
                    @empty
                        <p class="ui-caption ui-pick-empty">{{ __('lf.LF_assessment_registration_no_students') }}</p>
                    @endforelse
                </div>
            </div>
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="assessment-registration-note">{{ __('lf.LF_assessment_registration_note') }}</label>
                <input id="assessment-registration-note" type="text" name="note" maxlength="500" placeholder="{{ __('lf.LF_assessment_registration_note_placeholder') }}">
            </div>
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_assessment_registration_grant') }}
        </button>
    </footer>
</form>
