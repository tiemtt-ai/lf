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
            <div class="admin-card admin-form-card">
                <form id="course-template-update-form"
                      method="POST"
                      action="{{ route($routePrefix.'.update', $template->id) }}"
                      enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    @if ($template->status === \App\Support\CourseTemplateStatus::ARCHIVED)<fieldset disabled>@endif
                    @include('course-templates.partials.form')
                    @if ($template->status === \App\Support\CourseTemplateStatus::ARCHIVED)</fieldset>@endif

                </form>

                <footer class="admin-form-footer">
                    <div class="admin-form-footer-danger">
                        @if ($template->status === \App\Support\CourseTemplateStatus::INACTIVE
                            && \Illuminate\Support\Facades\Route::has($routePrefix.'.archive'))
                        <form method="POST"
                              action="{{ route($routePrefix.'.archive', $template->id) }}"
                              x-data="{ submitting: false }"
                              @submit="if (submitting || !window.confirm(@js(__('lf.LF_course_template_status_archive_confirm')))) { $event.preventDefault(); return } submitting = true">
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
                        @if ($template->status !== \App\Support\CourseTemplateStatus::ARCHIVED)
                            <button type="submit"
                                    form="course-template-update-form"
                                    class="btn btn-primary">
                                {{ __('lf.LF_common_button_save_changes') }}
                            </button>
                        @endif
                        <a href="{{ route($routePrefix.'.index') }}" class="admin-form-cancel">
                            {{ __('lf.LF_common_button_cancel') }}
                        </a>
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
                        <dt>{{ __('lf.LF_course_template_publish_current_draft') }}</dt>
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

                <section @class([
                             'course-template-readiness',
                             'is-ready' => $publishReadiness->isReady(),
                             'is-blocked' => ! $publishReadiness->isReady(),
                         ])
                         aria-labelledby="course-template-readiness-title">
                    <div class="course-template-readiness-header">
                        <h3 id="course-template-readiness-title">
                            {{ $publishReadiness->isReady()
                                ? __('lf.LF_course_template_readiness_ready')
                                : __('lf.LF_course_template_readiness_blocked', [
                                    'count' => $publishReadiness->blockers()->count(),
                                ]) }}
                        </h3>
                    </div>

                    @if ($publishReadiness->isReady())
                        <p class="course-template-readiness-help">{{ __('lf.LF_course_template_readiness_ready_help') }}</p>
                    @else
                        <p class="course-template-readiness-help">{{ __('lf.LF_course_template_readiness_blocked_help') }}</p>
                        <ol class="course-template-readiness-list">
                            @foreach ($publishReadiness->blockers() as $issue)
                                <li data-readiness-code="{{ $issue->code }}">
                                    <span class="course-template-readiness-message">{{ $issue->message() }}</span>
                                    <a href="{{ $issue->targetUrl(
                                        $routePrefix,
                                        (int) $template->id
                                    ) }}">
                                        {{ $issue->targetTab === 'information'
                                            ? __('lf.LF_course_template_readiness_fix_information')
                                            : __('lf.LF_course_template_readiness_fix_content') }}
                                    </a>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>

                <div class="admin-form-actions course-template-publish-actions">
                    @if (request()->user()?->role === 'customer_admin')
                        <form method="POST"
                              action="{{ route(
                                  $routePrefix.'.publish',
                                  $template->id
                              ) }}">
                            @csrf
                            <button type="submit"
                                    class="btn btn-primary course-template-publish-button"
                                    @disabled(! $publishReadiness->isReady())
                                    @if (! $publishReadiness->isReady()) aria-disabled="true" @endif>
                                {{ __('lf.LF_course_template_publish_action') }}
                            </button>
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
                    <div class="admin-table-wrap">
                        <table class="table course-template-history-table">
                            <thead>
                            <tr>
                                <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                                <th>{{ __('lf.table_code') }}</th>
                                <th>{{ __('lf.LF_course_template_history_version') }}</th>
                                <th>{{ __('lf.LF_course_template_history_published_at') }}</th>
                                <th>{{ __('lf.LF_course_template_history_published_by') }}</th>
                                <th>{{ __('lf.LF_course_template_history_status') }}</th>
                                <th>{{ __('lf.LF_course_template_history_current') }}</th>
                                <th>{{ __('lf.table_actions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($versions as $version)
                                <tr>
                                    <td class="admin-table-sequence">{{ $loop->iteration }}</td>
                                    <td>{{ $version->version_code }}</td>
                                    <td>
                                        {{ __('lf.LF_course_template_version_number', [
                                            'version' => $version->version_number,
                                        ]) }}
                                    </td>
                                    <td>
                                        {{ \Illuminate\Support\Carbon::parse(
                                            $version->published_at
                                        )->format('d/m/Y H:i') }}
                                    </td>
                                    <td>
                                        {{ $version->published_by_name
                                            ?? __('lf.LF_course_template_history_unknown_publisher') }}
                                    </td>
                                    <td>
                                        {{ __('lf.LF_course_template_version_status_'.$version->status) }}
                                    </td>
                                    <td>
                                        @if ($version->is_current)
                                            <span class="badge badge-success">
                                                {{ __('lf.LF_course_template_version_current') }}
                                            </span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @if (request()->user()?->role === 'customer_admin')
                                            <div class="admin-table-actions course-template-version-actions">
                                                <a class="admin-table-action-link admin-text-action"
                                                   href="{{ route(
                                                       'admin.course-templates.versions.show',
                                                       [
                                                           'templateId' => $template->id,
                                                           'versionId' => $version->id,
                                                       ]
                                                   ) }}">
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
                                                          onsubmit="return window.confirm(@js(
                                                              __('lf.LF_course_template_duplicate_confirmation')
                                                          ))">
                                                        @csrf
                                                        <button type="submit"
                                                                class="admin-link-button admin-text-action">
                                                            {{ __('lf.LF_course_template_duplicate_action') }}
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
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
                @endif
            </div>
        </section>
    </div>
@endsection
