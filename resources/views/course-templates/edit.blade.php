@extends('layouts.backend')

@section('title', __('lf.LF_course_template_common_edit'))
@section('page_title', __('lf.LF_course_template_common_edit'))

@section('content')
    @php
        $tabs = [
            'information' => __('lf.LF_course_template_tab_information'),
            'structure' => __('lf.LF_course_template_tab_structure'),
            'teachers' => __('lf.LF_course_template_tab_teachers'),
            'publish' => __('lf.LF_course_template_tab_publish'),
            'history' => __('lf.LF_course_template_tab_history'),
        ];
        $activeTab = request()->query('tab', 'information');
        $activeTab = array_key_exists($activeTab, $tabs)
            ? $activeTab
            : 'information';
        $readinessIssueTitleKey = static fn (object $issue): string => match ($issue->code) {
            'template_status' => 'lf.LF_course_template_readiness_issue_status_title',
            'template_category', 'template_category_inactive' => 'lf.LF_course_template_readiness_issue_category_title',
            'template_title' => 'lf.LF_course_template_readiness_issue_title_title',
            'template_publisher' => 'lf.LF_course_template_readiness_issue_publisher_title',
            'template_difficulty' => 'lf.LF_course_template_readiness_issue_difficulty_title',
            'template_estimated_minutes' => 'lf.LF_course_template_readiness_issue_estimated_minutes_title',
            'template_estimated_lesson_count' => 'lf.LF_course_template_readiness_issue_estimated_lessons_title',
            'template_estimated_lesson_count_mismatch' => 'lf.LF_course_template_readiness_issue_lesson_count_warning_title',
            'template_teacher_missing' => 'lf.LF_course_template_readiness_issue_teacher_warning_title',
            'template_intro_image', 'template_intro_video', 'template_intro_document', 'video_state' => 'lf.LF_course_template_readiness_issue_media_title',
            default => $issue->targetTab === 'content'
                ? 'lf.LF_course_template_readiness_issue_content_title'
                : 'lf.LF_course_template_readiness_issue_information_title',
        };
        $readinessIssueActionKey = static fn (object $issue): string => match ($issue->code) {
            'template_status' => 'lf.LF_course_template_readiness_action_change_status',
            'template_category', 'template_category_inactive' => 'lf.LF_course_template_readiness_action_review_category',
            'template_estimated_lesson_count', 'template_estimated_lesson_count_mismatch' => 'lf.LF_course_template_readiness_action_review_lesson_count',
            'template_teacher_missing' => 'lf.LF_course_template_readiness_action_assign_teacher',
            'template_intro_image', 'template_intro_video', 'template_intro_document', 'video_state' => 'lf.LF_course_template_readiness_action_review_media',
            default => $issue->targetTab === 'content'
                ? 'lf.LF_course_template_readiness_action_review_content'
                : 'lf.LF_course_template_readiness_action_review_field',
        };
    @endphp

    @if (session('course_template_created_title'))
        <div class="admin-alert admin-alert-success admin-form-card"
             role="status"
             aria-live="polite">
            <strong class="admin-alert-title">
                {{ session('course_template_created_title') }}
            </strong>
            <p class="admin-alert-guidance">
                {{ session('course_template_created_guidance') }}
            </p>
        </div>
    @elseif (session('success'))
        <div class="admin-alert admin-alert-success admin-form-card">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="course-template-authoring">
        <nav class="course-template-tabs"
             aria-label="{{ __('lf.LF_course_template_tabs_label') }}">
            @foreach ($tabs as $tab => $label)
                <a href="{{ route(
                    $routePrefix.'.edit',
                    ['id' => $template->id, 'tab' => $tab]
                ) }}"
                   @class([
                       'course-template-tab',
                       'is-active' => $activeTab === $tab,
                   ])
                   @if ($activeTab === $tab) aria-current="page" @endif>
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <section id="course-template-tab-information"
                 class="course-template-tab-panel"
                 @if ($activeTab !== 'information') hidden @endif>
            <div class="admin-card admin-form-card admin-form-surface">
                <form id="course-template-update-form"
                      class="admin-form-standard"
                      method="POST"
                      action="{{ route($routePrefix.'.update', $template->id) }}"
                      enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    @if ($template->status === \App\Support\CourseTemplateStatus::ARCHIVED)<fieldset disabled>@endif
                    @include('course-templates.partials.form')
                    @if ($template->status === \App\Support\CourseTemplateStatus::ARCHIVED)</fieldset>@endif

                </form>

                <footer class="admin-form-footer" data-actions-align="end">
                    <div class="admin-form-footer-danger">
                        @if ($template->status === \App\Support\CourseTemplateStatus::INACTIVE
                            && \Illuminate\Support\Facades\Route::has($routePrefix.'.archive'))
                            <form method="POST"
                              action="{{ route($routePrefix.'.archive', $template->id) }}"
                              x-data="{ submitting: false }"
                              data-lf-confirm="{{ __('lf.LF_course_template_status_archive_confirm') }}"
                              data-lf-confirm-tone="danger"
                              @submit="if (submitting) { $event.preventDefault(); return } submitting = true">
                            @csrf
                            <button type="submit"
                                    class="btn btn-danger"
                                    :disabled="submitting"
                                    :aria-busy="submitting">
                                {{ __('lf.LF_course_template_status_archive_action') }}
                            </button>
                        </form>
                        @endif
                    </div>

                    <div class="admin-form-footer-primary">
                        <a href="{{ route($routePrefix.'.index') }}" class="btn btn-secondary">
                            {{ __('lf.LF_common_button_cancel') }}
                        </a>
                        @if ($template->status !== \App\Support\CourseTemplateStatus::ARCHIVED)
                            <button type="submit"
                                    form="course-template-update-form"
                                    class="btn btn-primary">
                                {{ __('lf.LF_common_button_save_changes') }}
                            </button>
                        @endif
                    </div>
                </footer>
            </div>
        </section>

        <section id="course-template-tab-structure"
                 class="course-template-tab-panel"
                 @if ($activeTab !== 'structure') hidden @endif>
            @include('course-template-sections.partials.list')
        </section>

        <section id="course-template-tab-teachers"
                 class="course-template-tab-panel"
                 @if ($activeTab !== 'teachers') hidden @endif>
            @include('course-template-teachers.partials.list')
        </section>

        <section id="course-template-tab-publish"
                 class="course-template-tab-panel"
                 @if ($activeTab !== 'publish') hidden @endif>
            <div class="admin-card admin-form-card course-template-publish-card">
                <h2 id="course-template-publish-title"
                    class="admin-form-section-title">
                    {{ __('lf.LF_course_template_publish_title') }}
                </h2>

                <dl class="course-template-publish-summary"
                    aria-labelledby="course-template-publish-title">
                    <div>
                        <dt>{{ __('lf.LF_course_template_publish_current_working') }}</dt>
                        <dd>
                            {{ __('lf.LF_course_template_publish_current_draft_value', [
                                'status' => __('lf.LF_course_template_common_'.$template->status),
                                'revision' => $template->working_revision,
                            ]) }}
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_course_template_publish_current_version') }}</dt>
                        <dd @class(['is-empty' => ! $currentVersion])>
                            @if ($currentVersion)
                                {{ __('lf.LF_course_template_version_number', [
                                    'version' => $currentVersion->version_number,
                                ]) }}
                                <span class="badge badge-success">
                                    {{ __('lf.LF_course_template_version_current') }}
                                </span>
                            @else
                                {{ __('lf.LF_course_template_publish_not_available') }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_course_template_publish_last_time') }}</dt>
                        <dd @class(['is-empty' => ! $latestVersion?->published_at])>
                            {{ $latestVersion?->published_at
                                ? \Illuminate\Support\Carbon::parse(
                                    $latestVersion->published_at
                                )->format('d/m/Y H:i')
                                : __('lf.LF_course_template_publish_not_available') }}
                        </dd>
                    </div>
                </dl>

                @if ($publishReadiness->blockers()->isNotEmpty())
                    <section class="admin-alert admin-alert-danger course-template-readiness course-template-readiness-blockers"
                             role="alert"
                             aria-labelledby="course-template-readiness-blockers-title">
                        <header class="course-template-readiness-header">
                            <h3 id="course-template-readiness-blockers-title" class="admin-alert-title">
                                {{ trans_choice(
                                    'lf.LF_course_template_readiness_blocked_count',
                                    $publishReadiness->blockers()->count(),
                                    ['count' => $publishReadiness->blockers()->count()]
                                ) }}
                            </h3>
                            <p class="admin-alert-guidance">{{ __('lf.LF_course_template_readiness_blocked_help') }}</p>
                        </header>
                        <ol class="course-template-readiness-list">
                            @foreach ($publishReadiness->blockers() as $issue)
                                <li data-readiness-code="{{ $issue->code }}">
                                    <div class="course-template-readiness-message">
                                        <strong class="course-template-readiness-issue-title">{{ __($readinessIssueTitleKey($issue)) }}</strong>
                                        <span>{{ $issue->message() }}</span>
                                    </div>
                                    <a class="admin-text-action" href="{{ $issue->targetUrl(
                                        $routePrefix,
                                        (int) $template->id
                                    ) }}">
                                        {{ __($readinessIssueActionKey($issue)) }}
                                    </a>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif

                @if ($publishReadiness->warnings()->isNotEmpty())
                    <section class="admin-alert admin-alert-warning course-template-readiness course-template-readiness-warnings"
                             role="status"
                             aria-live="polite"
                             aria-labelledby="course-template-readiness-warnings-title">
                        <header class="course-template-readiness-header">
                            <h3 id="course-template-readiness-warnings-title" class="admin-alert-title">
                                {{ trans_choice(
                                    'lf.LF_course_template_readiness_warning_count',
                                    $publishReadiness->warnings()->count(),
                                    ['count' => $publishReadiness->warnings()->count()]
                                ) }}
                            </h3>
                            <p class="admin-alert-guidance">{{ __('lf.LF_course_template_readiness_warning_help') }}</p>
                        </header>
                        <ul class="course-template-readiness-list course-template-readiness-warning-list">
                            @foreach ($publishReadiness->warnings() as $issue)
                                <li data-readiness-warning-code="{{ $issue->code }}">
                                    <div class="course-template-readiness-message">
                                        <strong class="course-template-readiness-issue-title">{{ __($readinessIssueTitleKey($issue)) }}</strong>
                                        <span>{{ $issue->message() }}</span>
                                    </div>
                                    <a class="admin-text-action" href="{{ $issue->targetUrl(
                                        $routePrefix,
                                        (int) $template->id
                                    ) }}">
                                        {{ __($readinessIssueActionKey($issue)) }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($publishReadiness->blockers()->isEmpty() && $publishReadiness->warnings()->isEmpty())
                    <section class="admin-alert admin-alert-success course-template-readiness course-template-readiness-ready"
                             role="status"
                             aria-labelledby="course-template-readiness-ready-title">
                        <header class="course-template-readiness-header">
                            <h3 id="course-template-readiness-ready-title" class="admin-alert-title">{{ __('lf.LF_course_template_readiness_ready') }}</h3>
                            <p class="admin-alert-guidance">{{ __('lf.LF_course_template_readiness_ready_help') }}</p>
                        </header>
                    </section>
                @endif

                <div class="admin-form-actions course-template-publish-actions">
                    @if (request()->user()?->role === 'customer_admin')
                        <form method="POST"
                              action="{{ route(
                                  $routePrefix.'.publish',
                                  $template->id
                              ) }}"
                              @if (! $publishReadiness->isReady()) aria-describedby="course-template-publish-disabled-help" @endif>
                            @csrf
                            <button type="submit"
                                    class="btn btn-primary course-template-publish-button"
                                    @disabled(! $publishReadiness->isReady())
                                    @if (! $publishReadiness->isReady()) aria-disabled="true" aria-describedby="course-template-publish-disabled-help" @endif>
                                {{ __('lf.LF_course_template_publish_action') }}
                            </button>
                            @if (! $publishReadiness->isReady())
                                <p id="course-template-publish-disabled-help" class="course-template-publish-disabled-help">
                                    {{ trans_choice(
                                        'lf.LF_course_template_publish_disabled_help',
                                        $publishReadiness->blockers()->count(),
                                        ['count' => $publishReadiness->blockers()->count()]
                                    ) }}
                                </p>
                            @endif
                        </form>
                    @else
                        <button type="button"
                                class="btn btn-primary course-template-publish-button"
                                disabled
                                aria-disabled="true">
                            {{ __('lf.LF_course_template_publish_action') }}
                        </button>
                    @endif
                </div>
            </div>
        </section>

        <section id="course-template-tab-history"
                 class="course-template-tab-panel"
                 @if ($activeTab !== 'history') hidden @endif>
            <div class="admin-card admin-form-card course-template-history-card">
                <h2 class="admin-form-section-title">
                    {{ __('lf.LF_course_template_history_title') }}
                </h2>

                @if ($versions->isEmpty())
                    <div class="course-template-history-empty">
                        <p>{{ __('lf.LF_course_template_history_empty') }}</p>
                    </div>
                @else
                    <p class="course-template-history-count">
                        {{ trans_choice(
                            'lf.LF_course_template_history_count',
                            $versions->total(),
                            ['count' => $versions->total()]
                        ) }}
                    </p>
                    <div class="admin-table-wrap">
                    <table class="table course-template-history-table admin-table-has-actions">
                            <thead>
                            <tr>
                                <th>{{ __('lf.LF_course_template_history_version') }}</th>
                                <th>{{ __('lf.LF_course_template_history_published_at') }}</th>
                                <th>{{ __('lf.LF_course_template_history_published_by') }}</th>
                                <th>{{ __('lf.LF_course_template_history_status') }}</th>
                                <th class="course-template-history-actions-column">{{ __('lf.table_actions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($versions as $version)
                                <tr>
                                    <td data-label="{{ __('lf.LF_course_template_history_version') }}">
                                        <strong class="course-template-history-primary">{{ __('lf.LF_course_template_version_number', [
                                            'version' => $version->version_number,
                                        ]) }}</strong>
                                        <span class="course-template-history-meta">{{ $version->version_code }}</span>
                                    </td>
                                    <td data-label="{{ __('lf.LF_course_template_history_published_at') }}" class="course-template-history-date">
                                        {{ \Illuminate\Support\Carbon::parse(
                                            $version->published_at
                                        )->format('d/m/Y H:i') }}
                                    </td>
                                    <td data-label="{{ __('lf.LF_course_template_history_published_by') }}">
                                        {{ $version->published_by_name
                                            ?? __('lf.LF_course_template_history_unknown_publisher') }}
                                    </td>
                                    <td data-label="{{ __('lf.LF_course_template_history_status') }}">
                                        <div class="course-template-history-statuses">
                                        @if ($version->is_current)
                                            <span class="badge badge-success course-template-version-current-badge">
                                                {{ __('lf.LF_course_template_version_in_use') }}
                                            </span>
                                        @else
                                            <span class="badge admin-status-badge--neutral course-template-version-status-badge">
                                                {{ __('lf.LF_course_template_version_status_'.$version->status) }}
                                            </span>
                                        @endif
                                        </div>
                                    </td>
                                    <td data-label="{{ __('lf.table_actions') }}" class="course-template-history-actions-column">
                                        @if (request()->user()?->role === 'customer_admin')
                                            <x-admin-action-menu :label="__('lf.table_actions').': '.$version->version_code">
                                                <a class="admin-table-action-link admin-text-action"
                                                   href="{{ route(
                                                       'admin.course-templates.versions.show',
                                                       [
                                                           'templateId' => $template->id,
                                                           'versionId' => $version->id,
                                                       ]
                                                   ) }}">
                                                    <x-admin-action-icon name="view" />
                                                    {{ __('lf.LF_course_template_history_view') }}
                                                </a>

                                                @if (in_array(
                                                    $version->status,
                                                    ['published', 'deprecated', 'archived'],
                                                    true
                                                ))
                                                    <form method="POST"
                                                          action="{{ route(
                                                              'admin.course-templates.versions.duplicate-to-draft',
                                                              [
                                                                  'templateId' => $template->id,
                                                                  'versionId' => $version->id,
                                                              ]
                                                          ) }}"
                                                          data-lf-confirm="{{ __('lf.LF_course_template_duplicate_confirmation') }}"
                                                          data-lf-confirm-label="{{ __('lf.LF_course_template_duplicate_action') }}">
                                                        @csrf
                                                        <button type="submit"
                                                                class="admin-link-button admin-text-action admin-table-action-link">
                                                            <x-admin-action-icon name="duplicate" />
                                                            {{ __('lf.LF_course_template_duplicate_action') }}
                                                        </button>
                                                    </form>
                                                @endif
                                            </x-admin-action-menu>
                                        @else
                                            <span class="admin-link-button admin-text-action is-disabled"
                                                  aria-disabled="true">
                                                {{ __('lf.LF_course_template_history_view') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($versions->hasPages())
                        <div class="admin-pagination course-template-history-pagination">
                            {{ $versions->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </section>
    </div>
@endsection
