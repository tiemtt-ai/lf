@php
    $cohortScheduleStart = \Illuminate\Support\Carbon::parse($cohort->start_date)->startOfDay();
    $todayScheduleStart = now()->startOfDay();
    $minimumSessionStart = $todayScheduleStart->greaterThan($cohortScheduleStart) ? $todayScheduleStart : $cohortScheduleStart;
    $maximumSessionEnd = \Illuminate\Support\Carbon::parse($cohort->end_date)->endOfDay();
@endphp
<div class="admin-card admin-form-card admin-form-surface course-cohort-sessions"
     x-data="{
         formOpen: @js($errors->any()), editingId: null, submitting: false, detailSession: null, meetingLinkCopied: false,
         rescheduleSession: null, rescheduleStart: '', rescheduleEnd: '',
         sessionType: @js(old('session_type', 'curriculum')),
         lessonId: @js((string) old('version_lesson_id', '')),
         activityId: @js((string) old('version_activity_id', '')),
         title: @js(old('title', '')), titleDirty: @js(old('title') !== null),
         mode: @js(old('delivery_mode', 'online')),
         teacherIds: @js(array_map('strval', old('teacher_ids', []))),
         startsAt: @js(old('scheduled_start_at', '')), endsAt: @js(old('scheduled_end_at', '')),
         scheduleMin: @js($minimumSessionStart->format('Y-m-d\TH:i')),
         scheduleMax: @js($maximumSessionEnd->format('Y-m-d\TH:i')),
         provider: @js(old('online_provider', '')), meetingUrl: @js(old('meeting_url', '')),
         roomName: @js(old('room_name', '')), address: @js(old('address', '')),
         lessons: @js($versionLessons), activities: @js($versionActivities),
         teachers: @js($availableTeachers->map(fn ($teacher) => [
             'id' => (string) $teacher->id,
             'name' => $teacher->name,
             'role' => $teacher->role,
             'role_label' => $teacher->role === 'primary_teacher'
                 ? __('lf.LF_course_cohort_session_teacher_role_cohort_primary')
                 : __('lf.LF_course_cohort_teacher_role_'.$teacher->role),
             'assigned_from' => $teacher->assigned_from,
             'assigned_to' => $teacher->assigned_to,
         ])->values()),
         sessions: @js($sessions),
         storeUrl: @js(route('admin.course-cohorts.sessions.store', $cohort->id)),
         updateUrl: @js(route('admin.course-cohorts.sessions.update', [$cohort->id, '__SESSION__'])),
         rescheduleUrl: @js(route('admin.course-cohorts.sessions.schedule', [$cohort->id, '__SESSION__'])),
         availableActivities() {
             return this.activities.filter(item => String(item.version_lesson_id) === String(this.lessonId))
         },
         selectedActivity() {
             return this.activities.find(item => String(item.id) === String(this.activityId)) || null
         },
         selectableTeachers() {
             return this.teachers.filter(teacher => {
                 if (!this.startsAt || !this.endsAt) return false
                 const startsOn = this.startsAt.slice(0, 10)
                 const endsOn = this.endsAt.slice(0, 10)
                 return (!teacher.assigned_from || startsOn >= teacher.assigned_from)
                     && (!teacher.assigned_to || endsOn <= teacher.assigned_to)
             })
         },
         syncTeacherAvailability() {
             const eligibleIds = this.selectableTeachers().map(teacher => String(teacher.id))
             this.teacherIds = this.teacherIds.filter(id => eligibleIds.includes(String(id)))
         },
         sessionStartChanged() {
             this.endsAt = ''
             this.syncTeacherAvailability()
         },
         curriculumMeetingUrl() {
             return this.selectedActivity()?.live_class_url_snapshot || ''
         },
         meetingProvider(url) {
             if (!url) return ''
             try {
                 const host = new URL(url).hostname.toLocaleLowerCase()
                 if (host.includes('zoom.')) return 'Zoom'
                 if (host === 'meet.google.com') return 'Google Meet'
                 if (host.includes('teams.microsoft.com') || host.includes('teams.live.com')) return 'Microsoft Teams'
                 return host.replace(/^www\./, '')
             } catch (_) { return '' }
         },
         copyCurriculumMeetingLink() {
             const url = this.curriculumMeetingUrl()
             if (!url) return
             navigator.clipboard.writeText(url).then(() => {
                 this.meetingLinkCopied = true
                 setTimeout(() => this.meetingLinkCopied = false, 1600)
             })
         },
         resetForm() {
             this.editingId = null; this.sessionType = 'curriculum'; this.lessonId = ''; this.activityId = ''
             this.title = ''; this.titleDirty = false; this.mode = 'online'; this.teacherIds = []
             this.startsAt = ''; this.endsAt = ''; this.provider = ''; this.meetingUrl = ''
             this.roomName = ''; this.address = ''; this.submitting = false; this.meetingLinkCopied = false
         },
         openCreate() {
             this.resetForm(); this.formOpen = true
             this.$nextTick(() => this.$refs.sessionTypeCurriculum?.focus())
         },
         editSession(item) {
             this.editingId = item.id; this.formOpen = true; this.sessionType = item.session_type
             this.lessonId = item.version_lesson_id ? String(item.version_lesson_id) : ''
             this.activityId = item.version_activity_id ? String(item.version_activity_id) : ''
             this.title = item.title; this.titleDirty = true; this.mode = item.delivery_mode
             this.teacherIds = (item.teacher_ids || []).map(String)
             this.startsAt = item.scheduled_start_at.slice(0, 16).replace(' ', 'T')
             this.endsAt = item.scheduled_end_at.slice(0, 16).replace(' ', 'T')
             this.provider = item.online_provider || ''; this.meetingUrl = item.meeting_url_snapshot || ''
             this.roomName = item.room_name_snapshot || ''; this.address = item.address_snapshot || ''
             this.$nextTick(() => { this.$refs.sessionForm.scrollIntoView({ behavior: 'smooth', block: 'start' }); this.$refs.sessionTitle.focus() })
         },
         switchType(type) {
             this.sessionType = type
             if (type === 'operational') { this.lessonId = ''; this.activityId = ''; if (!this.titleDirty) this.title = '' }
         },
         lessonChanged() {
             this.activityId = ''
             const lesson = this.lessons.find(item => String(item.id) === String(this.lessonId))
             if (!this.titleDirty) this.title = lesson?.title_snapshot || ''
         },
         activityChanged() {
             this.meetingLinkCopied = false
         },
         cancelForm() { this.formOpen = false; this.resetForm() },
         openDetail(item) {
             this.detailSession = item
             this.$nextTick(() => this.$refs.sessionDetailClose?.focus())
         },
         closeDetail() { this.detailSession = null },
         openReschedule(item) {
             this.rescheduleSession = item
             this.rescheduleStart = item.scheduled_start_at.slice(0, 16).replace(' ', 'T')
             this.rescheduleEnd = item.scheduled_end_at.slice(0, 16).replace(' ', 'T')
             this.$nextTick(() => this.$refs.rescheduleStart?.focus())
         },
         rescheduleStartChanged() {
             this.rescheduleEnd = ''
         },
         closeReschedule() { this.rescheduleSession = null },
         submitForm(event) {
             if (this.submitting) { event.preventDefault(); return }
             this.submitting = true
         }
     }"
     x-init="$nextTick(() => document.querySelector('.course-cohort-session-error-summary a')?.focus())">
    <div class="admin-form-standard">
        <section class="admin-form-standard-section">
            <header class="admin-form-section-header course-cohort-sessions__header">
                <div>
                    <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_session_title') }}</h2>
                    <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_session_help') }}</p>
                </div>
                <div class="course-cohort-sessions__toolbar">
                    <span class="course-cohort-sessions__count">{{ __('lf.LF_course_cohort_session_count', ['count' => $sessions->count()]) }}</span>
                    @if (in_array($cohort->status, ['draft', 'active'], true))
                        <button type="button" class="btn btn-primary"
                                x-show="!formOpen" x-on:click="openCreate()">
                            {{ __('lf.LF_course_cohort_session_create') }}
                        </button>
                    @endif
                </div>
            </header>

            @if ($errors->any())
                <div class="admin-alert admin-alert-danger course-cohort-session-error-summary" role="alert">
                    <a class="admin-text-action" href="#session_title">{{ $errors->first() }}</a>
                </div>
            @endif

            @if (in_array($cohort->status, ['draft', 'active'], true))
                <form x-show="formOpen" x-cloak x-ref="sessionForm" method="POST"
                      action="{{ route('admin.course-cohorts.sessions.store', $cohort->id) }}"
                      x-bind:action="editingId ? updateUrl.replace('__SESSION__', editingId) : storeUrl"
                      class="course-cohort-session-form" x-on:submit="submitForm($event)">
                    @csrf
                    <input type="hidden" name="_method" value="PUT" x-bind:disabled="!editingId">

                    <fieldset class="course-cohort-session-type">
                        <legend class="lf-form-label">{{ __('lf.LF_course_cohort_session_type') }} <span aria-hidden="true">*</span></legend>
                        <div class="course-cohort-session-type__options">
                            <label class="admin-form-option-panel admin-form-option-panel--compact">
                                <input x-ref="sessionTypeCurriculum" type="radio" name="session_type" value="curriculum"
                                       x-bind:checked="sessionType === 'curriculum'" x-on:change="switchType('curriculum')">
                                <span><strong>{{ __('lf.LF_course_cohort_session_type_curriculum') }}</strong><small>{{ __('lf.LF_course_cohort_session_curriculum_help') }}</small></span>
                            </label>
                            <label class="admin-form-option-panel admin-form-option-panel--compact">
                                <input type="radio" name="session_type" value="operational"
                                       x-bind:checked="sessionType === 'operational'" x-on:change="switchType('operational')">
                                <span><strong>{{ __('lf.LF_course_cohort_session_type_operational') }}</strong><small>{{ __('lf.LF_course_cohort_session_operational_help') }}</small></span>
                            </label>
                        </div>
                    </fieldset>

                    <div class="course-cohort-session-form__grid" x-show="sessionType === 'curriculum'">
                        <div class="lf-form-group">
                            <x-form-label for="version_lesson_id" :value="__('lf.LF_course_cohort_session_lesson')" :required="true" />
                            <select id="version_lesson_id" name="version_lesson_id" class="lf-form-control"
                                    x-model="lessonId" x-on:change="lessonChanged()" x-bind:required="sessionType === 'curriculum'">
                                <option value="">{{ __('lf.LF_course_cohort_session_select_lesson') }}</option>
                                @foreach ($versionLessons as $lesson)<option value="{{ $lesson->id }}">{{ $lesson->title_snapshot }}</option>@endforeach
                            </select>
                            @error('version_lesson_id')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="version_activity_id" :value="__('lf.LF_course_cohort_session_activity')" :required="true" />
                            <select id="version_activity_id" name="version_activity_id" class="lf-form-control"
                                    x-model="activityId" x-on:change="activityChanged()" x-bind:required="sessionType === 'curriculum'" x-bind:disabled="!lessonId">
                                <option value="">{{ __('lf.LF_course_cohort_session_select_activity') }}</option>
                                <template x-for="activity in availableActivities()" :key="activity.id">
                                    <option :value="activity.id" x-text="activity.title_snapshot"></option>
                                </template>
                            </select>
                            <p class="lf-form-help course-cohort-session-form__binding-help" x-show="lessonId && availableActivities().length === 0" x-cloak>{{ __('lf.LF_course_cohort_session_no_live_activity') }}</p>
                            @error('version_activity_id')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <p class="admin-form-inline-notice" x-show="sessionType === 'operational'" x-cloak>
                        <span class="admin-form-inline-notice-icon" aria-hidden="true">i</span>
                        <span>{{ __('lf.LF_course_cohort_session_operational_help') }}</span>
                    </p>

                    <div class="course-cohort-session-form__grid">
                        <div class="lf-form-group">
                            <x-form-label for="session_title" :value="__('lf.LF_course_cohort_session_name')" :required="true" />
                            <input x-ref="sessionTitle" id="session_title" name="title" class="lf-form-control"
                                   x-model="title" x-on:input="titleDirty = true" required maxlength="255">
                            <p class="lf-form-help">{{ __('lf.LF_course_cohort_session_title_help') }}</p>
                            @error('title')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="delivery_mode" :value="__('lf.LF_course_cohort_session_mode')" :required="true" />
                            <select id="delivery_mode" name="delivery_mode" class="lf-form-control" x-model="mode" required>
                                @foreach (['online', 'offline', 'hybrid'] as $mode)<option value="{{ $mode }}">{{ __('lf.LF_course_cohort_session_mode_'.$mode) }}</option>@endforeach
                            </select>
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="scheduled_start_at" :value="__('lf.LF_course_cohort_session_start')" :required="true" />
                            <input id="scheduled_start_at" type="datetime-local" name="scheduled_start_at" class="lf-form-control"
                                   x-model="startsAt" x-on:change="sessionStartChanged()" x-bind:min="scheduleMin" x-bind:max="scheduleMax"
                                   x-bind:class="{ 'has-value': startsAt }" required>
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="scheduled_end_at" :value="__('lf.LF_course_cohort_session_end')" :required="true" />
                            <input id="scheduled_end_at" type="datetime-local" name="scheduled_end_at" class="lf-form-control"
                                   x-model="endsAt" x-on:change="syncTeacherAvailability()" x-bind:min="startsAt || scheduleMin" x-bind:max="scheduleMax"
                                   x-bind:class="{ 'has-value': endsAt }" required>
                        </div>
                        <div class="lf-form-group admin-form-field--full course-cohort-session-additional-teachers">
                            <span class="lf-form-label">{{ __('lf.LF_course_cohort_session_teachers') }}</span>
                            <div class="course-cohort-session-teacher-options" x-show="selectableTeachers().length > 0" x-cloak>
                                <template x-for="teacher in selectableTeachers()" :key="teacher.id">
                                    <label class="course-cohort-session-teacher-option">
                                        <input type="checkbox" name="teacher_ids[]" :value="teacher.id" x-model="teacherIds">
                                        <span><strong x-text="teacher.name"></strong><small x-text="teacher.role_label"></small></span>
                                    </label>
                                </template>
                            </div>
                            <p class="lf-form-help" x-show="!startsAt || !endsAt" x-cloak>{{ __('lf.LF_course_cohort_session_teachers_time_help') }}</p>
                            <p class="lf-form-help" x-show="startsAt && endsAt && selectableTeachers().length === 0" x-cloak>{{ __('lf.LF_course_cohort_session_teachers_empty') }}</p>
                            @error('teacher_ids')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                            @error('teacher_ids.*')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="lf-form-group" x-show="(mode === 'online' || mode === 'hybrid') && sessionType === 'curriculum'" x-cloak>
                            <x-form-label for="curriculum_online_provider" :value="__('lf.LF_course_cohort_session_provider')" />
                            <input id="curriculum_online_provider" class="lf-form-control" readonly
                                   x-bind:value="meetingProvider(curriculumMeetingUrl()) || @js(__('lf.LF_course_cohort_session_meeting_not_available'))">
                        </div>
                        <div class="lf-form-group" x-show="(mode === 'online' || mode === 'hybrid') && sessionType === 'curriculum'" x-cloak>
                            <x-form-label for="curriculum_meeting_url" :value="__('lf.LF_course_cohort_session_meeting_url')" />
                            <div class="course-cohort-session-copy-control">
                                <input id="curriculum_meeting_url" type="url" class="lf-form-control" readonly
                                       x-bind:value="curriculumMeetingUrl()" placeholder="{{ __('lf.LF_course_cohort_session_meeting_not_available') }}">
                                <button type="button" class="admin-copy-action course-cohort-session-copy-control__button"
                                        x-show="curriculumMeetingUrl()" x-on:click="copyCurriculumMeetingLink()"
                                        x-bind:class="{ 'is-copied': meetingLinkCopied }"
                                        x-bind:title="meetingLinkCopied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_session_copy_meeting_url'))"
                                        x-bind:aria-label="meetingLinkCopied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_session_copy_meeting_url'))">
                                    <span x-show="!meetingLinkCopied"><x-backend-icon name="copy" /></span>
                                    <span x-show="meetingLinkCopied" x-cloak><x-backend-icon name="check" /></span>
                                </button>
                            </div>
                            <p class="lf-form-help">{{ __('lf.LF_course_cohort_session_curriculum_meeting_help') }}</p>
                        </div>
                        <div class="lf-form-group" x-show="(mode === 'online' || mode === 'hybrid') && sessionType === 'operational'" x-cloak>
                            <x-form-label for="online_provider" :value="__('lf.LF_course_cohort_session_provider')" />
                            <input id="online_provider" name="online_provider" class="lf-form-control" x-model="provider">
                        </div>
                        <div class="lf-form-group" x-show="(mode === 'online' || mode === 'hybrid') && sessionType === 'operational'" x-cloak>
                            <x-form-label for="meeting_url" :value="__('lf.LF_course_cohort_session_meeting_url')" />
                            <input id="meeting_url" type="url" name="meeting_url" class="lf-form-control" x-model="meetingUrl">
                            <p class="lf-form-help">{{ __('lf.LF_course_cohort_session_operational_meeting_help') }}</p>
                        </div>
                        <div class="lf-form-group" x-show="mode === 'offline' || mode === 'hybrid'" x-cloak>
                            <x-form-label for="room_name" :value="__('lf.LF_course_cohort_session_room')" :required="true" />
                            <input id="room_name" name="room_name" class="lf-form-control" x-model="roomName" x-bind:required="mode === 'offline' || mode === 'hybrid'">
                        </div>
                        <div class="lf-form-group" x-show="mode === 'offline' || mode === 'hybrid'" x-cloak>
                            <x-form-label for="address" :value="__('lf.LF_course_cohort_session_address')" :required="true" />
                            <input id="address" name="address" class="lf-form-control" x-model="address" x-bind:required="mode === 'offline' || mode === 'hybrid'">
                        </div>
                    </div>

                    <footer class="admin-form-footer admin-form-footer--sticky course-cohort-session-form__footer">
                        <strong class="course-cohort-session-form__footer-context" x-text="editingId ? @js(__('lf.LF_course_cohort_session_edit')) : @js(__('lf.LF_course_cohort_session_create'))"></strong>
                        <div class="admin-form-footer-primary">
                            <button type="button" class="btn btn-secondary" x-on:click="cancelForm()">{{ __('lf.LF_common_button_cancel') }}</button>
                            <button type="submit" class="btn btn-primary" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                                <span x-show="!submitting" x-text="editingId ? @js(__('lf.LF_course_cohort_session_save_changes')) : @js(__('lf.LF_course_cohort_session_save'))"></span>
                                <span x-show="submitting" x-cloak>{{ __('lf.LF_course_cohort_student_saving') }}</span>
                            </button>
                        </div>
                    </footer>
                </form>
            @endif
        </section>

        <section class="admin-form-standard-section">
            <div class="admin-table-wrap course-cohort-session-table-wrap">
                <table class="table course-cohort-session-table">
                    <colgroup>
                        <col class="course-cohort-session-table__col-sequence">
                        <col class="course-cohort-session-table__col-name">
                        <col class="course-cohort-session-table__col-content">
                        <col class="course-cohort-session-table__col-schedule">
                        <col class="course-cohort-session-table__col-teacher">
                        <col class="course-cohort-session-table__col-status">
                        <col class="course-cohort-session-table__col-actions">
                    </colgroup>
                    <thead><tr>
                        <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                        <th>{{ __('lf.LF_course_cohort_session_name') }}</th>
                        <th>{{ __('lf.LF_course_cohort_session_content') }}</th>
                        <th>{{ __('lf.LF_course_cohort_session_schedule') }}</th>
                        <th>{{ __('lf.LF_course_cohort_session_teachers') }}</th>
                        <th class="course-cohort-session-table__status">{{ __('lf.LF_course_cohort_common_status') }}</th>
                        <th class="course-cohort-session-table__actions">{{ __('lf.table_actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($sessions as $session)
                        <tr>
                            <td class="admin-table-sequence" data-label="{{ __('lf.table_no') }}">{{ $session->session_no }}</td>
                            <td data-label="{{ __('lf.LF_course_cohort_session_name') }}">
                                <strong class="course-cohort-session-table__primary">{{ $session->title }}</strong>
                                <span class="course-cohort-session-table__meta">{{ __('lf.LF_course_cohort_session_type_'.$session->session_type) }} · {{ __('lf.LF_course_cohort_session_mode_'.$session->delivery_mode) }}</span>
                            </td>
                            <td data-label="{{ __('lf.LF_course_cohort_session_content') }}">
                                @if($session->session_type === 'curriculum')
                                    <strong class="course-cohort-session-table__primary">{{ $session->lesson_title }}</strong>
                                    <span class="course-cohort-session-table__meta">{{ $session->activity_title }}</span>
                                @else
                                    <span class="badge course-cohort-session-type-badge">{{ __('lf.LF_course_cohort_session_type_operational') }}</span>
                                    <span class="course-cohort-session-table__meta">{{ __('lf.LF_course_cohort_session_outside_content') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('lf.LF_course_cohort_session_schedule') }}">
                                <dl class="course-cohort-session-table__schedule">
                                    <div><dt>{{ __('lf.LF_course_cohort_session_table_start') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($session->scheduled_start_at)->format('d/m/Y H:i') }}</dd></div>
                                    <div><dt>{{ __('lf.LF_course_cohort_session_table_end') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($session->scheduled_end_at)->format('d/m/Y H:i') }}</dd></div>
                                </dl>
                            </td>
                            <td data-label="{{ __('lf.LF_course_cohort_session_teachers') }}">
                                @if($session->teacher_team->isNotEmpty())
                                    <strong class="course-cohort-session-table__primary">{{ $session->teacher_team->pluck('name')->join(', ') }}</strong>
                                @else
                                    <strong class="course-cohort-session-table__primary">{{ $session->primary_teacher_name ?: '—' }}</strong>
                                @endif
                            </td>
                            <td class="course-cohort-session-table__status" data-label="{{ __('lf.LF_course_cohort_common_status') }}">
                                <span @class([
                                    'badge',
                                    'course-cohort-session-status-badge',
                                    'course-cohort-session-status-badge--'.$session->status,
                                ])>{{ __('lf.LF_course_cohort_session_status_'.$session->status) }}</span>
                            </td>
                            <td class="course-cohort-session-table__actions" data-label="{{ __('lf.table_actions') }}">
                                <div class="course-cohort-session-action-menu" x-data="{ actionsOpen: false }"
                                     x-on:click.outside="actionsOpen = false" x-on:keydown.escape.stop="actionsOpen = false">
                                    <button type="button" class="admin-link-button course-cohort-session-action-menu__trigger"
                                            x-on:click="actionsOpen = !actionsOpen"
                                            x-bind:class="{ 'is-open': actionsOpen }"
                                            x-bind:aria-expanded="actionsOpen.toString()"
                                            aria-haspopup="menu">
                                        <span>{{ __('lf.table_actions') }}</span>
                                        <span class="course-cohort-session-action-menu__chevron" aria-hidden="true"></span>
                                    </button>
                                    <div class="course-cohort-session-action-menu__panel" role="menu"
                                         x-show="actionsOpen" x-cloak x-transition.opacity.duration.120ms>
                                        <button type="button" role="menuitem" class="admin-link-button admin-text-action admin-table-action-link"
                                                x-on:click="actionsOpen = false; openDetail(@js($session))">
                                            <svg class="course-cohort-session-action-menu__icon" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
                                                <circle cx="12" cy="12" r="2.5" />
                                            </svg>
                                            {{ __('lf.LF_course_cohort_session_details') }}
                                        </button>
                                        @if ($session->can_edit)
                                            <button type="button" role="menuitem" class="admin-link-button admin-text-action admin-table-action-link"
                                                    x-on:click="actionsOpen = false; editSession(@js($session))">
                                                <svg class="course-cohort-session-action-menu__icon" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="m4 16.5-.8 4.3 4.3-.8L19 8.5 15.5 5 4 16.5Z" />
                                                    <path d="m13.8 6.7 3.5 3.5" />
                                                </svg>
                                                {{ __('lf.LF_course_cohort_session_edit') }}
                                            </button>
                                        @endif
                                        @if ($session->status === 'scheduled' && in_array($cohort->status, ['draft', 'active'], true))
                                            <button type="button" role="menuitem" class="admin-link-button admin-text-action admin-table-action-link"
                                                    x-on:click="actionsOpen = false; openReschedule(@js($session))">
                                                <svg class="course-cohort-session-action-menu__icon" viewBox="0 0 24 24" aria-hidden="true">
                                                    <rect x="3" y="5" width="18" height="16" rx="2" />
                                                    <path d="M8 3v4M16 3v4M3 10h18M8 15h8M13 12l3 3-3 3" />
                                                </svg>
                                                {{ __('lf.LF_course_cohort_session_change_schedule') }}
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr class="course-cohort-session-empty-row"><td class="course-cohort-session-empty-cell" colspan="7"><div class="course-cohort-empty-state">{{ __('lf.LF_course_cohort_session_empty') }}</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div x-cloak x-show="detailSession" class="admin-modal-backdrop"
         x-on:click.self="closeDetail()" x-on:keydown.escape.window="closeDetail()">
        <section class="admin-modal course-cohort-session-modal" role="dialog" aria-modal="true"
                 aria-labelledby="course-cohort-session-detail-title">
            <header class="admin-modal-header">
                <div>
                    <span class="course-cohort-session-modal__eyebrow">{{ __('lf.LF_course_cohort_session_details') }}</span>
                    <h2 id="course-cohort-session-detail-title" x-text="detailSession?.title"></h2>
                </div>
                <button x-ref="sessionDetailClose" type="button"
                        class="course-enrollment-lifecycle-modal__close" x-on:click="closeDetail()"
                        aria-label="{{ __('lf.LF_common_button_close') }}"><span aria-hidden="true">×</span></button>
            </header>
            <div class="course-cohort-session-modal__body">
                <dl class="course-cohort-session-modal__grid">
                    <div><dt>{{ __('lf.LF_course_cohort_session_version') }}</dt><dd>{{ $cohort->version_code }}</dd></div>
                    <div><dt>{{ __('lf.LF_course_cohort_session_lesson') }}</dt><dd x-text="detailSession?.lesson_title || @js(__('lf.LF_course_cohort_session_outside_content'))"></dd></div>
                    <div><dt>{{ __('lf.LF_course_cohort_session_activity') }}</dt><dd x-text="detailSession?.activity_title || '—'"></dd></div>
                    <div><dt>{{ __('lf.LF_course_cohort_session_teachers') }}</dt><dd x-text="(detailSession?.teacher_team || []).map(teacher => teacher.name).join(', ') || detailSession?.primary_teacher_name || '—'"></dd></div>
                </dl>
            </div>
            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <button type="button" class="btn btn-secondary" x-on:click="closeDetail()">{{ __('lf.LF_common_button_close') }}</button>
                </div>
            </footer>
        </section>
    </div>

    <div x-cloak x-show="rescheduleSession" class="admin-modal-backdrop"
         x-on:click.self="closeReschedule()" x-on:keydown.escape.window="closeReschedule()">
        <form method="POST"
              x-bind:action="rescheduleSession ? rescheduleUrl.replace('__SESSION__', rescheduleSession.id) : ''"
              class="admin-modal course-cohort-session-modal course-cohort-session-reschedule-modal"
              role="dialog" aria-modal="true" aria-labelledby="course-cohort-session-reschedule-title">
            @csrf @method('PUT')
            <header class="admin-modal-header">
                <div>
                    <span class="course-cohort-session-modal__eyebrow">{{ __('lf.LF_course_cohort_session_change_schedule') }}</span>
                    <h2 id="course-cohort-session-reschedule-title" x-text="rescheduleSession?.title"></h2>
                </div>
                <button type="button" class="course-enrollment-lifecycle-modal__close"
                        x-on:click="closeReschedule()" aria-label="{{ __('lf.LF_common_button_close') }}">
                    <span aria-hidden="true">×</span>
                </button>
            </header>
            <div class="course-cohort-session-modal__body course-cohort-session-reschedule-modal__body">
                <div class="lf-form-group">
                    <x-form-label for="reschedule_start_at" :value="__('lf.LF_course_cohort_session_start')" :required="true" />
                    <input x-ref="rescheduleStart" id="reschedule_start_at" type="datetime-local"
                           name="scheduled_start_at" class="lf-form-control" x-model="rescheduleStart"
                           x-on:change="rescheduleStartChanged()" x-bind:min="scheduleMin" x-bind:max="scheduleMax" required>
                </div>
                <div class="lf-form-group">
                    <x-form-label for="reschedule_end_at" :value="__('lf.LF_course_cohort_session_end')" :required="true" />
                    <input id="reschedule_end_at" type="datetime-local" name="scheduled_end_at"
                           class="lf-form-control" x-model="rescheduleEnd"
                           x-bind:min="rescheduleStart || scheduleMin" x-bind:max="scheduleMax" required>
                </div>
                <div class="lf-form-group admin-form-field--full">
                    <x-form-label for="reschedule_reason" :value="__('lf.LF_course_cohort_session_change_reason')" />
                    <textarea id="reschedule_reason" name="reason" class="lf-form-control" rows="3" maxlength="1000"></textarea>
                </div>
            </div>
            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <button type="button" class="btn btn-secondary" x-on:click="closeReschedule()">{{ __('lf.LF_common_button_cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_course_cohort_session_save_schedule') }}</button>
                </div>
            </footer>
        </form>
    </div>
</div>
