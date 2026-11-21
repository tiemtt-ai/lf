@extends('layouts.tenant')

{{-- The learner's class page, laid out as MK Live B2B "Chi tiết lớp học" (Owner 2026-11-19): header with the two progress
     bars and the next Session, the class summary, then every Session with its Lesson's contents split into before /
     during / after. Data and rules stay LF's: what opens when comes from the runtime access service, attendance from
     LiveClass. --}}
@php
    use Illuminate\Support\Carbon;

    $now = Carbon::now();
    $courseTitle = $class->product_title ?: $class->version_title;
    $weekday = fn (Carbon $at): string => __('lf.LF_my_learning_board_weekday_'.$at->dayOfWeek);
    $counted = $class->sessions->reject(fn (object $s): bool => in_array($s->status, ['cancelled', 'no_show'], true));
    $first = $counted->first();
    $last = $counted->last();
    $metaParts = array_filter([
        $class->teachers !== [] ? implode(', ', $class->teachers) : null,
        $counted->isNotEmpty() ? $counted->sortBy(fn (object $s): int => ($s->starts_at->dayOfWeek + 6) % 7)
            ->map(fn (object $s): string => $weekday($s->starts_at))->unique()->implode(', ') : null,
        $counted->isNotEmpty() && $counted->map(fn (object $s): string => $s->starts_at->format('H:i').$s->ends_at->format('H:i'))->unique()->count() === 1
            ? $first->starts_at->format('H:i').'~'.$first->ends_at->format('H:i') : null,
        $first && $last ? __('lf.LF_my_learning_board_weeks', ['weeks' => intdiv((int) $first->starts_at->copy()->startOfDay()->diffInDays($last->starts_at->copy()->startOfDay()), 7) + 1]) : null,
    ]);

    // Progress as MK Live reads it: the Sessions attended against all, and the Sessions already held against all.
    $currentProgress = $class->session_total > 0 ? (int) round(100 * $class->session_attended / $class->session_total) : 0;
    $recommendedProgress = (int) $class->schedule_rate;

    // An Enrollment that has run out or is suspended opens nothing ahead: no next Session, no countdown (display only).
    $hold = $class->access_hold ?? null;

    // The Session to point at: the one running now, otherwise the next one still ahead.
    $entry = $hold ? null : $class->sessions->first(fn (object $s): bool => ($s->shown_status ?? $s->status) === 'live' && $s->ends_at->gt($now)) ?? $class->next_session;
    $entryLive = $entry && ($entry->shown_status ?? $entry->status) === 'live';
    $entryBadge = $entryLive ? __('lf.LF_my_learning_board_entry_live')
        : ($entry && $entry->starts_at->isSameDay($now->copy()->setTimezone($entry->timezone)) ? __('lf.LF_my_learning_board_entry_today') : __('lf.LF_my_learning_board_entry_upcoming'));

    $kindLabel = function (object $activity, object $session): string {
        if ($activity->activity_type === 'live_class') {
            return __($session->delivery_mode === 'online' ? 'lf.LF_my_learning_board_kind_meeting' : 'lf.LF_my_learning_board_kind_onsite');
        }

        return __('lf.LF_my_learning_board_kind_'.$activity->activity_type);
    };
    $kindIcon = fn (object $activity): string => match ($activity->activity_type) {
        'video', 'embedded_video' => 'video',
        'audio' => 'audio',
        'quiz' => 'quiz',
        'live_class' => 'meeting',
        'external_link' => 'link',
        default => 'document',
    };
    $lockLabel = fn (object $activity): string => match (true) {
        $activity->reason === 'session_not_open' && $activity->unlock_at !== null => __('lf.LF_my_learning_board_opens_at', ['time' => $activity->unlock_at->format('H:i'), 'date' => $activity->unlock_at->format('d/m')]),
        $activity->reason === 'session_unscheduled' => __('lf.LF_my_learning_activity_lock_unscheduled'),
        $activity->reason === 'outside_cohort_period' => __('lf.LF_my_learning_activity_lock_period'),
        $activity->reason === 'prerequisite_incomplete' => __('lf.LF_my_learning_activity_lock_prerequisite'),
        default => __('lf.LF_my_learning_board_locked'),
    };
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $entryFlash = session('lf_attendance_entry');
@endphp

