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
                        <li class="student-live-item" data-live-session="{{ $session->id }}">
                            <div class="student-live-copy">
                                <strong>{{ $session->title }}</strong>
                                <span>{{ $session->cohort_name }} · {{ $start->format('H:i') }}–{{ $end->format('H:i') }}</span>
                            </div>
                            <div class="student-live-actions">
                                @if ($meetingUrl = \App\Services\LiveClassWhiteboardService::meetingUrl($session))
                                    <a class="student-button is-outline" href="{{ $meetingUrl }}" target="_blank" rel="noopener noreferrer" data-live-meeting>
                                        {{ __('lf.LF_liveclass_whiteboard_join_meeting') }}
                                    </a>
                                @endif
                                <a class="student-button" href="{{ route('student.live-sessions.board', $session->id) }}" target="lf-whiteboard-{{ $session->id }}"
                                   onclick="event.preventDefault(); const w = window.open(this.href, this.target, 'popup,width=1280,height=800'); if (w) w.focus(); else window.location.href = this.href;">
                                    {{ __('lf.LF_liveclass_whiteboard_learner_view') }}
                                </a>
                            </div>
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
