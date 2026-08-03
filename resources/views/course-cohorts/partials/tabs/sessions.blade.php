<div class="admin-card admin-form-card admin-form-surface course-cohort-sessions"
     x-data="{
         formOpen: @js($errors->any()), editingId: null, submitting: false,
         sessionType: @js(old('session_type', 'curriculum')),
         lessonId: @js((string) old('version_lesson_id', '')),
         activityId: @js((string) old('version_activity_id', '')),
         title: @js(old('title', '')), titleDirty: @js(old('title') !== null),
         mode: @js(old('delivery_mode', 'online')),
         teacherId: @js((string) old('primary_teacher_id', '')),
         startsAt: @js(old('scheduled_start_at', '')), endsAt: @js(old('scheduled_end_at', '')),
         provider: @js(old('online_provider', '')), meetingUrl: @js(old('meeting_url', '')),
         roomName: @js(old('room_name', '')), address: @js(old('address', '')),
         lessons: @js($versionLessons), activities: @js($versionActivities),
         sessions: @js($sessions),
         storeUrl: @js(route('admin.course-cohorts.sessions.store', $cohort->id)),
         updateUrl: @js(route('admin.course-cohorts.sessions.update', [$cohort->id, '__SESSION__'])),
         availableActivities() {
             return this.activities.filter(item => String(item.version_lesson_id) === String(this.lessonId))
         },
         resetForm() {
             this.editingId = null; this.sessionType = 'curriculum'; this.lessonId = ''; this.activityId = ''
             this.title = ''; this.titleDirty = false; this.mode = 'online'; this.teacherId = ''
             this.startsAt = ''; this.endsAt = ''; this.provider = ''; this.meetingUrl = ''
             this.roomName = ''; this.address = ''; this.submitting = false
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
             this.teacherId = item.primary_teacher_id ? String(item.primary_teacher_id) : ''
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
             const matches = this.availableActivities()
             if (matches.length === 1) this.activityId = String(matches[0].id)
             if (!this.titleDirty) {
                 this.title = lesson
                     ? (matches.length === 1 ? `${lesson.title_snapshot} – ${matches[0].title_snapshot}` : lesson.title_snapshot)
                     : ''
             }
         },
         activityChanged() {
             if (this.titleDirty) return
             const lesson = this.lessons.find(item => String(item.id) === String(this.lessonId))
             const activity = this.activities.find(item => String(item.id) === String(this.activityId))
             this.title = lesson && activity ? `${lesson.title_snapshot} – ${activity.title_snapshot}` : ''
         },
         cancelForm() { this.formOpen = false; this.resetForm() },
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
                        <button type="button" class="btn admin-primary-outline-action"
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
                        <div class="lf-form-group admin-form-field--full">
                            <x-form-label for="session_title" :value="__('lf.LF_course_cohort_session_name')" :required="true" />
                            <input x-ref="sessionTitle" id="session_title" name="title" class="lf-form-control"
                                   x-model="title" x-on:input="titleDirty = true" required maxlength="255">
                            <p class="lf-form-help">{{ __('lf.LF_course_cohort_session_title_help') }}</p>
                            @error('title')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="scheduled_start_at" :value="__('lf.LF_course_cohort_session_start')" :required="true" />
                            <input id="scheduled_start_at" type="datetime-local" name="scheduled_start_at" class="lf-form-control" x-model="startsAt" required>
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="scheduled_end_at" :value="__('lf.LF_course_cohort_session_end')" :required="true" />
                            <input id="scheduled_end_at" type="datetime-local" name="scheduled_end_at" class="lf-form-control" x-model="endsAt" required>
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="delivery_mode" :value="__('lf.LF_course_cohort_session_mode')" :required="true" />
                            <select id="delivery_mode" name="delivery_mode" class="lf-form-control" x-model="mode" required>
                                @foreach (['online', 'offline', 'hybrid'] as $mode)<option value="{{ $mode }}">{{ __('lf.LF_course_cohort_session_mode_'.$mode) }}</option>@endforeach
                            </select>
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="primary_teacher_id" :value="__('lf.LF_course_cohort_session_primary_teacher')" />
                            <select id="primary_teacher_id" name="primary_teacher_id" class="lf-form-control" x-model="teacherId">
                                <option value="">{{ __('lf.LF_course_cohort_session_select_teacher') }}</option>
                                @foreach ($availableTeachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="lf-form-group" x-show="mode === 'online' || mode === 'hybrid'" x-cloak>
                            <x-form-label for="online_provider" :value="__('lf.LF_course_cohort_session_provider')" :required="true" />
                            <input id="online_provider" name="online_provider" class="lf-form-control" x-model="provider" x-bind:required="mode === 'online' || mode === 'hybrid'">
                        </div>
                        <div class="lf-form-group" x-show="mode === 'online' || mode === 'hybrid'" x-cloak>
                            <x-form-label for="meeting_url" :value="__('lf.LF_course_cohort_session_meeting_url')" :required="true" />
                            <input id="meeting_url" type="url" name="meeting_url" class="lf-form-control" x-model="meetingUrl" x-bind:required="mode === 'online' || mode === 'hybrid'">
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

                    <footer class="admin-form-footer admin-form-footer--sticky" data-actions-align="end">
                        <span class="admin-form-section-help" x-text="editingId ? @js(__('lf.LF_course_cohort_session_edit')) : @js(__('lf.LF_course_cohort_session_create'))"></span>
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
                        <th class="admin-table-sequence">#</th>
                        <th>{{ __('lf.LF_course_cohort_session_name') }}</th>
                        <th>{{ __('lf.LF_course_cohort_session_content') }}</th>
                        <th>{{ __('lf.LF_course_cohort_session_schedule') }}</th>
                        <th>{{ __('lf.LF_course_cohort_session_primary_teacher') }}</th>
                        <th>{{ __('lf.LF_course_cohort_common_status') }}</th>
                        <th class="course-cohort-session-table__actions">{{ __('lf.table_actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($sessions as $session)
                        <tr>
                            <td class="admin-table-sequence" data-label="#">{{ $session->session_no }}</td>
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
                                <span class="course-cohort-session-table__schedule">{{ \Illuminate\Support\Carbon::parse($session->scheduled_start_at)->format('d/m/Y H:i') }}<br>{{ \Illuminate\Support\Carbon::parse($session->scheduled_end_at)->format('d/m/Y H:i') }}</span>
                            </td>
                            <td data-label="{{ __('lf.LF_course_cohort_session_primary_teacher') }}">{{ $session->primary_teacher_name ?: '—' }}</td>
                            <td data-label="{{ __('lf.LF_course_cohort_common_status') }}">
                                <span @class([
                                    'badge',
                                    'course-cohort-session-status-badge',
                                    'course-cohort-session-status-badge--'.$session->status,
                                ])>{{ __('lf.LF_course_cohort_session_status_'.$session->status) }}</span>
                            </td>
                            <td class="course-cohort-session-table__actions" data-label="{{ __('lf.table_actions') }}">
                                <div class="admin-table-actions course-cohort-session-action-list">
                                    <details>
                                        <summary class="admin-text-action admin-table-action-link">{{ __('lf.LF_course_cohort_session_details') }}</summary>
                                        <div class="course-cohort-session-detail-popover">
                                            <strong>{{ __('lf.LF_course_cohort_session_version') }}</strong><span>{{ $cohort->version_code }}</span>
                                            <strong>{{ __('lf.LF_course_cohort_session_lesson') }}</strong><span>{{ $session->lesson_title ?: __('lf.LF_course_cohort_session_outside_content') }}</span>
                                            <strong>{{ __('lf.LF_course_cohort_session_activity') }}</strong><span>{{ $session->activity_title ?: '—' }}</span>
                                        </div>
                                    </details>
                                    @if ($session->can_edit)
                                        <button type="button" class="admin-link-button admin-text-action admin-table-action-link" x-on:click="editSession(@js($session))">{{ __('lf.LF_course_cohort_session_edit') }}</button>
                                    @endif
                                    @if ($session->status === 'scheduled' && in_array($cohort->status, ['draft', 'active'], true))
                                        <details>
                                            <summary class="admin-text-action admin-table-action-link">{{ __('lf.LF_course_cohort_session_change_schedule') }}</summary>
                                            <form method="POST" action="{{ route('admin.course-cohorts.sessions.schedule', [$cohort->id, $session->id]) }}" class="course-cohort-session-detail-popover course-cohort-session-reschedule-form">
                                                @csrf @method('PUT')
                                                <label class="lf-form-label">{{ __('lf.LF_course_cohort_session_start') }}</label>
                                                <input type="datetime-local" name="scheduled_start_at" class="lf-form-control" value="{{ \Illuminate\Support\Carbon::parse($session->scheduled_start_at)->format('Y-m-d\TH:i') }}" required>
                                                <label class="lf-form-label">{{ __('lf.LF_course_cohort_session_end') }}</label>
                                                <input type="datetime-local" name="scheduled_end_at" class="lf-form-control" value="{{ \Illuminate\Support\Carbon::parse($session->scheduled_end_at)->format('Y-m-d\TH:i') }}" required>
                                                <label class="lf-form-label">{{ __('lf.LF_course_cohort_session_change_reason') }}</label>
                                                <textarea name="reason" class="lf-form-control" rows="2" maxlength="1000"></textarea>
                                                <button type="submit" class="btn btn-primary">{{ __('lf.LF_course_cohort_session_save_schedule') }}</button>
                                            </form>
                                        </details>
                                    @endif
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
</div>
