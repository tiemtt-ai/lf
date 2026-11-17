<div class="admin-card admin-form-card admin-form-surface">
    <div class="admin-form-standard">
        <section class="admin-form-standard-section">
            <header class="admin-form-section-header">
                <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_attendance_title') }}</h2>
                <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_attendance_help') }}</p>
                @php
                    // The policy this class uses: its own, else the tenant default.
                    $attendancePolicy = $attendancePolicies->firstWhere('id', $cohort->attendance_policy_id) ?? $attendancePolicies->firstWhere('is_default', 1);
                @endphp
                @if ($attendancePolicy)
                    <p class="admin-form-section-help" data-attendance-policy>
                        {{ __('lf.LF_attendance_policy_in_use', ['name' => $attendancePolicy->name]) }}
                        {{ __('lf.LF_attendance_policy_summary_opens', ['value' => trans_choice('lf.LF_attendance_policy_minutes', (int) $attendancePolicy->opens_before_minutes, ['count' => (int) $attendancePolicy->opens_before_minutes])]) }}
                        · {{ __('lf.LF_attendance_policy_summary_late', ['value' => trans_choice('lf.LF_attendance_policy_minutes', (int) $attendancePolicy->late_after_minutes, ['count' => (int) $attendancePolicy->late_after_minutes])]) }}
                        · {{ __('lf.LF_attendance_policy_summary_absent', ['value' => $attendancePolicy->absent_after_minutes === null ? __('lf.LF_attendance_policy_unlimited') : trans_choice('lf.LF_attendance_policy_minutes', (int) $attendancePolicy->absent_after_minutes, ['count' => (int) $attendancePolicy->absent_after_minutes])]) }}.
                    </p>
                @endif
            </header>
            @if ($sessions->isNotEmpty())
                <div class="mk-table-top__actions">
                    <a class="ui-button ui-button--outline cursor-interaction" download
                       href="{{ route('admin.course-cohorts.attendance.export', $cohort->id) }}">
                        <x-backend-icon name="file-output" class="ui-icon" />
                        {{ __('lf.LF_export_attendance_excel') }}
                    </a>
                </div>
            @endif
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
                @include('liveclass-attendance.partials.roster', [
                    'attendance' => $attendance,
                    'history' => $attendanceHistory,
                    'attendanceTool' => $attendanceTool,
                    'action' => $cohort->status === 'active'
                        ? route('admin.course-cohorts.sessions.attendance', [$cohort->id, $selectedSessionId]) : null,
                ])
            </section>
        @else
            <div class="course-cohort-empty-state">{{ __('lf.LF_course_cohort_attendance_empty') }}</div>
        @endif
    </div>
</div>
