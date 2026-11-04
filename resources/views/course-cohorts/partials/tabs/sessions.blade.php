@php
    // Khoảng lịch hợp lệ do backend quyết định (CourseCohortSessionWindow) và
    // được dùng chung với validation; view không tự suy ra. NULL nghĩa là lớp
    // legacy chưa đủ thời gian vận hành.
    $sessionWindowOpen = $sessionWindow && $sessionWindow['min']->lte($sessionWindow['max']);
    $canCreateSession = $cohort->is_mutable && $sessionAcceptsNew && $sessionWindowOpen;
    $canBatchCreate = $canCreateSession && $plannedOccurrences->contains(fn (array $item): bool => ! $item['consumed']);
    $batchHasErrors = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'occurrences'));
    $formHasErrors = old('form_context') === 'session' && $errors->any();
    $rescheduleHasErrors = old('form_context') === 'reschedule'
        && collect($errors->keys())->contains(fn ($key) => in_array($key, ['scheduled_start_at', 'scheduled_end_at', 'reason'], true));
    $rescheduleOldSession = $rescheduleHasErrors
        ? $sessions->firstWhere('id', (int) old('reschedule_session_id'))
        : null;
    // Lưu sửa bị lỗi phải mở lại đúng buổi đang sửa, không rơi về form tạo mới.
    $formOldSession = $formHasErrors && old('editing_session_id')
        ? $sessions->firstWhere('id', (int) old('editing_session_id'))
        : null;
    $assignHasErrors = old('form_context') === 'assign_teachers' && $errors->any();
    $assignOldSession = $assignHasErrors ? $sessions->firstWhere('id', (int) old('assign_session_id')) : null;
    // Lỗi của ô đang hiện nằm ngay dưới ô đó; đầu popup chỉ còn lỗi không có chỗ hiện (khi sửa, ô thời gian bị ẩn).
    $formInlineKeys = ['title', 'version_lesson_id', 'version_activity_id', 'delivery_mode', 'teacher_ids', 'meeting_url', 'online_provider', 'room_name', 'address'];
    if (! $formOldSession) {
        array_push($formInlineKeys, 'scheduled_start_at', 'scheduled_end_at');
    }
    $formSummaryErrors = $formHasErrors
        ? collect($errors->messages())->reject(fn ($messages, $key) => in_array($key, $formInlineKeys, true) || str_starts_with($key, 'teacher_ids.'))->flatten()
        : collect();
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
            'mode' => (string) ($old['delivery_mode'] ?? ''),
            'meetingCopied' => false,
            'meetingUrl' => (string) ($old['meeting_url'] ?? ''),
            'meetingDefault' => '',
        ]);
    })->values();
    $batchTimezones = $plannedOccurrences->pluck('timezone')->filter()->unique()->values();
    $commonBatchTimezone = $batchTimezones->count() === 1 ? $batchTimezones->first() : null;
    $teacherRoleLabel = fn (object $teacher): string => $teacher->role === 'primary_teacher'
        ? __('lf.LF_course_cohort_session_teacher_role_cohort_primary')
        : __('lf.LF_course_cohort_teacher_role_'.$teacher->role);
    $lifecycleActions = ['start', 'complete', 'cancel', 'no-show'];
