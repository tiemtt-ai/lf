@php
    // The policy this class uses: its own, else the tenant default.
    $attendancePolicy = $attendancePolicies->firstWhere('id', $cohort->attendance_policy_id) ?? $attendancePolicies->firstWhere('is_default', 1);
    $attendanceMinutes = fn (?int $value): string => $value === null
        ? __('lf.LF_attendance_policy_unlimited')
        : trans_choice('lf.LF_attendance_policy_minutes', $value, ['count' => $value]);
    // The primary teacher records attendance on Lớp của tôi with class.operate; here it reads.
    $attendanceEditable = $cohort->status === 'active' && ($cohortAdmin ?? true);
@endphp
<section class="mk-section ui-cohort-attendance" aria-labelledby="cohort-attendance-title">
    <div class="ui-summary-head">
        <div>
            <h2 id="cohort-attendance-title">{{ __('lf.LF_course_cohort_attendance_title') }}</h2>
            <p class="ui-caption">{{ __('lf.LF_course_cohort_attendance_help') }}</p>
            @if ($attendancePolicy)
                <p class="ui-caption" data-attendance-policy>
                    {{ __('lf.LF_attendance_policy_in_use', ['name' => $attendancePolicy->name]) }}
                    {{ __('lf.LF_attendance_policy_summary_opens', ['value' => $attendanceMinutes((int) $attendancePolicy->opens_before_minutes)]) }}
                    · {{ __('lf.LF_attendance_policy_summary_late', ['value' => $attendanceMinutes((int) $attendancePolicy->late_after_minutes)]) }}
                    · {{ __('lf.LF_attendance_policy_summary_absent', ['value' => $attendanceMinutes($attendancePolicy->absent_after_minutes === null ? null : (int) $attendancePolicy->absent_after_minutes)]) }}.
                </p>
            @endif
        </div>
        @if ($sessions->isNotEmpty())
            <div class="mk-table-top__actions">
                <a class="ui-button ui-button--outline cursor-interaction ui-enroll-nowrap" download
                   href="{{ route($routePrefix.'.attendance.export', $cohort->id) }}">
                    <x-backend-icon name="file-output" class="ui-icon" />
                    {{ __('lf.LF_export_attendance_excel') }}
                </a>
            </div>
        @endif
    </div>

    @if ($selectedSessionId)
        <form method="GET" action="{{ route($routePrefix.'.show', $cohort->id) }}" class="ui-field ui-attendance-session">
            <input type="hidden" name="tab" value="attendance">
            <label class="ui-field__label" for="attendance_session_id">{{ __('lf.LF_course_cohort_attendance_session') }}</label>
            <select id="attendance_session_id" name="session_id" data-ui-kit-select onchange="this.form.submit()">
                @foreach ($sessions as $session)
                    <option value="{{ $session->id }}" @selected((int) $selectedSessionId === (int) $session->id)>
                        @if ($session->scheduled_start_at ?? null){{ \Illuminate\Support\Carbon::parse($session->scheduled_start_at)->format('d/m/Y H:i') }} · @endif#{{ $session->session_no }} · {{ $session->title }}
                    </option>
                @endforeach
            </select>
        </form>

        @unless ($attendanceEditable)
            <div class="ui-banner ui-cohort-tab-notice" role="status">
                <span class="ui-banner__text">{{ __(($cohortAdmin ?? true) ? 'lf.LF_course_cohort_attendance_read_only' : 'lf.LF_course_cohort_attendance_read_only_teacher') }}</span>
            </div>
        @endunless

        @include('liveclass-attendance.partials.roster', [
            'attendance' => $attendance,
            'history' => $attendanceHistory,
            'attendanceTool' => $attendanceTool,
            'action' => $attendanceEditable ? route($routePrefix.'.sessions.attendance', [$cohort->id, $selectedSessionId]) : null,
        ])
    @else
        <div class="mk-empty" role="status">
            <strong>{{ __('lf.LF_course_cohort_attendance_empty') }}</strong>
            <p class="ui-caption">{{ __('lf.LF_course_cohort_attendance_empty_help') }}</p>
        </div>
    @endif
</section>
