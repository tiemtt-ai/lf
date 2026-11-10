{{-- Live Sessions of the learner's Cohorts: board, meeting, or the QR hint in the room (ADR-0002 § LiveClass Whiteboard Amendment).
     Shown in the "Buổi học đang diễn ra" block of the standard B2B site. --}}
<ul class="lf-live-list" aria-label="{{ __('lf.LF_liveclass_whiteboard_learner_live_title') }}">
    @foreach ($liveSessions as $session)
        @php
            $start = \Illuminate\Support\Carbon::parse($session->scheduled_start_at);
            $end = \Illuminate\Support\Carbon::parse($session->scheduled_end_at);
            $meetingUrl = \App\Services\LiveClassWhiteboardService::meetingUrl($session);
            $boardUrl = route('student.live-sessions.board', $session->id);
        @endphp
        {{-- In class: check in with the QR code on the classroom screen. Online: the board and the meeting.
             In class + remote: the learner says where they are first. --}}
        <li class="lf-live" data-live-session="{{ $session->id }}" data-live-mode="{{ $session->delivery_mode }}"
            x-data="{ where: @js($session->delivery_mode === 'online' ? 'remote' : ($session->delivery_mode === 'offline' ? 'room' : '')) }">
            <span class="lf-live__dot" aria-hidden="true"></span>
            <div class="lf-live__copy">
                <h2>{{ __('lf.LF_liveclass_whiteboard_learner_live_title') }}</h2>
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
            <p class="lf-live__hint" x-show="where === 'room'" @if ($session->delivery_mode !== 'offline') x-cloak @endif data-live-qr-hint>
                {{ __('lf.LF_liveclass_whiteboard_learner_scan_qr') }}
            </p>
            @if ($session->delivery_mode !== 'offline')
                <div class="lf-live__actions" x-show="where === 'remote'" @if ($session->delivery_mode !== 'online') x-cloak @endif>
                    <a class="lf-live__btn is-primary" href="{{ $boardUrl }}" target="lf-whiteboard-{{ $session->id }}"
                       onclick="event.preventDefault(); const w = window.open(this.href, this.target, 'popup,width=1280,height=800'); if (w) w.focus(); else window.location.href = this.href;">
                        {{ __('lf.LF_liveclass_whiteboard_learner_view') }}
                    </a>
                    @if ($meetingUrl)
                        <a class="lf-live__btn" href="{{ $meetingUrl }}" target="_blank" rel="noopener noreferrer" data-live-meeting>
                            {{ __('lf.LF_liveclass_whiteboard_join_meeting') }}
                        </a>
                    @endif
                </div>
            @endif
        </li>
    @endforeach
</ul>
