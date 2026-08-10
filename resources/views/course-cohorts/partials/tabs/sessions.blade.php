@php
    // Khoảng lịch hợp lệ do backend quyết định (CourseCohortSessionWindow) và
    // được dùng chung với validation; view không tự suy ra. NULL nghĩa là lớp
    // legacy chưa đủ thời gian vận hành.
    $batchHasErrors = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'occurrences'));
    $rescheduleHasErrors = old('form_context') === 'reschedule'
        && collect($errors->keys())->contains(fn ($key) => in_array($key, ['scheduled_start_at', 'scheduled_end_at', 'reason'], true));
    $rescheduleOldSession = $rescheduleHasErrors
        ? $sessions->firstWhere('id', (int) old('reschedule_session_id'))
        : null;
    $occurrenceKey = fn (array $item): string => implode('|', [
        $item['schedule_id'] ?? '', $item['schedule_slot_id'] ?? '', $item['source_local_date'] ?? $item['date'] ?? '',
    ]);
    $oldBatchOccurrences = collect(old('occurrences', []))->keyBy($occurrenceKey);
    $uniqueTeacherIds = fn ($ids): array => collect(is_array($ids) ? $ids : [])
        ->map(fn ($id) => (string) $id)
        ->filter(fn ($id) => $id !== '')
        ->uniqueStrict()
        ->values()
        ->all();
    $batchRows = $plannedOccurrences->map(function (array $item, int $index) use ($oldBatchOccurrences, $occurrenceKey, $errors, $uniqueTeacherIds): array {
        $old = $oldBatchOccurrences->get($occurrenceKey($item), []);
        $hasError = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, "occurrences.$index."));
        $selected = ! $item['consumed'] && (bool) ($old['selected'] ?? false);

        return array_merge($item, [
            'selected' => $selected,
            'expanded' => $selected || $hasError,
            'hasError' => $hasError,
            'title' => (string) ($old['title'] ?? ''),
            'lessonId' => (string) ($old['version_lesson_id'] ?? ''),
            'activityId' => (string) ($old['version_activity_id'] ?? ''),
            'teacherIds' => $uniqueTeacherIds($old['teacher_ids'] ?? []),
            'teacherOpen' => false,
            'mode' => (string) ($old['delivery_mode'] ?? ''),
            'meetingCopied' => false,
        ]);
    })->values();
    $batchTimezones = $plannedOccurrences->pluck('timezone')->filter()->unique()->values();
    $commonBatchTimezone = $batchTimezones->count() === 1 ? $batchTimezones->first() : null;
