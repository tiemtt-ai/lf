{{-- Session detail popup, and the status changes of that session (only those its current status allows). --}}
<dialog x-ref="detailDialog" class="ui-modal ui-cohort-session-modal" data-columns="2" aria-labelledby="session_detail_title"
        x-on:close="detailSession = null; detailActions = []; detailBoardUrl = null">
    <div class="ui-modal__form">
        <header class="ui-modal__header">
            <div>
                <h3 id="session_detail_title" x-text="detailSession?.title"></h3>
                <p class="ui-caption" x-text="`${typeLabels[detailSession?.session_type] || '—'} · ${modeLabels[detailSession?.delivery_mode] || '—'}`"></p>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="closeDetail()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <dl class="ui-summary ui-enroll-summary ui-cohort-detail-summary">
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_common_status') }}</dt>
                    <dd><span class="ui-status" x-bind:class="`ui-status--${statusTones[detailSession?.status] || 'archived'}`" x-text="statusLabels[detailSession?.status] || '—'"></span></dd>
                </div>
                <div class="ui-summary__item"><dt>{{ __('lf.LF_course_cohort_session_source') }}</dt><dd x-text="relationLabels[detailSession?.schedule_relation] || '—'"></dd></div>
                <div class="ui-summary__item"><dt>{{ __('lf.LF_course_cohort_session_start') }}</dt><dd x-text="formatSessionDateTime(detailSession?.scheduled_start_at)"></dd></div>
                <div class="ui-summary__item"><dt>{{ __('lf.LF_course_cohort_session_end') }}</dt><dd x-text="formatSessionDateTime(detailSession?.scheduled_end_at)"></dd></div>
                <div class="ui-summary__item" x-show="detailSession?.actual_start_at"><dt>{{ __('lf.LF_course_cohort_session_actual_start') }}</dt><dd x-text="formatSessionDateTime(detailSession?.actual_start_at)"></dd></div>
                <div class="ui-summary__item" x-show="detailSession?.actual_end_at"><dt>{{ __('lf.LF_course_cohort_session_actual_end') }}</dt><dd x-text="formatSessionDateTime(detailSession?.actual_end_at)"></dd></div>
                <div class="ui-summary__item"><dt>{{ __('lf.LF_course_cohort_session_timezone') }}</dt><dd x-text="detailSession?.timezone || '—'"></dd></div>
                <div class="ui-summary__item"><dt>{{ __('lf.LF_course_cohort_session_version') }}</dt><dd>{{ $cohort->version_code }}</dd></div>
                <div class="ui-summary__item"><dt>{{ __('lf.LF_course_cohort_session_lesson') }}</dt><dd x-text="detailSession?.lesson_title || @js(__('lf.LF_course_cohort_session_outside_content'))"></dd></div>
                <div class="ui-summary__item"><dt>{{ __('lf.LF_course_cohort_session_activity') }}</dt><dd x-text="detailSession?.activity_title || '—'"></dd></div>
                <div class="ui-summary__item"><dt>{{ __('lf.LF_course_cohort_session_teachers') }}</dt><dd x-text="(detailSession?.teacher_team || []).map(teacher => teacher.name).join(', ') || detailSession?.primary_teacher_name || @js(__('lf.LF_course_cohort_session_batch_no_teacher'))"></dd></div>
                <div class="ui-summary__item ui-summary__item--wide" x-show="detailTeacherContacts().length">
                    <dt>{{ __('lf.LF_course_cohort_session_teacher_contact') }}</dt>
                    <dd><ul class="ui-cohort-session-contacts" x-show="detailTeacherContacts().length" x-cloak>
                        <template x-for="teacher in detailTeacherContacts()" :key="teacher.id">
                            <li>
                                <span x-text="teacher.name"></span>
                                <a x-show="teacher.phone" x-bind:href="`tel:${teacher.phone}`" x-text="teacher.phone"></a>
                                <a x-show="teacher.email" x-bind:href="`mailto:${teacher.email}`" x-text="teacher.email"></a>
                            </li>
                        </template>
                    </ul></dd>
                </div>
                <div class="ui-summary__item" x-show="detailSession?.delivery_mode === 'online' || detailSession?.delivery_mode === 'hybrid'">
                    <dt>{{ __('lf.LF_course_cohort_session_provider') }}</dt>
                    <dd x-text="detailSession?.online_provider || meetingProvider(detailSession?.meeting_url_snapshot || detailSession?.activity_meeting_url) || @js(__('lf.LF_course_cohort_session_meeting_not_available'))"></dd>
                </div>
                {{-- An in-class session has no meeting; the class's default link only serves online and hybrid sessions. --}}
                <div class="ui-summary__item" data-session-meeting-link x-show="detailSession?.delivery_mode !== 'offline' && (detailSession?.meeting_url_snapshot || detailSession?.activity_meeting_url)">
                    <dt>{{ __('lf.LF_course_cohort_session_meeting_url') }}</dt>
                    <dd><a class="ui-cohort-version-link" x-bind:href="detailSession?.meeting_url_snapshot || detailSession?.activity_meeting_url" target="_blank" rel="noopener noreferrer"><x-backend-icon name="external-link" class="ui-icon" />{{ __('lf.LF_course_cohort_session_join') }}</a></dd>
                </div>
                <div class="ui-summary__item" x-show="detailSession?.room_name_snapshot"><dt>{{ __('lf.LF_course_cohort_session_room') }}</dt><dd x-text="detailSession?.room_name_snapshot"></dd></div>
                <div class="ui-summary__item" x-show="detailSession?.address_snapshot"><dt>{{ __('lf.LF_course_cohort_session_address') }}</dt><dd x-text="detailSession?.address_snapshot"></dd></div>
                <div class="ui-summary__item ui-summary__item--wide" x-show="detailSession?.cancellation_reason"><dt>{{ __('lf.LF_course_cohort_session_status_reason') }}</dt><dd x-text="detailSession?.cancellation_reason"></dd></div>
            </dl>
        </div>
        <footer class="ui-modal__footer">
            <div class="ui-cohort-session-footer-start">
                <template x-for="action in detailActions.filter(item => item.danger)" :key="action.key">
                    <button type="button" class="ui-button ui-button--danger-outline cursor-interaction" x-on:click="openLifecycle(action)" x-text="action.label"></button>
                </template>
            </div>
            <button type="button" class="ui-button cursor-interaction" x-on:click="closeDetail()">{{ __('lf.LF_common_button_close') }}</button>
            {{-- Whiteboard of a live Session, in its own window (ADR-0002 § LiveClass Whiteboard Amendment). --}}
            <a class="ui-button ui-button--outline cursor-interaction" x-show="detailBoardUrl" x-cloak x-bind:href="detailBoardUrl"
               x-bind:target="'lf-whiteboard-' + (detailSession?.id ?? '')"
               x-on:click.prevent="const win = window.open(detailBoardUrl, 'lf-whiteboard-' + detailSession.id, 'popup,width=1280,height=800'); if (win) win.focus()">
                <x-backend-icon name="presentation" class="ui-icon" />{{ __('lf.LF_liveclass_whiteboard_open') }}
            </a>
            <template x-for="action in detailActions.filter(item => !item.danger)" :key="action.key">
                <button type="button" class="ui-button ui-button--primary cursor-interaction" x-on:click="openLifecycle(action)" x-text="action.label"></button>
            </template>
        </footer>
    </div>
</dialog>

<dialog x-ref="lifecycleDialog" class="ui-modal" data-columns="1" aria-labelledby="session_lifecycle_title" aria-describedby="session_lifecycle_body"
        x-on:cancel="if (lifecycleSubmitting) $event.preventDefault()">
    <form method="POST" class="ui-modal__form" x-bind:action="lifecycle?.url || ''"
          x-on:submit="if (lifecycleSubmitting) { $event.preventDefault(); return } lifecycleSubmitting = true">
        @csrf
        <header class="ui-modal__header">
            <div><h3 id="session_lifecycle_title" x-text="lifecycle?.title"></h3></div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="closeLifecycle()" x-bind:disabled="lifecycleSubmitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <p id="session_lifecycle_body" class="ui-enroll-dialog-text" x-text="lifecycle?.body"></p>
            <div class="ui-field ui-cohort-session-reason" x-show="lifecycle?.danger">
                <label class="ui-field__label" for="session_lifecycle_reason">{{ __('lf.LF_course_cohort_session_status_reason') }}</label>
                <textarea id="session_lifecycle_reason" name="reason" rows="2" maxlength="1000" x-model="lifecycleReason" x-bind:disabled="!lifecycle?.danger"></textarea>
            </div>
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="closeLifecycle()" x-bind:disabled="lifecycleSubmitting">{{ __('lf.LF_course_cohort_lifecycle_cancel') }}</button>
            <button type="submit" class="ui-button cursor-interaction" x-bind:class="lifecycle?.danger ? 'ui-button--danger-outline' : 'ui-button--primary'" x-bind:disabled="lifecycleSubmitting" x-bind:aria-busy="lifecycleSubmitting">
                <span x-show="!lifecycle?.danger"><x-backend-icon name="check" class="ui-icon" /></span>
                <span x-text="lifecycleSubmitting ? @js(__('lf.LF_course_cohort_lifecycle_processing')) : lifecycle?.label"></span>
            </button>
        </footer>
    </form>
</dialog>
