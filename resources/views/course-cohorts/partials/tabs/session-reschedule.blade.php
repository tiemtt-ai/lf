{{-- Reschedule popup: moves the planned interval only and appends the schedule-change audit. --}}
<dialog x-ref="rescheduleDialog" class="ui-modal ui-cohort-session-modal" data-columns="2" aria-labelledby="session_reschedule_title"
        x-on:close="rescheduleDialogClosed()" x-on:cancel="if (rescheduleSubmitting) $event.preventDefault()">
    <form method="POST" class="ui-modal__form" x-bind:action="rescheduleSession ? rescheduleUrl.replace('__SESSION__', rescheduleSession.id) : ''"
          x-on:submit="submitReschedule($event)">
        @csrf @method('PUT')
        <input type="hidden" name="form_context" value="reschedule">
        <input type="hidden" name="reschedule_session_id" x-bind:value="rescheduleSession?.id || ''">
        <header class="ui-modal__header">
            <div>
                <h3 id="session_reschedule_title">{{ __('lf.LF_course_cohort_session_change_schedule') }}</h3>
                <p class="ui-caption" x-text="rescheduleSession?.title"></p>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="closeReschedule()" x-bind:disabled="rescheduleSubmitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <div class="ui-modal__grid">
                <div class="ui-field ui-field--full">
                    <p class="ui-field__label">{{ __('lf.LF_course_cohort_session_current_schedule') }}</p>
                    <p class="ui-cohort-session-time-text" x-text="`${formatSessionDateTime(rescheduleSession?.scheduled_start_at)} → ${formatSessionDateTime(rescheduleSession?.scheduled_end_at)} · ${relationLabels[rescheduleSession?.schedule_relation] || '—'}`"></p>
                </div>
                <div class="ui-field">
                    <label class="ui-field__label" for="reschedule_start_at"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_start') }}</label>
                    <x-ui-date-input time id="reschedule_start_at" name="scheduled_start_at" x-ref="rescheduleStart" x-model="rescheduleStart"
                                     x-on:change="rescheduleStartChanged()" x-bind:min="scheduleMin" x-bind:max="scheduleMax" />
                    <p class="ui-caption" x-text="`{{ __('lf.LF_course_cohort_session_timezone') }}: ${rescheduleSession?.timezone || ''}`"></p>
                    <p class="ui-field__error" role="alert" x-show="showRescheduleErrors && rescheduleStartError" x-text="rescheduleStartError" x-cloak></p>
                </div>
                <div class="ui-field">
                    <label class="ui-field__label" for="reschedule_end_at"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_end') }}</label>
                    <x-ui-date-input time id="reschedule_end_at" name="scheduled_end_at" x-ref="rescheduleEnd" x-model="rescheduleEnd"
                                     x-on:change="clearTimeError($el)" x-bind:min="rescheduleStart || scheduleMin" x-bind:max="scheduleMax" />
                    @if ($rescheduleHasErrors)
                        @error('scheduled_end_at')<p class="ui-field__error" role="alert" x-show="showRescheduleErrors">{{ $message }}</p>@enderror
                    @endif
                </div>
                <div class="ui-field ui-field--full">
                    <label class="ui-field__label" for="reschedule_reason">{{ __('lf.LF_course_cohort_session_change_reason') }}</label>
                    <textarea id="reschedule_reason" name="reason" rows="2" maxlength="1000" x-model="rescheduleReason"></textarea>
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_session_change_reason_help') }}</p>
                    @if ($rescheduleHasErrors)
                        @error('reason')<p class="ui-field__error" role="alert" x-show="showRescheduleErrors">{{ $message }}</p>@enderror
                    @endif
                </div>
            </div>
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="closeReschedule()" x-bind:disabled="rescheduleSubmitting">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="rescheduleSubmitting" x-bind:aria-busy="rescheduleSubmitting">
                <x-backend-icon name="check" class="ui-icon" />
                {{ __('lf.LF_course_cohort_session_save_schedule') }}
            </button>
        </footer>
    </form>
</dialog>
