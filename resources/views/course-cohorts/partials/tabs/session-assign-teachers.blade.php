{{-- Past-start session of a draft class with no teacher: only teachers can still be added (Owner decision 2026-11-04). --}}
<dialog x-ref="assignDialog" class="ui-modal ui-cohort-teacher-modal" data-columns="1" aria-labelledby="session_assign_title"
        x-on:close="showAssignErrors = false; assignSession = null; assignTeacherIds = []" x-on:cancel="if (assignSubmitting) $event.preventDefault()">
    <form method="POST" class="ui-modal__form" x-bind:action="assignSession ? assignUrl.replace('__SESSION__', assignSession.id) : ''"
          x-on:submit="if (assignSubmitting) { $event.preventDefault(); return } assignSubmitting = true">
        @csrf @method('PUT')
        <input type="hidden" name="form_context" value="assign_teachers">
        <input type="hidden" name="assign_session_id" x-bind:value="assignSession?.id || ''">
        <header class="ui-modal__header">
            <div>
                <h3 id="session_assign_title">{{ __('lf.LF_course_cohort_session_assign_teachers') }}</h3>
                <p class="ui-caption" x-text="assignSession ? `${assignSession.title} · ${formatSessionDateTime(assignSession.scheduled_start_at)}` : ''"></p>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="closeAssign()" x-bind:disabled="assignSubmitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <div class="ui-field">
                <label class="ui-field__label" for="session_assign_teachers"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_teachers') }}</label>
                <select id="session_assign_teachers" name="teacher_ids[]" multiple required data-ui-kit-select
                        data-ui-placeholder="{{ __('lf.LF_course_cohort_session_select_teacher') }}"
                        data-ui-empty-label="{{ __('lf.LF_course_cohort_session_teachers_empty') }}"
                        x-model="assignTeacherIds">
                    @foreach ($availableTeachers as $teacher)
                        <option value="{{ $teacher->id }}" x-bind:disabled="!assignTeacherSelectable({{ $teacher->id }})">{{ $teacher->name }} · {{ $teacherRoleLabel($teacher) }}</option>
                    @endforeach
                </select>
                <p class="ui-caption">{{ __('lf.LF_course_cohort_session_assign_teachers_help') }}</p>
                @if ($assignHasErrors)
                    @foreach ($errors->all() as $message)<p class="ui-field__error" role="alert" x-show="showAssignErrors">{{ $message }}</p>@endforeach
                @endif
            </div>
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="closeAssign()" x-bind:disabled="assignSubmitting">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="assignSubmitting" x-bind:aria-busy="assignSubmitting">
                <x-backend-icon name="check" class="ui-icon" />
                {{ __('lf.LF_common_button_save') }}
            </button>
        </footer>
    </form>
</dialog>
