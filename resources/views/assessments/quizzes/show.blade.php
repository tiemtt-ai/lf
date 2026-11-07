@extends('layouts.backend')

@section('title', $quiz->title)
@section('page_title', $quiz->title)

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="route($routePrefix.'.index')" :label="__('lf.LF_navigation_menu_assessment_quizzes')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js'])
@endsection

@section('content')
@php
    $statusClass = ['draft' => 'ui-status--draft', 'published' => 'ui-status--active', 'archived' => 'ui-status--archived'];
    // Tabs as on the Course Template page; every panel is rendered so a dialog save can refresh its live region.
    $tabs = [
        'info' => __('lf.LF_assessment_quiz_tab_info'),
        'questions' => __('lf.LF_assessment_quiz_tab_questions'),
        'graders' => __('lf.LF_assessment_quiz_tab_graders'),
        'publish' => __('lf.LF_assessment_quiz_tab_publish'),
    ];
    $activeTab = array_key_exists((string) request()->query('tab'), $tabs) ? (string) request()->query('tab') : 'info';
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $multiSection = $sections->count() > 1;
    $position = 0;
@endphp
<div id="assessment-quiz-page" class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope data-ui-row-actions
     data-assessment-section="quizzes" data-assessment-quiz="{{ $quiz->id }}"
     data-csrf="{{ csrf_token() }}"
     data-msg-delete-error="{{ __('lf.LF_assessment_question_delete_error') }}"
     data-msg-session-expired="{{ __('lf.LF_assessment_category_session_expired') }}">
    <x-ui-list-toasts />

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Saving a dialog refetches this page and swaps the live regions below. --}}
    <form method="GET" action="{{ route($routePrefix.'.show', $quiz->id) }}" data-ui-live-filter hidden>
        {{-- The refetched page must render the same tab, or the swapped panels would come back hidden. --}}
        <input type="hidden" name="tab" value="{{ $activeTab }}">
    </form>

    {{-- The whole quiz: name, state, and the buttons that change the state of the whole quiz. --}}
    <section class="mk-section ui-framework__identity ui-quiz-identity" aria-labelledby="assessment-quiz-identity-title" data-ui-live-region="identity">
        <div class="ui-version__identity">
            <p class="ui-version__eyebrow">
                {{ __('lf.LF_assessment_quiz_type_'.$quiz->assessment_type) }} ·
                @if ($currentVersion)
                    {{ __('lf.LF_assessment_quiz_current_version', ['number' => $currentVersion->version_no, 'date' => \Illuminate\Support\Carbon::parse($currentVersion->published_at)->format('d/m/Y H:i')]) }}
                @else
                    {{ __('lf.LF_assessment_quiz_never_published') }}
                @endif
            </p>
            <h2 id="assessment-quiz-identity-title">{{ $quiz->title }}</h2>
            <div class="ui-version__chips">
                <span class="ui-status {{ $statusClass[$quiz->status] ?? '' }}">{{ __('lf.LF_assessment_quiz_status_'.$quiz->status) }}</span>
                <span @class(['ui-status', 'ui-status--active' => $quiz->registration_open, 'ui-status--archived' => ! $quiz->registration_open])>
                    {{ $quiz->registration_open ? __('lf.LF_assessment_quiz_registration_open') : __('lf.LF_assessment_quiz_registration_closed_state') }}
                </span>
                <span class="ui-caption">{{ trans_choice('lf.LF_assessment_registration_active_count', $activeRegistrations, ['count' => $activeRegistrations]) }}</span>
            </div>
        </div>
        @if ($quiz->status !== 'draft')
            <div class="ui-enroll-head__actions">
                @if ($quiz->status === 'published')
                    <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-dialog-form-open
                            data-ui-dialog-form-for="assessment-quiz-page"
                            data-url="{{ route(str_replace('quizzes', 'registrations', $routePrefix).'.create', ['quiz_id' => $quiz->id]) }}" aria-haspopup="dialog">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_assessment_registration_grant_short') }}
                    </button>
                    <form method="POST" action="{{ route($routePrefix.'.registration', $quiz->id) }}" class="ui-inline-form">
                        @csrf
                        <input type="hidden" name="open" value="{{ $quiz->registration_open ? 0 : 1 }}">
                        <button type="submit" class="ui-button ui-button--outline cursor-interaction">
                            {{ $quiz->registration_open ? __('lf.LF_assessment_quiz_close_registration') : __('lf.LF_assessment_quiz_open_registration') }}
                        </button>
                    </form>
                    <form method="POST" action="{{ route($routePrefix.'.archive', $quiz->id) }}" class="ui-inline-form">
                        @csrf
                        <button type="submit" class="ui-button ui-button--outline cursor-interaction">{{ __('lf.LF_assessment_quiz_archive') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route($routePrefix.'.restore', $quiz->id) }}" class="ui-inline-form">
                        @csrf
                        <button type="submit" class="ui-button ui-button--outline cursor-interaction">{{ __('lf.LF_assessment_quiz_restore') }}</button>
                    </form>
                @endif
            </div>
        @endif
    </section>

    {{-- Always present, so a dialog save can show or hide the notice. --}}
    <div data-ui-live-region="changes">
        @if ($hasChanges && $quiz->status !== 'archived')
            <div class="ui-quiz-notice" role="status">
                <x-backend-icon name="circle-alert" class="ui-icon" />
                <span>
                    {{ __('lf.LF_assessment_quiz_unpublished_changes') }}
                    @if ($activeTab !== 'publish')
                        <a href="{{ route($routePrefix.'.show', ['id' => $quiz->id, 'tab' => 'publish']) }}">{{ __('lf.LF_assessment_quiz_go_publish') }}</a>
                    @endif
                </span>
            </div>
        @endif
    </div>

    <nav class="course-template-tabs ui-quiz-tabs" aria-label="{{ __('lf.LF_assessment_quiz_tabs_label') }}">
        @foreach ($tabs as $tab => $label)
            <a href="{{ route($routePrefix.'.show', ['id' => $quiz->id, 'tab' => $tab]) }}"
               @class(['course-template-tab', 'is-active' => $activeTab === $tab])
               @if ($activeTab === $tab) aria-current="page" @endif>
                {{ $label }}
                @if ($tab === 'questions')<span class="ui-tab-count" data-ui-live-region="tab-questions">{{ $itemCount }}</span>@endif
                @if ($tab === 'publish' && $versions->isNotEmpty())<span class="ui-tab-count">{{ $versions->count() }}</span>@endif
            </a>
        @endforeach
    </nav>

    {{-- Thông tin: the settings a new version will carry. --}}
    <section id="assessment-quiz-tab-info" class="mk-section" aria-labelledby="assessment-quiz-info-title" data-ui-live-region="info"
             @if ($activeTab !== 'info') hidden @endif>
        <div class="ui-summary-head">
            <div>
                <h2 id="assessment-quiz-info-title">{{ __('lf.LF_assessment_quiz_info') }}</h2>
                <p class="ui-caption">{{ __('lf.LF_assessment_quiz_info_help') }}</p>
            </div>
            <div class="mk-table-top__actions">
                <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-dialog-form-open
                        data-ui-dialog-form-for="assessment-quiz-page"
                        data-url="{{ route($routePrefix.'.edit', $quiz->id) }}" aria-haspopup="dialog">
                    <x-backend-icon name="square-pen" class="ui-icon" />
                    {{ __('lf.LF_assessment_quiz_edit') }}
                </button>
            </div>
        </div>
        <dl class="ui-summary ui-quiz-summary">
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_quiz_type') }}</dt>
                <dd>{{ __('lf.LF_assessment_quiz_type_'.$quiz->assessment_type) }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_question_category') }}</dt>
                <dd>{{ $category ?? __('lf.LF_assessment_question_no_category') }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_quiz_duration') }}</dt>
                <dd>{{ $quiz->duration_minutes ? __('lf.LF_assessment_quiz_minutes', ['count' => $quiz->duration_minutes]) : __('lf.LF_assessment_quiz_no_time_limit') }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_quiz_max_attempts') }}</dt>
                <dd>{{ trans_choice('lf.LF_assessment_quiz_attempts_count', (int) $quiz->max_attempts, ['count' => $quiz->max_attempts]) }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_quiz_pass_score') }}</dt>
                <dd>{{ $quiz->pass_score_percentage !== null ? $trim($quiz->pass_score_percentage).'%' : __('lf.LF_assessment_quiz_no_pass_score') }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_assessment_quiz_show_result') }}</dt>
                <dd>{{ __('lf.LF_assessment_quiz_result_'.$quiz->show_result_mode) }}</dd>
            </div>
            @if (filled($quiz->description))
                <div class="ui-summary__item ui-summary__item--wide">
                    <dt>{{ __('lf.LF_assessment_quiz_description') }}</dt>
                    <dd class="ui-passage-content">{{ $quiz->description }}</dd>
                </div>
            @endif
        </dl>
    </section>

    {{-- Câu hỏi: the draft, its sections and chosen questions. --}}
    <section id="assessment-quiz-tab-questions" class="mk-section" aria-labelledby="assessment-quiz-questions-title"
             @if ($activeTab !== 'questions') hidden @endif>
        <div class="mk-tablebar">
            <div>
                <h2 id="assessment-quiz-questions-title" class="ui-passage-section-title">{{ __('lf.LF_assessment_quiz_questions') }}</h2>
                <p class="ui-caption" data-ui-live-region="totals">{{ trans_choice('lf.LF_assessment_quiz_totals', $itemCount, ['count' => $itemCount, 'points' => $trim($totalPoints)]) }}</p>
            </div>
            <x-ui-list-toolbar :url="route($routePrefix.'.questions.remove', $quiz->id)" name="ids[]"
                :label="__('lf.LF_assessment_quiz_remove')"
                :confirm-title="__('lf.LF_assessment_quiz_remove_title')"
                :confirm-message="__('lf.LF_assessment_quiz_remove_confirm', ['count' => ':count'])"
                :confirm-label="__('lf.LF_assessment_quiz_remove')">
                <x-slot:create>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                            data-ui-dialog-form-open data-ui-dialog-form-for="assessment-quiz-page"
                            data-url="{{ route($routePrefix.'.questions.pick', $quiz->id) }}" aria-haspopup="dialog">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_assessment_quiz_add_questions') }}
                    </button>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                            data-ui-dialog-form-open data-ui-dialog-form-for="assessment-quiz-page"
                            data-url="{{ route($routePrefix.'.questions.auto', $quiz->id) }}" aria-haspopup="dialog">
                        <x-backend-icon name="shuffle" class="ui-icon" />
                        {{ __('lf.LF_assessment_quiz_auto') }}
                    </button>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                            data-ui-dialog-form-open data-ui-dialog-form-for="assessment-quiz-page"
                            data-url="{{ route($routePrefix.'.sections.create', $quiz->id) }}" aria-haspopup="dialog">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_assessment_quiz_section_create') }}
                    </button>
                </x-slot:create>
            </x-ui-list-toolbar>
        </div>

        <div class="mk-table-scroll" data-ui-live-region="questions" role="region" aria-label="{{ __('lf.LF_assessment_quiz_questions') }}" tabindex="0">
            <table aria-labelledby="assessment-quiz-questions-title">
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
                @foreach ($sections as $section)
                    @php $sectionItems = $items->get($section->id, collect()); @endphp
                    <tbody data-quiz-section="{{ $section->id }}">
                        @if ($multiSection)
                            <tr class="ui-quiz-section-row">
                                <th scope="rowgroup" colspan="5" class="mk-text-cell">
                                    <span class="ui-quiz-section-title">{{ $section->title }}</span>
                                    <span class="ui-caption">
                                        @if ($section->skill_type) {{ __('lf.LF_assessment_question_skill_'.$section->skill_type) }} · @endif
                                        {{ trans_choice('lf.LF_assessment_quiz_totals', $sectionItems->count(), ['count' => $sectionItems->count(), 'points' => $trim($sectionItems->sum(fn ($i) => (float) $i->points))]) }}
                                    </span>
                                </th>
                                <td class="ux-action-cell">
                                    <span class="ui-passage-move">
                                        @foreach (['up' => ! $loop->first, 'down' => ! $loop->last] as $direction => $enabled)
                                            <form method="POST" action="{{ route($routePrefix.'.sections.move', [$quiz->id, $section->id]) }}">
                                                @csrf
                                                <input type="hidden" name="direction" value="{{ $direction }}">
                                                <button type="submit" class="ux-row-edit cursor-interaction" @disabled(! $enabled)
                                                        aria-label="{{ __('lf.LF_assessment_passage_move_'.$direction) }}: {{ $section->title }}"
                                                        data-tooltip="{{ __('lf.LF_assessment_passage_move_'.$direction) }}">
                                                    <x-backend-icon name="chevron-down" @class(['ui-icon', 'ui-icon--flip' => $direction === 'up']) />
                                                </button>
                                            </form>
                                        @endforeach
                                    </span>
                                    <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open
                                            data-url="{{ route($routePrefix.'.sections.edit', [$quiz->id, $section->id]) }}" aria-haspopup="dialog"
                                            aria-label="{{ __('lf.LF_assessment_quiz_section_edit') }}: {{ $section->title }}"
                                            data-tooltip="{{ __('lf.LF_assessment_quiz_section_edit') }}">
                                        <x-backend-icon name="square-pen" class="ui-icon" />
                                    </button>
                                    <button type="button" class="ux-row-edit cursor-interaction" data-ui-row-delete
                                            data-url="{{ route($routePrefix.'.sections.destroy', [$quiz->id, $section->id]) }}"
                                            data-confirm-title="{{ __('lf.LF_assessment_quiz_section_delete') }}"
                                            data-confirm-message="{{ __('lf.LF_assessment_quiz_section_delete_confirm', ['title' => $section->title]) }}"
                                            data-confirm-label="{{ __('lf.LF_assessment_category_bulk_delete') }}"
                                            @disabled($sectionItems->isNotEmpty())
                                            aria-label="{{ __('lf.LF_assessment_quiz_section_delete') }}: {{ $section->title }}"
                                            data-tooltip="{{ $sectionItems->isNotEmpty() ? __('lf.LF_assessment_quiz_section_error_not_empty') : __('lf.LF_assessment_quiz_section_delete') }}">
                                        <x-backend-icon name="trash-2" class="ui-icon" />
                                    </button>
                                </td>
                            </tr>
                        @endif
                        @forelse ($sectionItems as $item)
                            @php
                                $position++;
                                $label = __('lf.LF_assessment_quiz_question_number', ['number' => $position]);
                                $notReady = $item->question_status !== 'published' || ($item->passage_id && $item->passage_status !== 'published');
                            @endphp
                            <tr data-quiz-item="{{ $item->id }}">
                                <td class="ui-check-cell" data-label="{{ __('lf.LF_assessment_question_select') }}">
                                    <input type="checkbox" class="ui-bulk-box" data-ui-bulk-item value="{{ $item->id }}"
                                           aria-label="{{ __('lf.LF_assessment_question_select') }}: {{ $label }}">
                                </td>
                                <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $position }}</td>
                                <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_question_prompt') }}">
                                    <span class="mk-name-line"><span class="mk-name-text ui-passage-prompt">{{ $item->prompt }}</span></span>
                                    <p class="ui-caption">
                                        {{ __('lf.LF_assessment_question_type_'.$item->question_type) }}
                                        @if ($item->passage_title) · {{ __('lf.LF_assessment_question_in_passage', ['title' => $item->passage_title]) }} @endif
                                    </p>
                                </td>
                                <td class="mk-count-cell" data-label="{{ __('lf.LF_assessment_question_points') }}">{{ $trim($item->points) }}</td>
                                <td class="ux-action-cell" data-label="{{ __('lf.LF_assessment_category_status') }}">
                                    @if ($notReady)
                                        <span class="ui-status ui-status--inactive" data-tooltip="{{ __('lf.LF_assessment_quiz_item_not_ready_help') }}">{{ __('lf.LF_assessment_quiz_item_not_ready') }}</span>
                                    @else
                                        <span class="ui-status ui-status--active">{{ __('lf.LF_assessment_question_status_published') }}</span>
                                    @endif
                                </td>
                                <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                    <span class="ui-passage-move">
                                        @foreach (['up' => ! $loop->first, 'down' => ! $loop->last] as $direction => $enabled)
                                            <form method="POST" action="{{ route($routePrefix.'.questions.move', [$quiz->id, $item->id]) }}">
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
                                            data-url="{{ route($routePrefix.'.questions.edit', [$quiz->id, $item->id]) }}" aria-haspopup="dialog"
                                            aria-label="{{ __('lf.LF_assessment_quiz_item_edit') }}: {{ $label }}"
                                            data-tooltip="{{ __('lf.LF_assessment_quiz_item_edit') }}">
                                        <x-backend-icon name="square-pen" class="ui-icon" />
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr class="mk-empty-row">
                                <td class="mk-empty" colspan="6">
                                    <div role="status">
                                        <strong>{{ __('lf.LF_assessment_quiz_questions_empty') }}</strong>
                                        <p class="ui-caption">{{ __('lf.LF_assessment_quiz_questions_empty_help') }}</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                @endforeach
            </table>
        </div>
    </section>

    {{-- Người chấm (ADR-0027 D2): teachers who grade this quiz, besides admins and the quiz creator. --}}
    <section id="assessment-quiz-tab-graders" class="mk-section" aria-labelledby="assessment-quiz-graders-title" data-ui-live-region="graders"
             data-assessment-quiz-graders @if ($activeTab !== 'graders') hidden @endif>
        @php
            $graderRows = $quizGraders->map(fn ($teacher) => ['name' => $teacher->name, 'from' => __('lf.LF_assessment_quiz_grader_from_quiz')])
                ->concat($inheritedGraders->whereNotIn('id', $quizGraders->pluck('id'))
                    ->map(fn ($teacher) => ['name' => $teacher->name, 'from' => __('lf.LF_assessment_quiz_grader_from_category', ['category' => $teacher->category_name])]))
                ->values();
        @endphp
        <div class="ui-summary-head">
            <div>
                <h2 id="assessment-quiz-graders-title">{{ __('lf.LF_assessment_grader_graders') }}</h2>
                <p class="ui-caption">{{ __('lf.LF_assessment_quiz_graders_help') }}</p>
            </div>
            <div class="mk-table-top__actions">
                <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-dialog-form-open
                        data-ui-dialog-form-for="assessment-quiz-page"
                        data-url="{{ route($routePrefix.'.graders.edit', $quiz->id) }}" aria-haspopup="dialog">
                    <x-backend-icon name="user-cog" class="ui-icon" />
                    {{ __('lf.LF_assessment_grader_quiz_button') }}
                </button>
            </div>
        </div>
        <div class="mk-table-scroll" role="region" aria-label="{{ __('lf.LF_assessment_grader_graders') }}" tabindex="0">
            <table aria-labelledby="assessment-quiz-graders-title">
                <colgroup>
                    <col class="mk-col-sequence">
                    <col>
                    <col>
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_grader_teachers') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_quiz_grader_from') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($graderRows as $row)
                        <tr>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_grader_teachers') }}">{{ $row['name'] }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_quiz_grader_from') }}">{{ $row['from'] }}</td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="3">
                                <div role="status">
                                    <strong>{{ __('lf.LF_assessment_quiz_graders_empty') }}</strong>
                                    <p class="ui-caption">{{ __('lf.LF_assessment_quiz_graders_empty_help') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Xuất bản: publish the draft as a new frozen version; versions are never changed. --}}
    <section id="assessment-quiz-tab-publish" class="mk-section" aria-labelledby="assessment-quiz-versions-title" data-ui-live-region="versions"
             @if ($activeTab !== 'publish') hidden @endif>
        <div class="ui-summary-head">
            <div>
                <h2 id="assessment-quiz-versions-title">{{ __('lf.LF_assessment_quiz_versions') }}</h2>
                <p class="ui-caption">{{ __('lf.LF_assessment_quiz_versions_help') }}</p>
            </div>
            @unless ($quiz->status === 'archived')
                <div class="mk-table-top__actions">
                    <form method="POST" action="{{ route($routePrefix.'.publish', $quiz->id) }}" class="ui-inline-form">
                        @csrf
                        <button type="submit" class="ui-button ui-button--primary cursor-interaction" @disabled($itemCount === 0)
                                @if ($itemCount === 0) data-tooltip="{{ __('lf.LF_assessment_quiz_publish_error_empty') }}" @endif>
                            <x-backend-icon name="check" class="ui-icon" />
                            {{ $currentVersion ? __('lf.LF_assessment_quiz_publish_again') : __('lf.LF_assessment_quiz_publish') }}
                        </button>
                    </form>
                </div>
            @endunless
        </div>
        <div class="mk-table-scroll" role="region" aria-label="{{ __('lf.LF_assessment_quiz_versions') }}" tabindex="0">
            <table aria-labelledby="assessment-quiz-versions-title">
                <thead>
                    <tr>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_quiz_version') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_quiz_published_at') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_assessment_quiz_published_by') }}</th>
                        <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_quiz_question_count') }}</th>
                        <th scope="col" class="mk-count-cell">{{ __('lf.LF_assessment_quiz_total_points') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($versions as $version)
                        <tr>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_quiz_version') }}">
                                {{ __('lf.LF_assessment_quiz_version_number', ['number' => $version->version_no]) }}
                                @if ((int) $version->id === (int) $quiz->current_version_id)
                                    <span class="ui-status ui-status--active">{{ __('lf.LF_assessment_quiz_version_current') }}</span>
                                @endif
                            </td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_quiz_published_at') }}">{{ \Illuminate\Support\Carbon::parse($version->published_at)->format('d/m/Y H:i') }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_assessment_quiz_published_by') }}">{{ $version->publisher_name }}</td>
                            <td class="mk-count-cell" data-label="{{ __('lf.LF_assessment_quiz_question_count') }}">{{ $version->question_count }}</td>
                            <td class="mk-count-cell" data-label="{{ __('lf.LF_assessment_quiz_total_points') }}">{{ $trim($version->total_points) }}</td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row">
                            <td class="mk-empty" colspan="5">
                                <div role="status"><p class="ui-caption">{{ __('lf.LF_assessment_quiz_versions_empty') }}</p></div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @include('assessments.passages.partials.dialog-host', ['dialogTitleId' => 'assessment-quiz-dialog-title assessment-quiz-section-dialog-title assessment-quiz-item-dialog-title assessment-quiz-picker-title assessment-quiz-auto-title assessment-registration-dialog-title assessment-grader-dialog-title'])
</div>
@endsection