@endphp
<div class="admin-card admin-form-card admin-form-surface course-cohort-sessions"
     x-data="{
         formOpen: @js($errors->any() && ! $batchHasErrors && ! $rescheduleHasErrors), batchOpen: @js($batchHasErrors), editingId: null, editingSession: null, submitting: false, batchMessage: '', detailSession: null, meetingLinkCopied: false,
         rescheduleSession: @js($rescheduleOldSession),
         rescheduleStart: @js($rescheduleHasErrors ? old('scheduled_start_at', '') : ''),
         rescheduleEnd: @js($rescheduleHasErrors ? old('scheduled_end_at', '') : ''),
         rescheduleReason: @js($rescheduleHasErrors ? old('reason', '') : ''),
         rescheduleStartError: @js($rescheduleHasErrors ? $errors->first('scheduled_start_at') : ''),
         sessionType: @js(old('session_type', 'curriculum')),
         lessonId: @js((string) old('version_lesson_id', '')),
         activityId: @js((string) old('version_activity_id', '')),
         title: @js(old('title', '')), titleDirty: @js(old('title') !== null),
         mode: @js(old('delivery_mode', 'online')),
         teacherIds: @js($uniqueTeacherIds(old('teacher_ids', []))), teacherOpen: false,
         startsAt: @js(old('scheduled_start_at', '')), endsAt: @js(old('scheduled_end_at', '')),
         scheduleMin: @js($sessionWindow ? $sessionWindow['min']->format('Y-m-d\TH:i') : ''),
         scheduleMax: @js($sessionWindow ? $sessionWindow['max']->format('Y-m-d\TH:i') : ''),
         provider: @js(old('online_provider', '')), meetingUrl: @js(old('meeting_url', '')),
         roomName: @js(old('room_name', '')), address: @js(old('address', '')),
         statusLabels: @js(collect(['scheduled', 'live', 'completed', 'cancelled', 'no_show'])->mapWithKeys(fn ($status) => [$status => __('lf.LF_course_cohort_session_status_'.$status)])),
         typeLabels: @js(collect(['curriculum', 'operational'])->mapWithKeys(fn ($type) => [$type => __('lf.LF_course_cohort_session_type_'.$type)])),
         modeLabels: @js(collect(['online', 'offline', 'hybrid'])->mapWithKeys(fn ($mode) => [$mode => __('lf.LF_course_cohort_session_mode_'.$mode)])),
         relationLabels: @js(collect(['on_schedule', 'rescheduled', 'off_schedule', 'source_unknown'])->mapWithKeys(fn ($relation) => [$relation => __('lf.LF_course_cohort_session_relation_'.$relation)])),
         lessons: @js($versionLessons), activities: @js($versionActivities),
         occurrences: @js($plannedOccurrences),
         batchRows: @js($batchRows),
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
         selectedTeachers() {
             return this.teachers.filter(teacher => this.teacherIds.includes(String(teacher.id)))
         },
         toggleTeacher(teacherId) {
             const id = String(teacherId)
             const uniqueIds = [...new Set(this.teacherIds.map(String))]
             this.teacherIds = uniqueIds.includes(id) ? uniqueIds.filter(item => item !== id) : [...uniqueIds, id]
         },
         sessionStartChanged() {
             this.endsAt = ''
             this.syncTeacherAvailability()
         },
         batchActivities(row) { return this.activities.filter(item => String(item.version_lesson_id) === String(row.lessonId)) },
         selectedBatchActivity(row) { return this.activities.find(item => String(item.id) === String(row.activityId)) || null },
         batchMeetingUrl(row) { return this.selectedBatchActivity(row)?.live_class_url_snapshot || '' },
         batchMeetingProvider(row) { return this.meetingProvider(this.batchMeetingUrl(row)) },
         copyBatchMeetingLink(row) {
             const url = this.batchMeetingUrl(row)
             if (!url) return
             navigator.clipboard.writeText(url).then(() => {
                 row.meetingCopied = true
                 setTimeout(() => row.meetingCopied = false, 1600)
             })
         },
         batchLessonChanged(row) { row.activityId = ''; const lesson = this.lessons.find(item => String(item.id) === String(row.lessonId)); row.title = lesson?.title_snapshot || '' },
         batchSelectableTeachers(row) {
             return this.teachers.filter(teacher => (!teacher.assigned_from || row.date >= teacher.assigned_from) && (!teacher.assigned_to || row.date <= teacher.assigned_to))
         },
         selectedBatchTeachers(row) { return this.teachers.filter(teacher => row.teacherIds.includes(String(teacher.id))) },
         toggleBatchTeacher(row, teacherId) {
             const id = String(teacherId)
             const uniqueIds = [...new Set(row.teacherIds.map(String))]
             row.teacherIds = uniqueIds.includes(id) ? uniqueIds.filter(item => item !== id) : [...uniqueIds, id]
         },
         async resetBatchRow(row, trigger) {
             if (this.batchRowHasDraft(row)) {
                 const confirmed = await window.LFConfirm.open({
                     message: @js(__('lf.LF_course_cohort_session_batch_reset_confirm')),
                     confirmLabel: @js(__('lf.LF_course_cohort_session_batch_reset')),
                     tone: 'danger',
                     trigger,
                 })
                 if (!confirmed) return
             }
             row.title = ''; row.lessonId = ''; row.activityId = ''; row.teacherIds = []
             row.teacherOpen = false; row.mode = ''; row.hasError = false; row.meetingCopied = false
         },
         activateBatchRow(row, index, focusFirst = false) {
             if (row.consumed) return
             row.selected = true
             row.expanded = true
             this.batchMessage = ''
             if (focusFirst) this.$nextTick(() => document.getElementById(`batch_lesson_${index}`)?.focus())
         },
         beginBatchConfiguration(row, index, event) {
             if (row.selected || row.consumed || event?.target?.closest('[data-batch-passive]')) return
             this.activateBatchRow(row, index)
         },
         toggleBatchRow(row, checked) {
             row.selected = checked
             row.expanded = checked
             row.teacherOpen = false
             this.batchMessage = ''
         },
         toggleAllBatch(event) { this.batchRows.filter(row => !row.consumed).forEach(row => this.toggleBatchRow(row, event.target.checked)) },
         selectedBatchCount() { return this.batchRows.filter(row => row.selected).length },
         selectableBatchCount() { return this.batchRows.filter(row => !row.consumed).length },
         batchRowReady(row) { return Boolean(row.title && row.lessonId && row.activityId && row.mode) },
         batchRowHasDraft(row) { return Boolean(row.title || row.lessonId || row.activityId || row.teacherIds.length || row.mode) },
         readyBatchCount() { return this.batchRows.filter(row => row.selected && this.batchRowReady(row)).length },
         batchRowState(row) {
             if (row.consumed) return @js(__('lf.LF_course_cohort_session_batch_created_state'));
             if (row.hasError) return @js(__('lf.LF_course_cohort_session_batch_error_state'));
             if (!row.selected) return @js(__('lf.LF_course_cohort_session_batch_select_to_configure'));
             return this.batchRowReady(row)
                 ? @js(__('lf.LF_course_cohort_session_batch_configured'))
                 : @js(__('lf.LF_course_cohort_session_batch_incomplete'));
         },
         batchRowSummary(row) {
             if (!this.batchRowReady(row)) return ''
             const lesson = this.lessons.find(item => String(item.id) === String(row.lessonId))
             const teacherCount = new Set(row.teacherIds.map(String)).size
             return [lesson?.title_snapshot, this.modeLabels[row.mode], teacherCount ? `${teacherCount} {{ __('lf.LF_course_cohort_session_batch_teacher_unit') }}` : null].filter(Boolean).join(' · ')
         },
         batchRowHint(row) {
             return row.selected && !this.batchRowReady(row)
                 ? @js(__('lf.LF_course_cohort_session_batch_incomplete_hint'))
                 : ''
         },
         openBatch() { this.formOpen = false; this.batchOpen = true; this.submitting = false; this.$nextTick(() => this.$refs.batchForm?.scrollIntoView({ behavior: 'smooth', block: 'start' })) },
         closeBatch() { this.batchOpen = false; this.submitting = false },
         submitBatch(event) {
             if (this.submitting) { event.preventDefault(); return }
             if (this.selectedBatchCount() === 0) {
                 event.preventDefault()
                 this.batchMessage = @js(__('lf.LF_course_cohort_session_batch_select_required'));
                 this.$nextTick(() => setTimeout(() => document.querySelector('.course-cohort-session-batch-message')?.focus(), 0))
                 return
             }
             const invalidRow = this.batchRows.find(row => row.selected && !this.batchRowReady(row))
             if (invalidRow) {
                 event.preventDefault()
                 invalidRow.expanded = true
                 this.batchMessage = @js(__('lf.LF_course_cohort_session_batch_complete_required'));
                 this.$nextTick(() => document.getElementById(`batch_lesson_${this.batchRows.indexOf(invalidRow)}`)?.focus())
                 return
             }
             this.batchRows.forEach(row => { row.teacherIds = [...new Set(row.teacherIds.map(String))] })
             this.submitting = true
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
         formatSessionDateTime(value) {
             if (!value) return '—'
             const date = new Date(String(value).replace(' ', 'T'))
             if (Number.isNaN(date.getTime())) return value
             const pad = number => String(number).padStart(2, '0')
             return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}`
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
             this.editingId = null; this.editingSession = null; this.sessionType = 'curriculum'; this.lessonId = ''; this.activityId = ''
             this.title = ''; this.titleDirty = false; this.mode = 'online'; this.teacherIds = []; this.teacherOpen = false
             this.startsAt = ''; this.endsAt = ''; this.provider = ''; this.meetingUrl = ''
             this.roomName = ''; this.address = ''; this.submitting = false; this.meetingLinkCopied = false
         },
         openCreate() {
             this.resetForm(); this.batchOpen = false; this.formOpen = true
             this.$nextTick(() => this.$refs.sessionTypeCurriculum?.focus())
         },
         editSession(item) {
             this.editingId = item.id; this.editingSession = item; this.batchOpen = false; this.formOpen = true; this.sessionType = item.session_type
             this.lessonId = item.version_lesson_id ? String(item.version_lesson_id) : ''
             this.activityId = item.version_activity_id ? String(item.version_activity_id) : ''
             this.title = item.title; this.titleDirty = true; this.mode = item.delivery_mode
             this.teacherIds = [...new Set((item.teacher_ids || []).map(String))]; this.teacherOpen = false
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
             this.rescheduleReason = ''
             this.rescheduleStartError = ''
             this.$nextTick(() => this.$refs.rescheduleStart?.focus())
         },
         rescheduleStartChanged() {
             this.rescheduleEnd = ''
             this.rescheduleStartError = ''
         },
         submitReschedule(event) {
             this.rescheduleStartError = ''
             const toTimestamp = value => new Date(String(value || '').replace(' ', 'T')).getTime()
             const start = toTimestamp(this.rescheduleStart)
             const end = toTimestamp(this.rescheduleEnd)
             const hasOverlap = this.sessions.some(item => {
                 if (String(item.id) === String(this.rescheduleSession?.id)) return false
                 if (['cancelled', 'no_show'].includes(item.status)) return false
                 return toTimestamp(item.scheduled_start_at) < end
                     && toTimestamp(item.scheduled_end_at) > start
             })
             if (!hasOverlap) return
             event.preventDefault()
             this.rescheduleStartError = @js(__('lf.LF_course_cohort_session_schedule_overlap'));
             this.$nextTick(() => this.$refs.rescheduleStart?.focus())
         },
         closeReschedule() {
             this.rescheduleSession = null
             this.rescheduleStart = ''
             this.rescheduleEnd = ''
             this.rescheduleReason = ''
             this.rescheduleStartError = ''
         },
         submitForm(event) {
             if (this.submitting) { event.preventDefault(); return }
             this.submitting = true
         }
     }"
     x-init="$nextTick(() => {
         const rescheduleError = document.querySelector('.course-cohort-session-reschedule-modal [aria-invalid=\"true\"]')
         const batchError = document.querySelector('#session-batch-form [aria-invalid=\"true\"]')
         if (rescheduleError) rescheduleError.focus()
         else if (batchError) { batchError.closest('.course-cohort-session-batch-item')?.querySelector('.course-cohort-session-batch-item__trigger')?.setAttribute('aria-expanded', 'true'); batchError.focus() }
         else document.querySelector('.course-cohort-session-error-summary a')?.focus()
     })">
    <div class="admin-form-standard">
        <section class="admin-form-standard-section">
            <header class="admin-form-section-header course-cohort-sessions__header">
                <div>
                    <h2 class="admin-form-section-title">
                        <span x-show="!batchOpen">{{ __('lf.LF_course_cohort_session_title') }}</span>
                        <span x-show="batchOpen" x-cloak>{{ __('lf.LF_course_cohort_session_batch_title') }}</span>
                    </h2>
                    <p class="admin-form-section-help">
                        <span x-show="!batchOpen">{{ __('lf.LF_course_cohort_session_help') }}</span>
                        <span x-show="batchOpen" x-cloak>
                            {{ __('lf.LF_course_cohort_session_batch_help') }}
                            @if($commonBatchTimezone)
                                <small class="course-cohort-session-batch-timezone">{{ __('lf.LF_course_cohort_session_batch_timezone', ['timezone' => $commonBatchTimezone]) }}</small>
                            @endif
                        </span>
                    </p>
                </div>
                <div class="course-cohort-sessions__toolbar">
                    <span class="course-cohort-sessions__count" x-show="!batchOpen">{{ __('lf.LF_course_cohort_session_count', ['count' => $sessions->count()]) }}</span>
                    <span class="course-cohort-sessions__count" x-show="batchOpen" x-cloak x-text="`${selectedBatchCount()}/${selectableBatchCount()} ${@js(__('lf.LF_course_cohort_session_batch_selected_count'))}`"></span>
                    @if ($cohort->is_mutable)
                        @if($plannedOccurrences->isNotEmpty())
                            <button type="button" class="btn btn-primary" x-show="!formOpen && !batchOpen" x-on:click="openBatch()">{{ __('lf.LF_course_cohort_session_batch_open') }}</button>
                        @endif
                        <button type="button" class="btn btn-secondary" x-show="!formOpen && !batchOpen" x-on:click="openCreate()">{{ __('lf.LF_course_cohort_session_manual_open') }}</button>
                    @endif
                </div>
            </header>

            @if ($errors->any() && ! $rescheduleHasErrors)
                <div class="admin-alert admin-alert-danger course-cohort-session-error-summary" role="alert">
                    <a class="admin-text-action" href="{{ $batchHasErrors ? '#session-batch-form' : '#session_title' }}">{{ $errors->first() }}</a>
                </div>
            @endif

            @if ($cohort->is_mutable)
                <form id="session-batch-form" x-show="batchOpen" x-cloak x-ref="batchForm" method="POST"
                      action="{{ route('admin.course-cohorts.sessions.batch.store', $cohort->id) }}"
                      class="course-cohort-session-batch-form" x-on:submit="submitBatch($event)" novalidate>
                    @csrf
                    <div class="course-cohort-session-batch-list">
                        <label class="course-cohort-session-batch-select-all">
                            <input type="checkbox"
                                   x-bind:checked="selectableBatchCount() > 0 && selectedBatchCount() === selectableBatchCount()"
                                   x-effect="$el.indeterminate = selectedBatchCount() > 0 && selectedBatchCount() < selectableBatchCount()"
                                   x-on:change="toggleAllBatch($event)">
                            <span>{{ __('lf.LF_course_cohort_session_batch_select_all') }}</span>
                        </label>

                        @foreach($plannedOccurrences as $index => $occurrence)
                            <article class="course-cohort-session-batch-item"
                                     x-bind:class="{ 'is-selected': batchRows[{{ $index }}].selected, 'is-expanded': batchRows[{{ $index }}].expanded, 'is-incomplete': batchRows[{{ $index }}].selected && !batchRowReady(batchRows[{{ $index }}]) && !batchRows[{{ $index }}].hasError, 'is-configured': batchRows[{{ $index }}].selected && batchRowReady(batchRows[{{ $index }}]), 'has-error': batchRows[{{ $index }}].hasError, 'is-disabled': batchRows[{{ $index }}].consumed }">
                                <input type="hidden" name="occurrences[{{ $index }}][selected]" value="0">
                                <input type="hidden" name="occurrences[{{ $index }}][schedule_id]" value="{{ $occurrence['schedule_id'] }}">
                                <input type="hidden" name="occurrences[{{ $index }}][schedule_slot_id]" value="{{ $occurrence['schedule_slot_id'] }}">
                                <input type="hidden" name="occurrences[{{ $index }}][source_local_date]" value="{{ $occurrence['date'] }}">
                                <input type="hidden" name="occurrences[{{ $index }}][title]" x-model="batchRows[{{ $index }}].title" x-bind:disabled="!batchRows[{{ $index }}].selected">
                                <input type="hidden" name="occurrences[{{ $index }}][version_lesson_id]" x-model="batchRows[{{ $index }}].lessonId" x-bind:disabled="!batchRows[{{ $index }}].selected">
                                <input type="hidden" name="occurrences[{{ $index }}][version_activity_id]" x-model="batchRows[{{ $index }}].activityId" x-bind:disabled="!batchRows[{{ $index }}].selected">
                                <input type="hidden" name="occurrences[{{ $index }}][delivery_mode]" x-model="batchRows[{{ $index }}].mode" x-bind:disabled="!batchRows[{{ $index }}].selected">

                                <div class="course-cohort-session-batch-item__summary">
                                    <label class="course-cohort-session-batch-item__selection" for="batch_occurrence_{{ $index }}">
                                        <input id="batch_occurrence_{{ $index }}" type="checkbox" name="occurrences[{{ $index }}][selected]" value="1"
                                               x-bind:checked="batchRows[{{ $index }}].selected"
                                               x-bind:disabled="batchRows[{{ $index }}].consumed"
                                               x-on:change="toggleBatchRow(batchRows[{{ $index }}], $event.target.checked)">
                                        <span class="sr-only">{{ __('lf.LF_course_cohort_session_batch_select_occurrence', [
                                            'date' => \Illuminate\Support\Carbon::parse($occurrence['date'])->format('d/m/Y'),
                                            'time' => $occurrence['start_time'].'–'.$occurrence['end_time'],
                                        ]) }}</span>
                                    </label>
                                    <button id="batch_occurrence_trigger_{{ $index }}" type="button"
                                            class="course-cohort-session-batch-item__trigger"
                                            x-bind:aria-expanded="batchRows[{{ $index }}].expanded.toString()"
                                            x-bind:disabled="batchRows[{{ $index }}].consumed"
                                            aria-controls="batch_occurrence_panel_{{ $index }}"
                                            x-on:click="if (!batchRows[{{ $index }}].consumed) batchRows[{{ $index }}].expanded = !batchRows[{{ $index }}].expanded">
                                        <span class="course-cohort-session-batch-item__date">
                                            <strong>{{ \Illuminate\Support\Carbon::parse($occurrence['date'])->format('d/m/Y') }}</strong>
                                            <span>{{ \Illuminate\Support\Str::ucfirst(\Illuminate\Support\Carbon::parse($occurrence['date'])->translatedFormat('l')) }}</span>
                                            <span>{{ $occurrence['start_time'] }}–{{ $occurrence['end_time'] }}</span>
                                        </span>
                                        <span class="course-cohort-session-batch-item__meta">
                                            <span>
                                                {{ $occurrence['schedule_name'] }}
                                                @if(! $commonBatchTimezone) · {{ $occurrence['timezone'] }} @endif
                                            </span>
                                            <small x-show="batchRowSummary(batchRows[{{ $index }}])" x-text="batchRowSummary(batchRows[{{ $index }}])"></small>
                                            <small class="course-cohort-session-batch-item__hint" x-show="batchRowHint(batchRows[{{ $index }}])" x-text="batchRowHint(batchRows[{{ $index }}])"></small>
                                        </span>
                                        <span class="course-cohort-session-batch-item__state" x-text="batchRowState(batchRows[{{ $index }}])"></span>
                                        <span class="course-cohort-session-batch-item__chevron" x-show="!batchRows[{{ $index }}].consumed" aria-hidden="true"></span>
                                    </button>
                                </div>

                                <div id="batch_occurrence_panel_{{ $index }}" class="course-cohort-session-batch-item__form"
                                     role="region" aria-labelledby="batch_occurrence_trigger_{{ $index }}"
                                     x-bind:class="{ 'is-awaiting-selection': !batchRows[{{ $index }}].selected }"
                                     x-on:pointerdown.capture="beginBatchConfiguration(batchRows[{{ $index }}], {{ $index }}, $event)"
                                     x-on:focusin.capture="beginBatchConfiguration(batchRows[{{ $index }}], {{ $index }}, $event)"
                                     x-show="!batchRows[{{ $index }}].consumed && batchRows[{{ $index }}].expanded" x-cloak>
                                    <div class="course-cohort-session-batch-item__selection-notice" data-batch-passive x-show="!batchRows[{{ $index }}].selected" x-cloak>
                                        <p>{{ __('lf.LF_course_cohort_session_batch_not_selected_notice') }}</p>
                                        <button type="button" class="btn btn-secondary"
                                                x-on:click="activateBatchRow(batchRows[{{ $index }}], {{ $index }}, true)">
                                            {{ __('lf.LF_course_cohort_session_batch_select_and_configure') }}
                                        </button>
                                    </div>
                                    <div class="course-cohort-session-batch-item__form-heading">
                                        <strong>{{ __('lf.LF_course_cohort_session_batch_configuration') }}</strong>
                                        <button type="button" data-batch-passive class="admin-text-action course-cohort-session-batch-item__reset" x-show="batchRowHasDraft(batchRows[{{ $index }}])" x-cloak x-on:click="resetBatchRow(batchRows[{{ $index }}], $event.currentTarget)" aria-label="{{ __('lf.LF_course_cohort_session_batch_reset_occurrence', ['date' => \Illuminate\Support\Carbon::parse($occurrence['date'])->format('d/m/Y')]) }}">{{ __('lf.LF_course_cohort_session_batch_reset_configuration') }}</button>
                                    </div>
                                    <div class="lf-form-group">
                                        <label class="lf-form-label" for="batch_lesson_{{ $index }}">{{ __('lf.LF_course_cohort_session_lesson') }} <span aria-hidden="true">*</span></label>
                                        <select id="batch_lesson_{{ $index }}" class="lf-form-control" x-model="batchRows[{{ $index }}].lessonId" x-on:change="batchLessonChanged(batchRows[{{ $index }}])" x-bind:tabindex="batchRows[{{ $index }}].selected ? 0 : -1" @error("occurrences.$index.version_lesson_id") aria-invalid="true" aria-describedby="batch_lesson_error_{{ $index }}" @enderror><option value="">{{ __('lf.LF_course_cohort_session_select_lesson') }}</option>@foreach($versionLessons as $lesson)<option value="{{ $lesson->id }}">{{ $lesson->title_snapshot }}</option>@endforeach</select>
                                        @error("occurrences.$index.version_lesson_id")<p id="batch_lesson_error_{{ $index }}" class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                                    </div>
                                    <div class="lf-form-group">
                                        <label class="lf-form-label" for="batch_activity_{{ $index }}">{{ __('lf.LF_course_cohort_session_activity') }} <span aria-hidden="true">*</span></label>
                                        <select id="batch_activity_{{ $index }}" class="lf-form-control" x-model="batchRows[{{ $index }}].activityId" x-bind:tabindex="batchRows[{{ $index }}].selected ? 0 : -1" @error("occurrences.$index.version_activity_id") aria-invalid="true" aria-describedby="batch_activity_error_{{ $index }}" @enderror>
                                            <option value="">{{ __('lf.LF_course_cohort_session_select_activity') }}</option>
                                            @foreach($versionActivities as $activity)
                                                <option value="{{ $activity->id }}"
                                                        x-bind:hidden="String({{ $activity->version_lesson_id }}) !== String(batchRows[{{ $index }}].lessonId)"
                                                        x-bind:disabled="String({{ $activity->version_lesson_id }}) !== String(batchRows[{{ $index }}].lessonId)">
                                                    {{ $activity->title_snapshot }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error("occurrences.$index.version_activity_id")<p id="batch_activity_error_{{ $index }}" class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                                    </div>
                                    <div class="lf-form-group course-cohort-session-batch-item__teachers-field">
                                        <span class="lf-form-label">{{ __('lf.LF_course_cohort_session_teachers') }}</span>
                                        <div class="course-cohort-session-batch-teachers" x-on:click.outside="batchRows[{{ $index }}].teacherOpen = false">
                                            <div class="course-cohort-session-batch-teachers__control"
                                                 role="combobox" x-bind:tabindex="batchRows[{{ $index }}].selected ? 0 : -1"
                                                 aria-haspopup="listbox"
                                                 x-bind:aria-expanded="batchRows[{{ $index }}].teacherOpen.toString()"
                                                 aria-controls="batch-teacher-options-{{ $index }}"
                                                 x-on:click="batchRows[{{ $index }}].teacherOpen = !batchRows[{{ $index }}].teacherOpen"
                                                 x-on:keydown.enter.prevent="batchRows[{{ $index }}].teacherOpen = !batchRows[{{ $index }}].teacherOpen"
                                                 x-on:keydown.escape.stop="batchRows[{{ $index }}].teacherOpen = false">
                                                <span class="course-cohort-session-batch-teachers__placeholder"
                                                      x-show="batchRows[{{ $index }}].teacherIds.length === 0">{{ __('lf.LF_course_cohort_session_batch_no_teacher') }}</span>
                                                <template x-for="teacher in selectedBatchTeachers(batchRows[{{ $index }}])" :key="teacher.id">
                                                    <span class="course-cohort-session-batch-teachers__chip">
                                                        <span x-text="teacher.name"></span>
                                                        <button type="button" x-on:click.stop="toggleBatchTeacher(batchRows[{{ $index }}], teacher.id)" x-bind:aria-label="`{{ __('lf.LF_common_button_remove') }} ${teacher.name}`">×</button>
                                                    </span>
                                                </template>
                                            </div>
                                            <div id="batch-teacher-options-{{ $index }}"
                                                 class="course-cohort-session-batch-teachers__options"
                                                 role="listbox" aria-multiselectable="true"
                                                 x-show="batchRows[{{ $index }}].teacherOpen" x-cloak>
                                                <template x-for="teacher in batchSelectableTeachers(batchRows[{{ $index }}])" :key="teacher.id">
                                                    <button type="button" role="option"
                                                            class="course-cohort-session-batch-teachers__option"
                                                            x-bind:aria-selected="batchRows[{{ $index }}].teacherIds.includes(String(teacher.id)).toString()"
                                                            x-bind:class="{ 'is-selected': batchRows[{{ $index }}].teacherIds.includes(String(teacher.id)) }"
                                                            x-on:click="toggleBatchTeacher(batchRows[{{ $index }}], teacher.id)">
                                                        <span><span x-text="teacher.name"></span> · <span x-text="teacher.role_label"></span></span>
                                                        <span aria-hidden="true" x-show="batchRows[{{ $index }}].teacherIds.includes(String(teacher.id))">✓</span>
                                                    </button>
                                                </template>
                                                <p class="course-cohort-session-batch-teachers__empty"
                                                   x-show="batchSelectableTeachers(batchRows[{{ $index }}]).length === 0">{{ __('lf.LF_course_cohort_session_teachers_empty') }}</p>
                                            </div>
                                            <template x-for="teacherId in batchRows[{{ $index }}].teacherIds" :key="teacherId">
                                                <input type="hidden" name="occurrences[{{ $index }}][teacher_ids][]" x-bind:value="teacherId" x-bind:disabled="!batchRows[{{ $index }}].selected">
                                            </template>
                                        </div>
                                        @error("occurrences.$index.teacher_ids")<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                                    </div>
                                    <div class="lf-form-group">
                                        <label class="lf-form-label" for="batch_mode_{{ $index }}">{{ __('lf.LF_course_cohort_session_mode') }} <span aria-hidden="true">*</span></label>
                                        <select id="batch_mode_{{ $index }}" class="lf-form-control" x-model="batchRows[{{ $index }}].mode" x-bind:tabindex="batchRows[{{ $index }}].selected ? 0 : -1" @error("occurrences.$index.delivery_mode") aria-invalid="true" aria-describedby="batch_mode_error_{{ $index }}" @enderror><option value="">{{ __('lf.LF_course_cohort_session_select_mode') }}</option>@foreach(['online','offline','hybrid'] as $deliveryMode)<option value="{{ $deliveryMode }}">{{ __('lf.LF_course_cohort_session_mode_'.$deliveryMode) }}</option>@endforeach</select>
                                        @error("occurrences.$index.delivery_mode")<p id="batch_mode_error_{{ $index }}" class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                                    </div>
                                    <div class="lf-form-group"
                                         x-show="batchRows[{{ $index }}].mode === 'online' || batchRows[{{ $index }}].mode === 'hybrid'"
                                         x-cloak>
                                        <label class="lf-form-label" for="batch_provider_{{ $index }}">{{ __('lf.LF_course_cohort_session_provider') }}</label>
                                        <input id="batch_provider_{{ $index }}" class="lf-form-control" readonly
                                               x-bind:value="batchMeetingProvider(batchRows[{{ $index }}])"
                                               placeholder="{{ __('lf.LF_course_cohort_session_meeting_not_available') }}">
                                    </div>
                                    <div class="lf-form-group"
                                         x-show="batchRows[{{ $index }}].mode === 'online' || batchRows[{{ $index }}].mode === 'hybrid'"
                                         x-cloak>
                                        <label class="lf-form-label" for="batch_meeting_url_{{ $index }}">{{ __('lf.LF_course_cohort_session_meeting_url') }}</label>
                                        <div class="course-cohort-session-batch-meeting-link">
                                            <div class="course-cohort-session-copy-control">
                                                <input id="batch_meeting_url_{{ $index }}" type="url" class="lf-form-control" readonly
                                                       x-bind:value="batchMeetingUrl(batchRows[{{ $index }}])"
                                                       placeholder="{{ __('lf.LF_course_cohort_session_meeting_not_available') }}">
                                                <button type="button" class="admin-copy-action course-cohort-session-copy-control__button"
                                                        x-show="batchMeetingUrl(batchRows[{{ $index }}])"
                                                        x-on:click="copyBatchMeetingLink(batchRows[{{ $index }}])"
                                                        x-bind:class="{ 'is-copied': batchRows[{{ $index }}].meetingCopied }"
                                                        x-bind:title="batchRows[{{ $index }}].meetingCopied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_session_copy_meeting_url'))"
                                                        x-bind:aria-label="batchRows[{{ $index }}].meetingCopied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_session_copy_meeting_url'))">
                                                    <span x-show="!batchRows[{{ $index }}].meetingCopied"><x-backend-icon name="copy" /></span>
                                                    <span x-cloak x-show="batchRows[{{ $index }}].meetingCopied"><x-backend-icon name="check" /></span>
                                                </button>
                                            </div>
                                            <a class="btn btn-secondary course-cohort-session-open-link"
                                               x-show="batchMeetingUrl(batchRows[{{ $index }}])"
                                               x-bind:href="batchMeetingUrl(batchRows[{{ $index }}])"
                                               target="_blank" rel="noopener noreferrer">
                                                <x-backend-icon name="external-link" />
                                                {{ __('lf.LF_course_cohort_session_join') }}
                                            </a>
                                        </div>
                                    </div>
                                    <p class="course-cohort-session-batch-meeting-help"
                                       x-show="batchRows[{{ $index }}].mode === 'online' || batchRows[{{ $index }}].mode === 'hybrid'"
                                       x-cloak>{{ __('lf.LF_course_cohort_session_curriculum_meeting_help') }}</p>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="course-cohort-session-batch-location" x-show="batchRows.some(row => row.selected && (row.mode === 'offline' || row.mode === 'hybrid'))" x-cloak>
                        <div class="lf-form-group"><x-form-label for="batch_room_name" :value="__('lf.LF_course_cohort_session_room')" :required="true" /><input id="batch_room_name" name="room_name" class="lf-form-control"></div>
                        <div class="lf-form-group"><x-form-label for="batch_address" :value="__('lf.LF_course_cohort_session_address')" :required="true" /><input id="batch_address" name="address" class="lf-form-control"></div>
                    </div>
                    <p x-ref="batchMessage" class="lf-form-error course-cohort-session-batch-message" role="alert" tabindex="-1" x-show="batchMessage" x-text="batchMessage" x-cloak></p>
                    @error('occurrences')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                    <footer class="admin-form-footer admin-form-footer--sticky course-cohort-session-form__footer">
                        <strong class="course-cohort-session-form__footer-context"><span x-text="readyBatchCount()"></span> {{ __('lf.LF_course_cohort_session_batch_ready') }}</strong>
                        <div class="admin-form-footer-primary"><button type="button" class="btn btn-secondary" x-on:click="closeBatch()">{{ __('lf.LF_common_button_cancel') }}</button><button type="submit" class="btn btn-primary" x-bind:disabled="submitting" x-text="`${@js(__('lf.LF_course_cohort_session_batch_create'))} (${selectedBatchCount()})`"></button></div>
                    </footer>
                </form>

                <form x-show="formOpen" x-cloak x-ref="sessionForm" method="POST"
                      action="{{ route('admin.course-cohorts.sessions.store', $cohort->id) }}"
                      x-bind:action="editingId ? updateUrl.replace('__SESSION__', editingId) : storeUrl"
                      class="course-cohort-session-form" x-on:submit="submitForm($event)">
                    @csrf
                    <input type="hidden" name="_method" value="PUT" x-bind:disabled="!editingId">

                    <p class="admin-form-inline-notice" x-show="editingId" x-cloak>
                        <span class="admin-form-inline-notice-icon" aria-hidden="true">i</span>
                        <span>
                            <strong x-text="relationLabels[editingSession?.schedule_relation] || '—'"></strong>
                            — {{ __('lf.LF_course_cohort_session_edit_scope_help') }}
                        </span>
                    </p>

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
                            <select id="version_lesson_id" name="version_lesson_id" class="lf-form-control course-cohort-session-form__bound-control"
                                    x-model="lessonId" x-on:change="lessonChanged()" x-bind:required="sessionType === 'curriculum'"
                                    x-bind:class="{ 'has-value': lessonId }">
                                <option value="">{{ __('lf.LF_course_cohort_session_select_lesson') }}</option>
                                @foreach ($versionLessons as $lesson)<option value="{{ $lesson->id }}">{{ $lesson->title_snapshot }}</option>@endforeach
                            </select>
                            @error('version_lesson_id')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="version_activity_id" :value="__('lf.LF_course_cohort_session_activity')" :required="true" />
                            <select id="version_activity_id" name="version_activity_id" class="lf-form-control course-cohort-session-form__bound-control"
                                    x-model="activityId" x-on:change="activityChanged()" x-bind:required="sessionType === 'curriculum'" x-bind:disabled="!lessonId"
                                    x-bind:class="{ 'has-value': activityId }">
                                <option value="">{{ __('lf.LF_course_cohort_session_select_activity') }}</option>
                                @foreach($versionActivities as $activity)
                                    <option value="{{ $activity->id }}"
                                            x-bind:hidden="String({{ $activity->version_lesson_id }}) !== String(lessonId)"
                                            x-bind:disabled="String({{ $activity->version_lesson_id }}) !== String(lessonId)">
                                        {{ $activity->title_snapshot }}
                                    </option>
                                @endforeach
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
                                   x-model="title" x-on:input="titleDirty = true" required maxlength="255"
                                   placeholder="{{ __('lf.LF_course_cohort_session_name_placeholder') }}">
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
                                   x-bind:readonly="Boolean(editingId)" x-bind:class="{ 'has-value': startsAt }" required>
                            <p class="lf-form-help" x-show="editingId" x-cloak>{{ __('lf.LF_course_cohort_session_edit_schedule_help') }}</p>
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="scheduled_end_at" :value="__('lf.LF_course_cohort_session_end')" :required="true" />
                            <input id="scheduled_end_at" type="datetime-local" name="scheduled_end_at" class="lf-form-control"
                                   x-model="endsAt" x-on:change="syncTeacherAvailability()" x-bind:min="startsAt || scheduleMin" x-bind:max="scheduleMax"
                                   x-bind:readonly="Boolean(editingId)" x-bind:class="{ 'has-value': endsAt }" required>
                        </div>
                        <div class="lf-form-group admin-form-field--full course-cohort-session-additional-teachers">
                            <span class="lf-form-label">{{ __('lf.LF_course_cohort_session_teachers') }}</span>
                            <div class="course-cohort-session-batch-teachers" x-on:click.outside="teacherOpen = false">
                                <div class="course-cohort-session-batch-teachers__control"
                                     role="combobox" tabindex="0" aria-haspopup="listbox"
                                     x-bind:aria-expanded="teacherOpen.toString()"
                                     aria-controls="session-teacher-options"
                                     x-on:click="teacherOpen = !teacherOpen"
                                     x-on:keydown.enter.prevent="teacherOpen = !teacherOpen"
                                     x-on:keydown.escape.stop="teacherOpen = false">
                                    <span class="course-cohort-session-batch-teachers__placeholder"
                                          x-show="teacherIds.length === 0">{{ __('lf.LF_course_cohort_session_batch_no_teacher') }}</span>
                                    <template x-for="teacher in selectedTeachers()" :key="teacher.id">
                                        <span class="course-cohort-session-batch-teachers__chip">
                                            <span x-text="teacher.name"></span>
                                            <button type="button" x-on:click.stop="toggleTeacher(teacher.id)"
                                                    x-bind:aria-label="`{{ __('lf.LF_common_button_remove') }} ${teacher.name}`">×</button>
                                        </span>
                                    </template>
                                </div>
                                <div id="session-teacher-options" class="course-cohort-session-batch-teachers__options"
                                     role="listbox" aria-multiselectable="true" x-show="teacherOpen" x-cloak>
                                    <template x-for="teacher in selectableTeachers()" :key="teacher.id">
                                        <button type="button" role="option" class="course-cohort-session-batch-teachers__option"
                                                x-bind:aria-selected="teacherIds.includes(String(teacher.id)).toString()"
                                                x-bind:class="{ 'is-selected': teacherIds.includes(String(teacher.id)) }"
                                                x-on:click="toggleTeacher(teacher.id)">
                                            <span><span x-text="teacher.name"></span> · <span x-text="teacher.role_label"></span></span>
                                            <span aria-hidden="true" x-show="teacherIds.includes(String(teacher.id))">✓</span>
                                        </button>
                                    </template>
                                    <p class="course-cohort-session-batch-teachers__empty"
                                       x-show="selectableTeachers().length === 0">{{ __('lf.LF_course_cohort_session_teachers_empty') }}</p>
                                </div>
                                <template x-for="teacherId in teacherIds" :key="teacherId">
                                    <input type="hidden" name="teacher_ids[]" x-bind:value="teacherId">
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
                            <input id="online_provider" name="online_provider" class="lf-form-control" x-model="provider"
                                   placeholder="{{ __('lf.LF_course_cohort_session_provider_placeholder') }}">
                        </div>
                        <div class="lf-form-group" x-show="(mode === 'online' || mode === 'hybrid') && sessionType === 'operational'" x-cloak>
                            <x-form-label for="meeting_url" :value="__('lf.LF_course_cohort_session_meeting_url')" />
                            <input id="meeting_url" type="url" name="meeting_url" class="lf-form-control" x-model="meetingUrl"
                                   placeholder="{{ __('lf.LF_course_cohort_session_meeting_url_placeholder') }}">
                            <p class="lf-form-help">{{ __('lf.LF_course_cohort_session_operational_meeting_help') }}</p>
                        </div>
                        <div class="lf-form-group" x-show="mode === 'offline' || mode === 'hybrid'" x-cloak>
                            <x-form-label for="room_name" :value="__('lf.LF_course_cohort_session_room')" :required="true" />
                            <input id="room_name" name="room_name" class="lf-form-control" x-model="roomName" x-bind:required="mode === 'offline' || mode === 'hybrid'"
                                   placeholder="{{ __('lf.LF_course_cohort_session_room_placeholder') }}">
                        </div>
                        <div class="lf-form-group" x-show="mode === 'offline' || mode === 'hybrid'" x-cloak>
                            <x-form-label for="address" :value="__('lf.LF_course_cohort_session_address')" :required="true" />
                            <input id="address" name="address" class="lf-form-control" x-model="address" x-bind:required="mode === 'offline' || mode === 'hybrid'"
                                   placeholder="{{ __('lf.LF_course_cohort_session_address_placeholder') }}">
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
                <table class="table course-cohort-session-table admin-table-has-actions">
                    <colgroup>
                        <col class="course-cohort-session-table__col-sequence">
                        <col class="course-cohort-session-table__col-summary">
                        <col class="course-cohort-session-table__col-schedule">
                        <col class="course-cohort-session-table__col-teacher">
                        <col class="course-cohort-session-table__col-status">
                        <col class="course-cohort-session-table__col-actions">
                    </colgroup>
                    <thead><tr>
                        <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                        <th class="course-cohort-session-table__summary">{{ __('lf.LF_course_cohort_session_table_session') }}</th>
                        <th>{{ __('lf.LF_course_cohort_session_table_date_time') }}</th>
                        <th>{{ __('lf.LF_course_cohort_session_table_teachers') }}</th>
                        <th class="course-cohort-session-table__status">{{ __('lf.LF_course_cohort_common_status') }}</th>
                        <th class="course-cohort-session-table__actions">{{ __('lf.table_actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($sessions as $session)
                        @php
                            $sessionStartsAt = \Illuminate\Support\Carbon::parse($session->scheduled_start_at);
                            $sessionEndsAt = \Illuminate\Support\Carbon::parse($session->scheduled_end_at);
                            $sessionOccursOnOneDay = $sessionStartsAt->isSameDay($sessionEndsAt);
                            $sessionDisplayTitle = $session->title;
                            $sessionContentDetails = collect($session->session_type === 'curriculum'
                                ? [$session->lesson_title, $session->activity_title]
                                : [])
                                ->filter()
                                ->reject(fn ($value) => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::squish($value)) === \Illuminate\Support\Str::lower(\Illuminate\Support\Str::squish($sessionDisplayTitle)))
                                ->unique(fn ($value) => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::squish($value)));
                        @endphp
                        <tr>
                            <td class="admin-table-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                            <td class="course-cohort-session-table__summary" data-label="{{ __('lf.LF_course_cohort_session_table_session') }}">
                                <strong class="course-cohort-session-table__primary">{{ $sessionDisplayTitle }}</strong>
                                @foreach($sessionContentDetails as $contentDetail)
                                    <span class="course-cohort-session-table__meta">{{ $contentDetail }}</span>
                                @endforeach
                                <span class="course-cohort-session-table__meta">{{ __('lf.LF_course_cohort_session_table_type_'.$session->session_type) }} · {{ __('lf.LF_course_cohort_session_mode_'.$session->delivery_mode) }}</span>
                                <span class="badge course-cohort-session-origin-badge course-cohort-session-origin-badge--{{ $session->schedule_relation }}">{{ __('lf.LF_course_cohort_session_relation_'.$session->schedule_relation) }}</span>
                            </td>
                            <td data-label="{{ __('lf.LF_course_cohort_session_table_date_time') }}">
                                <dl class="course-cohort-session-table__schedule">
                                    @if($sessionOccursOnOneDay)
                                        <div class="course-cohort-session-table__date">
                                            <dd>{{ $sessionStartsAt->format('d/m/Y') }}</dd>
                                            <dt>{{ \Illuminate\Support\Str::ucfirst($sessionStartsAt->translatedFormat('l')) }}</dt>
                                        </div>
                                        <div class="course-cohort-session-table__time">
                                            <dt>{{ __('lf.LF_course_cohort_session_table_time_range') }}</dt>
                                            <dd>{{ $sessionStartsAt->format('H:i') }}–{{ $sessionEndsAt->format('H:i') }}</dd>
                                        </div>
                                    @else
                                        <div class="course-cohort-session-table__date-range">
                                            <dt>{{ __('lf.LF_course_cohort_session_table_start') }}</dt>
                                            <dd>{{ $sessionStartsAt->format('d/m/Y H:i') }}</dd>
                                        </div>
                                        <div class="course-cohort-session-table__date-range">
                                            <dt>{{ __('lf.LF_course_cohort_session_table_end') }}</dt>
                                            <dd>{{ $sessionEndsAt->format('d/m/Y H:i') }}</dd>
                                        </div>
                                    @endif
                                </dl>
                            </td>
                            <td data-label="{{ __('lf.LF_course_cohort_session_table_teachers') }}">
                                @if($session->teacher_team->isNotEmpty())
                                    <strong class="course-cohort-session-table__primary">{{ $session->teacher_team->pluck('name')->join(', ') }}</strong>
                                @else
                                    <span class="course-cohort-session-table__empty-value">{{ $session->primary_teacher_name ?: __('lf.LF_course_cohort_session_batch_no_teacher') }}</span>
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
                                <x-admin-action-menu :label="__('lf.table_actions').': '.$session->title">
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
                                        @if ($session->status === 'scheduled' && $cohort->is_mutable)
                                            <button type="button" role="menuitem" class="admin-link-button admin-text-action admin-table-action-link"
                                                    x-on:click="actionsOpen = false; openReschedule(@js($session))">
                                                <svg class="course-cohort-session-action-menu__icon" viewBox="0 0 24 24" aria-hidden="true">
                                                    <rect x="3" y="5" width="18" height="16" rx="2" />
                                                    <path d="M8 3v4M16 3v4M3 10h18M8 15h8M13 12l3 3-3 3" />
                                                </svg>
                                                {{ __('lf.LF_course_cohort_session_change_schedule') }}
                                            </button>
                                        @endif
                                        {{-- Session status là thao tác vận hành nên chỉ mở khi lớp đang hoạt động. --}}
                                        @if ($cohort->status === 'active')
                                            @foreach ([
                                                'start' => ['scheduled'],
                                                'complete' => ['live'],
                                                'cancel' => ['scheduled', 'live'],
                                                'no-show' => ['scheduled', 'live'],
                                            ] as $action => $allowedFrom)
                                                @if (in_array($session->status, $allowedFrom, true))
                                                    @include('course-cohorts.partials.lifecycle-action', [
                                                        'dialogId' => 'session-'.$action.'-'.$session->id,
                                                        'action' => route('admin.course-cohorts.sessions.'.$action, [$cohort->id, $session->id]),
                                                        'triggerClass' => in_array($action, ['cancel', 'no-show'], true)
                                                            ? 'admin-danger-text-action admin-table-action-link'
                                                            : 'admin-link-button admin-text-action admin-table-action-link',
                                                        'triggerLabel' => __('lf.LF_course_cohort_session_action_'.$action),
                                                        'title' => __('lf.LF_course_cohort_session_lifecycle_'.$action.'_title'),
                                                        'body' => __('lf.LF_course_cohort_session_lifecycle_'.$action.'_body'),
                                                        'confirmClass' => in_array($action, ['cancel', 'no-show'], true)
                                                            ? 'btn btn-danger'
                                                            : 'btn btn-primary',
                                                        'confirmLabel' => __('lf.LF_course_cohort_session_action_'.$action),
                                                    ])
                                                @endif
                                            @endforeach
                                        @endif
                                </x-admin-action-menu>
                            </td>
                        </tr>
                    @empty
                        <tr class="course-cohort-session-empty-row">
                            <td class="course-cohort-session-empty-cell" colspan="6">
                                <div class="course-cohort-empty-state" role="status">
                                    <strong>{{ __('lf.LF_course_cohort_session_empty') }}</strong>
                                    <span>{{ __('lf.LF_course_cohort_session_empty_help') }}</span>
                                    @if ($cohort->is_mutable)
                                        @if($plannedOccurrences->isNotEmpty())
                                            <button type="button" class="btn btn-primary" x-on:click="openBatch()">
                                                {{ __('lf.LF_course_cohort_session_batch_open') }}
                                            </button>
                                        @else
                                            <button type="button" class="btn btn-primary" x-on:click="openCreate()">
                                                {{ __('lf.LF_course_cohort_session_manual_open') }}
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
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
                    <div class="course-cohort-session-modal__summary">
                        <span x-text="typeLabels[detailSession?.session_type] || '—'"></span>
                        <span aria-hidden="true">•</span>
                        <span x-text="modeLabels[detailSession?.delivery_mode] || '—'"></span>
                    </div>
                </div>
                <span class="badge course-cohort-session-modal__status"
                      x-bind:class="detailSession ? 'course-cohort-session-status-badge--' + detailSession.status : ''"
                      x-text="statusLabels[detailSession?.status] || '—'"></span>
                <button x-ref="sessionDetailClose" type="button"
                        class="course-enrollment-lifecycle-modal__close" x-on:click="closeDetail()"
                        aria-label="{{ __('lf.LF_common_button_close') }}"><span aria-hidden="true">×</span></button>
            </header>
            <div class="course-cohort-session-modal__body">
                <section class="course-cohort-session-modal__section">
                    <h3>{{ __('lf.LF_course_cohort_session_detail_schedule') }}</h3>
                    <dl class="course-cohort-session-modal__grid course-cohort-session-modal__grid--schedule">
                        <div class="course-cohort-session-modal__schedule-source"><dt>{{ __('lf.LF_course_cohort_session_source') }}</dt><dd x-text="relationLabels[detailSession?.schedule_relation] || '—'"></dd></div>
                        <div><dt>{{ __('lf.LF_course_cohort_session_start') }}</dt><dd x-text="formatSessionDateTime(detailSession?.scheduled_start_at)"></dd></div>
                        <div><dt>{{ __('lf.LF_course_cohort_session_end') }}</dt><dd x-text="formatSessionDateTime(detailSession?.scheduled_end_at)"></dd></div>
                    </dl>
                </section>
                <section class="course-cohort-session-modal__section">
                    <h3>{{ __('lf.LF_course_cohort_session_detail_content') }}</h3>
                    <dl class="course-cohort-session-modal__grid">
                        <div><dt>{{ __('lf.LF_course_cohort_session_version') }}</dt><dd>{{ $cohort->version_code }}</dd></div>
                        <div><dt>{{ __('lf.LF_course_cohort_session_lesson') }}</dt><dd x-text="detailSession?.lesson_title || @js(__('lf.LF_course_cohort_session_outside_content'))"></dd></div>
                        <div><dt>{{ __('lf.LF_course_cohort_session_activity') }}</dt><dd x-text="detailSession?.activity_title || '—'"></dd></div>
                        <div><dt>{{ __('lf.LF_course_cohort_session_teachers') }}</dt><dd x-text="(detailSession?.teacher_team || []).map(teacher => teacher.name).join(', ') || detailSession?.primary_teacher_name || '—'"></dd></div>
                    </dl>
                </section>
                <section class="course-cohort-session-modal__section"
                         x-show="detailSession?.delivery_mode === 'online' || detailSession?.delivery_mode === 'hybrid' || detailSession?.room_name_snapshot || detailSession?.address_snapshot">
                    <h3>{{ __('lf.LF_course_cohort_session_detail_join') }}</h3>
                    <dl class="course-cohort-session-modal__grid">
                        <div x-show="detailSession?.delivery_mode === 'online' || detailSession?.delivery_mode === 'hybrid'">
                            <dt>{{ __('lf.LF_course_cohort_session_provider') }}</dt>
                            <dd x-text="detailSession?.online_provider || meetingProvider(detailSession?.meeting_url_snapshot || detailSession?.activity_meeting_url) || @js(__('lf.LF_course_cohort_session_meeting_not_available'))"></dd>
                        </div>
                        <div x-show="detailSession?.meeting_url_snapshot || detailSession?.activity_meeting_url">
                            <dt>{{ __('lf.LF_course_cohort_session_meeting_url') }}</dt>
                            <dd><a class="admin-text-action course-cohort-session-modal__join-link"
                                   x-bind:href="detailSession?.meeting_url_snapshot || detailSession?.activity_meeting_url"
                                   target="_blank" rel="noopener noreferrer"><x-backend-icon name="external-link" />{{ __('lf.LF_course_cohort_session_join') }}</a></dd>
                        </div>
                        <div x-show="detailSession?.room_name_snapshot"><dt>{{ __('lf.LF_course_cohort_session_room') }}</dt><dd x-text="detailSession?.room_name_snapshot"></dd></div>
                        <div x-show="detailSession?.address_snapshot"><dt>{{ __('lf.LF_course_cohort_session_address') }}</dt><dd x-text="detailSession?.address_snapshot"></dd></div>
                    </dl>
                </section>
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
              x-on:submit="submitReschedule($event)"
              class="admin-modal course-cohort-session-modal course-cohort-session-reschedule-modal"
              role="dialog" aria-modal="true" aria-labelledby="course-cohort-session-reschedule-title">
            @csrf @method('PUT')
            <input type="hidden" name="form_context" value="reschedule">
            <input type="hidden" name="reschedule_session_id" x-bind:value="rescheduleSession?.id || ''">
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
                <section class="course-cohort-session-modal__section course-cohort-session-reschedule-current"
                         aria-labelledby="course-cohort-session-current-schedule">
                    <h3 id="course-cohort-session-current-schedule">{{ __('lf.LF_course_cohort_session_current_schedule') }}</h3>
                    <dl>
                        <div class="course-cohort-session-modal__schedule-source"><dt>{{ __('lf.LF_course_cohort_session_source') }}</dt><dd x-text="relationLabels[rescheduleSession?.schedule_relation] || '—'"></dd></div>
                        <div><dt>{{ __('lf.LF_course_cohort_session_start') }}</dt><dd x-text="formatSessionDateTime(rescheduleSession?.scheduled_start_at)"></dd></div>
                        <div><dt>{{ __('lf.LF_course_cohort_session_end') }}</dt><dd x-text="formatSessionDateTime(rescheduleSession?.scheduled_end_at)"></dd></div>
                    </dl>
                </section>
                <section class="course-cohort-session-modal__section course-cohort-session-reschedule-new"
                         aria-labelledby="course-cohort-session-new-schedule">
                    <h3 id="course-cohort-session-new-schedule">{{ __('lf.LF_course_cohort_session_new_schedule') }}</h3>
                    <div class="course-cohort-session-reschedule-grid">
                        <div class="lf-form-group">
                            <x-form-label for="reschedule_start_at" :value="__('lf.LF_course_cohort_session_start')" :required="true" />
                            <input x-ref="rescheduleStart" id="reschedule_start_at" type="datetime-local"
                                   name="scheduled_start_at" class="lf-form-control" x-model="rescheduleStart"
                                   x-on:change="rescheduleStartChanged()" x-on:input="rescheduleStartError = ''" x-bind:min="scheduleMin" x-bind:max="scheduleMax"
                                   x-bind:class="{ 'has-value': rescheduleStart }" required
                                   x-bind:aria-invalid="rescheduleStartError ? 'true' : null" aria-describedby="reschedule_start_error">
                            <div class="course-cohort-session-reschedule-error-slot">
                                <p id="reschedule_start_error" class="lf-form-error" role="alert" aria-live="polite"
                                   x-show="rescheduleStartError" x-text="rescheduleStartError" x-cloak></p>
                            </div>
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="reschedule_end_at" :value="__('lf.LF_course_cohort_session_end')" :required="true" />
                            <input id="reschedule_end_at" type="datetime-local" name="scheduled_end_at"
                                   class="lf-form-control" x-model="rescheduleEnd"
                                   x-bind:min="rescheduleStart || scheduleMin" x-bind:max="scheduleMax"
                                   x-bind:class="{ 'has-value': rescheduleEnd }" required
                                   @if($rescheduleHasErrors && $errors->has('scheduled_end_at')) aria-invalid="true" aria-describedby="reschedule_end_error" @endif>
                            <div class="course-cohort-session-reschedule-error-slot">
                                @if($rescheduleHasErrors)
                                    @error('scheduled_end_at')
                                        <p id="reschedule_end_error" class="lf-form-error" role="alert">{{ $message }}</p>
                                    @enderror
                                @endif
                            </div>
                        </div>
                        <div class="lf-form-group admin-form-field--full">
                            <x-form-label for="reschedule_reason" :value="__('lf.LF_course_cohort_session_change_reason')" />
                            <textarea id="reschedule_reason" name="reason" class="lf-form-control" rows="2" maxlength="1000"
                                      x-model="rescheduleReason"
                                      @if($rescheduleHasErrors && $errors->has('reason')) aria-invalid="true" aria-describedby="reschedule_reason_error" @endif></textarea>
                            @if($rescheduleHasErrors)
                                @error('reason')
                                    <p id="reschedule_reason_error" class="lf-form-error" role="alert">{{ $message }}</p>
                                @enderror
                            @endif
                            <p class="lf-form-help">{{ __('lf.LF_course_cohort_session_change_reason_help') }}</p>
                        </div>
                    </div>
                </section>
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
