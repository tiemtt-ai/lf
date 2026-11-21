{{-- The edit history of one Session's attendance, its own card under the roster card. Shared by the Cohort attendance
     tab and the teacher's attendance page; nothing renders until the Session has a change. --}}
@if ($history->isNotEmpty())
    @php
        $modeLabel = fn (?string $mode): string => $mode ? __('lf.LF_course_cohort_session_mode_'.$mode) : '—';
        $statusLabel = fn (?string $status): string => __('lf.LF_course_cohort_attendance_status_'.($status ?: 'registered'));
    @endphp
    <section class="mk-section ui-attendance-history" aria-labelledby="attendance-history-title" data-attendance-history>
        <div class="ui-summary-head">
            <div>
                <h2 id="attendance-history-title">{{ __('lf.LF_course_cohort_attendance_history_title') }}</h2>
                <p class="ui-caption">{{ __('lf.LF_course_cohort_attendance_history_help') }}</p>
            </div>
        </div>
        <div class="mk-table-scroll" role="region" aria-labelledby="attendance-history-title" tabindex="0">
            <table>
                <colgroup><col class="mk-col-sequence"><col class="ui-attendance-history-col-time"><col><col><col class="ui-attendance-history-col-actor"><col></colgroup>
                <thead><tr>
                    <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_attendance_history_time') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_student_common_student') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_attendance_history_change') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_attendance_history_actor') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_attendance_notes') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($history as $change)
                    <tr>
                        <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                        <td class="mk-text-cell ui-enroll-nowrap" data-label="{{ __('lf.LF_course_cohort_attendance_history_time') }}">{{ \Illuminate\Support\Carbon::parse($change->created_at)->format('d/m/Y H:i') }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_student_common_student') }}"><span class="mk-name-text">{{ $change->student_name }}</span></td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_attendance_history_change') }}">
                            {{ $statusLabel($change->old_status) }} <span aria-hidden="true">→</span> <b>{{ $statusLabel($change->new_status) }}</b>
                            @if ($change->old_attendance_mode !== $change->new_attendance_mode)
                                <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_attendance_mode') }}: {{ $modeLabel($change->old_attendance_mode) }} <span aria-hidden="true">→</span> {{ $modeLabel($change->new_attendance_mode) }}</span>
                            @endif
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_attendance_history_actor') }}">{{ $change->change_source === 'system' ? __('lf.LF_course_cohort_attendance_history_system') : ($change->actor_name ?? '—') }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_attendance_notes') }}">{{ $change->notes ?: '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