@section('title', $class->name.' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))

@section('content')
<div class="lf-class-detail standard-live-lesson-detail" data-learn-class="{{ $class->cohort_id }}"
     data-live-refresh-url="{{ route('student.live-sessions.now', ['cohort' => $class->cohort_id]) }}">
    <section class="lf-class-detail__main classroom-main">
        <a href="{{ route('student.courses.index', ['tab' => 'classes']) }}" class="classroom-back">← {{ __('lf.LF_my_learning_back_classes') }}</a>
        @include('tenant.my-learning.partials.learn-notice')

        <div class="classroom-header">
            <div class="classroom-course-meta">
                @if ($class->level || $class->category)
                    <span class="student-tag-primary">{{ $class->category ?: \App\Services\LearnerMyLearningService::levelLabel($class->level) }}</span>
                @endif
                @foreach ($class->delivery_modes as $mode)
                    <span class="student-tag-soft">{{ __('lf.LF_course_cohort_session_mode_'.$mode) }}</span>
                @endforeach
            </div>
            <h1 class="classroom-course-title" id="learn-class-title">{{ $class->name }}</h1>
            <p class="classroom-course-inst">
                @if ($courseTitle && $courseTitle !== $class->name){{ $courseTitle }} · @endif{{ implode(' · ', $metaParts) }}
            </p>

            <div class="classroom-progress-action-row">
                <div class="dual-progress-wrap">
                    <div class="dual-progress-row">
                        <div class="dp-label">📍 {{ __('lf.LF_my_learning_board_progress_current') }}</div>
                        <div class="dp-bar-wrap"><div class="dp-bar current" style="width: {{ $currentProgress }}%;"></div></div>
                        <div class="dp-value current" data-learn-progress-current>{{ $currentProgress }}%</div>
                    </div>
                    <div class="dual-progress-row">
                        <div class="dp-label">🎯 {{ __('lf.LF_my_learning_board_progress_recommended') }}</div>
                        <div class="dp-bar-wrap"><div class="dp-bar recommended" style="width: {{ $recommendedProgress }}%;"></div></div>
                        <div class="dp-value recommended" data-learn-progress-recommended>{{ $recommendedProgress }}%</div>
                    </div>
                    <div class="dp-legend">
                        <span><i class="dp-dot current"></i>{{ __('lf.LF_my_learning_board_progress_current_legend', ['attended' => $class->session_attended, 'total' => $class->session_total]) }}</span>
                        <span><i class="dp-dot recommended"></i>{{ __('lf.LF_my_learning_board_progress_recommended_legend') }}</span>
                    </div>
                    @if ($currentProgress < $recommendedProgress)
                        <div class="dp-warning">⚠️ {{ __('lf.LF_my_learning_board_progress_warning', ['gap' => $recommendedProgress - $currentProgress]) }}</div>
                    @endif
                </div>

                <div data-upcoming-lesson-card data-learn-next>
                    <div @class(['today-class-entry-card', 'is-empty' => ! $entry])>
                        @if ($entry)
                            <div class="today-class-entry-top">
                                <span @class(['today-class-entry-badge', 'today-class-entry-badge--ongoing' => $entryLive])>{{ $entryBadge }}</span>
                            </div>
                        @endif
                        <div class="today-class-entry-title">{{ $entry ? $entry->title : __('lf.LF_my_learning_class_next_none') }}</div>
                        @if ($entry)
                            <div class="today-class-entry-time">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>
                                <span>{{ $entry->starts_at->format('d/m/Y') }} · {{ __('lf.LF_my_learning_board_entry_starts', ['time' => $entry->starts_at->format('H:i')]) }}</span>
                            </div>
                            <div class="today-class-entry-footer">
                                <a class="today-class-lesson-link" href="#live-lesson-{{ $entry->id }}">
                                    <span>{{ __('lf.LF_my_learning_board_entry_jump') }}</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14m-5-5 5 5 5-5"/></svg>
                                </a>
                            </div>
                        @else
                            <div class="today-class-entry-time">{{ __($hold ? 'lf.LF_my_learning_board_entry_access_'.$hold : 'lf.LF_my_learning_board_entry_none_hint') }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="classroom-summary" data-learn-class-summary>
            <div class="classroom-summary__figure">
                <strong>{{ $class->session_held > 0 ? $class->attendance_rate.'%' : '-' }}</strong>
                <span>{{ __('lf.LF_my_learning_board_attendance_rate') }}</span>
                <small>@foreach ($class->attendance_counts as $state => $count){{ __('lf.LF_my_learning_board_count_'.$state) }} {{ $count }}@if (! $loop->last) · @endif @endforeach</small>
            </div>
            <div class="classroom-summary__figure" data-learn-class-completion
                 @if ($class->completion_rate !== null) x-data="lfLearnerCompletion(@js($class->completion_ids))" x-on:lf-learn-progress.window="record($event.detail)" @endif>
                <strong @if ($class->completion_rate !== null) x-text="percent + '%'" @endif>{{ $class->completion_rate === null ? '-' : $class->completion_rate.'%' }}</strong>
                <span>{{ __('lf.LF_my_learning_board_completion_rate') }}</span>
                <small @if ($class->completion_rate !== null) x-text="count + '/' + total" @endif>{{ $class->completion_done }}/{{ $class->completion_total }}</small>
            </div>
            <a class="classroom-summary__link" href="{{ route('student.courses.index', ['tab' => 'results', 'view' => 'classes']) }}">{{ __('lf.LF_my_learning_board_view_results') }} <span aria-hidden="true">→</span></a>
        </div>

        <h2 class="classroom-section-title" id="learn-schedule-title">📋 {{ __('lf.LF_my_learning_board_schedule') }}</h2>
        @if ($hold)
            <div class="classroom-review-only" role="status" data-learn-access-hold="{{ $hold }}">{{ match (true) {
                $hold === 'suspended' => __('lf.LF_my_learning_class_access_suspended_notice'),
                $class->access_ended_at !== null => __('lf.LF_my_learning_class_access_ended_notice', ['date' => $class->access_ended_at->format('d/m/Y')]),
                default => __('lf.LF_my_learning_class_access_ended_notice_undated'),
            } }}</div>
        @elseif ($class->cohort_status === 'completed')
            {{-- ADR-0022 Amendment 1.4: the class has ended; the learner only reviews. --}}
            <div class="classroom-review-only" role="status" data-learn-review-only>{{ __('lf.LF_my_learning_review_only') }}</div>
        @endif

        <div class="week-list">
            @forelse ($class->sessions as $session)
                @php
                    $void = in_array($session->status, ['cancelled', 'no_show'], true);
                    $shown = $session->shown_status ?? $session->status;
                    $live = $liveSessions->get($session->id);
                    $isLiveNow = $shown === 'live';
                    $upcoming = $shown === 'scheduled' && $session->starts_at->gt($now) && ! $hold;
                    $attendance = $session->attendance_status;
                    $outside = $session->membership_outside ?? null;
                    [$chipClass, $chipText] = match (true) {
                        $outside !== null => ['chip-pending', __('lf.LF_my_learning_attendance_'.$outside)],
                        $void => ['chip-pending', __('lf.LF_my_learning_session_status_'.$session->status)],
                        $session->attendance_pending ?? false => ['chip-pending', __('lf.LF_my_learning_attendance_pending')],
                        $session->attendance_makeup ?? false => ['chip-done', '✓ '.__('lf.LF_my_learning_board_chip_makeup')],
                        $attendance === 'present' => ['chip-done', '✓ '.__('lf.LF_my_learning_attendance_present')],
                        $attendance === 'late' => ['chip-late', '⏰ '.__('lf.LF_my_learning_attendance_late')],
                        $attendance === 'absent' => ['chip-absent', '⚠ '.__('lf.LF_my_learning_board_chip_absent')],
                        $attendance === 'excused' => ['chip-excused', __('lf.LF_my_learning_attendance_excused')],
                        default => [null, null],
                    };
                    $infoStatus = match (true) {
                        $void => __('lf.LF_my_learning_session_status_'.$session->status),
                        $isLiveNow => __('lf.LF_my_learning_session_status_live'),
                        $session->status === 'completed' || $session->ends_at->lte($now) => __('lf.LF_my_learning_board_session_ended'),
                        default => __('lf.LF_my_learning_board_session_upcoming'),
                    };
                    $entered = $session->attendance_entered_at ?? null;
                    $detail = match (true) {
                        $entered !== null => __('lf.LF_my_learning_board_entered', ['time' => $entered->format('H:i')])
                            .(in_array($session->attendance_mode, ['online', 'offline'], true) ? ' · '.__('lf.LF_my_learning_board_entered_'.$session->attendance_mode) : ''),
                        in_array($attendance, ['present', 'late', 'absent', 'excused'], true) && $session->attendance_source === 'manual' => __('lf.LF_my_learning_board_entered_manual'),
                        default => null,
                    };
                    $phases = ['before' => collect(), 'during' => collect(), 'after' => collect()];
                    foreach ($session->activities as $activity) {
                        $phases[$activity->phase === 'anytime' ? 'during' : $activity->phase]->push($activity);
                    }
                    // A Session with a delivery mode always shows its own class row, even when its Lesson's
                    // "Buổi học trực tiếp" is not on the board (the Lesson is closed or the Session has none).
                    if ($session->delivery_mode && $session->status !== 'cancelled' && ! $session->activities->contains('activity_type', 'live_class')) {
                        $phases['during']->prepend((object) [
                            'id' => 0, 'activity_type' => 'live_class', 'title' => $session->title, 'description' => null, 'phase' => 'during',
                            'is_required' => false, 'counts_for_attendance' => false, 'allowed' => true, 'progress_status' => 'not_started',
                            'progress' => 0, 'resume_at' => 0, 'progress_rule' => 'attended', 'duration_seconds' => 0,
                        ]);
                    }
                    $place = collect([$session->room_name_snapshot, $session->facility_name_snapshot, $session->address_snapshot])->filter()->implode(' · ');
                @endphp
                <div id="live-lesson-{{ $session->id }}" tabindex="-1"
                     @class(['week-item', 'completed' => $chipClass === 'chip-done', 'current-week' => $isLiveNow, 'lesson-attendance-absent' => $attendance === 'absent',
                             'is-lesson-today' => $session->starts_at->isSameDay($now->copy()->setTimezone($session->timezone)), 'is-counting-down' => $upcoming, 'is-live-now' => $isLiveNow])
                     data-learn-session="{{ $session->id }}" data-learn-session-status="{{ $session->status }}" data-learn-session-shown="{{ $shown }}">
                    <div class="week-item-header">
                        @if ($session->status !== 'cancelled')
                        <button type="button" class="lesson-content-toggle" data-lesson-toggle aria-expanded="true" aria-controls="lesson-content-{{ $session->id }}"
                                data-expand-label="{{ __('lf.LF_my_learning_board_expand') }}" data-collapse-label="{{ __('lf.LF_my_learning_board_collapse') }}"
                                aria-label="{{ __('lf.LF_my_learning_board_collapse') }}" title="{{ __('lf.LF_my_learning_board_collapse') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
                        @endif
                        <div @class(['week-badge', 'badge-absent' => $attendance === 'absent', 'badge-locked' => $upcoming])>
                            {{ __('lf.LF_my_learning_board_session_no', ['no' => $session->session_no ?: $loop->iteration]) }}
                        </div>
                        <div class="week-item-info">
                            <h3>{{ $session->title }}</h3>
                            <p>
                                {{ $weekday($session->starts_at) }} {{ $session->starts_at->format('H:i') }}~{{ $session->ends_at->format('H:i') }}
                                · {{ $session->starts_at->format('d/m/Y') }}
                                @if (! $session->delivery_mode) · {{ __('lf.LF_course_cohort_session_type_self_study') }} @endif
                                · {{ $infoStatus }}
                                @if ($session->status === 'cancelled' && $session->cancellation_reason) · {{ $session->cancellation_reason }} @endif
                            </p>
                        </div>
                        <div class="week-item-right">
                            @if ($session->attendance_requirements->isNotEmpty() && ! $void)
                                <span class="lesson-attendance-help" data-learn-attendance-help>
                                    <button type="button" class="lesson-attendance-help__label" aria-describedby="attendance-requirements-{{ $session->id }}"><svg class="lesson-attendance-help__icon" aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><circle cx="12" cy="7" r=".7" fill="currentColor" stroke="none"/></svg><span>{{ __('lf.LF_my_learning_board_conditions') }}</span></button>
                                    <span class="lesson-attendance-help__popup" role="tooltip" id="attendance-requirements-{{ $session->id }}">
                                        <strong>{{ __('lf.LF_my_learning_board_conditions') }}</strong>
                                        <span>{{ __('lf.LF_my_learning_board_conditions_hint') }}</span>
                                        <ul>
                                            @foreach ($session->attendance_requirements as $required)
                                                <li><b>{{ $required->title }}</b><small>{{ __('lf.LF_my_learning_board_phase_'.($required->phase === 'anytime' ? 'during' : $required->phase)) }} · {{ __('lf.LF_my_learning_rule_'.$required->progress_rule, ['percent' => (int) ($required->completion_threshold ?? 100)]) }}</small></li>
                                            @endforeach
                                        </ul>
                                    </span>
                                </span>
                            @endif
                            @if ($isLiveNow && $chipClass === null)
                                <div class="lesson-live-guidance">
                                    <div class="lesson-live-guidance__heading"><strong>{{ __('lf.LF_my_learning_session_status_live') }}</strong></div>
                                </div>
                            @elseif ($upcoming && $chipClass === null)
                                <button type="button" class="week-enter-btn locked js-lesson-countdown" disabled data-learn-countdown="{{ $session->starts_at->getTimestamp() }}"
                                        data-countdown-label="{{ __('lf.LF_my_learning_board_countdown', ['time' => '__TIME__']) }}">
                                    <span class="lesson-countdown-label">{{ __('lf.LF_my_learning_board_countdown', ['time' => '--:--:--']) }}</span>
                                </button>
                            @elseif ($chipClass !== null)
                                <span class="week-status-chip {{ $chipClass }}" data-learn-attendance="{{ $outside ?? ($attendance ?? 'none') }}" @if ($session->attendance_pending ?? false) data-learn-attendance-pending @endif>{{ $chipText }}</span>
                            @endif
                            @if ($detail && $outside === null)
                                <span class="lesson-attendance-detail" data-learn-attendance-entry>{{ $detail }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- A cancelled Session keeps its line in the schedule, without a board. --}}
                    @if ($session->status !== 'cancelled')
                    <div id="lesson-content-{{ $session->id }}" class="lesson-collapsible-content">
                        <div class="lesson-learning-flow-board">
                            @if ($session->completion)
                                @php
                                    $lessonDone = $session->completion['done'] >= $session->completion['total'];
                                    $completionConfig = $session->completion_ids + ['labels' => [
                                        'done' => '✓ '.__('lf.LF_my_learning_board_lesson_done'),
                                        'progress' => __('lf.LF_my_learning_board_lesson_progress'),
                                    ]];
                                @endphp
                                {{-- Content completed on the page counts at once (learner-lesson.js `learnerCompletion`). --}}
                                <div @class(['learning-board-progress', 'learning-board-progress--done' => $lessonDone]) data-learn-session-completion
                                     x-data="lfLearnerCompletion(@js($completionConfig))" x-on:lf-learn-progress.window="record($event.detail)"
                                     x-bind:class="{ 'learning-board-progress--done': finished }">
                                    <span x-text="text">{{ $lessonDone ? '✓ '.__('lf.LF_my_learning_board_lesson_done') : __('lf.LF_my_learning_board_lesson_progress', $session->completion) }}</span>
                                    <span class="learning-board-progress__bar" aria-hidden="true"><span style="width: {{ (int) round($session->completion['done'] * 100 / $session->completion['total']) }}%" x-bind:style="{ width: percent + '%' }"></span></span>
                                </div>
                            @endif
                            @foreach ($phases as $phase => $items)
                                @continue(in_array($phase, ['before', 'after'], true) && $items->isEmpty())
                                <section @class(['learning-board-phase', 'learning-board-phase--'.($phase === 'during' ? 'inside' : $phase), 'learning-board-phase--locked' => $items->isNotEmpty() && $items->every(fn ($a) => ! $a->allowed && $a->activity_type !== 'live_class')])
                                         aria-label="{{ __('lf.LF_my_learning_board_phase_'.$phase) }}">
                                    <div class="learning-board-phase__label">{{ __('lf.LF_my_learning_board_phase_'.$phase) }}</div>
                                    <div class="learning-board-phase__items">
                                        @forelse ($items as $activity)
                                            @php
                                                $isLiveClass = $activity->activity_type === 'live_class';
                                                $card = $activity->activity_type === 'quiz' ? $quizCards->get($activity->id) : null;
                                                $done = $activity->progress_status === 'completed';
                                                $state = match (true) {
                                                    $isLiveClass => $entered !== null || in_array($attendance, ['present', 'late'], true) ? __('lf.LF_my_learning_board_state_joined') : '',
                                                    $activity->activity_type === 'quiz' => match (true) {
                                                        ! $card || ($card->finished->isEmpty() && ! $card->open) => __('lf.LF_my_learning_board_state_quiz_none'),
                                                        $card->best && ! $card->best->verdict['passed'] => __('lf.LF_my_learning_board_state_quiz_failed', ['score' => $trim($card->best->score), 'max' => $trim($card->best->max_score)]),
                                                        $card->best !== null => __('lf.LF_my_learning_board_state_quiz_passed', ['score' => $trim($card->best->score), 'max' => $trim($card->best->max_score)]),
                                                        $card->finished->isNotEmpty() => __('lf.LF_my_learning_board_state_quiz_submitted'),
                                                        default => __('lf.LF_my_learning_board_state_quiz_doing'),
                                                    },
                                                    $activity->activity_type === 'audio' && $done => __('lf.LF_my_learning_board_state_listened'),
                                                    in_array($activity->activity_type, ['video', 'embedded_video'], true) && $done => __('lf.LF_my_learning_board_state_watched'),
                                                    $activity->progress_status !== 'not_started' && in_array($activity->activity_type, ['document', 'external_link', 'embedded_video'], true) => __('lf.LF_my_learning_board_state_opened'),
                                                    default => '',
                                                };
                                                $percent = in_array($activity->activity_type, ['video', 'audio'], true) && ! $done && ($activity->played ?? 0) > 0 ? $activity->played : 0;
                                                $locked = ! $isLiveClass && ! $activity->allowed;
                                                // MK: an uploaded video or audio card follows playback live ("Đã nghe 25%", then "Đã nghe").
                                                $liveCard = in_array($activity->activity_type, ['video', 'audio'], true) && ! $locked;
                                                // Every open card but a Quiz (its attempts decide) shows its state as the page records it, and
                                                // follows the other cards of the same Activity (a Lesson over several Sessions).
                                                $tracked = ! $isLiveClass && ! $locked && $activity->activity_type !== 'quiz';
                                                $stateLabels = [
                                                    'opened' => in_array($activity->activity_type, ['document', 'external_link', 'embedded_video'], true) ? __('lf.LF_my_learning_board_state_opened') : '',
                                                    'completed' => match ($activity->activity_type) {
                                                        'audio' => __('lf.LF_my_learning_board_state_listened'),
                                                        'video', 'embedded_video' => __('lf.LF_my_learning_board_state_watched'),
                                                        'document', 'external_link' => __('lf.LF_my_learning_board_state_opened'),
                                                        default => '',
                                                    },
                                                ];
                                                $config = [
                                                    'activityId' => (int) $activity->id,
                                                    'labels' => $stateLabels,
                                                    'status' => $activity->progress_status,
                                                    'percentage' => $activity->progress,
                                                    'resumeAt' => $activity->resume_at,
                                                    'duration' => (int) $activity->duration_seconds,
                                                    'playedSeconds' => $activity->played_seconds ?? 0,
                                                    'viewedAt' => ($activity->last_viewed_at ?? null)?->format('Y-m-d H:i'),
                                                    'tracksPosition' => in_array($activity->progress_rule, ['watch_percentage', 'listen_percentage'], true),
                                                    'durationKnown' => (int) $activity->duration_seconds > 0,
                                                    'progressUrl' => route('student.courses.activities.progress', [$class->enrollment_id, $activity->id]),
                                                    'mediaUrl' => route('student.courses.activities.media', [$class->enrollment_id, $activity->id]),
                                                    'csrf' => csrf_token(),
                                                    'messages' => ['failed' => __('lf.LF_my_learning_activity_failed')],
                                                ];
                                            @endphp
                                            <div @class(['learning-board-item', 'learning-board-item--unopened' => $locked, 'learning-board-item--locked' => $locked])
                                                 data-learn-activity="{{ $activity->id }}" data-learn-activity-type="{{ $activity->activity_type }}"
                                                 @unless ($isLiveClass || $locked) x-data="lfLearnerActivity(@js($config))" @endunless
                                                 @if ($tracked) x-on:lf-learn-progress.window="syncProgress($event.detail)" @endif>
                                                <span class="learning-board-item__kind">
                                                    @include('tenant.my-learning.partials.board-icon', ['icon' => $kindIcon($activity)]){{ $kindLabel($activity, $session) }}
                                                    @if ($tracked)
                                                        @if ($liveCard)
                                                            <span class="learning-board-item__progress" data-activity-state="progressing" x-show="cardPercent > 0" x-text="cardPercent + '%'" @if ($percent === 0) x-cloak @endif>{{ $percent > 0 ? $percent.'%' : '' }}</span>
                                                        @endif
                                                        <span class="learning-board-item__activity" x-bind:data-activity-state="completed ? 'completed' : 'progressing'" x-text="stateLabel" data-activity-state="{{ $done ? 'completed' : 'progressing' }}">{{ $state }}</span>
                                                    @else
                                                        @if ($percent > 0)<span class="learning-board-item__progress" data-activity-state="progressing">{{ $percent }}%</span>@endif
                                                        <span class="learning-board-item__activity" data-activity-state="{{ $done || $state === __('lf.LF_my_learning_board_state_joined') ? 'completed' : 'progressing' }}">{{ $state }}</span>
                                                    @endif
                                                </span>
                                                <div class="learning-board-item__body">
                                                    <strong class="learning-board-item__title">{{ $activity->title }}</strong>
                                                    @if ($activity->is_required || ($activity->counts_for_attendance && $phase !== 'before') || $isLiveClass)
                                                        <span class="learning-board-item__rules">
                                                            @if ($activity->is_required)
                                                                <span @class(['learning-board-item__rule', 'learning-board-item__rule--done' => $done])
                                                                      @if ($tracked) x-bind:class="{ 'learning-board-item__rule--done': completed }" x-text="completed ? @js('✓ '.__('lf.LF_my_learning_board_rule_done')) : @js(__('lf.LF_my_learning_activity_required'))" @endif>{{ $done ? '✓ '.__('lf.LF_my_learning_board_rule_done') : __('lf.LF_my_learning_activity_required') }}</span>
                                                            @endif
                                                            @if (($activity->counts_for_attendance && $phase !== 'before') || $isLiveClass)
                                                                <span class="learning-board-item__rule learning-board-item__rule--attendance" data-learn-counts-attendance>{{ __('lf.LF_my_learning_board_rule_attendance') }}</span>
                                                            @endif
                                                        </span>
                                                    @endif
                                                    @if ($isLiveClass)
                                                        @if ($entered !== null && $session->attendance_source !== 'manual')
                                                            <p class="learning-board-item__checkin learning-board-item__checkin--done">✓ {{ __('lf.LF_my_learning_board_checked_in_'.match (true) {
                                                                $session->attendance_mode === 'online' => 'meeting',
                                                                $session->attendance_mode === 'offline' => $session->attendance_tool === 'button' ? 'button' : 'qr',
                                                                default => 'other',
                                                            }, ['time' => $entered->format('H:i')]) }}</p>
                                                        @elseif ($live && $session->delivery_mode !== 'online' && ! ($live->entry_button ?? false))
                                                            <p class="learning-board-item__checkin">{{ __('lf.LF_liveclass_whiteboard_learner_scan_qr') }}</p>
                                                        @endif
                                                        <p class="learning-board-item__description">{{ __('lf.LF_my_learning_board_mode_'.$session->delivery_mode) }}</p>
                                                        @if ($session->delivery_mode !== 'online' && $place !== '')
                                                            <p class="learning-board-item__description">{{ __('lf.LF_my_learning_board_room', ['place' => $place]) }}</p>
                                                        @endif
                                                    @elseif ($activity->description)
                                                        <p class="learning-board-item__description">{{ $activity->description }}</p>
                                                    @endif
                                                    @unless ($isLiveClass || $locked)
                                                        <p class="learning-board-item__checkin learning-board-item__checkin--late" x-show="error" x-text="error" x-cloak role="alert"></p>
                                                    @endunless
                                                </div>
                                                <div class="learning-board-item__action">
                                                    @if ($isLiveClass)
                                                        @if ($live)
                                                            @php
                                                                $isLive = $live->is_live ?? true;
                                                                $entryMeeting = $live->entry_meeting ?? false;
                                                                $entryButton = $live->entry_button ?? false;
                                                                $recordedLabel = \App\Support\LearnerAttendanceEntry::label($live->attendance ?? null);
                                                                $flash = is_array($entryFlash) && (int) ($entryFlash['session'] ?? 0) === (int) $live->id ? $entryFlash : null;
                                                                $meetingUrl = \App\Services\LiveClassWhiteboardService::meetingUrl($live);
                                                                $boardUrl = route('student.live-sessions.board', $live->id);
                                                            @endphp
                                                            <div class="lf-board-live" data-live-session="{{ $live->id }}" data-live-mode="{{ $live->delivery_mode }}"
                                                                 x-data="{ where: @js($live->delivery_mode === 'online' ? 'remote' : ($live->delivery_mode === 'offline' ? 'room' : '')) }">
                                                                @include('tenant.my-learning.partials.live-session-actions', ['session' => $live])
                                                            </div>
                                                        @elseif ($void)
                                                            <span class="learning-board-item__locked learning-board-item__ended">{{ __('lf.LF_my_learning_session_status_'.$session->status) }}</span>
                                                        @elseif ($session->status === 'completed' || $session->ends_at->lte($now))
                                                            <span class="learning-board-item__locked learning-board-item__ended">@include('tenant.my-learning.partials.board-icon', ['icon' => 'ended']){{ __('lf.LF_my_learning_board_live_ended') }}</span>
                                                        @elseif ($hold)
                                                            <span class="learning-board-item__locked" data-learn-locked>{{ __('lf.LF_my_learning_class_access_'.$hold) }}</span>
                                                        @else
                                                            <span class="learning-board-item__locked">{{ __('lf.LF_my_learning_board_opens_at', ['time' => $session->starts_at->format('H:i'), 'date' => $session->starts_at->format('d/m')]) }}</span>
                                                        @endif
                                                    @elseif ($locked)
                                                        <span class="learning-board-item__locked" data-learn-locked>{{ $lockLabel($activity) }}</span>
                                                    @elseif ($activity->activity_type === 'document')
                                                        <button type="button" x-on:click="openDocument()" data-learn-open-document>@include('tenant.my-learning.partials.board-icon', ['icon' => 'document']){{ __('lf.LF_my_learning_board_open_content') }}</button>
                                                    @elseif ($activity->activity_type === 'external_link')
                                                        @if ($activity->link_url)
                                                            <a href="{{ $activity->link_url }}" target="_blank" rel="noopener noreferrer" x-on:click="openLink()" data-learn-open-link>@include('tenant.my-learning.partials.board-icon', ['icon' => 'link']){{ __('lf.LF_my_learning_activity_open_link') }}</a>
                                                        @else
                                                            <span class="learning-board-item__locked learning-board-item__unavailable">{{ __('lf.LF_my_learning_activity_unavailable') }}</span>
                                                        @endif
                                                    @elseif (in_array($activity->activity_type, ['video', 'audio', 'embedded_video'], true))
                                                        @php $isAudio = $activity->activity_type === 'audio'; @endphp
                                                        @if ($activity->activity_type === 'embedded_video' && ! $activity->embed_url)
                                                            <span class="learning-board-item__locked learning-board-item__unavailable">{{ __('lf.LF_my_learning_activity_unavailable') }}</span>
                                                        @else
                                                            <button type="button" aria-haspopup="dialog" data-learn-play
                                                                    x-on:click="$refs.dialog.showModal(); {{ match ($activity->activity_type) { 'embedded_video' => 'showEmbed()', 'video' => 'openMedia()', default => 'if (! started) play()' } }}">@include('tenant.my-learning.partials.board-icon', ['icon' => $isAudio ? 'audio' : 'video']){{ __($isAudio ? 'lf.LF_my_learning_board_open_audio' : 'lf.LF_my_learning_board_open_video') }}</button>
                                                            <dialog x-ref="dialog" @class(['learning-video-dialog', 'learning-audio-dialog' => $isAudio, 'learning-upload-video-dialog' => ! $isAudio])
                                                                    aria-labelledby="learning-media-title-{{ $session->id }}-{{ $activity->id }}"
                                                                    x-on:close="$refs.player && $refs.player.pause()" x-on:click.self="$refs.dialog.close()">
                                                                <header class="learning-video-dialog__header">
                                                                    <h2 id="learning-media-title-{{ $session->id }}-{{ $activity->id }}">{{ $activity->title }}</h2>
                                                                    <button type="button" data-learning-video-close x-on:click="$refs.dialog.close()" aria-label="{{ __('lf.LF_my_learning_board_close') }}"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                                                                </header>
                                                                @if ($activity->activity_type === 'embedded_video')
                                                                    <template x-if="started">
                                                                        <div class="lf-learn-embed"><iframe src="{{ $activity->embed_url }}" title="{{ $activity->title }}" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>
                                                                    </template>
                                                                @elseif ($isAudio)
                                                                    <audio x-ref="player" controls preload="none" x-bind:src="src || false" x-on:timeupdate="onTime()" x-on:pause="onPause()" x-on:ended="onEnded()" x-on:error="onError()"></audio>
                                                                @else
                                                                    {{-- MK uploaded-video popup: a video stopped part-way asks "Tiếp tục xem / Xem từ đầu"; the
                                                                         footer shows the last opening and "Tiến độ xem: hh:mm:ss ~ %". Full screen keeps the footer. --}}
                                                                    <div class="learning-upload-video-player" x-ref="screen">
                                                                        <div class="learning-upload-video-frame">
                                                                            <video x-ref="player" controls playsinline preload="none" x-bind:src="src || false" x-on:timeupdate="onTime()" x-on:pause="onPause()" x-on:ended="onEnded()" x-on:error="onError()"></video>
                                                                            <div class="learning-upload-resume" x-show="asking" x-cloak data-upload-resume>
                                                                                <div class="learning-upload-resume-card" role="group" aria-labelledby="learning-resume-{{ $session->id }}-{{ $activity->id }}">
                                                                                    <span class="learning-upload-resume-icon" aria-hidden="true">▶</span>
                                                                                    <h3 id="learning-resume-{{ $session->id }}-{{ $activity->id }}">{{ __('lf.LF_my_learning_detail_resume_question') }}</h3>
                                                                                    <p><strong x-text="resumeClock">{{ sprintf('%02d:%02d:%02d', intdiv((int) $activity->resume_at, 3600), intdiv((int) $activity->resume_at % 3600, 60), (int) $activity->resume_at % 60) }}</strong></p>
                                                                                    <div>
                                                                                        <button type="button" data-upload-restart x-on:click="startOver()">{{ __('lf.LF_my_learning_detail_restart') }}</button>
                                                                                        <button type="button" data-upload-continue x-on:click="continuePlay()">{{ __('lf.LF_my_learning_detail_continue') }}</button>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                        <footer class="learning-upload-video-footer">
                                                                            <div class="learning-upload-video-progress">
                                                                                <time x-show="viewed" x-text="viewed" @unless ($activity->last_viewed_at ?? null) x-cloak @endunless>{{ ($activity->last_viewed_at ?? null)?->format('Y-m-d H:i') }}</time>
                                                                                <strong>{{ __('lf.LF_my_learning_board_video_progress') }}: <span x-text="watchedClock" data-upload-watched-time>{{ sprintf('%02d:%02d:%02d', intdiv((int) ($activity->played_seconds ?? 0), 3600), intdiv((int) ($activity->played_seconds ?? 0) % 3600, 60), (int) ($activity->played_seconds ?? 0) % 60) }}</span> ~ <span x-text="watchedPercent + '%'" data-upload-video-percent>{{ $activity->played ?? 0 }}%</span></strong>
                                                                            </div>
                                                                            <div class="learning-upload-video-actions">
                                                                                <button type="button" class="learning-upload-restart" data-upload-restart x-on:click="startOver()">{{ __('lf.LF_my_learning_detail_restart') }}</button>
                                                                                <button type="button" data-upload-video-fullscreen x-on:click="toggleFullscreen()" aria-label="{{ __('lf.LF_my_learning_detail_fullscreen') }}" title="{{ __('lf.LF_my_learning_detail_fullscreen') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H3v5M16 3h5v5M21 16v5h-5M8 21H3v-5"/></svg></button>
                                                                            </div>
                                                                        </footer>
                                                                    </div>
                                                                @endif
                                                                <p class="learning-video-dialog__error" x-show="error" x-text="error" x-cloak></p>
                                                            </dialog>
                                                        @endif
                                                    @elseif ($activity->activity_type === 'quiz')
                                                        @if (! $card || ! $card->version)
                                                            <span class="learning-board-item__locked learning-board-item__unavailable">{{ __('lf.LF_my_learning_activity_unavailable') }}</span>
                                                        @else
                                                            @php $lastFinished = $card->finished->last(); @endphp
                                                            @if ($lastFinished)
                                                                <a href="{{ route('student.assessments.attempts.result', $lastFinished->id) }}" data-learn-quiz-result>@include('tenant.my-learning.partials.board-icon', ['icon' => 'result']){{ __('lf.LF_my_learning_quiz_view_result') }}</a>
                                                            @endif
                                                            @if ($card->open)
                                                                <form method="POST" action="{{ route('student.courses.activities.attempts.store', [$class->enrollment_id, $activity->id]) }}">
                                                                    @csrf
                                                                    <button type="submit" data-learn-quiz-start>@include('tenant.my-learning.partials.board-icon', ['icon' => 'quiz']){{ __('lf.LF_my_learning_quiz_resume') }}</button>
                                                                </form>
                                                            @elseif ($class->cohort_status !== 'completed' && \App\Services\LearnerCourseQuizService::classOffersAttempt($card))
                                                                <form method="POST" action="{{ route('student.courses.activities.attempts.store', [$class->enrollment_id, $activity->id]) }}">
                                                                    @csrf
                                                                    <button type="submit" data-learn-quiz-start>@include('tenant.my-learning.partials.board-icon', ['icon' => 'quiz']){{ $lastFinished ? __('lf.LF_my_learning_quiz_retry') : __('lf.LF_my_learning_quiz_start') }}</button>
                                                                </form>
                                                            @elseif (! $lastFinished)
                                                                <span class="learning-board-item__locked learning-board-item__ended">{{ __($class->cohort_status === 'completed' ? 'lf.LF_my_learning_quiz_class_ended' : 'lf.LF_my_learning_quiz_no_attempts') }}</span>
                                                            @endif
                                                        @endif
                                                    @else
                                                        <span class="learning-board-item__locked learning-board-item__unavailable">{{ __('lf.LF_my_learning_activity_unavailable') }}</span>
                                                    @endif
                                                    {{-- The class page is where a class learner completes everything, so "Đánh dấu hoàn thành" is here too. --}}
                                                    @if (! $isLiveClass && ! $locked && $activity->progress_rule === 'manual' && $class->cohort_status !== 'completed')
                                                        <button type="button" x-show="! completed" @if ($done) x-cloak @endif x-on:click="markDone()" x-bind:disabled="busy" data-learn-mark-done>@include('tenant.my-learning.partials.board-icon', ['icon' => 'done']){{ __('lf.LF_my_learning_activity_mark_done') }}</button>
                                                    @endif
                                                </div>
                                            </div>
                                        @empty
                                            <div class="learning-board-phase__empty">{{ __('lf.LF_my_learning_board_phase_empty') }}</div>
                                        @endforelse
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
            @empty
                <div class="week-item">
                    <div class="week-item-header">
                        <div class="week-item-info">
                            <h3>{{ __('lf.LF_my_learning_schedule_empty') }}</h3>
                        </div>
                    </div>
                </div>
            @endforelse
        </div>
    </section>
</div>

<script>
    (() => {
        const page = document.querySelector('[data-learn-class]');
        if (! page) return;

        // Collapse / expand one Session's contents (MK Live).
        page.querySelectorAll('[data-lesson-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const open = button.getAttribute('aria-expanded') !== 'true';
                const body = document.getElementById(button.getAttribute('aria-controls'));
                button.setAttribute('aria-expanded', String(open));
                const label = open ? button.dataset.collapseLabel : button.dataset.expandLabel;
                button.setAttribute('aria-label', label);
                button.title = label;
                if (body) body.hidden = ! open;
            });
        });

        // "Vào học sau: hh:mm:ss" until a Session starts.
        const pad = (n) => String(n).padStart(2, '0');
        const countdowns = [...page.querySelectorAll('[data-learn-countdown]')];
        const tick = () => {
            const now = Math.floor(Date.now() / 1000);
            countdowns.forEach((button) => {
                const left = Math.max(0, Number(button.dataset.learnCountdown) - now);
                const days = Math.floor(left / 86400);
                const time = (days > 0 ? days + 'd ' : '') + [Math.floor(left % 86400 / 3600), Math.floor(left % 3600 / 60), left % 60].map(pad).join(':');
                button.querySelector('.lesson-countdown-label').textContent = button.dataset.countdownLabel.replace('__TIME__', time);
            });
        };
        if (countdowns.length) { tick(); window.setInterval(tick, 1000); }

        // The Sessions to act on now change on the server (entry opens, the teacher starts, the Session ends): ask once a
        // minute while the tab is visible and reload when the answer changes, unless something is playing or open.
        const url = page.dataset.liveRefreshUrl;
        let snapshot;
        const busy = () => document.querySelector('dialog[open]') || [...document.querySelectorAll('video, audio')].some((m) => ! m.paused);
        const refresh = async () => {
            if (document.visibilityState !== 'visible') return;
            try {
                const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' });
                if (! response.ok) return;
                const html = (await response.text()).trim();
                if (snapshot === undefined) { snapshot = html; return; }
                if (html !== snapshot && ! busy()) window.location.reload();
            } catch (error) {
                // Offline or signed out: try again next minute.
            }
        };
        refresh();
        window.setInterval(refresh, 60000);
        document.addEventListener('visibilitychange', refresh);
    })();
</script>
@endsection
