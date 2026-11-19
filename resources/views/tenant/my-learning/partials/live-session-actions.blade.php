{{-- What the learner can do for one Session open now: say where they are (hybrid), the "Điểm danh" button or the QR hint
     in the room, the board and the meeting from afar, then what was recorded. Used by the block of Sessions to act on now
     and by the class page board; the caller wraps it in an element whose `x-data` has `where`. --}}
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
        {{-- In the room with the button as the Session's tool: the learner checks in here, no code to scan.
             Once a result is recorded the button goes; the line below says what was recorded. --}}
        @if ($recordedLabel === null)
            <form method="POST" action="{{ route('student.live-sessions.attend', $session->id) }}" class="lf-live__actions"
                  x-show="where === 'room'" @if ($session->delivery_mode !== 'offline') x-cloak @endif data-live-attend>
                @csrf
                <button type="submit" class="lf-live__btn is-primary">{{ __('lf.LF_liveclass_attendance_entry_button') }}</button>
            </form>
        @endif
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
    @elseif ($flash && in_array($flash['result'] ?? '', ['too_early', 'too_late', 'not_offered', 'device_used'], true))
        <p class="lf-live__hint" role="alert">{{ __('lf.LF_liveclass_attendance_entry_'.$flash['result']) }}</p>
    @elseif ($session->entry_warning ?? false)
        <p class="lf-live__hint" role="status" data-live-entry-warning>{{ __('lf.LF_liveclass_attendance_entry_warning') }}</p>
    @endif
