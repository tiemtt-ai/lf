{{-- Cohort page tabs: a locked tab explains itself in the help line below instead of opening. Each tab describes itself in its own card head. --}}
@php
    $activeKey ??= 'overview';
    $tabLink ??= fn (array $tab): string => $tab['route'];
    // The overview note is a setup checklist: once a class left Draft, the overview only reports.
    $overviewNote = isset($cohort) && $cohort->status !== 'draft' ? __('lf.LF_course_cohort_tab_overview_running_note') : null;
@endphp
<div x-data="{ lockedReason: '' }"
     x-init="$nextTick(() => $refs.tabs.querySelector('[aria-current=page]')?.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'nearest' }))">
    <nav x-ref="tabs" @class(['course-template-tabs', 'course-cohort-detail-tabs', $navClass ?? null]) aria-label="{{ __('lf.LF_course_cohort_common_tabs') }}">
        @foreach ($cohortTabs as $tab)
            @continue(in_array($tab['key'], ['attendance', 'recordings'], true))
            <span class="sr-only">{{ $tab['key'] === 'overview' && $overviewNote ? $overviewNote : $tab['note'] }}</span>
            @if ($tab['accessible'] && $tab['route'])
                <a @class(['course-template-tab', 'is-active' => $activeKey === $tab['key']])
                   href="{{ $tabLink($tab) }}" @if($activeKey === $tab['key']) aria-current="page" @endif>
                    {{ $tab['label'] }}
                    @if ($tab['key'] === 'students' && isset($activeMembershipCount)) ({{ $activeMembershipCount }}/{{ $cohort->capacity ?? '∞' }}) @endif
                    @if ($tab['read_only'])
                        <x-backend-icon name="lock" class="course-cohort-detail-tab__lock-icon" />
                        <span class="sr-only">{{ __('lf.LF_course_cohort_tab_read_only') }}</span>
                    @endif
                </a>
            @elseif ($tab['accessible'])
                <button type="button" class="course-template-tab is-active" aria-current="page">{{ $tab['label'] }}</button>
            @else
                <button type="button" class="course-template-tab course-cohort-detail-tab--locked" aria-disabled="true"
                        title="{{ $tab['locked_reason'] }}"
                        x-on:click="lockedReason = @js($tab['locked_reason'])"
                        x-on:focus="lockedReason = @js($tab['locked_reason'])">
                    <span>{{ $tab['label'] }}</span>
                    <x-backend-icon name="lock" class="course-cohort-detail-tab__lock-icon" />
                </button>
                <span class="sr-only">{{ $tab['locked_reason'] }}</span>
            @endif
        @endforeach
    </nav>
    <p class="ui-caption course-cohort-detail-tabs-help" role="status" aria-live="polite" x-cloak x-show="lockedReason">
        <x-backend-icon name="info" class="ui-icon" aria-hidden="true" />
        <span x-text="lockedReason"></span>
    </p>
</div>
