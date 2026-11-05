{{-- The lesson's date in its summary line: its first saved Session, otherwise its date from the Schedule. --}}
@php
    $treeLiveActivities = $lessonActivities->where('activity_type', 'live_class');
    $treeLessonSessions = $lessonActivities
        ->flatMap(fn (object $item) => $sessionTree['sessionsByActivity']->get($item->id, collect()))
        ->filter(fn (object $session): bool => \App\Services\CourseCohortContentTree::delivers($session))
        ->sortBy('scheduled_start_at')
        ->values();
    $treeLessonProposal = $treeLiveActivities
        ->map(fn (object $item) => $sessionTree['proposals'][$item->id]['occurrence'] ?? null)
        ->filter()
        ->first();
@endphp
@if ($treeLiveActivities->isNotEmpty())
    <span data-lesson-date @class(['ui-cohort-session-unassigned' => $treeLessonSessions->isEmpty() && ! $treeLessonProposal])>
        <x-backend-icon name="calendar" class="ui-icon" />
        @if ($treeLessonSessions->isNotEmpty())
            @include('course-cohorts.partials.tabs.session-tree-date-range', ['rangeStart' => $treeLessonSessions->first()->scheduled_start_at, 'rangeEnd' => $treeLessonSessions->first()->scheduled_end_at])@if ($treeLessonSessions->count() > 1) · {{ __('lf.LF_course_cohort_session_count', ['count' => $treeLessonSessions->count()]) }}@endif
        @elseif ($treeLessonProposal)
            @include('course-cohorts.partials.tabs.session-tree-date-range', ['rangeStart' => $treeLessonProposal['date'].' '.$treeLessonProposal['start_time'], 'rangeEnd' => $treeLessonProposal['date'].' '.$treeLessonProposal['end_time']])
        @else
            {{ __('lf.LF_course_cohort_session_tree_unscheduled') }}
        @endif
    </span>
    @if ($treeLessonSessions->first()?->schedule_relation === 'rescheduled')
        @include('course-cohorts.partials.tabs.session-tree-date-edited', ['session' => $treeLessonSessions->first()])
    @endif
@endif
