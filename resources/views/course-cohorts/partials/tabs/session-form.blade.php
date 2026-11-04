{{-- Create / edit one session. Times change only through the reschedule popup, so editing shows them as text. --}}
<dialog x-ref="sessionDialog" class="ui-modal ui-cohort-session-modal" data-columns="2" aria-labelledby="session_form_title"
        x-on:close="sessionDialogClosed()" x-on:cancel="if (submitting) $event.preventDefault()">
    <form method="POST" class="ui-modal__form course-cohort-session-form"
          action="{{ route('admin.course-cohorts.sessions.store', $cohort->id) }}"
          x-bind:action="editingId ? updateUrl.replace('__SESSION__', editingId) : storeUrl"
          x-on:submit="submitForm($event)">
        @csrf
        <input type="hidden" name="_method" value="PUT" x-bind:disabled="!editingId">
        <input type="hidden" name="form_context" value="session">
        <input type="hidden" name="editing_session_id" x-bind:value="editingId || ''">
        <header class="ui-modal__header">
            <div>
                <h3 id="session_form_title" x-text="editingId ? @js(__('lf.LF_course_cohort_session_edit')) : @js(__('lf.LF_course_cohort_session_manual_title'))"></h3>
                <p class="ui-caption" x-show="editingId" x-cloak>{{ __('lf.LF_course_cohort_session_edit_scope_help') }}</p>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="closeForm()" x-bind:disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            @if ($formSummaryErrors->isNotEmpty())
                <div class="ui-field__error ui-cohort-manage-error" role="alert" x-show="showFormErrors">{{ $formSummaryErrors->first() }}</div>
            @endif
            <div class="ui-modal__grid">
                <fieldset class="ui-field ui-field--full">
                    <legend class="ui-field__label"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_type') }}</legend>
                    <div class="ui-option-cards">
                        <label class="ui-option-card">
                            <input type="radio" name="session_type" value="curriculum" x-bind:checked="sessionType === 'curriculum'" x-on:change="switchType('curriculum')">
                            <span class="ui-option-card__dot" aria-hidden="true"></span>
                            <span><b>{{ __('lf.LF_course_cohort_session_type_curriculum') }}</b><small>{{ __('lf.LF_course_cohort_session_curriculum_help') }}</small></span>
                        </label>
                        <label class="ui-option-card">
                            <input type="radio" name="session_type" value="operational" x-bind:checked="sessionType === 'operational'" x-on:change="switchType('operational')">
                            <span class="ui-option-card__dot" aria-hidden="true"></span>
                            <span><b>{{ __('lf.LF_course_cohort_session_type_operational') }}</b><small>{{ __('lf.LF_course_cohort_session_operational_help') }}</small></span>
                        </label>
                    </div>
                </fieldset>

                <div class="ui-field" x-show="sessionType === 'curriculum'">
                    <label class="ui-field__label" for="version_lesson_id"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_lesson') }}</label>
                    <select id="version_lesson_id" name="version_lesson_id" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_enrollment_index_no_options') }}"
                            x-model="lessonId" x-on:change="lessonChanged()" x-bind:required="sessionType === 'curriculum'">
                        <option value="">{{ __('lf.LF_course_cohort_session_select_lesson') }}</option>
                        @foreach ($versionLessons as $lesson)<option value="{{ $lesson->id }}">{{ $lesson->title_snapshot }}</option>@endforeach
                    </select>
                    @error('version_lesson_id')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field" x-show="sessionType === 'curriculum'">
                    <label class="ui-field__label" for="version_activity_id"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_activity') }}</label>
                    <select id="version_activity_id" name="version_activity_id" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_cohort_session_activity_empty') }}"
                            x-model="activityId" x-on:change="activityChanged()" x-bind:required="sessionType === 'curriculum'">
                        <option value="">{{ __('lf.LF_course_cohort_session_select_activity') }}</option>
                        @foreach ($versionActivities as $activity)
                            <option value="{{ $activity->id }}" x-bind:disabled="String({{ $activity->version_lesson_id }}) !== String(lessonId)">{{ $activity->title_snapshot }}</option>
                        @endforeach
                    </select>
                    @error('version_activity_id')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>

                <div class="ui-field">
                    <label class="ui-field__label" for="session_title"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_name') }}</label>
                    <input x-ref="sessionTitle" id="session_title" name="title" x-model="title" x-on:input="titleDirty = true" required maxlength="255"
                           placeholder="{{ __('lf.LF_course_cohort_session_name_placeholder') }}">
                    <p class="ui-caption" x-show="sessionType === 'curriculum'">{{ __('lf.LF_course_cohort_session_title_help') }}</p>
                    @error('title')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field">
                    <label class="ui-field__label" for="delivery_mode"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_mode') }}</label>
                    <select id="delivery_mode" name="delivery_mode" data-ui-kit-select x-model="mode" required>
                        @foreach (['online', 'offline', 'hybrid'] as $deliveryMode)<option value="{{ $deliveryMode }}">{{ __('lf.LF_course_cohort_session_mode_'.$deliveryMode) }}</option>@endforeach
                    </select>
                    @error('delivery_mode')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>

                {{-- Vẫn gửi đi khi sửa: backend bỏ qua giá trị này và giữ nguyên thời gian của buổi. --}}
                <div class="ui-field" x-show="!editingId">
                    <label class="ui-field__label" for="scheduled_start_at"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_start') }}</label>
                    <x-ui-date-input time id="scheduled_start_at" name="scheduled_start_at" x-ref="sessionStart" x-model="startsAt"
                                     x-on:change="sessionStartChanged()" x-bind:min="scheduleMin" x-bind:max="scheduleMax" />
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_session_batch_timezone', ['timezone' => config('app.timezone')]) }}</p>
                    @error('scheduled_start_at')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field" x-show="!editingId">
                    <label class="ui-field__label" for="scheduled_end_at"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_end') }}</label>
                    <x-ui-date-input time id="scheduled_end_at" name="scheduled_end_at" x-ref="sessionEnd" x-model="endsAt"
                                     x-on:change="sessionEndChanged()" x-bind:min="startsAt || scheduleMin" x-bind:max="scheduleMax" />
                    @error('scheduled_end_at')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field ui-field--full" x-show="editingId" x-cloak>
                    <p class="ui-field__label">{{ __('lf.LF_course_cohort_session_schedule') }}</p>
                    <p class="ui-cohort-session-time-text" x-text="`${formatSessionDateTime(editingSession?.scheduled_start_at)} → ${formatSessionDateTime(editingSession?.scheduled_end_at)}`"></p>
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_session_edit_schedule_help') }}</p>
                </div>

                <div class="ui-field ui-field--full" x-ref="sessionTeacherField">
                    <label class="ui-field__label" for="session_teachers">{{ __('lf.LF_course_cohort_session_teachers') }}</label>
                    <select id="session_teachers" name="teacher_ids[]" multiple data-ui-kit-select
                            data-ui-placeholder="{{ __('lf.LF_course_cohort_session_batch_no_teacher') }}"
                            data-ui-empty-label="{{ __('lf.LF_course_cohort_session_teachers_empty') }}"
                            x-model="teacherIds">
                        @foreach ($availableTeachers as $teacher)
                            <option value="{{ $teacher->id }}" x-bind:disabled="!teacherSelectable({{ $teacher->id }})">{{ $teacher->name }} · {{ $teacherRoleLabel($teacher) }}</option>
                        @endforeach
                    </select>
                    <p class="ui-caption" x-show="!startsAt || !endsAt" x-cloak>{{ __('lf.LF_course_cohort_session_teachers_time_help') }}</p>
                    <p class="ui-caption" x-show="startsAt && endsAt && selectableTeachers().length === 0" x-cloak>{{ __('lf.LF_course_cohort_session_teachers_empty') }}</p>
                    @error('teacher_ids')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                    @error('teacher_ids.*')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>

                <div class="ui-field" x-bind:class="{ 'ui-field--full': sessionType === 'curriculum' }" x-show="mode === 'online' || mode === 'hybrid'">
                    <label class="ui-field__label" for="meeting_url">{{ __('lf.LF_course_cohort_session_meeting_url') }}</label>
                    <div class="ui-cohort-session-link">
                        <input id="meeting_url" type="url" name="meeting_url" x-model.trim="meetingUrl" placeholder="{{ __('lf.LF_course_cohort_session_meeting_url_placeholder') }}">
                        <button type="button" class="ux-row-edit cursor-interaction" x-show="meetingUrl" x-on:click="copyCurriculumMeetingLink()"
                                x-bind:aria-label="meetingLinkCopied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_session_copy_meeting_url'))"
                                x-bind:data-tooltip="meetingLinkCopied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_session_copy_meeting_url'))">
                            <span x-show="!meetingLinkCopied"><x-backend-icon name="copy" class="ui-icon" /></span>
                            <span x-show="meetingLinkCopied" x-cloak><x-backend-icon name="check" class="ui-icon" /></span>
                        </button>
                    </div>
                    <p class="ui-caption" x-show="sessionType === 'curriculum'">{{ __('lf.LF_course_cohort_session_curriculum_meeting_help') }}</p>
                    <p class="ui-caption" x-show="sessionType === 'operational'" x-cloak>{{ __('lf.LF_course_cohort_session_operational_meeting_help') }}</p>
                    @error('meeting_url')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field" x-show="(mode === 'online' || mode === 'hybrid') && sessionType === 'operational'" x-cloak>
                    <label class="ui-field__label" for="online_provider">{{ __('lf.LF_course_cohort_session_provider') }}</label>
                    <input id="online_provider" name="online_provider" maxlength="50" x-model="provider" placeholder="{{ __('lf.LF_course_cohort_session_provider_placeholder') }}">
                    @error('online_provider')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field" x-show="mode === 'offline' || mode === 'hybrid'" x-cloak>
                    <label class="ui-field__label" for="room_name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_room') }}</label>
                    <input id="room_name" name="room_name" maxlength="255" x-model="roomName" x-bind:required="mode === 'offline' || mode === 'hybrid'" placeholder="{{ __('lf.LF_course_cohort_session_room_placeholder') }}">
                    @error('room_name')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field" x-show="mode === 'offline' || mode === 'hybrid'" x-cloak>
                    <label class="ui-field__label" for="address"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_address') }}</label>
                    <input id="address" name="address" maxlength="2000" x-model="address" x-bind:required="mode === 'offline' || mode === 'hybrid'" placeholder="{{ __('lf.LF_course_cohort_session_address_placeholder') }}">
                    @error('address')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="closeForm()" x-bind:disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                <x-backend-icon name="check" class="ui-icon" />
                <span x-text="editingId ? @js(__('lf.LF_course_cohort_session_save_changes')) : @js(__('lf.LF_course_cohort_session_manual_open'))"></span>
            </button>
        </footer>
    </form>
</dialog>
