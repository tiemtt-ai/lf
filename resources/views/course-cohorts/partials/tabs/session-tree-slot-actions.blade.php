{{-- What can be done with one session. $slotProposal is set when its date still comes from the Schedule; it opens in the same form. --}}
@php
    $slotProposal = $slotProposal ?? null;
    $slotEditLabel = $slotEditLabel ?? __('lf.LF_course_cohort_session_edit');
    $sessionEndsLifecycleOpen = in_array($session->status, ['scheduled', 'live'], true);
    // Session status là thao tác vận hành nên chỉ mở khi lớp đang hoạt động.
    $sessionLifecycle = $cohort->status !== 'active' || $slotProposal ? [] : array_filter([
        'start' => $session->status === 'scheduled',
        'complete' => $session->status === 'live',
        'cancel' => $sessionEndsLifecycleOpen,
        'no-show' => $sessionEndsLifecycleOpen,
    ]);
@endphp
<button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
        aria-label="{{ __('lf.LF_course_cohort_session_details') }}: {{ $session->title }}" data-tooltip="{{ __('lf.LF_course_cohort_session_details') }}"
        @foreach (array_keys($sessionLifecycle) as $action) data-lifecycle-{{ $action }}="{{ route('admin.course-cohorts.sessions.'.$action, [$cohort->id, $session->id]) }}" @endforeach
        @if ($cohort->status === 'active' && ! $slotProposal && $session->status === 'live') data-board-url="{{ route('admin.course-cohorts.sessions.board', [$cohort->id, $session->id]) }}" @endif
        x-on:click="openDetail(@js($session), $el)">
    <x-backend-icon name="eye" class="ui-icon" />
</button>
@if ($session->can_reschedule && ! $session->can_edit && $cohort->is_mutable)
    <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
            aria-label="{{ __('lf.LF_course_cohort_session_change_schedule') }}: {{ $session->title }}" data-tooltip="{{ __('lf.LF_course_cohort_session_change_schedule') }}"
            x-on:click="openReschedule(@js($session))">
        <x-backend-icon name="calendar" class="ui-icon" />
    </button>
@endif
@if ($slotProposal && $canCreateSession)
    <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
            aria-label="{{ $slotEditLabel }}: {{ $session->title }}" data-tooltip="{{ $slotEditLabel }}"
            x-on:click="openProposal(@js($slotProposal))">
        <x-backend-icon name="square-pen" class="ui-icon" />
    </button>
@endif
@if ($session->can_edit && $cohort->is_mutable)
    <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
            aria-label="{{ $slotEditLabel }}: {{ $session->title }}" data-tooltip="{{ $slotEditLabel }}"
            x-on:click="editSession(@js($session))">
        <x-backend-icon name="square-pen" class="ui-icon" />
    </button>
@endif
@if ($session->can_assign_teachers)
    <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
            aria-label="{{ __('lf.LF_course_cohort_session_assign_teachers') }}: {{ $session->title }}" data-tooltip="{{ __('lf.LF_course_cohort_session_assign_teachers') }}"
            x-on:click="openAssign(@js($session))">
        <x-backend-icon name="users" class="ui-icon" />
    </button>
@endif
