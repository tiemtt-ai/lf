{{-- On a live activity's row: view its session (and start or finish it from there). The session is edited only on the lesson row,
     since a lesson holds one live session (2026-11-09); late teachers and a locked session's new time keep their own buttons. --}}
@if ($activitySlots['session'])
    @include('course-cohorts.partials.tabs.session-tree-slot-actions', ['session' => $activitySlots['session'], 'slotProposal' => $activitySlots['proposal'], 'slotEditable' => false])
@endif
