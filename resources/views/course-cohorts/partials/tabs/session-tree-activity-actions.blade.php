{{-- On a live activity's row, where the Content tab has its actions: view and edit its session or its date from the Schedule. --}}
@if ($activitySlots['session'])
    @include('course-cohorts.partials.tabs.session-tree-slot-actions', ['session' => $activitySlots['session'], 'slotProposal' => $activitySlots['proposal'], 'slotEditLabel' => __('lf.LF_course_cohort_session_edit_activity')])
@elseif ($activitySlots['proposal'] && $canCreateSession)
    <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
            aria-label="{{ __('lf.LF_course_cohort_session_edit_activity') }}: {{ $activity->title_snapshot }}" data-tooltip="{{ __('lf.LF_course_cohort_session_edit_activity') }}"
            x-on:click="openProposal(@js($activitySlots['proposal']))">
        <x-backend-icon name="square-pen" class="ui-icon" />
    </button>
@endif
