{{-- Below a live activity only its further sessions. Cancelled ones are history, grouped under "Buổi đã hủy": open when there is one,
     folded when there are several. The session the activity delivers is edited on the lesson row. --}}
@php
    $cancelledSlots = $activitySlots['others']->where('status', 'cancelled')->sortBy('scheduled_start_at')->values();
    $otherSlots = $activitySlots['others']->reject(fn (object $session): bool => $session->status === 'cancelled')->values();
@endphp
@if ($otherSlots->isNotEmpty() || $cancelledSlots->isNotEmpty())
    <div class="ui-cohort-session-tree__slots">
        @foreach ($otherSlots as $session)
            @include('course-cohorts.partials.tabs.session-tree-slot', ['showTitle' => false])
        @endforeach
        @if ($cancelledSlots->isNotEmpty())
            <details class="ui-cohort-session-cancelled" data-cancelled-sessions="{{ $cancelledSlots->count() }}" @if ($cancelledSlots->count() === 1) open @endif>
                <summary class="ui-cohort-session-cancelled__summary cursor-interaction">
                    <x-backend-icon name="chevron-right" class="ui-icon" />
                    {{ $cancelledSlots->count() > 1 ? __('lf.LF_course_cohort_session_cancelled_group_count', ['count' => $cancelledSlots->count()]) : __('lf.LF_course_cohort_session_cancelled_group') }}
                </summary>
                <div class="ui-cohort-session-tree__slots">
                    @foreach ($cancelledSlots as $session)
                        @include('course-cohorts.partials.tabs.session-tree-slot', ['showTitle' => false])
                    @endforeach
                </div>
            </details>
        @endif
    </div>
@endif
