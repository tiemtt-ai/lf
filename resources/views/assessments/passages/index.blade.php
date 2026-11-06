@extends('layouts.backend')

@section('title', __('lf.LF_navigation_menu_assessment_passages'))
@section('page_title', __('lf.LF_navigation_menu_assessment_passages'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
@php
    $hasActiveFilters = $filters['keyword'] !== '' || $filters['category_id'] || $filters['skill_type'] || $filters['status'];
    $statusClass = ['draft' => 'ui-status--draft', 'published' => 'ui-status--active', 'archived' => 'ui-status--archived'];
    $columns = $showAuthor ? 8 : 7;
    $selectLabels = 'data-ui-empty-label="'.e(__('lf.LF_assessment_category_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_assessment_category_clear_filters')).'"';
@endphp
<div id="assessment-passage-list" class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope data-ui-row-actions
     data-assessment-section="passages"
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

    <x-ui-list-head :title="__('lf.LF_navigation_menu_assessment_passages')" :text="__('lf.LF_assessment_passages_help')" />

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-tablebar mk-tablebar--filters">
            <form class="mk-filter-form" method="GET" action="{{ route($routePrefix.'.index') }}" role="search" data-ui-live-filter>
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-passage-keyword">{{ __('lf.LF_common_button_search') }}</label>
                    <div class="ui-search">
                        <input id="assessment-passage-keyword" type="search" name="keyword" maxlength="255" value="{{ $filters['keyword'] }}"
                               placeholder="{{ __('lf.LF_assessment_passage_search_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <x-backend-icon name="search" class="ui-icon" />
                        </button>
                    </div>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-passage-category">{{ __('lf.LF_assessment_question_category') }}</label>
                    <select id="assessment-passage-category" name="category_id" data-ui-kit-select {!! $selectLabels !!}>
                        <option value="">{{ __('lf.LF_assessment_question_all_categories') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($filters['category_id'] === (int) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-passage-skill">{{ __('lf.LF_assessment_question_skill') }}</label>
                    <select id="assessment-passage-skill" name="skill_type" data-ui-kit-select {!! $selectLabels !!}>
                        <option value="">{{ __('lf.LF_assessment_passage_all_skills') }}</option>
                        @foreach (\App\Services\Assessment\AssessmentQuestionService::SKILLS as $skill)
                            <option value="{{ $skill }}" @selected($filters['skill_type'] === $skill)>{{ __('lf.LF_assessment_question_skill_'.$skill) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="assessment-passage-status">{{ __('lf.LF_assessment_category_status') }}</label>
                    <select id="assessment-passage-status" name="status" data-ui-kit-select {!! $selectLabels !!}>
                        <option value="">{{ __('lf.LF_assessment_category_all_statuses') }}</option>
                        @foreach (\App\Services\Assessment\AssessmentPassageService::STATUSES as $statusOption)
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
                :confirm-title="__('lf.LF_assessment_passage_bulk_delete_title')"
                :confirm-message="__('lf.LF_assessment_passage_bulk_delete_confirm', ['count' => ':count'])"
                :confirm-label="__('lf.LF_assessment_category_bulk_delete')">
                <x-slot:create>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                            data-ui-dialog-form-open data-ui-dialog-form-for="assessment-passage-list"
                            data-url="{{ route($routePrefix.'.create') }}" aria-haspopup="dialog">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_assessment_passage_create') }}
                    </button>
                </x-slot:create>
            </x-ui-list-toolbar>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="table" role="region" aria-label="{{ __('lf.LF_navigation_menu_assessment_passages') }}" tabindex="0">
            <table class="ui-asm-table" aria-labelledby="admin-page-title">
                <colgroup>
                    <col class="ui-col-check">
                    <col class="mk-col-sequence">
                    <col>
                    <col class="ui-asm-col-category">
                    @if ($showAuthor)<col class="ui-asm-col-author">@endif
                    <col class="ui-asm-col-count">
                    <col class="ui-asm-col-status">
                    <col class="mk-col-action-wide">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="ui-check-cell">
                            <input type="checkbox" class="ui-bulk-box" data-ui-bulk-select-all aria-label="{{ __('lf.LF_assessment_passage_select_all') }}">
                        </th>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_passage_title') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_question_category') }}</th>
                        @if ($showAuthor)<th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_question_author') }}</th>@endif
                        <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_passage_question_count') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_assessment_category_status') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($passages as $passage)
                        @php
                            $deleteBlocker = match (true) {
                                $passage->usage['versions'] > 0 => trans_choice('lf.LF_assessment_passage_delete_blocked_versions', $passage->usage['versions'], ['count' => $passage->usage['versions']]),
                                $passage->usage['quizzes'] > 0 => trans_choice('lf.LF_assessment_passage_delete_blocked_quizzes', $passage->usage['quizzes'], ['count' => $passage->usage['quizzes']]),
                                default => null,
                            };
                        @endphp
                        <tr>
                            <td class="ui-check-cell" data-label="{{ __('lf.LF_assessment_passage_select') }}">
                                @if ($deleteBlocker)
                                    <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $deleteBlocker }}" data-tooltip-align="start">
                                        <input type="checkbox" class="ui-bulk-box" disabled aria-label="{{ $deleteBlocker }}">
                                    </span>
                                @else
                                    <input type="checkbox" class="ui-bulk-box" data-ui-bulk-item value="{{ $passage->id }}"
                                           aria-label="{{ __('lf.LF_assessment_passage_select') }}: {{ $passage->title }}">
                                @endif
                            </td>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $passages->firstItem() + $loop->index }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_passage_title') }}">
                                <span class="mk-name-line">
                                    <a class="mk-name-text" href="{{ route($routePrefix.'.show', $passage->id) }}">{{ $passage->title }}</a>
                                </span>
                                <p class="ui-caption">
                                    {{ $passage->skill_type ? __('lf.LF_assessment_question_skill_'.$passage->skill_type) : __('lf.LF_assessment_question_not_set') }}
                                    @if ($passage->difficulty_level) · {{ __('lf.LF_assessment_question_level_'.$passage->difficulty_level) }} @endif
                                    @if ((int) $passage->media_count > 0) · {{ trans_choice('lf.LF_assessment_question_media_count', (int) $passage->media_count, ['count' => $passage->media_count]) }} @endif
                                </p>
                            </td>
                            <td @class(['mk-text-cell', 'mk-na' => ! $passage->category_name]) data-label="{{ __('lf.LF_assessment_question_category') }}">
                                {{ $passage->category_name ?? __('lf.LF_assessment_question_no_category') }}
                            </td>
                            @if ($showAuthor)
                                <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_question_author') }}">{{ $passage->author_name }}</td>
                            @endif
                            <td @class(['mk-count-cell', 'mk-na' => (int) $passage->question_count === 0]) data-label="{{ __('lf.LF_assessment_passage_question_count') }}">{{ number_format((int) $passage->question_count) }}</td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_assessment_category_status') }}">
                                <span class="ui-status {{ $statusClass[$passage->status] ?? '' }}">{{ __('lf.LF_assessment_question_status_'.$passage->status) }}</span>
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                <a class="ux-row-edit cursor-interaction" href="{{ route($routePrefix.'.show', $passage->id) }}"
                                   aria-label="{{ __('lf.LF_assessment_passage_manage') }}: {{ $passage->title }}" data-tooltip="{{ __('lf.LF_assessment_passage_manage') }}">
                                    <x-backend-icon name="sliders" class="ui-icon" />
                                </a>
                                <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open
                                        data-url="{{ route($routePrefix.'.edit', $passage->id) }}" aria-haspopup="dialog"
                                        aria-label="{{ __('lf.LF_assessment_passage_edit') }}: {{ $passage->title }}"
                                        data-tooltip="{{ __('lf.LF_assessment_passage_edit') }}">
                                    <x-backend-icon name="square-pen" class="ui-icon" />
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="{{ $columns }}">
                                <div role="status">
                                    <strong>{{ $hasActiveFilters ? __('lf.LF_assessment_passage_filter_empty') : __('lf.LF_assessment_passage_empty') }}</strong>
                                    <p class="ui-caption">{{ $hasActiveFilters ? __('lf.LF_assessment_category_filter_empty_help') : __('lf.LF_assessment_passage_empty_help') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui-list-foot :paginator="$passages"
            :label="__('lf.LF_navigation_menu_assessment_passages')"
            :page-size-label="__('lf.LF_assessment_category_page_size')"
            :page-size-option="__('lf.LF_assessment_category_page_size_option', ['count' => $passages->perPage()])" />
    </section>

    @include('assessments.passages.partials.dialog-host', ['dialogTitleId' => 'assessment-passage-dialog-title'])
</div>
@endsection
