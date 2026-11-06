@extends('layouts.backend')

@section('title', $passage->title)
@section('page_title', $passage->title)

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="route($routePrefix.'.index')" :label="__('lf.LF_assessment_passage_back')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
@php
    $statusClass = ['draft' => 'ui-status--draft', 'published' => 'ui-status--active', 'archived' => 'ui-status--archived'];
    $archived = $passage->status === 'archived';
@endphp
<div id="assessment-passage-page" class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope data-ui-row-actions
     data-assessment-section="passages" data-assessment-passage="{{ $passage->id }}"
     data-csrf="{{ csrf_token() }}"
     data-msg-delete-error="{{ __('lf.LF_assessment_question_delete_error') }}"
     data-msg-session-expired="{{ __('lf.LF_assessment_category_session_expired') }}">
    <x-ui-list-toasts />

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Saving a dialog refetches this page and swaps the live regions below. --}}
    <form method="GET" action="{{ route($routePrefix.'.show', $passage->id) }}" data-ui-live-filter hidden></form>

    <x-ui-list-head :title="$passage->title" :text="__('lf.LF_assessment_passage_show_help')" />

    <section class="mk-section" aria-labelledby="assessment-passage-info-title" data-ui-live-region="info">
        <div class="ui-summary-head">
            <div>
                <h2 id="assessment-passage-info-title">{{ __('lf.LF_assessment_passage_info') }}</h2>
            </div>
            <div class="mk-table-top__actions">
                <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-dialog-form-open
                        data-ui-dialog-form-for="assessment-passage-page"
                        data-url="{{ route($routePrefix.'.edit', $passage->id) }}" aria-haspopup="dialog">
                    <x-backend-icon name="square-pen" class="ui-icon" />
                    {{ __('lf.LF_assessment_passage_edit') }}
                </button>
            </div>
        </div>
        <dl class="ui-summary">
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_category_status') }}</dt>
                <dd><span class="ui-status {{ $statusClass[$passage->status] ?? '' }}">{{ __('lf.LF_assessment_question_status_'.$passage->status) }}</span></dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_question_category') }}</dt>
                <dd>{{ $category ?? __('lf.LF_assessment_question_no_category') }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_question_skill') }}</dt>
                <dd>{{ $passage->skill_type ? __('lf.LF_assessment_question_skill_'.$passage->skill_type) : __('lf.LF_assessment_question_not_set') }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_question_level') }}</dt>
                <dd>{{ $passage->difficulty_level ? __('lf.LF_assessment_question_level_'.$passage->difficulty_level) : __('lf.LF_assessment_question_not_set') }}</dd>
            </div>
            <div class="ui-summary__item ui-summary__item--wide">
                <dt>{{ __('lf.LF_assessment_passage_content') }}</dt>
                <dd class="ui-passage-content">{{ filled($passage->content) ? $passage->content : __('lf.LF_assessment_passage_no_content') }}</dd>
            </div>
            @foreach (['prompt_image', 'prompt_audio', 'prompt_video'] as $role)
                @if ($media[$role] ?? null)
                    <div class="ui-summary__item ui-summary__item--wide">
                        <dt>{{ __('lf.LF_assessment_passage_'.$role) }}</dt>
                        <dd>
                            @if ($media[$role]['url'])
                                @if ($role === 'prompt_image')
                                    <img class="ui-passage-media" src="{{ $media[$role]['url'] }}" alt="{{ $media[$role]['name'] }}">
                                @elseif ($role === 'prompt_audio')
                                    <audio class="ui-passage-media" controls preload="metadata" src="{{ $media[$role]['url'] }}"></audio>
                                @else
                                    <video class="ui-passage-media" controls preload="metadata" src="{{ $media[$role]['url'] }}"></video>
                                @endif
                            @else
                                <span class="ui-caption">{{ $media[$role]['name'] }} · {{ __('lf.LF_assessment_passage_media_processing') }}</span>
                            @endif
                        </dd>
                    </div>
                @endif
            @endforeach
        </dl>
    </section>

    <section class="mk-section" aria-labelledby="assessment-passage-questions-title">
        <div class="mk-tablebar">
            <div>
                <h2 id="assessment-passage-questions-title" class="ui-passage-section-title">{{ __('lf.LF_assessment_passage_questions') }}</h2>
                <p class="ui-caption">{{ __('lf.LF_assessment_passage_questions_help') }}</p>
            </div>
            <x-ui-list-toolbar :url="route($questionRoute.'.bulk-destroy')" name="ids[]"
                :label="__('lf.LF_assessment_category_bulk_delete')"
                :confirm-title="__('lf.LF_assessment_question_bulk_delete_title')"
                :confirm-message="__('lf.LF_assessment_question_bulk_delete_confirm', ['count' => ':count'])"
                :confirm-label="__('lf.LF_assessment_category_bulk_delete')">
                <x-slot:create>
                    @unless ($archived)
                        <button type="button" class="ui-button ui-button--outline cursor-interaction"
                                data-ui-dialog-form-open data-ui-dialog-form-for="assessment-passage-page"
                                data-url="{{ route($questionRoute.'.create', ['passage_id' => $passage->id]) }}" aria-haspopup="dialog">
                            <x-backend-icon name="plus" class="ui-icon" />
                            {{ __('lf.LF_assessment_passage_add_question') }}
                        </button>
                    @endunless
                </x-slot:create>
            </x-ui-list-toolbar>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="questions" role="region" aria-label="{{ __('lf.LF_assessment_passage_questions') }}" tabindex="0">
            <table aria-labelledby="assessment-passage-questions-title">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col>
                    <col class="mk-col-count">
                    <col class="mk-col-status">
                    <col class="mk-col-action-wide">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="ui-check-cell">
                            <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all aria-label="{{ __('lf.LF_assessment_question_select_all') }}">
                        </th>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_question_prompt') }}</th>
                        <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_question_points') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_assessment_category_status') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($children as $question)
                        @php
                            $deleteBlocker = match (true) {
                                (int) $question->version_count > 0 => trans_choice('lf.LF_assessment_question_delete_blocked_versions', (int) $question->version_count, ['count' => $question->version_count]),
                                (int) $question->quiz_count > 0 => trans_choice('lf.LF_assessment_question_delete_blocked_quizzes', (int) $question->quiz_count, ['count' => $question->quiz_count]),
                                default => null,
                            };
                            $label = \Illuminate\Support\Str::limit((string) $question->prompt, 60);
                        @endphp
                        <tr data-passage-question="{{ $question->id }}">
                            <td class="ui-check-cell" data-label="{{ __('lf.LF_assessment_question_select') }}">
                                @if ($deleteBlocker)
                                    <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $deleteBlocker }}" data-tooltip-align="start">
                                        <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ $deleteBlocker }}">
                                    </span>
                                @else
                                    <input type="checkbox" class="ui-bulk-box" data-ui-bulk-item value="{{ $question->id }}"
                                           aria-label="{{ __('lf.LF_assessment_question_select') }}: {{ $label }}">
                                @endif
                            </td>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_question_prompt') }}">
                                <span class="mk-name-line"><span class="mk-name-text ui-passage-prompt">{{ $question->prompt }}</span></span>
                                <p class="ui-caption">{{ __('lf.LF_assessment_question_type_'.$question->question_type) }}</p>
                            </td>
                            <td class="mk-count-cell" data-label="{{ __('lf.LF_assessment_question_points') }}">{{ rtrim(rtrim(number_format((float) $question->default_points, 2, '.', ''), '0'), '.') }}</td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_assessment_category_status') }}">
                                <span class="ui-status {{ $statusClass[$question->status] ?? '' }}">{{ __('lf.LF_assessment_question_status_'.$question->status) }}</span>
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                <span class="ui-passage-move">
                                    @foreach (['up' => ! $loop->first, 'down' => ! $loop->last] as $direction => $enabled)
                                        <form method="POST" action="{{ route($routePrefix.'.questions.move', [$passage->id, $question->id]) }}">
                                            @csrf
                                            <input type="hidden" name="direction" value="{{ $direction }}">
                                            <button type="submit" class="ux-row-edit cursor-interaction" @disabled(! $enabled)
                                                    aria-label="{{ __('lf.LF_assessment_passage_move_'.$direction) }}: {{ $label }}"
                                                    data-tooltip="{{ __('lf.LF_assessment_passage_move_'.$direction) }}">
                                                <x-backend-icon name="chevron-down" @class(['ui-icon', 'ui-icon--flip' => $direction === 'up']) />
                                            </button>
                                        </form>
                                    @endforeach
                                </span>
                                <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open
                                        data-url="{{ route($questionRoute.'.edit', $question->id) }}" aria-haspopup="dialog"
                                        aria-label="{{ __('lf.LF_assessment_question_edit') }}: {{ $label }}"
                                        data-tooltip="{{ __('lf.LF_assessment_question_edit') }}">
                                    <x-backend-icon name="square-pen" class="ui-icon" />
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="6">
                                <div role="status">
                                    <strong>{{ __('lf.LF_assessment_passage_questions_empty') }}</strong>
                                    <p class="ui-caption">{{ $archived ? __('lf.LF_assessment_passage_error_archived') : __('lf.LF_assessment_passage_questions_empty_help') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @include('assessments.passages.partials.dialog-host', ['dialogTitleId' => 'assessment-passage-dialog-title assessment-question-dialog-title'])
</div>
@endsection
