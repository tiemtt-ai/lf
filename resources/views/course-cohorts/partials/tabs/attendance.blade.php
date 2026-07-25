<div class="admin-card admin-form-card admin-form-surface">
    <div class="admin-form-standard">
        <section class="admin-form-standard-section">
            <header class="admin-form-section-header">
                <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_attendance_title') }}</h2>
                <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_attendance_help') }}</p>
            </header>
            <form method="GET" action="{{ route($routePrefix.'.show', $cohort->id) }}" class="admin-form-field-grid">
                <input type="hidden" name="tab" value="attendance">
                <div class="lf-form-group">
                    <x-form-label for="attendance_session_id" :value="__('lf.LF_course_cohort_attendance_session')" />
                    <select id="attendance_session_id" name="session_id" class="lf-form-control" onchange="this.form.submit()">
                        @foreach ($sessions as $session)<option value="{{ $session->id }}" @selected((int)$selectedSessionId === (int)$session->id)>#{{ $session->session_no }} · {{ $session->title }}</option>@endforeach
                    </select>
                </div>
            </form>
        </section>
        @if ($selectedSessionId)
            <section class="admin-form-standard-section">
                <form method="POST" action="{{ route('admin.course-cohorts.sessions.attendance', [$cohort->id, $selectedSessionId]) }}">
                    @csrf @method('PUT')
                    <div class="admin-table-wrap"><table class="table">
                        <thead><tr><th>{{ __('lf.LF_course_cohort_student_common_student') }}</th><th>{{ __('lf.LF_course_cohort_attendance_status') }}</th><th>{{ __('lf.LF_course_cohort_attendance_mode') }}</th><th>{{ __('lf.LF_course_cohort_attendance_notes') }}</th></tr></thead>
                        <tbody>
                        @foreach ($attendance as $index => $row)
                            <tr>
                                <td><strong>{{ $row->student_name }}</strong><br><span class="lf-secondary-text">{{ $row->student_email }}</span><input type="hidden" name="attendance[{{ $index }}][enrollment_id]" value="{{ $row->enrollment_id }}"></td>
                                <td><select name="attendance[{{ $index }}][status]" class="lf-form-control">@foreach(['registered','present','late','absent','excused'] as $status)<option value="{{ $status }}" @selected(($row->attendance_status ?: 'registered') === $status)>{{ __('lf.LF_course_cohort_attendance_status_'.$status) }}</option>@endforeach</select></td>
                                <td><select name="attendance[{{ $index }}][attendance_mode]" class="lf-form-control"><option value="">—</option><option value="online" @selected($row->attendance_mode === 'online')>{{ __('lf.LF_course_cohort_session_mode_online') }}</option><option value="offline" @selected($row->attendance_mode === 'offline')>{{ __('lf.LF_course_cohort_session_mode_offline') }}</option></select></td>
                                <td><input name="attendance[{{ $index }}][notes]" class="lf-form-control" value="{{ $row->notes }}"></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    <div class="admin-form-footer" data-actions-align="end"><div class="admin-form-footer-primary"><button type="submit" class="btn btn-primary">{{ __('lf.LF_course_cohort_attendance_save') }}</button></div></div>
                </form>
            </section>
        @else
            <div class="course-cohort-empty-state">{{ __('lf.LF_course_cohort_attendance_empty') }}</div>
        @endif
    </div>
</div>
