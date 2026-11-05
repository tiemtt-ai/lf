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
    $formInlineKeys = ['title', 'version_lesson_id', 'version_activity_id', 'delivery_mode', 'teacher_ids', 'meeting_url', 'online_provider', 'room_name', 'address', 'scheduled_start_at', 'scheduled_end_at', 'reason'];
    // Popup của hoạt động chỉ hiện link (và ngày giờ khi chưa có ngày): lỗi của các ô đã ẩn phải lên đầu popup, không thì bấm Lưu mà không thấy gì.
    if ($formHasErrors && old('opened_version_activity_id') && old('opened_scope') !== 'lesson') {
        $formInlineKeys = old('original_start_at') ? ['meeting_url'] : ['meeting_url', 'scheduled_start_at', 'scheduled_end_at'];
    }
    $formSummaryErrors = $formHasErrors
        ? collect($errors->messages())->reject(fn ($messages, $key) => in_array($key, $formInlineKeys, true) || (in_array('teacher_ids', $formInlineKeys, true) && str_starts_with($key, 'teacher_ids.')))->flatten()
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
    // What the session form shows from the Template. Read only on the class: the Version is immutable.
    $yesNo = fn ($value): string => __($value ? 'lf.LF_course_template_lesson_common_yes' : 'lf.LF_course_template_lesson_common_no');
    $programmeContent = $versionActivities->mapWithKeys(fn (object $item): array => [$item->id => [
        'lesson' => (string) $versionLessons->firstWhere('id', $item->version_lesson_id)?->title_snapshot,
        'title' => $item->title_snapshot,
        'rows' => collect([
            $item->description_snapshot ? ['label' => __('lf.LF_course_template_activity_common_description'), 'value' => $item->description_snapshot, 'wide' => true] : null,
            ['label' => __('lf.LF_course_template_activity_learning_availability'), 'value' => collect(['anytime', 'before_session', 'during_session', 'after_session'])
                ->filter(fn (string $phase): bool => (bool) $item->{'available_'.$phase})
                ->map(fn (string $phase): string => __('lf.LF_course_template_activity_learning_availability_'.$phase))->implode(', ')],
            ['label' => __('lf.LF_course_template_activity_common_completion_rule'), 'value' => __('lf.LF_course_template_activity_common_completion_'.$item->completion_rule)],
            $item->estimated_duration_seconds_snapshot ? ['label' => __('lf.LF_course_template_activity_common_estimated_duration_minutes'), 'value' => __('lf.LF_course_template_activity_common_duration_minutes', ['minutes' => (int) ceil($item->estimated_duration_seconds_snapshot / 60)])] : null,
            ['label' => __('lf.LF_course_template_activity_common_preview'), 'value' => $yesNo($item->is_preview)],
            ['label' => __('lf.LF_course_template_activity_common_unlock_rule'), 'value' => __('lf.LF_course_template_activity_common_unlock_'.($item->unlock_rule_snapshot ?: 'none'))],
            $item->unlock_after_version_activity_id && $versionActivities->firstWhere('id', $item->unlock_after_version_activity_id) ? ['label' => __('lf.LF_course_template_activity_common_unlock_after_activity'), 'value' => $versionActivities->firstWhere('id', $item->unlock_after_version_activity_id)->title_snapshot] : null,
            ['label' => __('lf.LF_course_template_activity_common_required'), 'value' => $yesNo($item->is_required)],
        ])->filter()->values(),
    ]]);
    // The fields of the Template's lesson form, for the edit opened on a lesson row.
    $programmeLessons = $versionLessons->mapWithKeys(fn (object $item): array => [$item->id => collect([
        ['label' => __('lf.LF_course_template_lesson_common_name'), 'value' => $item->title_snapshot, 'wide' => true],
        ['label' => __('lf.LF_course_template_lesson_common_short_description'), 'value' => $item->short_description_snapshot ?: '—', 'wide' => true],
        ['label' => __('lf.LF_course_template_lesson_common_description'), 'value' => $item->description_snapshot ?: '—', 'wide' => true],
        ['label' => __('lf.LF_course_template_lesson_common_type'), 'value' => __('lf.LF_course_template_lesson_common_role_'.($item->lesson_type ?: 'regular'))],
        ['label' => __('lf.LF_course_template_lesson_common_preview'), 'value' => $yesNo($item->is_preview)],
        ['label' => __('lf.LF_course_template_lesson_common_unlock_rule'), 'value' => __('lf.LF_course_template_lesson_common_unlock_'.($item->unlock_rule_snapshot ?: 'none'))],
        $item->unlock_at_snapshot ? ['label' => __('lf.LF_course_template_lesson_common_unlock_at'), 'value' => \Illuminate\Support\Carbon::parse($item->unlock_at_snapshot)->format('d/m/Y H:i')] : null,
        $item->prerequisite_titles !== [] ? ['label' => __('lf.LF_course_template_lesson_common_unlock_after_lessons'), 'value' => implode(', ', $item->prerequisite_titles), 'wide' => true] : null,
    ])->filter()->values()]);
