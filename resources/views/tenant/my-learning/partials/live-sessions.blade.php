{{-- Sessions of the learner's Cohorts they can act on now: board, meeting, the QR hint or the "Điểm danh" button
     (ADR-0002 § LiveClass Whiteboard Amendment and § Attendance Policy And Recording Amendment). A Session whose entry
     time has opened shows before the teacher starts; its board opens only once it is live. --}}
@php
    $entryFlash = session('lf_attendance_entry');
    $attendanceLabel = function (?object $row): ?string {
        if (! $row || ($row->joined_at === null && $row->status === 'registered')) {
            return null;
        }
        $status = __('lf.LF_course_cohort_attendance_status_'.$row->status);
        if ($row->joined_at === null || ! $row->attendance_mode) {
            return __('lf.LF_liveclass_attendance_entry_result_manual', ['status' => $status]);
        }

        return __('lf.LF_liveclass_attendance_entry_result', [
            'status' => $status,
            'time' => \Illuminate\Support\Carbon::parse($row->joined_at)->format('H:i'),
            'mode' => __('lf.LF_liveclass_attendance_entry_mode_'.$row->attendance_mode),
        ]);
    };
@endphp
<ul class="lf-live-list" aria-label="{{ __('lf.LF_liveclass_whiteboard_learner_live_title') }}">
    @foreach ($liveSessions as $session)
        @php
            $start = \Illuminate\Support\Carbon::parse($session->scheduled_start_at);
            $end = \Illuminate\Support\Carbon::parse($session->scheduled_end_at);
            $meetingUrl = \App\Services\LiveClassWhiteboardService::meetingUrl($session);
            $boardUrl = route('student.live-sessions.board', $session->id);
            $isLive = $session->is_live ?? true;
            $entryMeeting = $session->entry_meeting ?? false;
            $entryButton = $session->entry_button ?? false;
            $recordedLabel = $attendanceLabel($session->attendance ?? null);
            $flash = is_array($entryFlash) && (int) ($entryFlash['session'] ?? 0) === (int) $session->id ? $entryFlash : null;
        @endphp
        {{-- In class: check in with the QR code on the classroom screen. Online: the board and the meeting.
             In class + remote: the learner says where they are first. --}}
        <li class="lf-live" data-live-session="{{ $session->id }}" data-live-mode="{{ $session->delivery_mode }}"
            x-data="{ where: @js($session->delivery_mode === 'online' ? 'remote' : ($session->delivery_mode === 'offline' ? 'room' : '')) }">
            <span class="lf-live__dot" aria-hidden="true"></span>
            <div class="lf-live__copy">
                <h2>{{ $isLive ? __('lf.LF_liveclass_whiteboard_learner_live_title') : __('lf.LF_liveclass_attendance_entry_upcoming_title') }}</h2>
                <p><strong>{{ $session->title }}</strong> · {{ $session->cohort_name }} · {{ $start->format('H:i') }}–{{ $end->format('H:i') }} · {{ __('lf.LF_course_cohort_session_mode_'.$session->delivery_mode) }}</p>
            </div>
            @if ($session->delivery_mode === 'hybrid')
                <div class="lf-live__where" role="group" aria-label="{{ __('lf.LF_liveclass_whiteboard_learner_where') }}">
                    <button type="button" x-bind:aria-pressed="(where === 'room').toString()" x-on:click="where = 'room'" data-live-where="room">
                        {{ __('lf.LF_liveclass_whiteboard_learner_in_room') }}
                    </button>
                    <button type="button" x-bind:aria-pressed="(where === 'remote').toString()" x-on:click="where = 'remote'" data-live-where="remote">
                        {{ __('lf.LF_liveclass_whiteboard_learner_remote') }}
                    </button>
                </div>
            @endif
            @if ($entryButton)
                {{-- In the room with the button as the Session's tool: the learner checks in here, no code to scan. --}}
                <form method="POST" action="{{ route('student.live-sessions.attend', $session->id) }}" class="lf-live__actions"
                      x-show="where === 'room'" @if ($session->delivery_mode !== 'offline') x-cloak @endif data-live-attend>
                    @csrf
                    <button type="submit" class="lf-live__btn is-primary" @disabled($recordedLabel !== null)>{{ __('lf.LF_liveclass_attendance_entry_button') }}</button>
                </form>
            @else
                <p class="lf-live__hint" x-show="where === 'room'" @if ($session->delivery_mode !== 'offline') x-cloak @endif data-live-qr-hint>
                    {{ __('lf.LF_liveclass_whiteboard_learner_scan_qr') }}
                </p>
            @endif
            @if ($session->delivery_mode !== 'offline')
                <div class="lf-live__actions" x-show="where === 'remote'" @if ($session->delivery_mode !== 'online') x-cloak @endif>
                    @if ($isLive)
                        <a class="lf-live__btn is-primary" href="{{ $boardUrl }}" target="lf-whiteboard-{{ $session->id }}"
                           onclick="event.preventDefault(); const w = window.open(this.href, this.target, 'popup,width=1280,height=800'); if (w) w.focus(); else window.location.href = this.href;">
                            {{ __('lf.LF_liveclass_whiteboard_learner_view') }}
                        </a>
                    @endif
                    @if ($meetingUrl && $entryMeeting)
                        {{-- The entry is recorded on the server first, then the meeting opens in its own tab. --}}
                        <form method="POST" action="{{ route('student.live-sessions.meeting', $session->id) }}" target="_blank" data-live-meeting-form>
                            @csrf
                            <button type="submit" class="lf-live__btn @if (! $isLive) is-primary @endif" data-live-meeting>{{ __('lf.LF_liveclass_whiteboard_join_meeting') }}</button>
                        </form>
                    @elseif ($meetingUrl && $isLive)
                        <a class="lf-live__btn" href="{{ $meetingUrl }}" target="_blank" rel="noopener noreferrer" data-live-meeting>
                            {{ __('lf.LF_liveclass_whiteboard_join_meeting') }}
                        </a>
                    @endif
                </div>
            @endif
            @if ($recordedLabel)
                <p class="lf-live__hint" role="status" data-live-attendance>{{ $recordedLabel }}</p>
            @elseif ($flash && in_array($flash['result'] ?? '', ['too_early', 'too_late', 'not_offered'], true))
                <p class="lf-live__hint" role="alert">{{ __('lf.LF_liveclass_attendance_entry_'.$flash['result']) }}</p>
            @elseif ($session->entry_warning ?? false)
                <p class="lf-live__hint" role="status" data-live-entry-warning>{{ __('lf.LF_liveclass_attendance_entry_warning') }}</p>
            @endif
        </li>
    @endforeach
</ul>