@endphp
<section class="mk-section course-cohort-sessions" aria-labelledby="cohort-sessions-title"
     x-data="{
         editingId: @js($formOldSession?->id), editingSession: @js($formOldSession), submitting: false, syncing: false, batchMessage: '', batchSubmitting: false,
         detailSession: null, detailActions: [], lifecycle: null, lifecycleReason: '', lifecycleSubmitting: false,
         showFormErrors: true, showRescheduleErrors: true, meetingLinkCopied: false,
         rescheduleSession: @js($rescheduleOldSession),
         rescheduleStart: @js($rescheduleHasErrors ? old('scheduled_start_at', '') : ''),
         rescheduleEnd: @js($rescheduleHasErrors ? old('scheduled_end_at', '') : ''),
         rescheduleReason: @js($rescheduleHasErrors ? old('reason', '') : ''),
         rescheduleStartError: @js($rescheduleHasErrors ? $errors->first('scheduled_start_at') : ''),
         rescheduleSubmitting: false,
         assignSession: @js($assignOldSession), assignTeacherIds: @js($assignHasErrors ? $uniqueTeacherIds(old('teacher_ids', [])) : []), assignSubmitting: false, showAssignErrors: true,
         assignUrl: @js(route('admin.course-cohorts.sessions.teachers', [$cohort->id, '__SESSION__'])),
         sessionType: @js($formHasErrors ? old('session_type', 'curriculum') : 'curriculum'),
         lessonId: @js($formHasErrors ? (string) old('version_lesson_id', '') : ''),
         activityId: @js($formHasErrors ? (string) old('version_activity_id', '') : ''),
         title: @js($formHasErrors ? old('title', '') : ''), titleDirty: @js($formHasErrors && old('title') !== null),
         mode: @js($formHasErrors ? old('delivery_mode', 'online') : 'online'),
         teacherIds: @js($formHasErrors ? $uniqueTeacherIds(old('teacher_ids', [])) : []),
         startsAt: @js($formHasErrors ? old('scheduled_start_at', '') : ''), endsAt: @js($formHasErrors ? old('scheduled_end_at', '') : ''),
         scheduleMin: @js($sessionWindow ? $sessionWindow['min']->format('Y-m-d\TH:i') : ''),
         scheduleMax: @js($sessionWindow ? $sessionWindow['max']->format('Y-m-d\TH:i') : ''),
         provider: @js($formHasErrors ? old('online_provider', '') : ''), meetingUrl: @js($formHasErrors ? old('meeting_url', '') : ''), meetingDefault: '',
         roomName: @js($formHasErrors ? old('room_name', '') : ''), address: @js($formHasErrors ? old('address', '') : ''),
         batchRoom: @js($batchHasErrors ? old('room_name', '') : ''), batchAddress: @js($batchHasErrors ? old('address', '') : ''),
         statusLabels: @js(collect(['scheduled', 'live', 'completed', 'cancelled', 'no_show'])->mapWithKeys(fn ($status) => [$status => __('lf.LF_course_cohort_session_status_'.$status)])),
         typeLabels: @js(collect(['curriculum', 'operational'])->mapWithKeys(fn ($type) => [$type => __('lf.LF_course_cohort_session_type_'.$type)])),
         modeLabels: @js(collect(['online', 'offline', 'hybrid'])->mapWithKeys(fn ($mode) => [$mode => __('lf.LF_course_cohort_session_mode_'.$mode)])),
         relationLabels: @js(collect(['on_schedule', 'rescheduled', 'off_schedule', 'source_unknown'])->mapWithKeys(fn ($relation) => [$relation => __('lf.LF_course_cohort_session_relation_'.$relation)])),
         statusTones: { scheduled: 'draft', live: 'active', completed: 'completed', cancelled: 'danger', no_show: 'archived' },
         lifecycleTexts: @js(collect($lifecycleActions)->mapWithKeys(fn ($action) => [$action => [
             'label' => __('lf.LF_course_cohort_session_action_'.$action),
             'title' => __('lf.LF_course_cohort_session_lifecycle_'.$action.'_title'),
             'body' => __('lf.LF_course_cohort_session_lifecycle_'.$action.'_body'),
             'danger' => in_array($action, ['cancel', 'no-show'], true),
         ]])),
         lessons: @js($versionLessons), activities: @js($versionActivities),
         batchRows: @js($batchRows),
         teachers: @js($availableTeachers->map(fn ($teacher) => [
             'id' => (string) $teacher->id,
             'assigned_from' => $teacher->assigned_from,
             'assigned_to' => $teacher->assigned_to,
         ])->values()),
         storeUrl: @js(route('admin.course-cohorts.sessions.store', $cohort->id)),
         updateUrl: @js(route('admin.course-cohorts.sessions.update', [$cohort->id, '__SESSION__'])),
         rescheduleUrl: @js(route('admin.course-cohorts.sessions.schedule', [$cohort->id, '__SESSION__'])),
         init() {
             if (@js($batchHasErrors)) this.openBatch()
             else if (@js($rescheduleHasErrors && $rescheduleOldSession)) { this.previousStart = this.rescheduleStart; this.$nextTick(() => this.$refs.rescheduleDialog?.showModal()) }
             else if (@js($formHasErrors)) this.showSessionDialog()
             else if (@js($assignHasErrors && $assignOldSession)) { this.syncSelects(this.$refs.assignDialog); this.$nextTick(() => this.$refs.assignDialog?.showModal()) }
         },
         // Kit selects redraw on change. A sync only repaints them: the handlers below must not treat it as a pick.
         syncSelects(scope) {
             this.$nextTick(() => {
                 this.syncing = true
                 scope?.querySelectorAll('select[data-ui-kit-select]').forEach(select => select.dispatchEvent(new Event('change', { bubbles: true })))
                 this.syncing = false
             })
         },
         teacherFits(teacher, startsOn, endsOn) {
             return (!teacher.assigned_from || startsOn >= teacher.assigned_from) && (!teacher.assigned_to || endsOn <= teacher.assigned_to)
         },
         selectableTeachers() {
             if (!this.startsAt || !this.endsAt) return []
             return this.teachers.filter(teacher => this.teacherFits(teacher, this.startsAt.slice(0, 10), this.endsAt.slice(0, 10)))
         },
         teacherSelectable(id) { return this.selectableTeachers().some(teacher => teacher.id === String(id)) },
         syncTeacherAvailability() {
             const eligibleIds = this.selectableTeachers().map(teacher => teacher.id)
             this.teacherIds = this.teacherIds.filter(id => eligibleIds.includes(String(id)))
             this.syncSelects(this.$refs.sessionTeacherField)
         },
         // Moving the start keeps the length of the session, so the end does not have to be picked again.
         movedEnd(previousStart, nextStart, end) {
             if (!previousStart || !nextStart || !end) return end
             const parse = value => new Date(String(value).replace(' ', 'T')).getTime()
             const moved = new Date(parse(end) + parse(nextStart) - parse(previousStart))
             if (Number.isNaN(moved.getTime())) return end
             const pad = number => String(number).padStart(2, '0')
             return `${moved.getFullYear()}-${pad(moved.getMonth() + 1)}-${pad(moved.getDate())}T${pad(moved.getHours())}:${pad(moved.getMinutes())}`
         },
         previousStart: '',
         sessionStartChanged() {
             this.endsAt = this.movedEnd(this.previousStart, this.startsAt, this.endsAt)
             this.previousStart = this.startsAt
             this.clearTimeError(this.$refs.sessionStart)
             this.clearTimeError(this.$refs.sessionEnd)
             this.syncTeacherAvailability()
         },
         sessionEndChanged() {
             this.clearTimeError(this.$refs.sessionEnd)
             this.syncTeacherAvailability()
         },
         clearTimeError(field) { field?.setCustomValidity?.('') },
         timeError(field, message) {
             field?.setCustomValidity?.(message)
             field?.reportValidity?.()
         },
         // Required/ordered checks only: overlap and the class window stay with the backend, which knows the timezones.
         timesInvalid(startField, endField, start, end) {
             if (!start) { this.timeError(startField, @js(__('lf.LF_course_cohort_session_time_required'))); return true }
             if (!end) { this.timeError(endField, @js(__('lf.LF_course_cohort_session_time_required'))); return true }
             if (String(end) <= String(start)) { this.timeError(endField, @js(__('lf.LF_course_cohort_session_end_after_start'))); return true }
             return false
         },
         batchActivities(row) { return this.activities.filter(item => String(item.version_lesson_id) === String(row.lessonId)) },
         selectedBatchActivity(row) { return this.activities.find(item => String(item.id) === String(row.activityId)) || null },
         batchMeetingUrl(row) { return row.meetingUrl || '' },
         batchActivityChanged(row) {
             if (this.syncing) return
             const next = this.selectedBatchActivity(row)?.live_class_url_snapshot || ''
             if (!row.meetingUrl || row.meetingUrl === row.meetingDefault) row.meetingUrl = next
             row.meetingDefault = next; row.meetingCopied = false
         },
         copyBatchMeetingLink(row) {
             const url = this.batchMeetingUrl(row)
             if (!url) return
             navigator.clipboard.writeText(url).then(() => {
                 row.meetingCopied = true
                 setTimeout(() => row.meetingCopied = false, 1600)
             })
         },
         batchLessonChanged(row, field) {
             if (this.syncing) return
             row.activityId = ''
             this.batchActivityChanged(row)
             const lesson = this.lessons.find(item => String(item.id) === String(row.lessonId))
             row.title = lesson?.title_snapshot || ''
             this.syncSelects(field?.closest('article'))
         },
         batchTeacherSelectable(row, id) {
             return this.teachers.some(teacher => teacher.id === String(id) && this.teacherFits(teacher, row.date, row.date))
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
             row.mode = ''; row.hasError = false; row.meetingCopied = false
             row.meetingUrl = ''; row.meetingDefault = ''
             this.syncSelects(trigger.closest('article'))
         },
         toggleBatchRow(row, checked) {
             row.selected = checked
             row.expanded = checked
             this.batchMessage = ''
         },
         // The row header selects a row on first use, then only folds it. Unselecting keeps what was picked.
         batchHeaderClicked(row) {
             if (row.consumed) return
             if (!row.selected) this.toggleBatchRow(row, true)
             else row.expanded = !row.expanded
         },
         toggleAllBatch(event) { this.batchRows.filter(row => !row.consumed).forEach(row => this.toggleBatchRow(row, event.target.checked)) },
         selectedBatchCount() { return this.batchRows.filter(row => row.selected).length },
         selectableBatchCount() { return this.batchRows.filter(row => !row.consumed).length },
         batchRowReady(row) { return Boolean(row.title && row.lessonId && row.activityId && row.mode) },
         batchRowHasDraft(row) { return Boolean(row.title || row.lessonId || row.activityId || row.teacherIds.length || row.mode) },
         readyBatchCount() { return this.batchRows.filter(row => row.selected && this.batchRowReady(row)).length },
         batchNeedsLocation() { return this.batchRows.some(row => row.selected && (row.mode === 'offline' || row.mode === 'hybrid')) },
         batchRowState(row) {
             if (row.consumed) return @js(__('lf.LF_course_cohort_session_batch_created_state'));
             if (row.hasError) return @js(__('lf.LF_course_cohort_session_batch_error_state'));
             if (!row.selected) return @js(__('lf.LF_course_cohort_session_batch_select_to_configure'));
             return this.batchRowReady(row)
                 ? @js(__('lf.LF_course_cohort_session_batch_configured'))
                 : @js(__('lf.LF_course_cohort_session_batch_incomplete'));
         },
         batchRowTone(row) {
             if (row.consumed) return 'ui-status--completed'
             if (row.hasError) return 'ui-status--danger'
             if (!row.selected) return 'ui-status--archived'
             return this.batchRowReady(row) ? 'ui-status--active' : 'ui-status--inactive'
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
         openBatch() {
             this.batchSubmitting = false
             this.syncSelects(this.$refs.batchDialog)
             this.$nextTick(() => this.$refs.batchDialog?.showModal())
         },
         closeBatch() { if (this.$refs.batchDialog?.open) this.$refs.batchDialog.close() },
         submitBatch(event) {
             if (this.batchSubmitting) { event.preventDefault(); return }
             if (this.selectedBatchCount() === 0) {
                 event.preventDefault()
                 this.batchMessage = @js(__('lf.LF_course_cohort_session_batch_select_required'));
                 this.$nextTick(() => this.$refs.batchMessage?.focus())
                 return
             }
             const invalidRow = this.batchRows.find(row => row.selected && !this.batchRowReady(row))
             if (invalidRow) {
                 event.preventDefault()
                 invalidRow.expanded = true
                 this.batchMessage = @js(__('lf.LF_course_cohort_session_batch_complete_required'));
                 this.$nextTick(() => document.getElementById(`batch_lesson_${this.batchRows.indexOf(invalidRow)}-ui-kit`)?.focus())
                 return
             }
             if (this.batchNeedsLocation() && (!this.batchRoom.trim() || !this.batchAddress.trim())) {
                 event.preventDefault()
                 this.batchMessage = @js(__('lf.LF_course_cohort_session_batch_location_required'));
                 this.$nextTick(() => document.getElementById(this.batchRoom.trim() ? 'batch_address' : 'batch_room_name')?.focus())
                 return
             }
             this.batchMessage = ''
             this.batchRows.forEach(row => { row.teacherIds = [...new Set(row.teacherIds.map(String))] })
             this.batchSubmitting = true
         },
         curriculumMeetingUrl() {
             return this.activities.find(item => String(item.id) === String(this.activityId))?.live_class_url_snapshot || ''
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
         // Stored times are wall-clock in the session's own timezone: split the text, never go through the browser's zone.
         formatSessionDateTime(value) {
             const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/)
             return match ? `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}` : (value || '—')
         },
         copyCurriculumMeetingLink() {
             const url = this.meetingUrl
             if (!url) return
             navigator.clipboard.writeText(url).then(() => {
                 this.meetingLinkCopied = true
                 setTimeout(() => this.meetingLinkCopied = false, 1600)
             })
         },
         resetForm() {
             this.editingId = null; this.editingSession = null; this.sessionType = 'curriculum'; this.lessonId = ''; this.activityId = ''
             this.title = ''; this.titleDirty = false; this.mode = 'online'; this.teacherIds = []
             this.startsAt = ''; this.endsAt = ''; this.previousStart = ''; this.provider = ''; this.meetingUrl = ''; this.meetingDefault = ''
             this.roomName = ''; this.address = ''; this.submitting = false; this.meetingLinkCopied = false
             this.clearTimeError(this.$refs.sessionStart)
             this.clearTimeError(this.$refs.sessionEnd)
         },
         showSessionDialog() {
             this.previousStart = this.startsAt
             this.syncSelects(this.$refs.sessionDialog)
             this.$nextTick(() => this.$refs.sessionDialog?.showModal())
         },
         openCreate() {
             this.showFormErrors = false
             this.resetForm()
             this.showSessionDialog()
         },
         editSession(item) {
             this.showFormErrors = false
             this.resetForm()
             this.editingId = item.id; this.editingSession = item; this.sessionType = item.session_type
             this.lessonId = item.version_lesson_id ? String(item.version_lesson_id) : ''
             this.activityId = item.version_activity_id ? String(item.version_activity_id) : ''
             this.title = item.title; this.titleDirty = true; this.mode = item.delivery_mode
             this.teacherIds = [...new Set((item.teacher_ids || []).map(String))]
             this.startsAt = item.scheduled_start_at.slice(0, 16).replace(' ', 'T')
             this.endsAt = item.scheduled_end_at.slice(0, 16).replace(' ', 'T')
             this.provider = item.online_provider || ''; this.meetingUrl = item.meeting_url_snapshot || ''
             this.meetingDefault = this.curriculumMeetingUrl()
             this.roomName = item.room_name_snapshot || ''; this.address = item.address_snapshot || ''
             this.showSessionDialog()
         },
         closeForm() { if (this.$refs.sessionDialog?.open) this.$refs.sessionDialog.close() },
         sessionDialogClosed() { this.showFormErrors = false; this.resetForm() },
         switchType(type) {
             this.sessionType = type
             if (type === 'operational') {
                 this.lessonId = ''; this.activityId = ''; this.activityChanged()
                 if (!this.titleDirty) this.title = ''
                 this.syncSelects(this.$refs.sessionDialog)
             }
         },
         lessonChanged() {
             if (this.syncing) return
             this.activityId = ''
             this.activityChanged()
             const lesson = this.lessons.find(item => String(item.id) === String(this.lessonId))
             if (!this.titleDirty) this.title = lesson?.title_snapshot || ''
             this.syncSelects(this.$refs.sessionDialog)
         },
         activityChanged() {
             if (this.syncing) return
             // The Activity link is only a default: keep a link the admin typed.
             const next = this.curriculumMeetingUrl()
             if (!this.meetingUrl || this.meetingUrl === this.meetingDefault) this.meetingUrl = next
             this.meetingDefault = next
             this.meetingLinkCopied = false
         },
         submitForm(event) {
             if (this.submitting) { event.preventDefault(); return }
             if (!this.editingId && this.timesInvalid(this.$refs.sessionStart, this.$refs.sessionEnd, this.startsAt, this.endsAt)) {
                 event.preventDefault()
                 return
             }
             this.submitting = true
         },
         openDetail(item, trigger) {
             this.detailSession = item
             this.detailActions = @js($lifecycleActions)
                 .map(key => ({ key, url: trigger.getAttribute(`data-lifecycle-${key}`), ...this.lifecycleTexts[key] }))
                 .filter(action => action.url)
             this.$refs.detailDialog.showModal()
         },
         closeDetail() { this.$refs.detailDialog.close() },
         openLifecycle(action) {
             this.lifecycle = action
             this.lifecycleReason = ''
             this.lifecycleSubmitting = false
             this.$refs.lifecycleDialog.showModal()
         },
         closeLifecycle() { if (!this.lifecycleSubmitting) this.$refs.lifecycleDialog.close() },
         openReschedule(item) {
             this.showRescheduleErrors = false
             this.rescheduleSession = item
             this.rescheduleStart = item.scheduled_start_at.slice(0, 16).replace(' ', 'T')
             this.rescheduleEnd = item.scheduled_end_at.slice(0, 16).replace(' ', 'T')
             this.previousStart = this.rescheduleStart
             this.rescheduleReason = ''
             this.rescheduleStartError = ''
             this.clearTimeError(this.$refs.rescheduleStart)
             this.clearTimeError(this.$refs.rescheduleEnd)
             this.$refs.rescheduleDialog.showModal()
         },
         rescheduleStartChanged() {
             this.rescheduleEnd = this.movedEnd(this.previousStart, this.rescheduleStart, this.rescheduleEnd)
             this.previousStart = this.rescheduleStart
             this.rescheduleStartError = ''
             this.clearTimeError(this.$refs.rescheduleStart)
             this.clearTimeError(this.$refs.rescheduleEnd)
         },
         submitReschedule(event) {
             if (this.rescheduleSubmitting) { event.preventDefault(); return }
             this.rescheduleStartError = ''
             if (this.timesInvalid(this.$refs.rescheduleStart, this.$refs.rescheduleEnd, this.rescheduleStart, this.rescheduleEnd)) {
                 event.preventDefault()
                 return
             }
             const current = value => String(value || '').slice(0, 16).replace(' ', 'T')
             if (this.rescheduleStart === current(this.rescheduleSession?.scheduled_start_at)
                 && this.rescheduleEnd === current(this.rescheduleSession?.scheduled_end_at)) {
                 event.preventDefault()
                 this.timeError(this.$refs.rescheduleStart, @js(__('lf.LF_course_cohort_session_reschedule_unchanged')));
                 return
             }
             this.rescheduleSubmitting = true
         },
         assignTeacherSelectable(id) {
             if (!this.assignSession) return false
             const date = value => String(value).slice(0, 10)
             return this.teachers.some(teacher => teacher.id === String(id) && this.teacherFits(teacher, date(this.assignSession.scheduled_start_at), date(this.assignSession.scheduled_end_at)))
         },
         openAssign(item) {
             this.showAssignErrors = false
             this.assignSession = item
             this.assignTeacherIds = []
             this.syncSelects(this.$refs.assignDialog)
             this.$nextTick(() => this.$refs.assignDialog.showModal())
         },
         closeAssign() { if (this.$refs.assignDialog?.open) this.$refs.assignDialog.close() },
         closeReschedule() { if (this.$refs.rescheduleDialog?.open) this.$refs.rescheduleDialog.close() },
         rescheduleDialogClosed() {
             this.showRescheduleErrors = false
             this.rescheduleSession = null
             this.rescheduleStart = ''
             this.rescheduleEnd = ''
             this.rescheduleReason = ''
             this.rescheduleStartError = ''
         }
     }">
            <div class="ui-summary-head">
                <div>
                    <h2 id="cohort-sessions-title">{{ __('lf.LF_course_cohort_session_title') }}</h2>
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_session_help') }}</p>
                </div>
                <div class="mk-table-top__actions">
                    <span class="ui-caption ui-enroll-nowrap">{{ __('lf.LF_course_cohort_session_count', ['count' => $sessions->count()]) }}</span>
                    @if ($canBatchCreate)
                        <button type="button" class="ui-button ui-button--outline cursor-interaction" aria-haspopup="dialog" x-on:click="openBatch()">{{ __('lf.LF_course_cohort_session_batch_open') }}</button>
                    @endif
                    @if ($canCreateSession)
                        <button type="button" class="ui-button ui-button--outline cursor-interaction" aria-haspopup="dialog" x-on:click="openCreate()">{{ __('lf.LF_course_cohort_session_manual_open') }}</button>
                    @endif
                </div>
            </div>

            @if ($cohort->is_mutable && ! $sessionAcceptsNew)
                <div class="ui-banner ui-cohort-tab-notice" role="status">
                    <span class="ui-banner__text">{{ __('lf.LF_course_type_cohort_legacy_self_paced') }}</span>
                </div>
            @elseif ($cohort->is_mutable && ! $sessionWindow)
                <div class="ui-banner ui-cohort-tab-notice" role="status">
                    <span class="ui-banner__text">{{ __('lf.LF_course_cohort_session_schedule_cohort_period_required') }}</span>
                    <a class="ui-button ui-button--sm ui-button--outline cursor-interaction" href="{{ route('admin.course-cohorts.edit', $cohort->id) }}">{{ __('lf.LF_course_cohort_schedule_operation_action') }}</a>
                </div>
            @elseif ($cohort->is_mutable && ! $sessionWindowOpen)
                <div class="ui-banner ui-cohort-tab-notice" role="status">
                    <span class="ui-banner__text">{{ __('lf.LF_course_cohort_session_window_passed') }}</span>
                </div>
            @endif

            {{-- Lỗi không thuộc popup nào (lỗi lifecycle đã hiện ở đầu trang). --}}
            @if ($errors->any() && ! $batchHasErrors && ! $rescheduleHasErrors && ! $formHasErrors && ! $assignHasErrors && ! $errors->has('lifecycle'))
                <div class="ui-banner ui-cohort-tab-notice" role="alert"><span class="ui-banner__text">{{ $errors->first() }}</span></div>
            @endif

            @if ($canBatchCreate)
                @include('course-cohorts.partials.tabs.session-batch')
            @endif
            @if ($cohort->is_mutable)
                @include('course-cohorts.partials.tabs.session-form')
            @endif

            <div class="mk-table-scroll" role="region" aria-labelledby="cohort-sessions-title" tabindex="0">
                <table class="ui-cohort-session-table">
                    <colgroup>
                        <col class="mk-col-sequence">
                        <col>
                        <col class="ui-cohort-col-session-time">
                        <col class="ui-cohort-col-role">
                        <col class="mk-col-status">
                        <col class="mk-col-action-wide">
                    </colgroup>
                    <thead><tr>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_session_table_session') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_session_table_date_time') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_session_table_teachers') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_cohort_common_status') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($sessions as $session)
                        @php
                            $sessionStartsAt = \Illuminate\Support\Carbon::parse($session->scheduled_start_at);
                            $sessionEndsAt = \Illuminate\Support\Carbon::parse($session->scheduled_end_at);
                            $sessionContentDetails = collect($session->session_type === 'curriculum'
                                ? [$session->lesson_title, $session->activity_title]
                                : [])
                                ->filter()
                                ->reject(fn ($value) => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::squish($value)) === \Illuminate\Support\Str::lower(\Illuminate\Support\Str::squish($session->title)))
                                ->unique(fn ($value) => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::squish($value)));
                            $sessionTone = match ($session->status) { 'live' => 'active', 'completed' => 'completed', 'cancelled' => 'danger', 'no_show' => 'archived', default => 'draft' };
                            $sessionTeacherNames = $session->teacher_team->isNotEmpty() ? $session->teacher_team->pluck('name')->join(', ') : $session->primary_teacher_name;
                            $sessionEndsLifecycleOpen = in_array($session->status, ['scheduled', 'live'], true);
                            // Session status là thao tác vận hành nên chỉ mở khi lớp đang hoạt động.
                            $sessionLifecycle = $cohort->status !== 'active' ? [] : array_filter([
                                'start' => $session->status === 'scheduled',
                                'complete' => $session->status === 'live',
                                'cancel' => $sessionEndsLifecycleOpen,
                                'no-show' => $sessionEndsLifecycleOpen,
                            ]);
                        @endphp
                        <tr>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_session_table_session') }}">
                                <span class="mk-name-text">{{ $session->title }}</span>
                                @foreach ($sessionContentDetails as $contentDetail)
                                    <span class="ui-caption ui-framework-description">{{ $contentDetail }}</span>
                                @endforeach
                                <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_session_table_type_'.$session->session_type) }} · {{ __('lf.LF_course_cohort_session_mode_'.$session->delivery_mode) }} · {{ __('lf.LF_course_cohort_session_relation_'.$session->schedule_relation) }}</span>
                            </td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_session_table_date_time') }}">
                                @if ($sessionStartsAt->isSameDay($sessionEndsAt))
                                    <span class="ui-cohort-line ui-enroll-nowrap">{{ $sessionStartsAt->format('d/m/Y') }} · {{ $sessionStartsAt->format('H:i') }}–{{ $sessionEndsAt->format('H:i') }}</span>
                                @else
                                    <span class="ui-cohort-line ui-enroll-nowrap">{{ $sessionStartsAt->format('d/m/Y H:i') }}</span>
                                    <span class="ui-cohort-line ui-enroll-nowrap">→ {{ $sessionEndsAt->format('d/m/Y H:i') }}</span>
                                @endif
                                <span class="ui-caption ui-framework-description">{{ \Illuminate\Support\Str::ucfirst($sessionStartsAt->translatedFormat('l')) }}@if ($session->timezone && $session->timezone !== config('app.timezone')) · {{ $session->timezone }}@endif</span>
                            </td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_session_table_teachers') }}">
                                @if ($sessionTeacherNames)
                                    {{ $sessionTeacherNames }}
                                @else
                                    <span @class(['ui-caption', 'ui-cohort-session-unassigned' => $sessionEndsLifecycleOpen])>{{ __('lf.LF_course_cohort_session_batch_no_teacher') }}</span>
                                @endif
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_course_cohort_common_status') }}">
                                <span class="ui-status ui-status--{{ $sessionTone }}">{{ __('lf.LF_course_cohort_session_status_'.$session->status) }}</span>
                                @if ($session->is_past_due)
                                    <span class="ui-caption ui-framework-description ui-cohort-session-overdue">{{ __('lf.LF_course_cohort_session_past_due') }}</span>
                                @endif
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
                                        aria-label="{{ __('lf.LF_course_cohort_session_details') }}: {{ $session->title }}" data-tooltip="{{ __('lf.LF_course_cohort_session_details') }}"
                                        @foreach (array_keys($sessionLifecycle) as $action) data-lifecycle-{{ $action }}="{{ route('admin.course-cohorts.sessions.'.$action, [$cohort->id, $session->id]) }}" @endforeach
                                        x-on:click="openDetail(@js($session), $el)">
                                    <x-backend-icon name="eye" class="ui-icon" />
                                </button>
                                @if ($session->can_edit && $cohort->is_mutable)
                                    <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
                                            aria-label="{{ __('lf.LF_course_cohort_session_edit') }}: {{ $session->title }}" data-tooltip="{{ __('lf.LF_course_cohort_session_edit') }}"
                                            x-on:click="editSession(@js($session))">
                                        <x-backend-icon name="square-pen" class="ui-icon" />
                                    </button>
                                @endif
                                @if ($session->can_assign_teachers)
                                    <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
                                            aria-label="{{ __('lf.LF_course_cohort_session_assign_teachers') }}: {{ $session->title }}" data-tooltip="{{ __('lf.LF_course_cohort_session_assign_teachers') }}"
                                            x-on:click="openAssign(@js($session))">
                                        <x-backend-icon name="users" class="ui-icon" />
                                    </button>
                                @endif
                                @if ($session->status === 'scheduled' && $cohort->is_mutable)
                                    <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
                                            aria-label="{{ __('lf.LF_course_cohort_session_change_schedule') }}: {{ $session->title }}" data-tooltip="{{ __('lf.LF_course_cohort_session_change_schedule') }}"
                                            x-on:click="openReschedule(@js($session))">
                                        <x-backend-icon name="calendar" class="ui-icon" />
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="6">
                                <div role="status">
                                    <strong>{{ __('lf.LF_course_cohort_session_empty') }}</strong>
                                    <p class="ui-caption">{{ __('lf.LF_course_cohort_session_empty_help') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @include('course-cohorts.partials.tabs.session-detail')
            @if ($cohort->is_mutable)
                @include('course-cohorts.partials.tabs.session-reschedule')
                @include('course-cohorts.partials.tabs.session-assign-teachers')
            @endif
</section>
