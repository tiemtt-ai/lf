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
    $statusClass = ['scheduled' => 'ui-status--draft', 'live' => 'ui-status--active'];
    $actionTexts = [
        'start' => ['title' => __('lf.LF_liveclass_whiteboard_start_title'), 'body' => __('lf.LF_liveclass_whiteboard_start_body'), 'label' => __('lf.LF_liveclass_whiteboard_start'), 'danger' => false],
        'complete' => ['title' => __('lf.LF_liveclass_whiteboard_complete_title'), 'body' => __('lf.LF_liveclass_whiteboard_complete_body'), 'label' => __('lf.LF_liveclass_whiteboard_complete'), 'danger' => true],
    ];
@endphp
{{-- The board opens in its own window, as in AllVia (1280×800). Start posts into that window, so the
     list stays here and reloads to show the new status. --}}
<div class="lf-admin-ui lf-admin-ui--list" data-liveclass-sessions
     x-data="{
         action: null, submitting: false,
         texts: @js($actionTexts),
         ask(kind, url, sessionId) { this.action = { kind, url, sessionId, ...this.texts[kind] }; this.submitting = false; this.$refs.confirmDialog.showModal() },
         close() { if (! this.submitting) this.$refs.confirmDialog.close() },
         openBoard(url, sessionId) { const win = window.open(url, 'lf-whiteboard-' + sessionId, 'popup,width=1280,height=800'); if (win) win.focus() },
         submit(event) {
             if (this.submitting) { event.preventDefault(); return }
             this.submitting = true
             if (this.action.kind === 'start') {
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

    <section class="mk-section" aria-labelledby="admin-page-title">
        @if ($sessions->isEmpty())
            <div class="admin-form-empty-state" role="status">
                <strong>{{ __('lf.LF_liveclass_whiteboard_sessions_empty') }}</strong>
                <span>{{ __('lf.LF_liveclass_whiteboard_sessions_empty_help') }}</span>
            </div>
        @else
            <div class="mk-table-scroll" role="region" aria-label="{{ __('lf.LF_navigation_menu_teacher_live_sessions') }}" tabindex="0">
                <table class="ui-asm-table ui-asm-table--wide ui-wb-session-table" aria-labelledby="admin-page-title">
                    <thead>
                        <tr>
                            <th scope="col" class="mk-sequence ui-wb-col-sequence">{{ __('lf.table_no') }}</th>
                            <th scope="col" class="mk-text-cell">{{ __('lf.LF_liveclass_whiteboard_col_session') }}</th>
                            <th scope="col" class="mk-text-cell ui-wb-col-class">{{ __('lf.LF_liveclass_whiteboard_col_class') }}</th>
                            <th scope="col" class="mk-text-cell ui-wb-col-time">{{ __('lf.LF_liveclass_whiteboard_col_time') }}</th>
                            <th scope="col" class="mk-text-cell ui-wb-col-mode">{{ __('lf.LF_liveclass_whiteboard_col_mode') }}</th>
                            <th scope="col" class="ux-action-cell ui-wb-col-status">{{ __('lf.LF_liveclass_whiteboard_col_status') }}</th>
                            <th scope="col" class="ux-action-cell ui-wb-col-actions">{{ __('lf.table_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $session)
                            @php
                                $start = \Illuminate\Support\Carbon::parse($session->scheduled_start_at);
                                $end = \Illuminate\Support\Carbon::parse($session->scheduled_end_at);
                            @endphp
                            <tr data-live-session="{{ $session->id }}">
                                <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                                <td class="mk-text-cell" data-label="{{ __('lf.LF_liveclass_whiteboard_col_session') }}">
                                    <span class="mk-name-line"><span class="mk-name-text" title="{{ $session->title }}">{{ $session->title }}</span></span>
                                    @if ($session->lesson_title && $session->lesson_title !== $session->title)
                                        <p class="ui-caption">{{ $session->lesson_title }}</p>
                                    @endif
                                </td>
                                <td class="mk-text-cell" data-label="{{ __('lf.LF_liveclass_whiteboard_col_class') }}">{{ $session->cohort_name }}</td>
                                <td class="mk-text-cell ui-asm-nowrap" data-label="{{ __('lf.LF_liveclass_whiteboard_col_time') }}">
                                    {{ $start->format('d/m/Y') }} · {{ $start->format('H:i') }}–{{ $end->format('H:i') }}
                                    @if ($session->is_today)
                                        <p class="ui-caption ui-wb-today">{{ __('lf.LF_liveclass_whiteboard_today') }}</p>
                                    @endif
                                </td>
                                <td class="mk-text-cell" data-label="{{ __('lf.LF_liveclass_whiteboard_col_mode') }}">
                                    {{ __('lf.LF_course_cohort_session_mode_'.$session->delivery_mode) }}
                                    @if ($meetingUrl = \App\Services\LiveClassWhiteboardService::meetingUrl($session))
                                        <p class="ui-caption"><a class="ui-cohort-version-link" href="{{ $meetingUrl }}" target="_blank" rel="noopener noreferrer"><x-backend-icon name="external-link" class="ui-icon" />{{ __('lf.LF_liveclass_whiteboard_join_meeting') }}</a></p>
                                    @elseif ($session->room_name_snapshot)
                                        <p class="ui-caption">{{ $session->room_name_snapshot }}</p>
                                    @endif
                                </td>
                                <td class="ux-action-cell" data-label="{{ __('lf.LF_liveclass_whiteboard_col_status') }}">
                                    <span class="ui-status {{ $statusClass[$session->status] ?? '' }}">{{ __('lf.LF_course_cohort_session_status_'.$session->status) }}</span>
                                </td>
                                <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                    <div class="ui-wb-session-actions">
                                        @if ($session->can_start)
                                            <button type="button" class="ui-button ui-button--primary cursor-interaction"
                                                    data-url="{{ route('teacher.live-sessions.start', $session->id) }}" x-on:click="ask('start', $el.dataset.url, {{ $session->id }})">
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
                                        @if ($session->can_complete)
                                            <button type="button" class="ui-button ui-button--danger-outline cursor-interaction"
                                                    data-url="{{ route('teacher.live-sessions.complete', $session->id) }}" x-on:click="ask('complete', $el.dataset.url, {{ $session->id }})">
                                                {{ __('lf.LF_liveclass_whiteboard_complete') }}
                                            </button>
                                        @endif
                                        @if (! $session->can_start && ! $session->can_open)
                                            <span class="ui-caption">—</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <dialog x-ref="confirmDialog" class="ui-modal" data-columns="1" aria-labelledby="live_session_confirm_title" aria-describedby="live_session_confirm_body"
            x-on:cancel="if (submitting) $event.preventDefault()">
        <form method="POST" class="ui-modal__form" x-bind:action="action?.url || ''"
              x-bind:target="action?.kind === 'start' ? 'lf-whiteboard-' + action.sessionId : '_self'"
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
                <button type="submit" class="ui-button cursor-interaction" x-bind:class="action?.danger ? 'ui-button--danger-outline' : 'ui-button--primary'" x-bind:disabled="submitting">
                    <span x-show="! action?.danger"><x-backend-icon name="check" class="ui-icon" /></span>
                    <span x-text="action?.label"></span>
                </button>
            </footer>
        </form>
    </dialog>
</div>
@endsection
