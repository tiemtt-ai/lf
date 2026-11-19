{{-- Edit on the lesson row, where the Content tab has it: opens the session of the lesson's first live activity, or its date from the Schedule. Past sessions stay locked. --}}
@php
    $treeLessonLive = $lessonActivities->firstWhere('activity_type', 'live_class');
    $treeLessonOpen = $treeLessonLive && $cohort->is_mutable
        ? $sessionTree['sessionsByActivity']->get($treeLessonLive->id, collect())->first(fn (object $session): bool => $session->status === 'scheduled' && $session->can_edit)
        : null;
    $treeLessonProposed = $treeLessonLive && $canCreateSession ? ($sessionTree['proposals'][$treeLessonLive->id] ?? null) : null;
    // A self-study lesson has no form: its session is only moved or cancelled, here on its row (ADR-0023 §10).
    $treeSelfStudySession = \App\Support\LessonType::normalize($lesson->lesson_type ?? null) === \App\Support\LessonType::SELF_STUDY
        ? \App\Services\CourseCohortContentTree::slotsOfLesson($sessionTree, $lesson)['session']
        : null;
@endphp
@if ($treeSelfStudySession && $treeSelfStudySession->id)
    <div class="admin-table-actions" data-self-study-session="{{ $treeSelfStudySession->id }}">
        @include('course-cohorts.partials.tabs.session-tree-slot-actions', ['session' => $treeSelfStudySession, 'slotProposal' => null, 'slotEditable' => false])
    </div>
@endif
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
