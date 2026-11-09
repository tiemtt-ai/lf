{{-- A further session of an activity, such as a cancelled one: when, who, status and its actions. An activity's main session has only its actions, on the activity's own row. --}}
@php
    $slotProposal = $slotProposal ?? null;
    $sessionStartsAt = \Illuminate\Support\Carbon::parse($session->scheduled_start_at);
    $sessionEndsAt = \Illuminate\Support\Carbon::parse($session->scheduled_end_at);
    $sessionTone = match ($session->status) { 'live' => 'active', 'completed' => 'completed', 'cancelled' => 'danger', 'no_show' => 'archived', default => 'draft' };
    $sessionTeacherNames = $session->teacher_team->isNotEmpty() ? $session->teacher_team->pluck('name')->join(', ') : $session->primary_teacher_name;
    $sessionEndsLifecycleOpen = in_array($session->status, ['scheduled', 'live'], true);
@endphp
<div @class(['ui-cohort-session-tree__slot', 'ui-cohort-session-tree__slot--cancelled' => $session->status === 'cancelled']) @if ($slotProposal) data-proposed-activity="{{ $slotProposal['activity_id'] }}" @else data-session-id="{{ $session->id }}" @endif>
    <div class="ui-cohort-session-tree__when">
        @if ($showTitle)
            <span class="mk-name-text">{{ $session->title }}</span>
        @endif
        @if ($sessionStartsAt->isSameDay($sessionEndsAt))
            <span class="ui-enroll-nowrap ui-cohort-session-tree__time">{{ $sessionStartsAt->format('d/m/Y') }} · {{ $sessionStartsAt->format('H:i') }}–{{ $sessionEndsAt->format('H:i') }}</span>
        @else
            <span class="ui-enroll-nowrap ui-cohort-session-tree__time">{{ $sessionStartsAt->format('d/m/Y H:i') }} → {{ $sessionEndsAt->format('d/m/Y H:i') }}</span>
        @endif
        @if ($session->schedule_relation === 'rescheduled')
            @include('course-cohorts.partials.tabs.session-tree-date-edited')
        @endif
        <span class="ui-caption">{{ collect([
            \Illuminate\Support\Str::ucfirst($sessionStartsAt->translatedFormat('l')),
            __('lf.LF_course_cohort_session_mode_'.$session->delivery_mode),
            $session->timezone && $session->timezone !== config('app.timezone') ? $session->timezone : null,
        ])->filter()->implode(' · ') }}</span>
        @if ($session->status === 'cancelled' && filled($session->cancellation_reason ?? null))
            <span class="ui-caption" data-cancellation-reason>{{ __('lf.LF_course_cohort_session_status_reason') }}: {{ $session->cancellation_reason }}</span>
        @endif
    </div>
    <div class="ui-cohort-session-tree__teachers">
        @if ($sessionTeacherNames)
            {{ $sessionTeacherNames }}
        @else
            <span @class(['ui-caption', 'ui-cohort-session-unassigned' => $sessionEndsLifecycleOpen])>{{ __('lf.LF_course_cohort_session_batch_no_teacher') }}</span>
        @endif
    </div>
    <div class="ui-cohort-session-tree__status">
        <span class="ui-status ui-status--{{ $sessionTone }}">{{ __('lf.LF_course_cohort_session_status_'.$session->status) }}</span>
        @if ($session->is_past_due)
            <span class="ui-caption ui-cohort-session-overdue">{{ __('lf.LF_course_cohort_session_past_due') }}</span>
        @endif
    </div>
    <div class="ui-cohort-session-tree__actions">
        @include('course-cohorts.partials.tabs.session-tree-slot-actions')
    </div>
</div>
