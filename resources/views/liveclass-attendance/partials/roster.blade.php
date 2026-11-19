{{-- The roster of one Session with each learner's result, and the edit history under it. Shared by the Cohort
     attendance tab (administrator) and the teacher's attendance page. `$action` null means read-only.
     ADR-0002 § Attendance Policy And Recording Amendment, part B. --}}
@php
    $manualStatuses = \App\Services\LiveClassAttendanceRecorder::MANUAL_STATUSES;
    $entryLabel = function (object $row) use ($attendanceTool): ?string {
        if ($row->attendance_status === 'registered' && $row->joined_at) {
            // Entered, waiting for the content ticked "Tính vào điểm danh" (ADR-0022 Amendment 1.3, Đợt 3).
            return __('lf.LF_course_cohort_attendance_pending', ['time' => \Illuminate\Support\Carbon::parse($row->joined_at)->format('H:i')]);
        }
        if (! $row->attendance_status || $row->attendance_status === 'registered') {
            return null;
        }
        if ($row->attendance_source === 'makeup') {
            return __('lf.LF_course_cohort_attendance_source_makeup');
        }
        if ($row->attendance_source !== 'system') {
            return __('lf.LF_course_cohort_attendance_source_manual');
        }
        if (! $row->joined_at) {
            return __('lf.LF_course_cohort_attendance_source_auto_absent');
        }
        $time = \Illuminate\Support\Carbon::parse($row->joined_at)->format('H:i');

        return match (true) {
            // A self-study session: the moment the ticked content was done, or the learner's own button.
            $attendanceTool === \App\Services\LiveClassSelfStudySession::TYPE => __('lf.LF_course_cohort_attendance_checked_in_self_study', ['time' => $time]),
            $row->attendance_mode === 'online' => __('lf.LF_course_cohort_attendance_checked_in_online', ['time' => $time]),
            $attendanceTool === 'button' => __('lf.LF_course_cohort_attendance_checked_in_button', ['time' => $time]),
            default => __('lf.LF_course_cohort_attendance_checked_in_qr', ['time' => $time]),
        };
    };
    $modeLabel = fn (?string $mode): string => $mode ? __('lf.LF_course_cohort_session_mode_'.$mode) : '—';
