@extends('layouts.tenant')

@section('title', $title.' | '.($tenant?->name ?? 'LF'))

@section('content')
    <header class="student-page-heading tenant-page-heading">
        <p class="student-eyebrow">{{ __('lf.LF_student_title_student_personalized_experience') }}</p>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
    </header>

    @isset($liveSessions)
        <section class="student-card student-section student-live-sessions" aria-labelledby="student-live-sessions-title">
            <div class="student-section-header">
                <h2 id="student-live-sessions-title" class="student-section-title">{{ __('lf.LF_liveclass_whiteboard_learner_live_title') }}</h2>
            </div>
            @if ($liveSessions->isEmpty())
                <p class="student-section-copy">{{ __('lf.LF_liveclass_whiteboard_learner_live_empty') }}</p>
            @else
                <ul class="student-live-list">
                    @foreach ($liveSessions as $session)
                        @php
                            $start = \Illuminate\Support\Carbon::parse($session->scheduled_start_at);
                            $end = \Illuminate\Support\Carbon::parse($session->scheduled_end_at);
                        @endphp
                        @php
                            $meetingUrl = \App\Services\LiveClassWhiteboardService::meetingUrl($session);
                            $boardUrl = route('student.live-sessions.board', $session->id);
                        @endphp
                        {{-- In class: check in with the QR code on the classroom screen. Online: the board and the meeting.
                             In class + remote: the learner says where they are first. --}}
                        <li class="student-live-item" data-live-session="{{ $session->id }}" data-live-mode="{{ $session->delivery_mode }}"
                            x-data="{ where: @js($session->delivery_mode === 'online' ? 'remote' : ($session->delivery_mode === 'offline' ? 'room' : '')) }">
                            <div class="student-live-copy">
                                <strong>{{ $session->title }}</strong>
                                <span>{{ $session->cohort_name }} · {{ $start->format('H:i') }}–{{ $end->format('H:i') }} · {{ __('lf.LF_course_cohort_session_mode_'.$session->delivery_mode) }}</span>
                            </div>
                            @if ($session->delivery_mode === 'hybrid')
                                <div class="student-live-where" role="group" aria-label="{{ __('lf.LF_liveclass_whiteboard_learner_where') }}">
                                    <button type="button" class="student-button is-outline" x-bind:aria-pressed="(where === 'room').toString()" x-on:click="where = 'room'" data-live-where="room">
                                        {{ __('lf.LF_liveclass_whiteboard_learner_in_room') }}
                                    </button>
                                    <button type="button" class="student-button is-outline" x-bind:aria-pressed="(where === 'remote').toString()" x-on:click="where = 'remote'" data-live-where="remote">
                                        {{ __('lf.LF_liveclass_whiteboard_learner_remote') }}
                                    </button>
                                </div>
                            @endif
                            <p class="student-live-hint" x-show="where === 'room'" @if ($session->delivery_mode !== 'offline') x-cloak @endif data-live-qr-hint>
                                {{ __('lf.LF_liveclass_whiteboard_learner_scan_qr') }}
                            </p>
                            @if ($session->delivery_mode !== 'offline')
                            <div class="student-live-actions" x-show="where === 'remote'" @if ($session->delivery_mode !== 'online') x-cloak @endif>
                                <a class="student-button" href="{{ $boardUrl }}" target="lf-whiteboard-{{ $session->id }}"
                                   onclick="event.preventDefault(); const w = window.open(this.href, this.target, 'popup,width=1280,height=800'); if (w) w.focus(); else window.location.href = this.href;">
                                    {{ __('lf.LF_liveclass_whiteboard_learner_view') }}
                                </a>
                                @if ($meetingUrl)
                                    <a class="student-button is-outline" href="{{ $meetingUrl }}" target="_blank" rel="noopener noreferrer" data-live-meeting>
                                        {{ __('lf.LF_liveclass_whiteboard_join_meeting') }}
                                    </a>
                                @endif
                            </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endisset

    <div class="tenant-feature-grid">
        @foreach ($items as $item)
            <article class="student-card student-section">
                <h2 class="student-section-title">{{ $item }}</h2>
                <p class="student-section-copy">{{ __('lf.LF_student_message_student_protected_area') }}</p>
            </article>
        @endforeach
    </div>
@endsection
