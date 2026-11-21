{{-- Sessions of the learner's Cohorts they can act on now: board, meeting, the QR hint or the "Điểm danh" button
     (ADR-0002 § LiveClass Whiteboard Amendment and § Attendance Policy And Recording Amendment). A Session whose entry
     time has opened shows before the teacher starts; its board opens only once it is live. --}}
@php
    $entryFlash = session('lf_attendance_entry');
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
            $recordedLabel = \App\Support\LearnerAttendanceEntry::label($session->attendance ?? null);
            $flash = is_array($entryFlash) && (int) ($entryFlash['session'] ?? 0) === (int) $session->id ? $entryFlash : null;
        @endphp
        @if ($session->is_self_study ?? false)
            {{-- A self-study session, during its hours: its own button when nothing is ticked, otherwise the content decides
                 (ADR-0022 Amendment 1.3, Đợt 4, Bước 3). --}}
            <li class="lf-live" data-live-session="{{ $session->id }}" data-live-mode="self_study">
                <span class="lf-live__dot" aria-hidden="true"></span>
                <div class="lf-live__copy">
                    <h2>{{ __('lf.LF_liveclass_self_study_now_title') }}</h2>
                    <p><strong>{{ $session->title }}</strong> · {{ $session->cohort_name }} · {{ $start->format('H:i') }}–{{ $end->format('H:i') }}</p>
                </div>
                <div class="lf-live__actions">
                    <a class="lf-live__btn @if (! $entryButton) is-primary @endif" href="{{ route('student.courses.classes.show', $session->cohort_id) }}#live-lesson-{{ $session->id }}" data-live-self-study-lesson>
                        {{ __('lf.LF_liveclass_self_study_open_lesson') }}
                    </a>
                    {{-- Once a result is recorded the button goes; the line below says what was recorded. --}}
                    @if ($entryButton && $recordedLabel === null)
                        <form method="POST" action="{{ route('student.live-sessions.attend', $session->id) }}" data-live-attend>
                            @csrf
                            <button type="submit" class="lf-live__btn is-primary">{{ __('lf.LF_liveclass_attendance_entry_button') }}</button>
                        </form>
                    @endif
                </div>
                @if ($recordedLabel)
                    <p class="lf-live__hint" role="status" data-live-attendance>{{ $recordedLabel }}</p>
                @elseif ($flash && in_array($flash['result'] ?? '', ['too_early', 'too_late', 'not_offered', 'device_used'], true))
                    <p class="lf-live__hint" role="alert">{{ __('lf.LF_liveclass_attendance_entry_'.$flash['result']) }}</p>
                @elseif (! $entryButton)
                    <p class="lf-live__hint" role="status" data-live-self-study-content>{{ __('lf.LF_liveclass_self_study_content_hint', ['time' => $end->format('H:i')]) }}</p>
                @endif
            </li>
            @continue
        @endif
        {{-- In class: check in with the QR code on the classroom screen. Online: the board and the meeting.
             In class + remote: the learner says where they are first. --}}
        <li class="lf-live" data-live-session="{{ $session->id }}" data-live-mode="{{ $session->delivery_mode }}"
            x-data="{ where: @js($session->delivery_mode === 'online' ? 'remote' : ($session->delivery_mode === 'offline' ? 'room' : '')) }">
            <span class="lf-live__dot" aria-hidden="true"></span>
            <div class="lf-live__copy">
                <h2>{{ $isLive ? __('lf.LF_liveclass_whiteboard_learner_live_title') : __('lf.LF_liveclass_attendance_entry_upcoming_title') }}</h2>
                <p><strong>{{ $session->title }}</strong> · {{ $session->cohort_name }} · {{ $start->format('H:i') }}–{{ $end->format('H:i') }} · {{ __('lf.LF_course_cohort_session_mode_'.$session->delivery_mode) }}</p>
            </div>
            @include('tenant.my-learning.partials.live-session-actions')
        </li>
    @endforeach
</ul>
