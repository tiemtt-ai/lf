@extends('layouts.tenant')

@php
    $title = $course->product_title ?: $course->version_title;
    $day = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : null;
    $clock = function (int $seconds): string {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds % 60);
    };
    $lockLabel = function (object $lesson) use ($day): string {
        return match ($lesson->reason) {
            'prerequisite_incomplete', 'prerequisites_incomplete' => __('lf.LF_my_learning_lock_prerequisite'),
            'unlock_time_pending' => __('lf.LF_my_learning_lock_time', ['date' => \Illuminate\Support\Carbon::parse($lesson->unlock_at)->setTimezone(config('app.timezone'))->format('d/m/Y H:i')]),
            'outside_enrollment_access_window' => __('lf.LF_my_learning_lock_window'),
            'inactive_or_invalid_enrollment' => __('lf.LF_my_learning_lock_enrollment'),
            default => __('lf.LF_my_learning_lock_other'),
        };
    };
    $windowEnd = match ($course->phase) {
        'access' => $course->access_ends_at,
        'review' => $course->review_ends_at,
        default => null,
    };
    $totalDays = $course->access_starts_at && $course->access_ends_at
        ? (int) round(\Illuminate\Support\Carbon::parse($course->access_starts_at)->diffInDays(\Illuminate\Support\Carbon::parse($course->review_ends_at ?: $course->access_ends_at)))
        : null;
    $reviewDays = $course->review_ends_at && $course->access_ends_at
        ? (int) round(\Illuminate\Support\Carbon::parse($course->access_ends_at)->diffInDays(\Illuminate\Support\Carbon::parse($course->review_ends_at)))
        : 0;
    $lastDay = $course->review_ends_at ?: $course->access_ends_at;
    $remaining = $lastDay && $course->phase !== 'ended' ? max(0, (int) ceil(now()->diffInMinutes(\Illuminate\Support\Carbon::parse($lastDay), false) / 1440)) : null;
    $daysLeft = $windowEnd ? max(0, (int) ceil(now()->diffInMinutes(\Illuminate\Support\Carbon::parse($windowEnd), false) / 1440)) : null;
@endphp