@endphp
<section class="mk-section course-cohort-sessions" aria-labelledby="cohort-sessions-title"
     x-data="{
         editingId: @js($formOldSession?->id), editingSession: @js($formOldSession), submitting: false, syncing: false, batchMessage: '', batchSubmitting: false,
         detailSession: null, detailActions: [], lifecycle: null, lifecycleReason: '', lifecycleSubmitting: false,
         showFormErrors: true, showRescheduleErrors: true, meetingLinkCopied: false, defaultTeachersPending: false, editingProgramme: @js($formHasErrors && (old('schedule_id') || old('opened_version_activity_id'))),
         programmeContentByActivity: @js($programmeContent),
         programmeLessonRows: @js($programmeLessons),
         formScope: @js($formHasErrors && old('opened_scope') === 'lesson' ? 'lesson' : 'activity'),
         lessonScope() { return this.formScope === 'lesson' && this.programmeForm() },
         activityScope() { return this.formScope !== 'lesson' && this.programmeForm() },
         meetingBeforeEdit: null,
         openMeetingEdit() {
             this.meetingBeforeEdit = this.meetingUrl
             this.meetingEditing = true
             this.$nextTick(() => this.$refs.programmeMeetingUrl?.focus())
         },
         closeMeetingEdit() {
             // A form reopened on a refused link has no earlier value here: go back to the saved one.
             this.meetingUrl = this.meetingBeforeEdit ?? (this.editingSession?.meeting_url_snapshot || this.curriculumMeetingUrl())
             this.meetingBeforeEdit = null
             this.meetingEditing = false
         },
         programmeForm() { return this.editingProgramme || this.editingSession?.session_type === 'curriculum' },
         programmeContent() { return this.programmeContentByActivity[this.activityId] || null },
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
         openedActivityId: @js($formHasErrors ? (string) old('opened_version_activity_id', '') : ''),
         activityId: @js($formHasErrors ? (string) old('version_activity_id', '') : ''),
         title: @js($formHasErrors ? old('title', '') : ''), titleDirty: @js($formHasErrors && old('title') !== null),
         mode: @js($formHasErrors ? old('delivery_mode', 'online') : 'online'),
         teacherIds: @js($formHasErrors ? $uniqueTeacherIds(old('teacher_ids', [])) : []),
         startsAt: @js($formHasErrors ? old('scheduled_start_at', '') : ''), endsAt: @js($formHasErrors ? old('scheduled_end_at', '') : ''),
         scheduleMin: @js($sessionWindow ? $sessionWindow['min']->format('Y-m-d\TH:i') : ''),
         scheduleMax: @js($sessionWindow ? $sessionWindow['max']->format('Y-m-d\TH:i') : ''),
         provider: @js($formHasErrors ? old('online_provider', '') : ''), meetingUrl: @js($formHasErrors ? old('meeting_url', '') : ''), meetingDefault: '', meetingEditing: @js($errors->has('meeting_url')),
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
         batchRows: @js($batchRows), batchMapped: 0,
         proposals: @js(array_values($contentTree['proposals'])),
         previewOpen: false, preview: { name: '', url: '', type: '', mimeType: '' },
         openVersionPreview(name, url, type, mimeType = '') { this.preview = { name, url, type, mimeType }; this.previewOpen = true },
         closeVersionPreview() { this.previewOpen = false; this.preview = { name: '', url: '', type: '', mimeType: '' } },
         scheduleOrigin: @js($formHasErrors && old('schedule_id') ? ['schedule_id' => old('schedule_id'), 'schedule_slot_id' => old('schedule_slot_id'), 'source_local_date' => old('source_local_date')] : null),
         originalStart: @js($formHasErrors ? old('original_start_at', '') : ''), originalEnd: @js($formHasErrors ? old('original_end_at', '') : ''),
         scheduleReason: @js($formHasErrors ? old('reason', '') : ''),
         teachers: @js($availableTeachers->map(fn ($teacher) => [
             'id' => (string) $teacher->id,
             'assigned_from' => $teacher->assigned_from,
             'assigned_to' => $teacher->assigned_to, 'name' => $teacher->name, 'phone' => $teacher->phone, 'email' => $teacher->email,
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
         selectedTeacherContacts() { return this.teachers.filter(teacher => this.teacherIds.map(String).includes(teacher.id)) },
         detailTeacherContacts() { const ids = (this.detailSession?.teacher_ids || []).map(String); return this.teachers.filter(teacher => ids.includes(teacher.id)) },
         syncTeacherAvailability() {
             const eligibleIds = this.selectableTeachers().map(teacher => teacher.id)
             // A new session starts with the class's teachers, once its date says who is available.
             if (this.defaultTeachersPending && this.startsAt && this.endsAt) { this.teacherIds = [...eligibleIds]; this.defaultTeachersPending = false }
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
         // The dates shown on the tree, ready to confirm together. Nothing is saved until the admin confirms the batch.
         mapBatch() {
             const taken = new Set(this.batchRows.filter(row => row.activityId).map(row => String(row.activityId)))
             const rowKey = row => [row.schedule_id, row.schedule_slot_id, row.source_local_date || row.date].join('|')
             this.batchMapped = 0
             this.proposals.filter(item => item.occurrence_key && !taken.has(item.activity_id)).forEach(item => {
                 const row = this.batchRows.find(candidate => rowKey(candidate) === item.occurrence_key && !candidate.consumed && !this.batchRowHasDraft(candidate))
                 if (!row) return
                 row.lessonId = item.lesson_id; row.activityId = item.activity_id; row.title = item.lesson_title; row.mode = 'online'
                 this.batchActivityChanged(row)
                 row.teacherIds = this.teachers.filter(teacher => this.batchTeacherSelectable(row, teacher.id)).map(teacher => teacher.id)
                 row.selected = true; row.expanded = false
                 this.batchMapped++
             })
         },
         openBatchMapped() {
             this.mapBatch()
             this.openBatch()
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
             this.editingId = null; this.editingSession = null; this.sessionType = 'curriculum'; this.lessonId = ''; this.activityId = ''; this.openedActivityId = ''
             this.title = ''; this.titleDirty = false; this.mode = 'online'; this.teacherIds = []
             this.startsAt = ''; this.endsAt = ''; this.previousStart = ''; this.provider = ''; this.meetingUrl = ''; this.meetingDefault = ''; this.meetingEditing = false; this.meetingBeforeEdit = null; this.formScope = 'activity'
             this.roomName = ''; this.address = ''; this.submitting = false; this.meetingLinkCopied = false
             this.scheduleOrigin = null; this.originalStart = ''; this.originalEnd = ''; this.scheduleReason = ''; this.defaultTeachersPending = false; this.editingProgramme = false
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
             this.defaultTeachersPending = true
             this.showSessionDialog()
         },
         editSession(item, scope = 'activity') {
             this.showFormErrors = false
             this.resetForm()
             this.formScope = scope
             this.editingId = item.id; this.editingSession = item; this.sessionType = item.session_type
             this.lessonId = item.version_lesson_id ? String(item.version_lesson_id) : ''
             this.activityId = item.version_activity_id ? String(item.version_activity_id) : ''
             this.openedActivityId = this.activityId
             this.title = item.title; this.titleDirty = true; this.mode = item.delivery_mode
             this.teacherIds = [...new Set((item.teacher_ids || []).map(String))]
             this.startsAt = item.scheduled_start_at.slice(0, 16).replace(' ', 'T')
             this.endsAt = item.scheduled_end_at.slice(0, 16).replace(' ', 'T')
             this.originalStart = this.startsAt; this.originalEnd = this.endsAt
             this.provider = item.online_provider || ''; this.meetingUrl = item.meeting_url_snapshot || ''
             this.meetingDefault = this.curriculumMeetingUrl()
             this.roomName = item.room_name_snapshot || ''; this.address = item.address_snapshot || ''
             this.showSessionDialog()
         },
         // Opens the lesson's session with its date from the Schedule. Changing the date keeps the occurrence and audits the move.
         openProposal(item, scope = 'activity') {
             this.showFormErrors = false
             this.resetForm()
             this.formScope = scope
             this.editingProgramme = true
             this.lessonId = item.lesson_id; this.activityId = item.activity_id; this.title = item.lesson_title
             this.openedActivityId = item.activity_id
             if (item.occurrence) {
                 const at = time => `${item.occurrence.date}T${String(time).slice(0, 5)}`
                 this.startsAt = this.originalStart = at(item.occurrence.start_time)
                 this.endsAt = this.originalEnd = at(item.occurrence.end_time)
                 this.scheduleOrigin = { schedule_id: item.occurrence.schedule_id, schedule_slot_id: item.occurrence.schedule_slot_id, source_local_date: item.occurrence.date }
             }
             this.activityChanged()
             this.defaultTeachersPending = true
             this.syncTeacherAvailability()
             this.showSessionDialog()
         },
         scheduleMoved() { return Boolean(this.originalStart) && (this.startsAt !== this.originalStart || this.endsAt !== this.originalEnd) },
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
             if (this.timesInvalid(this.$refs.sessionStart, this.$refs.sessionEnd, this.startsAt, this.endsAt)) {
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
                    <span class="ui-caption ui-enroll-nowrap">{{ __('lf.LF_course_cohort_session_count', ['count' => $sessions->count() + collect($contentTree['proposals'])->whereNotNull('occurrence')->count()]) }}</span>
                    @if ($canBatchCreate)
                        <button type="button" class="ui-button ui-button--outline cursor-interaction" aria-haspopup="dialog" x-on:click="openBatchMapped()">{{ __('lf.LF_course_cohort_session_batch_open') }}</button>
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

            @include('course-cohorts.partials.tabs.session-tree')

            @include('course-cohorts.partials.tabs.session-detail')
            @if ($cohort->is_mutable)
                @include('course-cohorts.partials.tabs.session-reschedule')
                @include('course-cohorts.partials.tabs.session-assign-teachers')
            @endif
</section>