@endphp
@if ($action)<form method="POST" action="{{ $action }}" data-attendance-form>@csrf @method('PUT')@endif
    <div class="mk-table-scroll ui-attendance-roster" role="region" aria-label="{{ __('lf.LF_course_cohort_attendance_title') }}" tabindex="0">
        <table>
            <colgroup>
                <col class="mk-col-sequence">
                <col>
                <col class="ui-attendance-col-status">
                <col class="ui-attendance-col-mode">
                <col>
            </colgroup>
            <thead><tr>
                <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_student_common_student') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_attendance_status') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_attendance_mode') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_attendance_notes') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($attendance as $index => $row)
                @php
                    $current = $row->attendance_status ?: 'registered';
                    $source = $entryLabel($row);
                @endphp
                <tr>
                    <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                    <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_student_common_student') }}">
                        <span class="mk-name-text">{{ $row->student_name }}</span>
                        <span class="ui-caption ui-framework-description">{{ $row->student_email }}</span>
                        @if ($source && ! ($row->outside ?? null))<span class="ui-caption ui-framework-description" @if ($row->joined_at && $row->attendance_source === 'system') data-attendance-checked-in @endif>{{ $source }}</span>@endif
                        @if ($action && ! ($row->outside ?? null))
                            <input type="hidden" name="attendance[{{ $index }}][enrollment_id]" value="{{ $row->enrollment_id }}">
                            <input type="hidden" name="attendance[{{ $index }}][loaded_status]" value="{{ $current }}">
                            <input type="hidden" name="attendance[{{ $index }}][loaded_mode]" value="{{ $row->attendance_mode }}">
                            <input type="hidden" name="attendance[{{ $index }}][loaded_notes]" value="{{ $row->notes }}">
                        @endif
                    </td>
                    @if ($row->outside ?? null)
                        {{-- ADR-0034: the Session is outside this learner's time in the class; it takes no result. --}}
                        <td class="mk-text-cell" colspan="3" data-label="{{ __('lf.LF_course_cohort_attendance_status') }}" data-attendance-outside="{{ $row->outside }}">
                            <span class="ui-caption">{{ __('lf.LF_course_cohort_attendance_outside_'.$row->outside) }}</span>
                        </td>
                    @elseif ($action)
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_attendance_status') }}">
                            <label class="ui-sr-only" for="attendance-status-{{ $index }}">{{ __('lf.LF_course_cohort_attendance_status') }}: {{ $row->student_name }}</label>
                            <select id="attendance-status-{{ $index }}" name="attendance[{{ $index }}][status]" data-ui-kit-select>
                                {{-- "Not recorded yet" is offered only while there is no result: a result cannot be taken back. --}}
                                @if ($current === 'registered')<option value="registered" selected>{{ __('lf.LF_course_cohort_attendance_status_registered') }}</option>@endif
                                @foreach ($manualStatuses as $status)
                                    <option value="{{ $status }}" @selected($current === $status)>{{ __('lf.LF_course_cohort_attendance_status_'.$status) }}</option>
                                @endforeach
                            </select>
                            @error("attendance.{$index}.status")<p class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_attendance_mode') }}">
                            <label class="ui-sr-only" for="attendance-mode-{{ $index }}">{{ __('lf.LF_course_cohort_attendance_mode') }}: {{ $row->student_name }}</label>
                            <select id="attendance-mode-{{ $index }}" name="attendance[{{ $index }}][attendance_mode]" data-ui-kit-select
                                    data-ui-clear-label="{{ __('lf.LF_course_cohort_attendance_mode_clear') }}">
                                <option value="">{{ __('lf.LF_course_cohort_attendance_mode_none') }}</option>
                                @foreach (['online', 'offline'] as $mode)
                                    <option value="{{ $mode }}" @selected($row->attendance_mode === $mode)>{{ __('lf.LF_course_cohort_session_mode_'.$mode) }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_attendance_notes') }}">
                            <input name="attendance[{{ $index }}][notes]" maxlength="1000" value="{{ $row->notes }}" placeholder="{{ __('lf.LF_course_cohort_attendance_notes_placeholder') }}" aria-label="{{ __('lf.LF_course_cohort_attendance_notes') }}: {{ $row->student_name }}">
                        </td>
                    @else
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_attendance_status') }}">{{ __('lf.LF_course_cohort_attendance_status_'.$current) }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_attendance_mode') }}">{{ $modeLabel($row->attendance_mode) }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_attendance_notes') }}">{{ $row->notes ?: '—' }}</td>
                    @endif
                </tr>
            @empty
                <tr class="mk-empty-row"><td class="mk-empty" colspan="5"><div role="status"><strong>{{ __('lf.LF_course_cohort_attendance_no_learners') }}</strong></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@if ($action)
    @if ($attendance->isNotEmpty())
        <div class="ui-attendance-actions">
            <button type="submit" class="ui-button ui-button--primary cursor-interaction">
                <x-backend-icon name="check" class="ui-icon" />
                {{ __('lf.LF_course_cohort_attendance_save') }}
            </button>
        </div>
    @endif
</form>
@endif

<details class="ui-attendance-history" @if ($history->isEmpty()) hidden @endif data-attendance-history>
    <summary>{{ trans_choice('lf.LF_course_cohort_attendance_history', $history->count(), ['count' => $history->count()]) }}</summary>
    <ul>
        @foreach ($history as $change)
            <li>
                <span class="ui-caption">{{ \Illuminate\Support\Carbon::parse($change->created_at)->format('d/m/Y H:i') }}</span>
                <b>{{ $change->student_name }}</b>:
                {{ $change->old_status ? __('lf.LF_course_cohort_attendance_status_'.$change->old_status) : __('lf.LF_course_cohort_attendance_status_registered') }}
                <span aria-hidden="true">→</span>
                {{ __('lf.LF_course_cohort_attendance_status_'.$change->new_status) }}
                @if ($change->old_attendance_mode !== $change->new_attendance_mode)
                    ({{ $modeLabel($change->old_attendance_mode) }} <span aria-hidden="true">→</span> {{ $modeLabel($change->new_attendance_mode) }})
                @endif
                · {{ $change->change_source === 'system' ? __('lf.LF_course_cohort_attendance_history_system') : ($change->actor_name ?? '—') }}
                @if ($change->notes)<span class="ui-caption">· {{ $change->notes }}</span>@endif
            </li>
        @endforeach
    </ul>
</details>