@section('title', $title.' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))

@section('content')
<div class="lf-learn-course" x-data="{ tab: 'lessons' }">
    <a class="lf-cd-back" href="{{ route('student.courses.index') }}"><span aria-hidden="true">←</span> {{ __('lf.LF_my_learning_back') }}</a>
    @if ($course->review_only ?? false)
        {{-- ADR-0022 Amendment 1.4: the class has ended; the learner only reviews. --}}
        <p class="student-alert" role="status" data-learn-review-only>{{ __('lf.LF_my_learning_review_only') }}</p>
    @endif

    <section class="lf-cd-summary" aria-labelledby="learn-course-title">
        <h1 id="learn-course-title">{{ $title }}</h1>
        @if ($course->teachers !== [])
            <p class="lf-cd-teacher">{{ __('lf.LF_my_learning_teacher') }}: <strong>{{ implode(', ', $course->teachers) }}</strong></p>
        @endif

        <div @class(['lf-cd-stats', 'has-three' => $course->lesson_open < $course->lesson_total])>
            <div class="lf-cd-stat">
                <span class="lf-cd-stat__label">{{ __('lf.LF_my_learning_detail_duration') }} <button type="button" class="lf-cd-tip" aria-label="{{ __('lf.LF_my_learning_detail_duration_tip') }}" data-tip="{{ __('lf.LF_my_learning_detail_duration_tip') }}">?</button></span>
                <strong>{{ $clock($course->duration_seconds) }} ({{ __('lf.LF_my_learning_detail_lessons_unit', ['count' => $course->lesson_total]) }})</strong>
            </div>
            <div class="lf-cd-stat">
                <span class="lf-cd-stat__label">{{ __('lf.LF_my_learning_detail_done') }} <button type="button" class="lf-cd-tip" aria-label="{{ __('lf.LF_my_learning_detail_done_tip') }}" data-tip="{{ __('lf.LF_my_learning_detail_done_tip') }}">?</button></span>
                <strong>{{ $course->lesson_completed }}/{{ __('lf.LF_my_learning_detail_lessons_unit', ['count' => $course->lesson_total]) }}</strong>
                @php
                    $ratioLabel = $course->watch_ratio !== null ? __('lf.LF_my_learning_detail_watch_ratio') : __('lf.LF_my_learning_detail_ratio');
                    $ratio = $course->watch_ratio ?? $course->progress;
                @endphp
                <div class="lf-cd-total" data-learn-ratio="{{ $course->watch_ratio !== null ? 'watch' : 'completion' }}">
                    <span>{{ $ratioLabel }}</span>
                    <progress value="{{ $ratio }}" max="100" aria-label="{{ $ratioLabel }}"></progress>
                    <span class="lf-cd-total__percent">{{ $ratio }}%</span>
                </div>
            </div>
            @if ($course->lesson_open < $course->lesson_total)
                <div class="lf-cd-stat">
                    <span class="lf-cd-stat__label">{{ __('lf.LF_my_learning_detail_open') }} <button type="button" class="lf-cd-tip" aria-label="{{ __('lf.LF_my_learning_detail_open_tip') }}" data-tip="{{ __('lf.LF_my_learning_detail_open_tip') }}">?</button></span>
                    <strong>{{ $course->lesson_open }}/{{ __('lf.LF_my_learning_detail_lessons_unit', ['count' => $course->lesson_total]) }}</strong>
                </div>
            @endif
        </div>

        <div class="lf-cd-access">
            <span>{{ __('lf.LF_my_learning_detail_access') }}</span>
            @if ($course->phase === 'open')
                <strong>{{ __('lf.LF_my_learning_no_time_limit') }}</strong>
            @else
                <strong>{{ $day($course->access_starts_at) }} → {{ $day($lastDay) }}</strong>
                @if ($totalDays !== null)
                    <span>{{ __('lf.LF_my_learning_detail_access_days', ['total' => $totalDays, 'review' => $reviewDays]) }}</span>
                @endif
                <span aria-hidden="true">→</span>
            @endif
            <strong @class(['lf-cd-access__state', 'is-muted' => $course->phase === 'ended' || $course->status !== 'active', 'is-review' => $course->phase === 'review' && $course->status === 'active'])>
                @if (in_array($course->status, ['pending', 'suspended', 'completed', 'expired'], true))
                    {{ __('lf.LF_my_learning_status_'.$course->status) }}
                @elseif ($remaining !== null && in_array($course->phase, ['access', 'review'], true))
                    {{ __('lf.LF_my_learning_detail_days_left', ['days' => $remaining]) }}
                @else
                    {{ __('lf.LF_my_learning_phase_'.$course->phase) }}
                @endif
            </strong>
            @if ($course->resume_lesson_id)
                <a class="lf-cd-resume" href="{{ route('student.courses.lessons.show', [$course->id, $course->resume_lesson_id]) }}" data-learn-resume>
                    {{ $course->progress > 0 ? __('lf.LF_my_learning_resume') : __('lf.LF_my_learning_start') }} <span aria-hidden="true">→</span>
                </a>
            @endif
        </div>

        @if ($course->is_class && $course->cohort_id)
            <p class="lf-cd-class">
                {{ __('lf.LF_my_learning_course_is_class') }}
                <a class="student-text-link" href="{{ route('student.courses.classes.show', $course->cohort_id) }}">{{ __('lf.LF_my_learning_course_open_class') }}</a>
            </p>
        @endif
    </section>

    <section class="lf-cd-workspace">
        <div class="lf-cd-tabs" role="tablist">
            @foreach (['lessons', 'intro', 'teachers'] as $name)
                <button type="button" role="tab" class="lf-cd-tab" :class="{ 'is-active': tab === '{{ $name }}' }" :aria-selected="tab === '{{ $name }}'"
                        aria-controls="learn-pane-{{ $name }}" @click="tab = '{{ $name }}'" data-learn-tab="{{ $name }}">{{ __('lf.LF_my_learning_detail_tab_'.$name) }}</button>
            @endforeach
        </div>

        <div class="lf-cd-pane" id="learn-pane-lessons" role="tabpanel" x-show="tab === 'lessons'" aria-labelledby="learn-outline-title">
            <div class="lf-cd-heading">
                <div>
                    <h2 id="learn-outline-title">{{ __('lf.LF_my_learning_course_outline') }}</h2>
                    <p>{{ __('lf.LF_my_learning_course_outline_help') }}</p>
                </div>
                @if ($course->lesson_total > 0)
                    <label class="lf-cd-search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m16 16 5 5"></path></svg>
                        <input type="search" placeholder="{{ __('lf.LF_my_learning_detail_search') }}" aria-label="{{ __('lf.LF_my_learning_detail_search') }}" data-learn-search>
                    </label>
                @endif
            </div>

            @if ($course->lesson_total === 0)
                <div class="lf-cd-empty" role="status"><p>{{ __('lf.LF_my_learning_course_outline_empty') }}</p></div>
            @endif

            <div class="lf-cd-sections">
            @foreach ($course->sections as $section)
                <div class="lf-cd-section" data-learn-section>
                    @if ($course->sections->count() > 1 || $section->title)
                        <h3 class="lf-cd-section__title">{{ $section->title ?? __('lf.LF_my_learning_course_untitled_section') }}</h3>
                    @endif
                    <ol class="lf-cd-lessons">
                        @foreach ($section->lessons as $lesson)
                            @php
                                // MK "learning course" row: the state and the meter follow the Lesson's video or audio;
                                // a Lesson without either follows its own progress. A Lesson not open yet stays locked.
                                $percent = $lesson->watched ?? ($lesson->progress_status === 'completed' ? 100 : (int) $lesson->progress);
                                $state = match (true) {
                                    $lesson->progress_status === 'completed' => 'completed',
                                    ! $lesson->allowed => 'locked',
                                    $lesson->progress_status !== 'not_started' || $percent > 0 => 'progress',
                                    default => 'new',
                                };
                                $stateLabel = match ($state) {
                                    'completed' => __('lf.LF_my_learning_lesson_completed'),
                                    'locked' => $lockLabel($lesson),
                                    'progress' => __('lf.LF_my_learning_detail_state_watching'),
                                    default => __('lf.LF_my_learning_detail_state_new'),
                                };
                                $lessonUrl = route('student.courses.lessons.show', [$course->id, $lesson->id]);
                            @endphp
                            <li @class(['lf-cd-lesson', 'is-'.$state]) data-learn-lesson="{{ $lesson->id }}" data-learn-lesson-state="{{ $lesson->allowed ? 'open' : 'locked' }}" data-learn-lesson-title="{{ mb_strtolower($lesson->title) }}">
                                <div class="lf-cd-lesson__no" aria-hidden="true"><small>{{ __('lf.LF_my_learning_course_lesson_no') }}</small><strong>{{ sprintf('%02d', $lesson->number) }}</strong></div>
                                <div class="lf-cd-lesson__main">
                                    <div class="lf-cd-lesson__title">
                                        <h3>
                                            @if ($lesson->allowed)
                                                <a class="lf-learn-lesson__link" href="{{ $lessonUrl }}">{{ $lesson->title }}</a>
                                            @else
                                                {{ $lesson->title }}
                                            @endif
                                        </h3>
                                        <span class="lf-cd-state" data-learn-lesson-badge="{{ $state }}">
                                            @if ($state === 'locked')<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>@endif
                                            {{ $stateLabel }}
                                        </span>
                                    </div>
                                    <div class="lf-cd-lesson__meta">
                                        @if ($lesson->duration_seconds)<span>{{ $clock((int) $lesson->duration_seconds) }}</span>@endif
                                        <span class="lf-cd-meter">
                                            <span>{{ $lesson->media ? __('lf.LF_my_learning_detail_watched_'.$lesson->media) : __('lf.LF_my_learning_detail_learned') }}</span>
                                            <progress max="100" value="{{ $percent }}" aria-label="{{ $lesson->media ? __('lf.LF_my_learning_detail_watched_'.$lesson->media) : __('lf.LF_my_learning_detail_learned') }}"></progress>
                                            <strong>{{ $percent }}%</strong>
                                        </span>
                                        @if ($lesson->quiz_activity_id)
                                            <span class="lf-cd-chip" data-learn-quiz-count="{{ $lesson->quiz_attempts }}"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="m8 10 2 2 4-4M8 16h8"/></svg>{{ $lesson->quiz_attempts > 0 ? __('lf.LF_my_learning_detail_quiz_count', ['count' => $lesson->quiz_attempts]) : __('lf.LF_my_learning_detail_quiz_none') }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="lf-cd-lesson__actions">
                                    @if ($lesson->allowed)
                                        <a class="lf-cd-action" href="{{ $lessonUrl }}"
                                           data-learn-open data-learn-open-no="{{ sprintf('%02d', $lesson->number) }}" data-learn-open-title="{{ $lesson->title }}"
                                           data-learn-open-viewed="{{ $lesson->last_viewed_at?->format('Y-m-d H:i') }}" data-learn-open-seconds="{{ $lesson->watched_seconds }}" data-learn-open-percent="{{ $lesson->media ? $percent : '' }}">
                                            <svg viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="14"/><path d="M13 10.5v11l9-5.5z"/></svg>
                                            <span>{{ __('lf.LF_my_learning_detail_enter') }}</span>
                                        </a>
                                    @else
                                        <span class="lf-cd-action is-disabled" aria-disabled="true">
                                            <svg viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="14"/><path d="M13 10.5v11l9-5.5z"/></svg>
                                            <span>{{ __('lf.LF_my_learning_detail_enter') }}</span>
                                        </span>
                                    @endif
                                    @if ($lesson->quiz_activity_id)
                                        {{-- The Quiz is taken on the lesson page (ADR-0032 D4): its attempts, limits and results live there. --}}
                                        @if ($lesson->allowed)
                                            <a class="lf-cd-action is-secondary" href="{{ $lessonUrl }}#activity-{{ $lesson->quiz_activity_id }}" data-learn-quiz-link>
                                                <svg viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15"/><rect x="10" y="9" width="12" height="15" rx="2"/><path d="M13.5 9V7.8h5V9M13.2 14.2l1.4 1.4 2.5-2.6M13.2 19l1.4 1.4 2.5-2.6M18.8 14.3h1M18.8 19.1h1"/></svg>
                                                <span>{{ __('lf.LF_my_learning_detail_quiz') }}</span>
                                            </a>
                                        @else
                                            <span class="lf-cd-action is-secondary is-disabled" aria-disabled="true">
                                                <svg viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15"/><rect x="10" y="9" width="12" height="15" rx="2"/><path d="M13.5 9V7.8h5V9M13.2 14.2l1.4 1.4 2.5-2.6M13.2 19l1.4 1.4 2.5-2.6M18.8 14.3h1M18.8 19.1h1"/></svg>
                                                <span>{{ __('lf.LF_my_learning_detail_quiz') }}</span>
                                            </span>
                                        @endif
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endforeach
            </div>
            <p class="lf-cd-search-empty" data-learn-search-empty hidden>{{ __('lf.LF_my_learning_detail_search_empty') }}</p>
        </div>

        <div class="lf-cd-pane lf-cd-prose" id="learn-pane-intro" role="tabpanel" x-show="tab === 'intro'" x-cloak>
            @if (trim((string) ($course->description ?: $course->summary)) !== '')
                @if ($course->summary)<p class="lf-cd-prose__lead">{{ $course->summary }}</p>@endif
                @if ($course->description)<div>{!! nl2br(e(strip_tags((string) $course->description))) !!}</div>@endif
            @else
                <p>{{ __('lf.LF_my_learning_detail_intro_empty') }}</p>
            @endif
        </div>

        <div class="lf-cd-pane" id="learn-pane-teachers" role="tabpanel" x-show="tab === 'teachers'" x-cloak>
            @forelse ($course->teachers as $teacher)
                <div class="lf-cd-teacher-card">
                    <span class="lf-cd-teacher-card__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($teacher, 0, 1)) }}</span>
                    <strong>{{ $teacher }}</strong>
                </div>
            @empty
                <p class="lf-cd-prose">{{ __('lf.LF_my_learning_detail_teachers_empty') }}</p>
            @endforelse
        </div>
    </section>
</div>
{{-- "Vào học" opens the Lesson here, like the MK video popup: its video or audio plays on the black stage and the
     footer shows the last opening and how much was played. The Activities come from the lesson page, so the players,
     progress events and locks are the lesson page's own; the Quiz keeps its own button. Without JavaScript the link
     opens that page. --}}
<dialog class="lf-cd-modal" data-learn-modal aria-labelledby="learn-modal-title">
    <header class="lf-cd-modal__head">
        <span class="lf-cd-modal__no" aria-hidden="true"><small>{{ __('lf.LF_my_learning_course_lesson_no') }}</small><strong data-learn-modal-no></strong></span>
        <h2 id="learn-modal-title" data-learn-modal-title></h2>
        <button type="button" class="lf-cd-modal__close" data-learn-modal-close aria-label="{{ __('lf.LF_my_learning_board_close') }}" title="{{ __('lf.LF_my_learning_board_close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    </header>
    <div class="lf-cd-modal__body" data-learn-modal-body aria-live="polite">
        <div class="lf-cd-modal__screen">
            <div class="lf-cd-modal__stage" data-learn-modal-stage></div>
            {{-- MK: a video stopped part-way asks whether to go on from there or start over. --}}
            <div class="lf-cd-modal__resume" data-learn-modal-resume hidden>
                <div class="lf-cd-modal__resume-card" role="group" aria-labelledby="learn-modal-resume-title">
                    <span class="lf-cd-modal__resume-icon" aria-hidden="true">▶</span>
                    <h3 id="learn-modal-resume-title">{{ __('lf.LF_my_learning_detail_resume_question') }}</h3>
                    <p><strong data-learn-modal-resume-time>00:00:00</strong></p>
                    <div>
                        <button type="button" data-learn-modal-resume-restart>{{ __('lf.LF_my_learning_detail_restart') }}</button>
                        <button type="button" class="is-primary" data-learn-modal-resume-continue>{{ __('lf.LF_my_learning_detail_continue') }}</button>
                    </div>
                </div>
            </div>
        </div>
        <section class="lf-cd-modal__more" data-learn-modal-more hidden aria-labelledby="learn-modal-more-title">
            <h3 id="learn-modal-more-title">{{ __('lf.LF_my_learning_detail_other_content') }}</h3>
            <div data-learn-modal-more-list></div>
        </section>
    </div>
    <footer class="lf-cd-modal__foot">
        <p class="lf-cd-modal__progress" data-learn-modal-progress hidden>
            <time data-learn-modal-viewed></time>
            <strong>{{ __('lf.LF_my_learning_detail_progress') }}: <span data-learn-modal-seconds>00:00:00</span> ~ <span data-learn-modal-percent>0%</span></strong>
        </p>
        <span class="lf-cd-modal__nav">
            <button type="button" class="lf-cd-modal__restart" data-learn-modal-restart>{{ __('lf.LF_my_learning_detail_restart') }}</button>
            <button type="button" data-learn-modal-fullscreen aria-label="{{ __('lf.LF_my_learning_detail_fullscreen') }}" title="{{ __('lf.LF_my_learning_detail_fullscreen') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H3v5M16 3h5v5M21 16v5h-5M8 21H3v-5"/></svg></button>
            <button type="button" data-learn-modal-previous aria-label="{{ __('lf.LF_my_learning_detail_previous') }}" title="{{ __('lf.LF_my_learning_detail_previous') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg></button>
            <button type="button" data-learn-modal-next aria-label="{{ __('lf.LF_my_learning_detail_next') }}" title="{{ __('lf.LF_my_learning_detail_next') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
        </span>
    </footer>
</dialog>
<script>
    (() => {
        const root = document.querySelector('.lf-learn-course');
        const input = root.querySelector('[data-learn-search]');
        const empty = root.querySelector('[data-learn-search-empty]');
        const filter = () => {
            if (! input) return;
            const term = input.value.trim().toLowerCase();
            let shown = 0;
            root.querySelectorAll('[data-learn-section]').forEach((section) => {
                let inSection = 0;
                section.querySelectorAll('[data-learn-lesson]').forEach((row) => {
                    const match = term === '' || row.dataset.learnLessonTitle.includes(term);
                    row.hidden = ! match;
                    if (match) inSection++;
                });
                section.hidden = inSection === 0;
                shown += inSection;
            });
            empty.hidden = shown > 0;
        };
        input?.addEventListener('input', filter);

        const modal = document.querySelector('[data-learn-modal]');
        if (! modal || typeof modal.showModal !== 'function') return;
        const pick = (name) => modal.querySelector('[data-learn-modal-' + name + ']');
        const stage = pick('stage');
        const more = pick('more');
        const moreList = pick('more-list');
        const progress = pick('progress');
        const restart = pick('restart');
        const resume = pick('resume');
        const fullscreen = pick('fullscreen');
        const previous = pick('previous');
        const next = pick('next');
        const messages = { loading: @js(__('lf.LF_my_learning_detail_loading')), failed: @js(__('lf.LF_my_learning_activity_failed')) };
        const MEDIA = ['video', 'embedded_video', 'audio'];
        let current = null;  // the lesson page URL shown
        let request = 0;     // only the latest "Vào học" may fill the popup
        let main = null;     // the stage's Activity: { item, duration, played }
        const links = () => [...root.querySelectorAll('[data-learn-open]')];
        const clock = (value) => {
            const total = Math.max(0, Math.floor(Number(value) || 0));
            return [Math.floor(total / 3600), Math.floor(total / 60) % 60, total % 60].map((part) => String(part).padStart(2, '0')).join(':');
        };
        const stamp = (date) => date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0')
            + ' ' + String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
        const showProgress = (viewed, seconds, percent) => {
            const time = pick('viewed');
            time.hidden = ! viewed;
            time.textContent = viewed || '';
            time.dateTime = (viewed || '').replace(' ', 'T');
            pick('seconds').textContent = clock(seconds);
            pick('percent').textContent = Math.min(100, Math.max(0, Math.floor(percent))) + '%';
        };
        const note = (text, alert = false) => {
            const p = document.createElement('p');
            p.className = 'lf-cd-modal__note';
            p.setAttribute('role', alert ? 'alert' : 'status');
            p.textContent = text;
            stage.replaceChildren(p);
            stage.className = 'lf-cd-modal__stage';
            stage.hidden = false;
            more.hidden = true;
            moreList.replaceChildren();
        };
        const player = () => main?.item.querySelector('video, audio, iframe') ?? null;
        const state = () => (main && window.Alpine ? window.Alpine.$data(main.item) : null);
        const stop = () => {
            resume.hidden = true;
            // The pause event comes after the stage is emptied for the next lesson, so report the position now.
            const data = state();
            if (data?.started && main.item.querySelector('video, audio')?.paused === false) data.onPause();
            modal.querySelectorAll('video, audio').forEach((media) => media.pause());
        };
        const syncButtons = () => {
            const media = player();
            restart.disabled = ! main || main.item.dataset.learnActivityType === 'embedded_video' || main.item.classList.contains('is-locked');
            fullscreen.disabled = ! main || main.item.classList.contains('is-locked');
            progress.hidden = ! main;
            return media;
        };

        const open = async (link) => {
            const mine = ++request;
            stop();
            main = null;
            current = link.href;
            pick('no').textContent = link.dataset.learnOpenNo;
            pick('title').textContent = link.dataset.learnOpenTitle;
            const all = links();
            const at = all.findIndex((other) => other.href === current);
            previous.disabled = at <= 0;
            next.disabled = at < 0 || at >= all.length - 1;
            syncButtons();
            note(messages.loading);
            if (! modal.open) modal.showModal();
            try {
                const response = await fetch(link.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
                if (! response.ok) throw new Error('lesson');
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const content = page.querySelector('.lf-learn-activities');
                const none = page.querySelector('.lf-learn-outline .lf-learn-empty');
                if (mine !== request) return;
                if (! content) throw new Error('lesson');
                // The Quiz has its own button on the row; the first video (else audio) plays on the stage.
                const items = [...content.children].filter((item) => item.dataset.learnActivityType !== 'quiz');
                const first = items.find((item) => ['video', 'embedded_video'].includes(item.dataset.learnActivityType))
                    ?? items.find((item) => MEDIA.includes(item.dataset.learnActivityType));
                const rest = items.filter((item) => item !== first);
                // Alpine starts the players of the nodes as they enter the document.
                if (first) {
                    const item = document.importNode(first, true);
                    const duration = Number(item.dataset.learnActivityDuration) || 0;
                    const percent = Number(item.dataset.learnActivityPercent) || 0;
                    main = { item, duration, played: Number(item.dataset.learnActivitySeconds) || 0, percent, viewed: item.dataset.learnActivityViewed,
                        resumeAt: Number(item.dataset.learnActivityResume) || 0 };
                    stage.className = 'lf-cd-modal__stage is-' + item.dataset.learnActivityType;
                    stage.replaceChildren(item);
                    showProgress(main.viewed, main.played, percent);
                } else {
                    stage.hidden = true;
                    stage.replaceChildren();
                }
                const list = document.createElement('ol');
                list.className = 'lf-learn-activities';
                list.append(...rest.map((item) => document.importNode(item, true)));
                moreList.replaceChildren(...[none ? document.importNode(none, true) : null, rest.length ? list : null].filter(Boolean));
                more.hidden = ! (rest.length || none);
                syncButtons();
                // "Vào học" means play: start the stage's player once Alpine has picked it up. A video or audio stopped
                // part-way first asks, like MK, whether to go on from there or start over (one near its end starts over).
                if (main && ! main.item.classList.contains('is-locked')) {
                    const asks = ['video', 'audio'].includes(main.item.dataset.learnActivityType) && main.resumeAt > 0
                        && ! (main.duration > 0 && main.resumeAt >= main.duration - 5);
                    if (asks) {
                        pick('resume-time').textContent = clock(main.resumeAt);
                        resume.hidden = false;
                        pick('resume-continue').focus();
                    } else {
                        window.setTimeout(() => { if (mine === request) main?.item.querySelector('[data-learn-play]')?.click(); }, 0);
                    }
                }
            } catch (error) {
                if (mine === request) note(messages.failed, true);
            }
        };

        // The footer follows the stage's player: played time only grows with normal playback, as the server counts it.
        stage.addEventListener('timeupdate', (event) => {
            if (! main || ! event.target.matches('video, audio')) return;
            const time = event.target.currentTime;
            if (time > main.played && time <= main.played + 3) main.played = time;
            const served = Number(state()?.percentage) || 0;
            const local = main.duration > 0 ? (100 * main.played) / main.duration : 0;
            // The server's answer only matters once it says complete; it rounds, the row floors.
            showProgress(main.viewed, served >= 100 ? main.duration : main.played, served >= 100 ? 100 : Math.max(local, main.percent));
        }, true);
        stage.addEventListener('play', () => {
            if (! main) return;
            main.viewed = stamp(new Date());
            pick('viewed').hidden = false;
            pick('viewed').textContent = main.viewed;
        }, true);

        const startOver = () => {
            resume.hidden = true;
            const media = player();
            if (! main || ! media || media.tagName === 'IFRAME') return;
            if (state()?.started) {
                // Report where playback got to before going back, so the played share is not lost.
                if (media.currentTime > 0) state().send('position', { position_seconds: media.currentTime }).catch(() => {});
                media.currentTime = 0;
                media.play().catch(() => {});
                return;
            }
            media.addEventListener('playing', () => { media.currentTime = 0; }, { once: true });
            state()?.play();
        };
        restart.addEventListener('click', startOver);
        pick('resume-restart').addEventListener('click', startOver);
        pick('resume-continue').addEventListener('click', () => {
            resume.hidden = true;
            main?.item.querySelector('[data-learn-play]')?.click();
        });
        fullscreen.addEventListener('click', () => {
            if (document.fullscreenElement) { document.exitFullscreen?.(); return; }
            const media = player();
            const target = media && media.tagName !== 'AUDIO' && media.offsetParent ? media : stage;
            (target.requestFullscreen ?? target.webkitRequestFullscreen)?.call(target);
        });

        // After learning, the figures and the lesson rows are read again; the page itself stays.
        const refresh = async () => {
            try {
                const response = await fetch(window.location.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
                if (! response.ok) return;
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                ['.lf-cd-stats', '.lf-cd-access', '#learn-pane-lessons .lf-cd-sections'].forEach((selector) => {
                    const fresh = page.querySelector(selector);
                    const stale = root.querySelector(selector);
                    if (fresh && stale) stale.replaceWith(document.importNode(fresh, true));
                });
                filter();
            } catch (error) { /* the next visit shows the new figures */ }
        };

        root.addEventListener('click', (event) => {
            const link = event.target.closest('[data-learn-open]');
            if (! link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
            event.preventDefault();
            open(link);
        });
        const step = (by) => {
            const all = links();
            const target = all[all.findIndex((other) => other.href === current) + by];
            if (target) open(target);
        };
        previous.addEventListener('click', () => step(-1));
        next.addEventListener('click', () => step(1));
        pick('close').addEventListener('click', () => modal.close());
        modal.addEventListener('click', (event) => { if (event.target === modal) modal.close(); });
        modal.addEventListener('close', () => {
            request++;
            stop();
            if (document.fullscreenElement) document.exitFullscreen?.();
            // Let the players send their last position before they leave the page.
            window.setTimeout(() => {
                if (modal.open) return;
                main = null;
                stage.replaceChildren();
                moreList.replaceChildren();
                refresh();
            }, 400);
        });
    })();
</script>
@endsection
