{{-- Edit on the lesson row, where the Content tab has it: opens the session of the lesson's first live activity, or its date from the Schedule. Past sessions stay locked. --}}
@php
    $treeLessonLive = $lessonActivities->firstWhere('activity_type', 'live_class');
    $treeLessonOpen = $treeLessonLive && $cohort->is_mutable
        ? $sessionTree['sessionsByActivity']->get($treeLessonLive->id, collect())->first(fn (object $session): bool => $session->status === 'scheduled' && $session->can_edit)
        : null;
    $treeLessonProposed = $treeLessonLive && $canCreateSession ? ($sessionTree['proposals'][$treeLessonLive->id] ?? null) : null;
@endphp
@if ($treeLessonOpen || $treeLessonProposed)
    <div class="admin-table-actions">
        <button type="button" class="admin-link-button admin-text-action ui-row-action"
                aria-haspopup="dialog"
                aria-label="{{ __('lf.LF_course_template_lesson_common_edit') }}: {{ $lesson->title_snapshot }}"
                data-tooltip="{{ __('lf.LF_course_template_lesson_common_edit') }}"
                @if ($treeLessonOpen)
                    x-on:click="editSession(@js($treeLessonOpen))"
                @else
                    x-on:click="openProposal(@js($treeLessonProposed))"
                @endif>
            <x-backend-icon name="square-pen" class="ui-icon" />
            <span class="ui-sr-only">{{ __('lf.LF_course_template_lesson_common_edit') }}</span>
        </button>
    </div>
@endif
