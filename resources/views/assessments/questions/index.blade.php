@extends('layouts.backend')

@section('title', __('lf.LF_navigation_menu_assessment_questions'))
@section('page_title', __('lf.LF_navigation_menu_assessment_questions'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
@php
    $hasActiveFilters = $filters['keyword'] !== '' || $filters['category_id'] || $filters['question_type'] || $filters['status'];
    $statusClass = ['draft' => 'ui-status--draft', 'published' => 'ui-status--active', 'archived' => 'ui-status--archived'];
    $columns = $showAuthor ? 8 : 7;
@endphp
<div id="assessment-question-list" class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope data-ui-row-actions
     data-assessment-section="questions"
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

    <x-ui-list-head :title="__('lf.LF_navigation_menu_assessment_questions')" :text="__('lf.LF_assessment_questions_help')" />

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters">
            <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.index') }}" role="search" data-ui-live-filter>
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-question-keyword">{{ __('lf.LF_common_button_search') }}</label>
                    <div class="ui-search">
                        <input id="assessment-question-keyword" type="search" name="keyword" maxlength="255" value="{{ $filters['keyword'] }}"
                               placeholder="{{ __('lf.LF_assessment_question_search_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <x-backend-icon name="search" class="ui-icon" />
                        </button>
                    </div>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-question-category">{{ __('lf.LF_assessment_question_category') }}</label>
                    <select id="assessment-question-category" name="category_id" data-ui-kit-select
                            data-ui-empty-label="{{ __('lf.LF_assessment_category_no_options') }}"
                            data-ui-clear-label="{{ __('lf.LF_assessment_category_clear_filters') }}">
                        <option value="">{{ __('lf.LF_assessment_question_all_categories') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($filters['category_id'] === (int) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-question-type">{{ __('lf.LF_assessment_question_type') }}</label>
                    <select id="assessment-question-type" name="question_type" data-ui-kit-select
                            data-ui-empty-label="{{ __('lf.LF_assessment_category_no_options') }}"
                            data-ui-clear-label="{{ __('lf.LF_assessment_category_clear_filters') }}">
                        <option value="">{{ __('lf.LF_assessment_question_all_types') }}</option>
                        @foreach (\App\Services\Assessment\AssessmentQuestionService::TYPES as $type)
                            <option value="{{ $type }}" @selected($filters['question_type'] === $type)>{{ __('lf.LF_assessment_question_type_'.$type) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-question-status">{{ __('lf.LF_assessment_category_status') }}</label>
                    <select id="assessment-question-status" name="status" data-ui-kit-select
                            data-ui-empty-label="{{ __('lf.LF_assessment_category_no_options') }}"
                            data-ui-clear-label="{{ __('lf.LF_assessment_category_clear_filters') }}">
                        <option value="">{{ __('lf.LF_assessment_category_all_statuses') }}</option>
                        @foreach (\App\Services\Assessment\AssessmentQuestionService::STATUSES as $statusOption)
                            <option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>{{ __('lf.LF_assessment_question_status_'.$statusOption) }}</option>
                        @endforeach
                    </select>
                </div>

                <span class="ui-live-slot" data-ui-live-region="clear-filters">
                    @if ($hasActiveFilters)
                        <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.index') }}">
                            {{ __('lf.LF_assessment_category_clear_filters') }}
                        </a>
                    @endif
                </span>
            </form>

            <x-ui-list-toolbar :url="route($routePrefix.'.bulk-destroy')" name="ids[]"
                :label="__('lf.LF_assessment_category_bulk_delete')"
                :confirm-title="__('lf.LF_assessment_question_bulk_delete_title')"
                :confirm-message="__('lf.LF_assessment_question_bulk_delete_confirm', ['count' => ':count'])"
                :confirm-label="__('lf.LF_assessment_category_bulk_delete')">
                <x-slot:create>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                            data-ui-dialog-form-open data-ui-dialog-form-for="assessment-question-list"
                            data-url="{{ route($routePrefix.'.create') }}" aria-haspopup="dialog">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_assessment_question_create') }}
                    </button>
                </x-slot:create>
            </x-ui-list-toolbar>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_navigation_menu_assessment_questions') }}" tabindex="0">
            <table class="ui-asm-table" aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col>
                    <col class="ui-asm-col-category">
                    @if ($showAuthor)<col class="ui-asm-col-author">@endif
                    <col class="ui-asm-col-count">
                    <col class="ui-asm-col-status">
                    <col class="mk-col-action">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="ui-check-cell">
                            <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all aria-label="{{ __('lf.LF_assessment_question_select_all') }}">
                        </th>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_question_prompt') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_question_category') }}</th>
                        @if ($showAuthor)<th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_question_author') }}</th>@endif
                        <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_question_points') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_assessment_category_status') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($questions as $question)
                        @php
                            $deleteBlocker = match (true) {
                                (int) $question->version_count > 0 => trans_choice('lf.LF_assessment_question_delete_blocked_versions', (int) $question->version_count, ['count' => $question->version_count]),
                                (int) $question->quiz_count > 0 => trans_choice('lf.LF_assessment_question_delete_blocked_quizzes', (int) $question->quiz_count, ['count' => $question->quiz_count]),
                                default => null,
                            };
                            $label = \Illuminate\Support\Str::limit((string) $question->prompt, 60);
                        @endphp
                        <tr>
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
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $questions->firstItem() + $loop->index }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_question_prompt') }}">
                                <span class="mk-name-line"><span class="mk-name-text">{{ \Illuminate\Support\Str::limit((string) $question->prompt, 140) }}</span></span>
                                <p class="ui-caption">
                                    {{ __('lf.LF_assessment_question_type_'.$question->question_type) }}
                                    @if ($question->skill_type) · {{ __('lf.LF_assessment_question_skill_'.$question->skill_type) }} @endif
                                    @if ($question->difficulty_level) · {{ __('lf.LF_assessment_question_level_'.$question->difficulty_level) }} @endif
                                    @if ($question->passage_title) · {{ __('lf.LF_assessment_question_in_passage', ['title' => $question->passage_title]) }} @endif
                                    @if ((int) $question->media_count > 0) · {{ trans_choice('lf.LF_assessment_question_media_count', (int) $question->media_count, ['count' => $question->media_count]) }} @endif
                                </p>
                            </td>
                            <td @class(['mk-text-cell', 'mk-na' => ! $question->category_name]) data-label="{{ __('lf.LF_assessment_question_category') }}">
                                {{ $question->category_name ?? __('lf.LF_assessment_question_no_category') }}
                            </td>
                            @if ($showAuthor)
                                <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_question_author') }}">{{ $question->author_name }}</td>
                            @endif
                            <td class="mk-count-cell" data-label="{{ __('lf.LF_assessment_question_points') }}">{{ rtrim(rtrim(number_format((float) $question->default_points, 2, ',', '.'), '0'), ',') }}</td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_assessment_category_status') }}">
                                <span class="ui-status {{ $statusClass[$question->status] ?? '' }}">{{ __('lf.LF_assessment_question_status_'.$question->status) }}</span>
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open
                                        data-url="{{ route($routePrefix.'.edit', $question->id) }}" aria-haspopup="dialog"
                                        aria-label="{{ __('lf.LF_assessment_question_edit') }}: {{ $label }}"
                                        data-tooltip="{{ __('lf.LF_assessment_question_edit') }}">
                                    <x-backend-icon name="square-pen" class="ui-icon" />
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="{{ $columns }}">
                                <div role="status">
                                    <strong>{{ $hasActiveFilters ? __('lf.LF_assessment_question_filter_empty') : __('lf.LF_assessment_question_empty') }}</strong>
                                    <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_assessment_category_filter_empty_help') : __('lf.LF_assessment_question_empty_help') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$questions"
            :label="__('lf.LF_navigation_menu_assessment_questions')"
            :page-size-label="__('lf.LF_assessment_category_page_size')"
            :page-size-option="__('lf.LF_assessment_category_page_size_option', ['count' => $questions->perPage()])" />
    </section>

    <dialog class="ui-modal" data-columns="3" aria-labelledby="assessment-question-dialog-title" data-ui-dialog-form
            data-msg-load-error="{{ __('lf.LF_assessment_category_dialog_load_error') }}"
            data-msg-discard-title="{{ __('lf.LF_assessment_category_dialog_discard_title') }}"
            data-msg-discard-text="{{ __('lf.LF_assessment_category_dialog_discard_text') }}"
            data-msg-discard="{{ __('lf.LF_assessment_category_dialog_discard') }}"
            data-msg-save-error="{{ __('lf.LF_assessment_category_dialog_save_error') }}"
            data-msg-session-expired="{{ __('lf.LF_assessment_category_session_expired') }}"
            data-msg-required-input="{{ __('lf.LF_assessment_category_dialog_required_input') }}"
            data-msg-required-select="{{ __('lf.LF_assessment_category_dialog_required_select') }}"></dialog>
</div>
@endsection
