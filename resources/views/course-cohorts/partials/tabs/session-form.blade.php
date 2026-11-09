{{-- Create / edit one session. A changed date and time is saved as a reschedule: the old interval stays in the audit and the schedule origin is untouched. --}}
<dialog x-ref="sessionDialog" class="ui-modal ui-cohort-session-modal" data-columns="2" aria-labelledby="session_form_title"
        x-on:close="sessionDialogClosed()" x-on:cancel="if (submitting) $event.preventDefault()">
    <form method="POST" class="ui-modal__form course-cohort-session-form"
          action="{{ route('admin.course-cohorts.sessions.store', $cohort->id) }}"
          x-bind:action="editingId ? updateUrl.replace('__SESSION__', editingId) : storeUrl"
          x-on:submit="submitForm($event)">
        @csrf
        <input type="hidden" name="_method" value="PUT" x-bind:disabled="!editingId">
        <input type="hidden" name="form_context" value="session">
        <input type="hidden" name="opened_scope" x-bind:value="formScope">
        <input type="hidden" name="editing_session_id" x-bind:value="editingId || ''">
        <input type="hidden" name="opened_version_activity_id" x-bind:value="openedActivityId">
        <input type="hidden" name="apply_schedule" x-bind:value="scheduleMoved() ? '1' : '0'">
        <input type="hidden" name="original_start_at" x-bind:value="originalStart">
        <input type="hidden" name="original_end_at" x-bind:value="originalEnd">
        <input type="hidden" name="schedule_id" x-bind:value="scheduleOrigin?.schedule_id || ''" x-bind:disabled="!scheduleOrigin || editingId">
        <input type="hidden" name="schedule_slot_id" x-bind:value="scheduleOrigin?.schedule_slot_id || ''" x-bind:disabled="!scheduleOrigin || editingId">
        <input type="hidden" name="source_local_date" x-bind:value="scheduleOrigin?.source_local_date || ''" x-bind:disabled="!scheduleOrigin || editingId">
        <header class="ui-modal__header">
            <div>
                <h3 id="session_form_title" x-text="lessonScope() ? @js(__('lf.LF_course_cohort_session_edit_lesson')) : (programmeForm() ? @js(__('lf.LF_course_cohort_session_edit_activity')) : (editingId ? @js(__('lf.LF_course_cohort_session_edit')) : @js(__('lf.LF_course_cohort_session_manual_title'))))"></h3>
                <p class="ui-caption" x-show="activityScope() && programmeContent()" x-cloak>{{ __('lf.LF_course_template_lesson_dialog_location') }}: <span x-text="programmeContent()?.lesson"></span></p>
                <p class="ui-caption" x-show="activityScope()" x-cloak>{{ __('lf.LF_course_cohort_session_programme_form_help') }}</p>
                <p class="ui-caption" x-show="lessonScope()" x-cloak>{{ __('lf.LF_course_cohort_session_lesson_form_help') }}</p>
                <p class="ui-caption" x-show="editingId && !programmeForm()" x-cloak>{{ __('lf.LF_course_cohort_session_edit_scope_help') }}</p>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="closeForm()" x-bind:disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            @if ($formSummaryErrors->isNotEmpty())
                <div class="ui-field__error ui-cohort-manage-error" role="alert" x-show="showFormErrors">{{ $formSummaryErrors->first() }}</div>
            @endif
            <div class="ui-modal__grid">
                {{-- Opened on a lesson row: the fields of the Template's lesson form. The session's place and meeting link are edited below. --}}
                <template x-if="lessonScope()">
                    <dl class="ui-summary ui-field--full ui-cohort-session-template">
                        <template x-for="row in (programmeLessonRows[lessonId] || [])" :key="row.label">
                            <div class="ui-summary__item" x-bind:class="{ 'ui-summary__item--wide': row.wide }">
                                <dt class="ui-field__label" x-text="row.label"></dt>
                                <dd x-text="row.value"></dd>
                            </div>
                        </template>
                    </dl>
                </template>
                {{-- Opened on an activity row: only the fields of the Template's activity form, in its order: read only on the class, except the session's own link. The session's date, teachers and place are edited on the lesson row and posted here unchanged. --}}
                <template x-if="activityScope() && programmeContent()">
                    <dl class="ui-summary ui-field--full ui-cohort-session-template">
                        <div class="ui-summary__item">
                            <dt class="ui-field__label">{{ __('lf.LF_course_template_activity_common_type') }}</dt>
                            <dd>{{ __('lf.LF_course_template_activity_common_type_live_class') }}</dd>
                        </div>
                        <div class="ui-summary__item">
                            <dt class="ui-field__label">{{ __('lf.LF_course_template_activity_common_name') }}</dt>
                            <dd x-text="programmeContent().title"></dd>
                        </div>
                        <div class="ui-summary__item ui-summary__item--wide" x-show="mode === 'online' || mode === 'hybrid'">
                            <dt class="ui-field__label">{{ __('lf.LF_course_cohort_session_meeting_url') }}</dt>
                            <dd>
                                <div class="ui-cohort-session-template__link" x-show="!meetingEditing">
                                    <a x-show="meetingUrl" x-bind:href="meetingUrl" target="_blank" rel="noopener noreferrer" x-text="meetingUrl"></a>
                                    <span x-show="!meetingUrl" class="ui-caption">{{ __('lf.LF_course_cohort_session_no_meeting_url') }}</span>
                                    <button type="button" class="ux-row-edit cursor-interaction"
                                            aria-label="{{ __('lf.LF_course_cohort_session_meeting_url_edit') }}" data-tooltip="{{ __('lf.LF_course_cohort_session_meeting_url_edit') }}"
                                            x-on:click="openMeetingEdit()">
                                        <x-backend-icon name="square-pen" class="ui-icon" />
                                    </button>
                                </div>
                                {{-- Always posted: a hidden input until opened, so an untouched link keeps its value. Left empty, the session falls back to the Template's link. --}}
                                <div class="ui-cohort-session-template__link">
                                    <input x-ref="programmeMeetingUrl" name="meeting_url" x-bind:type="meetingEditing ? 'url' : 'hidden'" x-model.trim="meetingUrl"
                                           x-bind:disabled="mode === 'offline'" maxlength="2000"
                                           aria-label="{{ __('lf.LF_course_cohort_session_meeting_url') }}" placeholder="{{ __('lf.LF_course_cohort_session_meeting_url_placeholder') }}">
                                    {{-- Closing drops what was typed: the link stays as it was when the field opened. --}}
                                    <button type="button" class="ux-row-edit cursor-interaction" x-show="meetingEditing" x-cloak
                                            aria-label="{{ __('lf.LF_common_button_close') }}" data-tooltip="{{ __('lf.LF_common_button_close') }}"
                                            x-on:click="closeMeetingEdit()">
                                        <x-backend-icon name="x" class="ui-icon" />
                                    </button>
                                </div>
                                <p class="ui-caption" x-show="meetingEditing">{{ __('lf.LF_course_cohort_session_meeting_url_override_help') }}</p>
                                @error('meeting_url')<p class="ui-field__error" role="alert" x-show="showFormErrors && meetingEditing">{{ $message }}</p>@enderror
                            </dd>
                        </div>
                        <template x-for="row in programmeContent().rows" :key="row.label">
                            <div class="ui-summary__item" x-bind:class="{ 'ui-summary__item--wide': row.wide }">
                                <dt class="ui-field__label" x-text="row.label"></dt>
                                <dd x-text="row.value"></dd>
                            </div>
                        </template>
                    </dl>
                </template>

                <fieldset class="ui-field ui-field--full" x-show="!programmeForm()">
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

                <div class="ui-field" x-show="sessionType === 'curriculum' && !programmeForm()">
                    <label class="ui-field__label" for="version_lesson_id"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_lesson') }}</label>
                    <select id="version_lesson_id" name="version_lesson_id" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_enrollment_index_no_options') }}"
                            x-model="lessonId" x-on:change="lessonChanged()" x-bind:required="sessionType === 'curriculum'">
                        <option value="">{{ __('lf.LF_course_cohort_session_select_lesson') }}</option>
                        @foreach ($versionLessons as $lesson)<option value="{{ $lesson->id }}">{{ $lesson->title_snapshot }}</option>@endforeach
                    </select>
                    @error('version_lesson_id')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field" x-show="sessionType === 'curriculum' && !programmeForm()">
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

                <div class="ui-field" x-show="!programmeForm()">
                    <label class="ui-field__label" for="session_title"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_name') }}</label>
                    <input x-ref="sessionTitle" id="session_title" name="title" x-model="title" x-on:input="titleDirty = true" required maxlength="255"
                           placeholder="{{ __('lf.LF_course_cohort_session_name_placeholder') }}">
                    <p class="ui-caption" x-show="sessionType === 'curriculum'">{{ __('lf.LF_course_cohort_session_title_help') }}</p>
                    @error('title')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                {{-- In class or online; an in-class session with remote learners is stored as hybrid. --}}
                <input type="hidden" name="delivery_mode" x-bind:value="mode">
                <fieldset class="ui-field ui-field--full ui-fieldset-reset" x-show="!activityScope()">
                    <legend class="ui-field__label"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_mode') }}</legend>
                    <div class="ui-option-cards" role="radiogroup">
                        @foreach (['offline', 'online'] as $place)
                            <label class="ui-option-card" x-bind:class="{ 'is-selected': {{ $place === 'online' ? "mode === 'online'" : "mode !== 'online'" }} }">
                                <input type="radio" name="session_place" value="{{ $place }}" x-bind:checked="{{ $place === 'online' ? "mode === 'online'" : "mode !== 'online'" }}"
                                       x-on:change="mode = @js($place)">
                                <span class="ui-option-card__dot" aria-hidden="true"></span>
                                <span><b>{{ __('lf.LF_course_cohort_session_mode_'.$place) }}</b></span>
                            </label>
                        @endforeach
                    </div>
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_session_place_help') }}</p>
                    @error('delivery_mode')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </fieldset>
                <div class="ui-field" x-show="(mode === 'offline' || mode === 'hybrid') && !activityScope()" x-cloak>
                    <label class="ui-field__label" for="room_name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_room') }}</label>
                    <input id="room_name" name="room_name" maxlength="255" x-model="roomName" x-bind:required="(mode === 'offline' || mode === 'hybrid') && !activityScope()" placeholder="{{ __('lf.LF_course_cohort_session_room_placeholder') }}">
                    @error('room_name')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field" x-show="(mode === 'offline' || mode === 'hybrid') && !activityScope()" x-cloak>
                    <label class="ui-field__label" for="address">{{ __('lf.LF_course_cohort_session_address') }}</label>
                    <input id="address" name="address" maxlength="2000" x-model="address" placeholder="{{ __('lf.LF_course_cohort_session_address_placeholder') }}">
                    @error('address')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field ui-field--full" x-show="mode !== 'online' && !activityScope()" x-cloak>
                    <label class="ui-check">
                        <input type="checkbox" x-bind:checked="mode === 'hybrid'" x-on:change="mode = $event.target.checked ? 'hybrid' : 'offline'">
                        <span>{{ __('lf.LF_course_cohort_session_remote') }}</span>
                    </label>
                    <p class="ui-caption" x-show="mode === 'hybrid'" x-cloak>{{ __('lf.LF_course_cohort_session_remote_help') }}</p>
                </div>
                <div class="ui-field" x-bind:class="{ 'ui-field--full': sessionType === 'curriculum' }" x-show="(mode === 'online' || mode === 'hybrid') && !activityScope()">
                    <label class="ui-field__label" for="meeting_url">{{ __('lf.LF_course_cohort_session_meeting_url') }}</label>
                    <div class="ui-cohort-session-link">
                        <input id="meeting_url" type="url" name="meeting_url" x-model.trim="meetingUrl" x-bind:disabled="activityScope()" placeholder="{{ __('lf.LF_course_cohort_session_meeting_url_placeholder') }}">
                        <button type="button" class="ux-row-edit cursor-interaction" x-show="meetingUrl" x-on:click="copyCurriculumMeetingLink()"
                                x-bind:aria-label="meetingLinkCopied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_session_copy_meeting_url'))"
                                x-bind:data-tooltip="meetingLinkCopied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_session_copy_meeting_url'))">
                            <span x-show="!meetingLinkCopied"><x-backend-icon name="copy" class="ui-icon" /></span>
                            <span x-show="meetingLinkCopied" x-cloak><x-backend-icon name="check" class="ui-icon" /></span>
                        </button>
                    </div>
                    <p class="ui-caption" x-show="sessionType === 'curriculum'">{{ __('lf.LF_course_cohort_session_curriculum_meeting_help') }}</p>
                    <p class="ui-caption" x-show="sessionType === 'operational'" x-cloak>{{ __('lf.LF_course_cohort_session_operational_meeting_help') }}</p>
                    <template x-if="!activityScope()"><span>@error('meeting_url')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror</span></template>
                </div>
                <div class="ui-field" x-show="(mode === 'online' || mode === 'hybrid') && sessionType === 'operational'" x-cloak>
                    <label class="ui-field__label" for="online_provider">{{ __('lf.LF_course_cohort_session_provider') }}</label>
                    <input id="online_provider" name="online_provider" maxlength="50" x-model="provider" placeholder="{{ __('lf.LF_course_cohort_session_provider_placeholder') }}">
                    @error('online_provider')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>

                {{-- An activity with no date yet cannot be saved without one: only then its time stays on this popup. --}}
                <div class="ui-field" x-show="!activityScope() || !originalStart">
                    <label class="ui-field__label" for="scheduled_start_at"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_start') }}</label>
                    <x-ui-date-input time id="scheduled_start_at" name="scheduled_start_at" x-ref="sessionStart" x-model="startsAt"
                                     x-on:change="sessionStartChanged()" x-bind:min="scheduleMin" x-bind:max="scheduleMax" />
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_session_batch_timezone', ['timezone' => config('app.timezone')]) }}</p>
                    @error('scheduled_start_at')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field" x-show="!activityScope() || !originalStart">
                    <label class="ui-field__label" for="scheduled_end_at"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_end') }}</label>
                    <x-ui-date-input time id="scheduled_end_at" name="scheduled_end_at" x-ref="sessionEnd" x-model="endsAt"
                                     x-on:change="sessionEndChanged()" x-bind:min="startsAt || scheduleMin" x-bind:max="scheduleMax" />
                    @error('scheduled_end_at')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>
                <div class="ui-field ui-field--full" x-show="!activityScope() && canResetSchedule()" x-cloak>
                    <button type="button" class="ui-button ui-button--sm ui-button--outline ui-cohort-session-reset cursor-interaction" x-on:click="resetSchedule()">
                        <span x-text="@js(__('lf.LF_course_cohort_session_date_reset')) + ' (' + scheduleDefaultLabel() + ')'"></span>
                    </button>
                </div>
                <div class="ui-field ui-field--full" x-show="scheduleMoved()" x-cloak>
                    <label class="ui-field__label" for="session_change_reason">{{ __('lf.LF_course_cohort_session_change_reason') }}</label>
                    <textarea id="session_change_reason" name="reason" rows="2" maxlength="1000" x-model="scheduleReason" x-bind:disabled="!scheduleMoved()"></textarea>
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_session_change_reason_help') }}</p>
                    @error('reason')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>

                <div class="ui-field ui-field--full" x-ref="sessionTeacherField" x-show="!activityScope()">
                    <label class="ui-field__label" for="session_teachers">{{ __('lf.LF_course_cohort_session_teachers') }}</label>
                    <select id="session_teachers" name="teacher_ids[]" multiple data-ui-kit-select
                            data-ui-placeholder="{{ __('lf.LF_course_cohort_session_batch_no_teacher') }}"
                            data-ui-empty-label="{{ __('lf.LF_course_cohort_session_teachers_empty') }}"
                            x-model="teacherIds">
                        @foreach ($availableTeachers as $teacher)
                            <option value="{{ $teacher->id }}" x-bind:disabled="!teacherSelectable({{ $teacher->id }})">{{ $teacher->name }} · {{ $teacherRoleLabel($teacher) }}</option>
                        @endforeach
                    </select>
                    <p class="ui-field__label" x-show="selectedTeacherContacts().length" x-cloak>{{ __('lf.LF_course_cohort_session_teacher_contact') }}</p>
                    <ul class="ui-cohort-session-contacts" x-show="selectedTeacherContacts().length" x-cloak>
                        <template x-for="teacher in selectedTeacherContacts()" :key="teacher.id">
                            <li>
                                <span x-text="teacher.name"></span>
                                <a x-show="teacher.phone" x-bind:href="`tel:${teacher.phone}`" x-text="teacher.phone"></a>
                                <a x-show="teacher.email" x-bind:href="`mailto:${teacher.email}`" x-text="teacher.email"></a>
                            </li>
                        </template>
                    </ul>
                    <p class="ui-caption" x-show="!startsAt || !endsAt" x-cloak>{{ __('lf.LF_course_cohort_session_teachers_time_help') }}</p>
                    <p class="ui-caption" x-show="startsAt && endsAt && selectableTeachers().length === 0" x-cloak>{{ __('lf.LF_course_cohort_session_teachers_empty') }}</p>
                    @error('teacher_ids')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                    @error('teacher_ids.*')<p class="ui-field__error" role="alert" x-show="showFormErrors">{{ $message }}</p>@enderror
                </div>

            </div>
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="closeForm()" x-bind:disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-show="!activityScope() || mode !== 'offline'" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                <x-backend-icon name="check" class="ui-icon" />
                <span x-text="editingId || editingProgramme ? @js(__('lf.LF_course_cohort_session_save_changes')) : @js(__('lf.LF_course_cohort_session_manual_open'))"></span>
            </button>
        </footer>
    </form>
</dialog>
