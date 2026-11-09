{{-- The lesson's date in its summary line: its first saved Session, otherwise its date from the Schedule. --}}
@php
    $treeLiveActivities = $lessonActivities->where('activity_type', 'live_class');
    $treeLessonSessions = $lessonActivities
        ->flatMap(fn (object $item) => $sessionTree['sessionsByActivity']->get($item->id, collect()))
        ->filter(fn (object $session): bool => \App\Services\CourseCohortContentTree::delivers($session))
        ->sortBy('scheduled_start_at')
        ->values();
    // A cancelled session before the lesson's current date makes that date a make-up (2026-11-09).
    $treeLessonCancelled = $treeLiveActivities
        ->flatMap(fn (object $item) => $sessionTree['sessionsByActivity']->get($item->id, collect()))
        ->where('status', 'cancelled')
        ->sortByDesc('scheduled_start_at')
        ->first();
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
    @if ($treeLessonSessions->isNotEmpty())
        {{-- How the lesson's session is delivered: in class, in class with remote learners, or online. --}}
        <span class="ui-session-mode ui-session-mode--{{ $treeLessonSessions->first()->delivery_mode }}" data-session-mode="{{ $treeLessonSessions->first()->delivery_mode }}">{{ __('lf.LF_course_cohort_session_mode_'.$treeLessonSessions->first()->delivery_mode) }}</span>
    @endif
    @if ($treeLessonCancelled && ($treeLessonSessions->isNotEmpty() || $treeLessonProposal))
        @php $makeupFor = \Illuminate\Support\Carbon::parse($treeLessonCancelled->scheduled_start_at); @endphp
        <span class="ui-status ui-status--draft ui-cohort-session-edited" data-session-makeup
              title="{{ __('lf.LF_course_cohort_session_makeup_for', ['date' => $makeupFor->format('d/m/Y').' · '.$makeupFor->format('H:i')]) }}">{{ __('lf.LF_course_cohort_session_makeup') }}</span>
    @endif
    @if ($treeLessonSessions->first()?->schedule_relation === 'rescheduled')
        @include('course-cohorts.partials.tabs.session-tree-date-edited', ['session' => $treeLessonSessions->first()])
    @endif
@endif
