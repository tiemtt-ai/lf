{{-- Create sessions from projected schedule occurrences: pick the rows, configure each, confirm one atomic batch. --}}
<dialog x-ref="batchDialog" class="ui-modal ui-cohort-session-batch-modal" data-columns="3" aria-labelledby="session_batch_title"
        x-on:cancel="if (batchSubmitting) $event.preventDefault()">
    <form id="session-batch-form" method="POST" action="{{ route('admin.course-cohorts.sessions.batch.store', $cohort->id) }}"
          class="ui-modal__form" x-on:submit="submitBatch($event)" novalidate>
        @csrf
        <header class="ui-modal__header">
            <div>
                <h3 id="session_batch_title">{{ __('lf.LF_course_cohort_session_batch_title') }}</h3>
                <p class="ui-caption">
                    {{ __('lf.LF_course_cohort_session_batch_help') }}
                    @if ($commonBatchTimezone) {{ __('lf.LF_course_cohort_session_batch_timezone', ['timezone' => $commonBatchTimezone]) }}. @endif
                </p>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="closeBatch()" x-bind:disabled="batchSubmitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            @error('occurrences')<div class="ui-field__error ui-cohort-manage-error" role="alert">{{ $message }}</div>@enderror
            <div class="ui-cohort-session-batch__toolbar">
                <label class="ui-check">
                    <input type="checkbox"
                           x-bind:checked="selectableBatchCount() > 0 && selectedBatchCount() === selectableBatchCount()"
                           x-effect="$el.indeterminate = selectedBatchCount() > 0 && selectedBatchCount() < selectableBatchCount()"
                           x-on:change="toggleAllBatch($event)">
                    <span>{{ __('lf.LF_course_cohort_session_batch_select_all') }}</span>
                </label>
                <span class="ui-caption ui-enroll-nowrap" x-text="`${selectedBatchCount()}/${selectableBatchCount()} ${@js(__('lf.LF_course_cohort_session_batch_selected_count'))}`"></span>
            </div>

            <div class="ui-cohort-session-batch">
                @foreach ($plannedOccurrences as $index => $occurrence)
                    @php($occurrenceDate = \Illuminate\Support\Carbon::parse($occurrence['date']))
                    <article class="ui-cohort-session-batch__row"
                             x-bind:class="{ 'is-selected': batchRows[{{ $index }}].selected, 'has-error': batchRows[{{ $index }}].hasError, 'is-disabled': batchRows[{{ $index }}].consumed }">
                        <input type="hidden" name="occurrences[{{ $index }}][selected]" value="0">
                        <input type="hidden" name="occurrences[{{ $index }}][schedule_id]" value="{{ $occurrence['schedule_id'] }}">
                        <input type="hidden" name="occurrences[{{ $index }}][schedule_slot_id]" value="{{ $occurrence['schedule_slot_id'] }}">
                        <input type="hidden" name="occurrences[{{ $index }}][source_local_date]" value="{{ $occurrence['date'] }}">
                        <input type="hidden" name="occurrences[{{ $index }}][title]" x-model="batchRows[{{ $index }}].title" x-bind:disabled="!batchRows[{{ $index }}].selected">
                        <input type="hidden" name="occurrences[{{ $index }}][version_lesson_id]" x-model="batchRows[{{ $index }}].lessonId" x-bind:disabled="!batchRows[{{ $index }}].selected">
                        <input type="hidden" name="occurrences[{{ $index }}][version_activity_id]" x-model="batchRows[{{ $index }}].activityId" x-bind:disabled="!batchRows[{{ $index }}].selected">
                        <input type="hidden" name="occurrences[{{ $index }}][delivery_mode]" x-model="batchRows[{{ $index }}].mode" x-bind:disabled="!batchRows[{{ $index }}].selected">
                        <input type="hidden" name="occurrences[{{ $index }}][meeting_url]" x-model="batchRows[{{ $index }}].meetingUrl" x-bind:disabled="!batchRows[{{ $index }}].selected || !['online', 'hybrid'].includes(batchRows[{{ $index }}].mode)">
                        <template x-for="teacherId in batchRows[{{ $index }}].teacherIds" :key="teacherId">
                            <input type="hidden" name="occurrences[{{ $index }}][teacher_ids][]" x-bind:value="teacherId" x-bind:disabled="!batchRows[{{ $index }}].selected">
                        </template>

                        <div class="ui-cohort-session-batch__head">
                            <label class="ui-cohort-session-batch__check" for="batch_occurrence_{{ $index }}">
                                <input id="batch_occurrence_{{ $index }}" type="checkbox" name="occurrences[{{ $index }}][selected]" value="1"
                                       x-bind:checked="batchRows[{{ $index }}].selected"
                                       x-bind:disabled="batchRows[{{ $index }}].consumed"
                                       x-on:change="toggleBatchRow(batchRows[{{ $index }}], $event.target.checked)">
                                <span class="ui-sr-only">{{ __('lf.LF_course_cohort_session_batch_select_occurrence', ['date' => $occurrenceDate->format('d/m/Y'), 'time' => $occurrence['start_time'].'–'.$occurrence['end_time']]) }}</span>
                            </label>
                            <button id="batch_occurrence_trigger_{{ $index }}" type="button" class="ui-cohort-session-batch__trigger cursor-interaction"
                                    x-bind:aria-expanded="batchRows[{{ $index }}].expanded.toString()"
                                    x-bind:disabled="batchRows[{{ $index }}].consumed"
                                    aria-controls="batch_occurrence_panel_{{ $index }}"
                                    x-on:click="batchHeaderClicked(batchRows[{{ $index }}])">
                                <span class="ui-cohort-session-batch__when">
                                    <span class="mk-name-text ui-enroll-nowrap">{{ $occurrenceDate->format('d/m/Y') }} · {{ $occurrence['start_time'] }}–{{ $occurrence['end_time'] }}</span>
                                    <span class="ui-caption">{{ \Illuminate\Support\Str::ucfirst($occurrenceDate->translatedFormat('l')) }}</span>
                                </span>
                                <span class="ui-cohort-session-batch__meta">
                                    <span class="ui-caption">{{ $occurrence['schedule_name'] }}@if (! $commonBatchTimezone) · {{ $occurrence['timezone'] }}@endif</span>
                                    <span class="ui-caption" x-show="batchRowSummary(batchRows[{{ $index }}])" x-text="batchRowSummary(batchRows[{{ $index }}])"></span>
                                    <span class="ui-caption ui-cohort-session-overdue" x-show="batchRowHint(batchRows[{{ $index }}])" x-text="batchRowHint(batchRows[{{ $index }}])"></span>
                                </span>
                                <span class="ui-status" x-bind:class="batchRowTone(batchRows[{{ $index }}])" x-text="batchRowState(batchRows[{{ $index }}])"></span>
                                <span class="ui-cohort-session-batch__chevron" x-show="!batchRows[{{ $index }}].consumed" aria-hidden="true"></span>
                            </button>
                        </div>

                        <div id="batch_occurrence_panel_{{ $index }}" class="ui-cohort-session-batch__panel" role="region" aria-labelledby="batch_occurrence_trigger_{{ $index }}"
                             x-show="!batchRows[{{ $index }}].consumed && batchRows[{{ $index }}].expanded" x-cloak>
                            <div class="ui-modal__grid">
                                <div class="ui-field">
                                    <label class="ui-field__label" for="batch_lesson_{{ $index }}"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_lesson') }}</label>
                                    <select id="batch_lesson_{{ $index }}" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_enrollment_index_no_options') }}" x-model="batchRows[{{ $index }}].lessonId" x-on:change="batchLessonChanged(batchRows[{{ $index }}], $el)"
                                            @error("occurrences.$index.version_lesson_id") aria-invalid="true" aria-describedby="batch_lesson_error_{{ $index }}" @enderror>
                                        <option value="">{{ __('lf.LF_course_cohort_session_select_lesson') }}</option>
                                        @foreach ($versionLessons as $lesson)<option value="{{ $lesson->id }}">{{ $lesson->title_snapshot }}</option>@endforeach
                                    </select>
                                    @error("occurrences.$index.version_lesson_id")<p id="batch_lesson_error_{{ $index }}" class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                                </div>
                                <div class="ui-field">
                                    <label class="ui-field__label" for="batch_activity_{{ $index }}"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_activity') }}</label>
                                    <select id="batch_activity_{{ $index }}" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_cohort_session_activity_empty') }}" x-model="batchRows[{{ $index }}].activityId" x-on:change="batchActivityChanged(batchRows[{{ $index }}])"
                                            @error("occurrences.$index.version_activity_id") aria-invalid="true" aria-describedby="batch_activity_error_{{ $index }}" @enderror>
                                        <option value="">{{ __('lf.LF_course_cohort_session_select_activity') }}</option>
                                        @foreach ($versionActivities as $activity)
                                            <option value="{{ $activity->id }}" x-bind:disabled="String({{ $activity->version_lesson_id }}) !== String(batchRows[{{ $index }}].lessonId)">{{ $activity->title_snapshot }}</option>
                                        @endforeach
                                    </select>
                                    @error("occurrences.$index.version_activity_id")<p id="batch_activity_error_{{ $index }}" class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                                </div>
                                <div class="ui-field">
                                    <label class="ui-field__label" for="batch_mode_{{ $index }}"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_mode') }}</label>
                                    <select id="batch_mode_{{ $index }}" data-ui-kit-select x-model="batchRows[{{ $index }}].mode"
                                            @error("occurrences.$index.delivery_mode") aria-invalid="true" aria-describedby="batch_mode_error_{{ $index }}" @enderror>
                                        <option value="">{{ __('lf.LF_course_cohort_session_select_mode') }}</option>
                                        @foreach (['online', 'offline', 'hybrid'] as $deliveryMode)<option value="{{ $deliveryMode }}">{{ __('lf.LF_course_cohort_session_mode_'.$deliveryMode) }}</option>@endforeach
                                    </select>
                                    @error("occurrences.$index.delivery_mode")<p id="batch_mode_error_{{ $index }}" class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                                </div>
                                <div class="ui-field" x-bind:class="{ 'ui-field--full': !['online', 'hybrid'].includes(batchRows[{{ $index }}].mode) }">
                                    <label class="ui-field__label" for="batch_teachers_{{ $index }}">{{ __('lf.LF_course_cohort_session_teachers') }}</label>
                                    <select id="batch_teachers_{{ $index }}" multiple data-ui-kit-select
                                            data-ui-placeholder="{{ __('lf.LF_course_cohort_session_batch_no_teacher') }}"
                                            data-ui-empty-label="{{ __('lf.LF_course_cohort_session_teachers_empty') }}"
                                            x-model="batchRows[{{ $index }}].teacherIds">
                                        @foreach ($availableTeachers as $teacher)
                                            <option value="{{ $teacher->id }}" x-bind:disabled="!batchTeacherSelectable(batchRows[{{ $index }}], {{ $teacher->id }})">{{ $teacher->name }} · {{ $teacherRoleLabel($teacher) }}</option>
                                        @endforeach
                                    </select>
                                    @error("occurrences.$index.teacher_ids")<p class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                                </div>
                                <div class="ui-field ui-cohort-session-batch__link" x-show="['online', 'hybrid'].includes(batchRows[{{ $index }}].mode)" x-cloak>
                                    <label class="ui-field__label" for="batch_meeting_url_{{ $index }}">{{ __('lf.LF_course_cohort_session_meeting_url') }}</label>
                                    <div class="ui-cohort-session-link">
                                        <input id="batch_meeting_url_{{ $index }}" type="url" x-model.trim="batchRows[{{ $index }}].meetingUrl"
                                               @error("occurrences.$index.meeting_url") aria-invalid="true" @enderror
                                               placeholder="{{ __('lf.LF_course_cohort_session_meeting_url_placeholder') }}">
                                        <button type="button" class="ux-row-edit cursor-interaction" x-show="batchMeetingUrl(batchRows[{{ $index }}])"
                                                x-on:click="copyBatchMeetingLink(batchRows[{{ $index }}])"
                                                x-bind:aria-label="batchRows[{{ $index }}].meetingCopied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_session_copy_meeting_url'))"
                                                x-bind:data-tooltip="batchRows[{{ $index }}].meetingCopied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_session_copy_meeting_url'))">
                                            <span x-show="!batchRows[{{ $index }}].meetingCopied"><x-backend-icon name="copy" class="ui-icon" /></span>
                                            <span x-show="batchRows[{{ $index }}].meetingCopied" x-cloak><x-backend-icon name="check" class="ui-icon" /></span>
                                        </button>
                                    </div>
                                    <p class="ui-caption">{{ __('lf.LF_course_cohort_session_curriculum_meeting_help') }}</p>
                                    @error("occurrences.$index.meeting_url")<p class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                                </div>
                            </div>
                            @error("occurrences.$index.scheduled_start_at")<p class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                            @error("occurrences.$index.source_local_date")<p class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                            <button type="button" class="ui-button ui-button--sm cursor-interaction ui-cohort-session-batch__reset" x-show="batchRowHasDraft(batchRows[{{ $index }}])" x-cloak
                                    x-on:click="resetBatchRow(batchRows[{{ $index }}], $event.currentTarget)"
                                    aria-label="{{ __('lf.LF_course_cohort_session_batch_reset_occurrence', ['date' => $occurrenceDate->format('d/m/Y')]) }}">{{ __('lf.LF_course_cohort_session_batch_reset_configuration') }}</button>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="ui-modal__grid ui-cohort-session-batch__location" x-show="batchNeedsLocation()" x-cloak>
                <p class="ui-caption ui-field--full">{{ __('lf.LF_course_cohort_session_batch_location_help') }}</p>
                <div class="ui-field">
                    <label class="ui-field__label" for="batch_room_name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_room') }}</label>
                    <input id="batch_room_name" name="room_name" maxlength="255" x-model="batchRoom" placeholder="{{ __('lf.LF_course_cohort_session_room_placeholder') }}">
                </div>
                <div class="ui-field">
                    <label class="ui-field__label" for="batch_address"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_session_address') }}</label>
                    <input id="batch_address" name="address" maxlength="2000" x-model="batchAddress" placeholder="{{ __('lf.LF_course_cohort_session_address_placeholder') }}">
                </div>
            </div>
            <p x-ref="batchMessage" class="ui-field__error ui-cohort-session-batch__message" role="alert" tabindex="-1" x-show="batchMessage" x-text="batchMessage" x-cloak></p>
        </div>
        <footer class="ui-modal__footer">
            <span class="ui-caption"><span x-text="readyBatchCount()"></span> {{ __('lf.LF_course_cohort_session_batch_ready') }}</span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="closeBatch()" x-bind:disabled="batchSubmitting">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="batchSubmitting" x-bind:aria-busy="batchSubmitting">
                <x-backend-icon name="check" class="ui-icon" />
                <span x-text="`${@js(__('lf.LF_course_cohort_session_batch_create'))} (${selectedBatchCount()})`"></span>
            </button>
        </footer>
    </form>
</dialog>
