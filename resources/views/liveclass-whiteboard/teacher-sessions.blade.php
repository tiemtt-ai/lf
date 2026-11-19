@extends('layouts.backend')

@section('title', __('lf.LF_navigation_menu_teacher_live_sessions'))
@section('page_title', __('lf.LF_navigation_menu_teacher_live_sessions'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
@php
    $statusClass = ['scheduled' => 'ui-status--draft', 'live' => 'ui-status--active', 'completed' => 'ui-status--completed'];
    $actionTexts = [
        'start' => ['title' => __('lf.LF_liveclass_whiteboard_start_title'), 'body' => __('lf.LF_liveclass_whiteboard_start_body'), 'label' => __('lf.LF_liveclass_whiteboard_start'), 'danger' => false],
        'complete' => ['title' => __('lf.LF_liveclass_whiteboard_complete_title'), 'body' => __('lf.LF_liveclass_whiteboard_complete_body'), 'label' => __('lf.LF_liveclass_whiteboard_complete'), 'danger' => true],
    ];
    $indexUrl = route('teacher.live-sessions.index');
    $tabs = ['upcoming' => $indexUrl, 'overdue' => $indexUrl.'?tab=overdue', 'taught' => $indexUrl.'?tab=taught'];
    $attendanceOn = \App\Services\LiveClassAttendanceCheckIn::enabled();
@endphp
{{-- The board opens in its own window, as in AllVia (1280×800). Start posts into that window, so the
     list stays here and reloads to show the new status. --}}
<div class="lf-admin-ui lf-admin-ui--list" data-liveclass-sessions
     x-data="{
         action: null, submitting: false,
         texts: @js($actionTexts),
         // `noBoard`: a practice session starts on this page, with no board window to open.
         ask(kind, url, sessionId, noBoard = false) {
             this.action = { kind, url, sessionId, noBoard, ...this.texts[kind], ...(noBoard && kind === 'start' ? { body: @js(__('lf.LF_liveclass_whiteboard_start_body_no_board')) } : {}) }
             this.submitting = false; this.$refs.confirmDialog.showModal()
         },
         close() { if (! this.submitting) this.$refs.confirmDialog.close() },
         openBoard(url, sessionId) { const win = window.open(url, 'lf-whiteboard-' + sessionId, 'popup,width=1280,height=800'); if (win) win.focus() },
         submit(event) {
             if (this.submitting) { event.preventDefault(); return }
             this.submitting = true
             if (this.action.kind === 'start' && ! this.action.noBoard) {
                 window.open('', event.target.target, 'popup,width=1280,height=800')
                 setTimeout(() => window.location.reload(), 1500)
             }
         },
     }">
    <x-ui-list-toasts />

    <x-ui-list-head :title="__('lf.LF_navigation_menu_teacher_live_sessions')" :text="__('lf.LF_liveclass_whiteboard_sessions_help')" />

    @if ($errors->has('lifecycle'))
        <div class="admin-alert admin-alert-danger" role="alert">{{ $errors->first('lifecycle') }}</div>
    @endif

    {{-- Upcoming is where the teacher teaches; "Cần xử lý" keeps missed Sessions in sight and "Đã dạy" leads back
         to attendance (Owner 2026-11-18). Tabs sit above the card, like the Template tabs. --}}
    <nav class="course-template-tabs" aria-label="{{ __('lf.LF_liveclass_whiteboard_tabs_label') }}" data-live-session-tabs>
        @foreach ($tabs as $tabKey => $tabUrl)
            <a @class(['course-template-tab', 'cursor-interaction', 'is-active' => $tab === $tabKey]) href="{{ $tabUrl }}"
               @if ($tab === $tabKey) aria-current="page" @endif>{{ __('lf.LF_liveclass_whiteboard_tab_'.$tabKey) }}
                @isset($tabCounts[$tabKey])<span class="ui-tab-count">{{ $tabCounts[$tabKey] }}</span>@endisset</a>
        @endforeach
    </nav>

    {{-- Rows, not a table: every part stays on screen at any width, as on "Lớp của tôi". --}}
    <section class="mk-section" aria-labelledby="live-sessions-list-title">
        <div class="ui-summary-head">
            <div>
                <h2 id="live-sessions-list-title">{{ __('lf.LF_liveclass_whiteboard_list_'.$tab) }}</h2>
                @if ($tab === 'overdue' && $sessions->isNotEmpty())
                    <p class="ui-caption">{{ __('lf.LF_liveclass_whiteboard_list_overdue_help') }}</p>
                @endif
            </div>
        </div>

        @forelse ($sessions as $session)
            @php
                $start = \Illuminate\Support\Carbon::parse($session->scheduled_start_at);
                $end = \Illuminate\Support\Carbon::parse($session->scheduled_end_at);
                $meetingUrl = $tab === 'upcoming' ? \App\Services\LiveClassWhiteboardService::meetingUrl($session) : null;
            @endphp
            <article class="ui-teacher-cohort" data-live-session="{{ $session->id }}">
                <div class="ui-teacher-cohort__main">
                    <div class="ui-teacher-cohort__title-row">
                        <h3 class="ui-teacher-cohort__title">{{ $session->title }}</h3>
                        <span class="ui-status {{ $statusClass[$session->status] ?? '' }}">{{ __('lf.LF_course_cohort_session_status_'.$session->status) }}</span>
                        @if ($session->is_today ?? false)
                            <span class="ui-teacher-cohort__role ui-wb-today">{{ __('lf.LF_liveclass_whiteboard_today') }}</span>
                        @endif
                    </div>
                    <p class="ui-teacher-cohort__meta">
                        {{ $session->cohort_name }} · {{ $start->format('d/m/Y') }} · {{ $start->format('H:i') }}–{{ $end->format('H:i') }}
                    </p>
                    <p class="ui-teacher-cohort__note">
                        <span class="ui-session-mode ui-session-mode--{{ $session->delivery_mode }}">{{ __('lf.LF_course_cohort_session_mode_'.$session->delivery_mode) }}</span>
                        @if ($session->room_name_snapshot && $session->delivery_mode !== 'online')
                            <span>{{ $session->room_name_snapshot }}</span>
                        @endif
                        @if ($session->lesson_title && $session->lesson_title !== $session->title)
                            <span>{{ $session->lesson_title }}</span>
                        @endif
                    </p>
                    @if ($session->start_waits ?? false)
                        <p class="ui-teacher-cohort__note" data-live-session-waits>{{ __('lf.LF_liveclass_whiteboard_start_from', ['time' => $session->start_opens_at->format('H:i')]) }}</p>
                    @endif
                    @if ($session->complete_waits ?? false)
                        <p class="ui-teacher-cohort__note" data-live-session-waits>{{ __('lf.LF_liveclass_whiteboard_complete_from', ['time' => $start->format('H:i')]) }}</p>
                    @endif
                    @if ($tab === 'overdue' && ! in_array((int) $session->cohort_id, $managedCohortIds, true))
                        <p class="ui-teacher-cohort__note">{{ __('lf.LF_liveclass_whiteboard_overdue_ask_admin') }}</p>
                    @endif
                </div>
                <div class="ui-teacher-cohort__actions">
                    @if ($tab === 'upcoming')
                        @if ($session->can_start)
                            <button type="button" class="ui-button ui-button--primary cursor-interaction"
                                    data-url="{{ route('teacher.live-sessions.start', $session->id) }}" x-on:click="ask('start', $el.dataset.url, {{ $session->id }}, @js((bool) ($session->is_practice ?? false)))">
                                <x-backend-icon name="presentation" class="ui-icon" />{{ __('lf.LF_liveclass_whiteboard_start') }}
                            </button>
                        @endif
                        @if ($session->can_open)
                            <a class="ui-button ui-button--primary cursor-interaction" href="{{ route('teacher.live-sessions.board', $session->id) }}"
                               target="lf-whiteboard-{{ $session->id }}"
                               x-on:click.prevent="openBoard($el.href, {{ $session->id }})">
                                <x-backend-icon name="presentation" class="ui-icon" />{{ __('lf.LF_liveclass_whiteboard_open') }}
                            </a>
                        @endif
                        {{-- In class + remote and online sessions have a meeting room. --}}
                        @if ($meetingUrl)
                            <a class="ui-button cursor-interaction" href="{{ $meetingUrl }}" target="_blank" rel="noopener noreferrer">
                                <x-backend-icon name="external-link" class="ui-icon" />{{ __('lf.LF_liveclass_whiteboard_join_meeting') }}
                            </a>
                        @endif
                        @if ($session->can_edit_place || $session->can_edit_link)
                            @include('liveclass-whiteboard.partials.session-operations', ['session' => $session])
                        @endif
                        @if ($session->can_complete)
                            <button type="button" class="ui-button ui-button--danger-outline cursor-interaction"
                                    data-url="{{ route('teacher.live-sessions.complete', $session->id) }}" x-on:click="ask('complete', $el.dataset.url, {{ $session->id }})">
                                {{ __('lf.LF_liveclass_whiteboard_complete') }}
                            </button>
                        @endif
                    @elseif ($tab === 'overdue')
                        @if (in_array((int) $session->cohort_id, $managedCohortIds, true))
                            <a class="ui-button ui-button--outline cursor-interaction" href="{{ route('teacher.course-cohorts.show', ['id' => $session->cohort_id, 'tab' => 'sessions']) }}" data-live-session-manage>
                                {{ __('lf.LF_liveclass_whiteboard_overdue_manage') }}
                            </a>
                        @endif
                    @elseif ($attendanceOn)
                        <a class="ui-button cursor-interaction" href="{{ route('teacher.cohorts.attendance', ['cohort' => $session->cohort_id, 'session_id' => $session->id]) }}" data-live-session-attendance>
                            {{ __('lf.LF_teacher_attendance_open') }}
                        </a>
                    @endif
                </div>
            </article>
        @empty
            <div class="ui-teacher-cohort-empty" role="status">
                <strong>{{ __('lf.LF_liveclass_whiteboard_'.($tab === 'upcoming' ? 'sessions' : $tab).'_empty') }}</strong>
                <p class="ui-caption">{{ __('lf.LF_liveclass_whiteboard_'.($tab === 'upcoming' ? 'sessions' : $tab).'_empty_help') }}</p>
            </div>
        @endforelse

        @if ($sessions instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $sessions->total() > 0)
            <x-ui-list-foot :paginator="$sessions" :label="__('lf.LF_liveclass_whiteboard_list_taught')"
                            :page-size-label="__('lf.LF_course_category_index_page_size')"
                            :page-size-option="__('lf.LF_course_category_index_page_size_option', ['count' => $sessions->perPage()])" />
        @endif
    </section>

    <dialog x-ref="confirmDialog" class="ui-modal" data-columns="1" aria-labelledby="live_session_confirm_title" aria-describedby="live_session_confirm_body"
            x-on:cancel="if (submitting) $event.preventDefault()">
        <form method="POST" class="ui-modal__form" x-bind:action="action?.url || ''"
              x-bind:target="action?.kind === 'start' && ! action.noBoard ? 'lf-whiteboard-' + action.sessionId : '_self'"
              x-on:submit="submit($event)">
            @csrf
            <header class="ui-modal__header">
                <div><h3 id="live_session_confirm_title" x-text="action?.title"></h3></div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="close()" x-bind:disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>
            <div class="ui-modal__body">
                <p id="live_session_confirm_body" class="ui-enroll-dialog-text" x-text="action?.body"></p>
            </div>
            <footer class="ui-modal__footer">
                <button type="button" class="ui-button cursor-interaction" x-on:click="close()" x-bind:disabled="submitting">{{ __('lf.LF_liveclass_whiteboard_cancel') }}</button>
                <button type="submit" class="ui-button cursor-interaction" x-bind:class="action ? (action.danger ? 'ui-button--danger-outline' : 'ui-button--primary') : ''" x-bind:disabled="submitting">
                    <span x-show="! action?.danger"><x-backend-icon name="check" class="ui-icon" /></span>
                    <span x-text="action?.label"></span>
                </button>
            </footer>
        </form>
    </dialog>
</div>
@endsection
