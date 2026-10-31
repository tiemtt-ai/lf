@extends('layouts.backend')

@section('title', __('lf.LF_course_template_common_edit'))
@section('page_title', __('lf.LF_course_template_common_edit'))

@section('page_back')
    <x-page-back-link :href="$backUrl" :label="__('lf.LF_course_template_common_title')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js', 'resources/js/admin-ui-kit-row-actions.js', 'resources/js/course-template-activity-status.js'])
@endsection

@section('content')
    @php
        $tabs = [
            'information' => __('lf.LF_course_template_tab_information'),
            'structure' => __('lf.LF_course_template_tab_structure'),
            'teachers' => __('lf.LF_course_template_tab_teachers'),
            ...($learningMappingState || $aiAuthoring ? ['learning' => __('lf.LF_learning_frameworks')] : []),
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
    @elseif (session('success') && ! in_array($activeTab, ['information', 'structure', 'teachers', 'publish'], true))
        {{-- These tabs show the message as a toast. --}}
        <div class="admin-alert admin-alert-success admin-form-card">
            {{ session('success') }}
        </div>
    @endif

    @if (session('info'))
        <div class="admin-alert admin-alert-info admin-form-card"
             role="status"
             aria-live="polite">
            {{ session('info') }}
        </div>
    @endif
    @if (session('warning'))
        <div class="admin-alert admin-alert-warning admin-form-card" role="alert">
            {{ session('warning') }}
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
            @php
                $isArchived = $template->status === \App\Support\CourseTemplateStatus::ARCHIVED;
                $categoryName = $categories->firstWhere('id', $template->category_id)?->name;
                $dash = '—';
                $introVideoName = $introVideoMedia?->display_name
                    ?? ($introVideoEmbedUrl ? ucfirst((string) $template->intro_video_provider) : null);
                $groups = [
                    'general' => [
                        ['title', __('lf.LF_course_template_common_name'), $template->title],
                        ['category_id', __('lf.LF_course_template_common_category'), $categoryName],
                        ['publisher_name', __('lf.LF_course_template_common_publisher_name'), $template->publisher_name],
                        ['difficulty_level', __('lf.LF_course_template_common_difficulty_level'), $template->difficulty_level ? __('lf.LF_course_template_common_'.$template->difficulty_level) : null],
                        ['estimated_minutes_per_lesson', __('lf.LF_course_template_estimated_minutes_per_lesson'), $template->estimated_minutes_per_lesson],
                        ['estimated_lesson_count', __('lf.LF_course_template_estimated_lesson_count'), $template->estimated_lesson_count],
                        ['status', __('lf.LF_course_template_common_status'), null],
                    ],
                    'media' => [
                        ['intro_image_file', __('lf.LF_course_template_intro_image'), $introImageMedia?->display_name, 'image',
                            $introImageMedia?->signed_url ? ['kind' => 'image', 'url' => $introImageMedia->signed_url, 'mimeType' => $introImageMedia->mime_type] : null,
                            ($introImageThumbnail['state'] ?? null) === 'image' ? $introImageThumbnail['url'] : null],
                        ['intro_video_source', __('lf.LF_course_template_intro_video'), $introVideoName, 'video',
                            $introVideoMedia?->signed_url
                                ? ['kind' => 'video', 'url' => $introVideoMedia->signed_url, 'mimeType' => $introVideoMedia->mime_type]
                                : ($introVideoEmbedUrl ? ['kind' => 'embed', 'url' => $introVideoEmbedUrl, 'mimeType' => ''] : null),
                            ($introVideoThumbnail['state'] ?? null) === 'provider_video_thumbnail' ? $introVideoThumbnail['url'] : null],
                        ['intro_document_file', __('lf.LF_course_template_intro_document'), $introDocumentMedia?->display_name,
                            match ($introDocumentThumbnail['kind'] ?? null) {
                                'pdf' => 'file-pdf', 'word' => 'file-text', 'spreadsheet' => 'file-spreadsheet',
                                'presentation' => 'file-presentation', default => 'document',
                            },
                            $introDocumentMedia?->signed_url ? ['kind' => 'document', 'url' => $introDocumentMedia->signed_url, 'mimeType' => $introDocumentMedia->mime_type] : null,
                            null],
                    ],
                    'content' => [
                        ['short_description', __('lf.LF_course_template_common_short_description'), $template->short_description],
                        ['description', __('lf.LF_course_template_common_description'), $template->description ? \Illuminate\Support\Str::limit(strip_tags((string) $template->description), 320) : null],
                    ],
                ];
            @endphp
            <div class="lf-admin-ui lf-admin-ui--list" data-ui-dialog-form-scope
                 x-data="{
                     previewOpen: false,
                     preview: { name: '', url: '', mimeType: '', mediaType: '' },
                     openPreview(detail) {
                         if (! ['image', 'video', 'embed'].includes(detail.kind)) {
                             window.open(detail.url, '_blank', 'noopener');
                             return;
                         }
                         this.preview = { name: detail.name, url: detail.url, mimeType: detail.mimeType || '', mediaType: detail.kind };
                         this.previewOpen = true;
                     },
                     closePreview() {
                         this.previewOpen = false;
                         this.preview = { name: '', url: '', mimeType: '', mediaType: '' };
                     },
                 }">
                <x-ui-list-toasts />

                <section class="mk-section" aria-labelledby="course-template-information-title">
                    <div class="ui-summary-head">
                        <h2 id="course-template-information-title">{{ __('lf.LF_course_template_tab_information') }}</h2>
                        <button type="button" class="ui-button ui-button--primary cursor-interaction"
                                data-ui-dialog-form-open data-ui-dialog-form-hash-open aria-haspopup="dialog"
                                data-url="{{ route($routePrefix.'.form', ['id' => $template->id, 'context' => 'workspace']) }}">
                            {{ $isArchived ? __('lf.LF_course_template_action_view_info') : __('lf.LF_course_template_action_edit_info') }}
                        </button>
                    </div>

                    @foreach ($groups as $groupKey => $items)
                        @if ($groupKey === 'media')
                            {{-- Filled resources come first; the edit form keeps its fixed slot order. The sort is stable, so each part keeps the form's order. --}}
                            @php($items = collect($items)->sortBy(static fn (array $item): int => ($item[2] ?? null) === null || $item[2] === '' ? 1 : 0)->values()->all())
                        @endif
                        <div class="ui-summary-group" data-summary-group="{{ $groupKey }}">
                            <dl @class(['ui-summary', 'ui-summary--media' => $groupKey === 'media'])>
                                @foreach ($items as $item)
                                    @php([$field, $label, $value] = $item)
                                    @php($icon = $item[3] ?? null)
                                    @php($preview = $item[4] ?? null)
                                    <div data-summary-field="{{ $field }}" @class(['ui-summary__item', 'ui-summary__item--wide' => in_array($field, ['title', 'short_description', 'description'], true), 'ui-summary__item--title' => $field === 'title'])>
                                        <dt class="ui-field__label">{{ $label }}</dt>
                                        <dd>
                                            @if ($field === 'status')
                                                <span class="ui-status ui-status--{{ $template->status }}">{{ __('lf.LF_course_template_common_'.$template->status) }}</span>
                                            @elseif ($groupKey === 'media')
                                                {{-- Same 112px tile as the media slots of the edit form (x-ui-media-slot), read-only: View on hover, no Upload or Remove. --}}
                                                @php($thumb = $item[5] ?? null)
                                                <div class="ui-media-tile" @if ($value !== null && $value !== '') title="{{ $value }}" @endif>
                                                    @if ($value !== null && $value !== '')
                                                        <div class="ui-media-tile__filled">
                                                            @if ($thumb)
                                                                <img class="ui-media-tile__image" src="{{ $thumb }}" alt="{{ $value }}" loading="lazy">
                                                            @else
                                                                <span class="ui-media-tile__fallback" role="img" aria-label="{{ $value }}">
                                                                    <x-backend-icon :name="$icon" class="ui-icon" />
                                                                </span>
                                                            @endif
                                                            @if ($preview)
                                                                <div class="ui-media-tile__actions">
                                                                    <button type="button" class="ui-media-tile__action cursor-interaction"
                                                                            aria-haspopup="dialog"
                                                                            aria-label="{{ __('lf.LF_media_slot_view') }}: {{ $value }}"
                                                                            title="{{ __('lf.LF_media_slot_view') }}"
                                                                            x-on:click="openPreview(@js(['name' => $value] + $preview))">
                                                                        <x-backend-icon name="eye" class="ui-icon" />
                                                                    </button>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @else
                                                        <span class="ui-media-tile__empty ui-media-tile__empty--readonly" aria-label="{{ __('lf.LF_course_template_summary_empty') }}">{{ $dash }}</span>
                                                    @endif
                                                </div>
                                            @else
                                                @if ($value !== null && $value !== '')
                                                    {{ $value }}
                                                @else
                                                    <span class="ui-summary__empty" aria-label="{{ __('lf.LF_course_template_summary_empty') }}">{{ $dash }}</span>
                                                @endif
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach

                    @if ($template->status === \App\Support\CourseTemplateStatus::INACTIVE
                        && \Illuminate\Support\Facades\Route::has($routePrefix.'.archive'))
                        <div class="ui-summary-foot">
                            <form method="POST"
                                  action="{{ route($routePrefix.'.archive', $template->id) }}"
                                  x-data="{ submitting: false }"
                                  data-lf-confirm="{{ __('lf.LF_course_template_status_archive_confirm') }}"
                                  data-lf-confirm-tone="danger"
                                  @submit="if (submitting) { $event.preventDefault(); return } submitting = true">
                                @csrf
                                <button type="submit" class="ui-button cursor-interaction" :disabled="submitting" :aria-busy="submitting">
                                    {{ __('lf.LF_course_template_status_archive_action') }}
                                </button>
                            </form>
                        </div>
                    @endif
                </section>

                <x-media-preview-modal ui-kit id="course-template-summary-preview-title" show="previewOpen" close="closePreview()" title="preview.name" compact="false">
                    <template x-if="previewOpen && preview.mediaType === 'image'">
                        <img x-bind:src="preview.url" x-bind:alt="preview.name">
                    </template>
                    <template x-if="previewOpen && preview.mediaType === 'video'">
                        <video controls preload="metadata" autoplay x-bind:src="preview.url"></video>
                    </template>
                    <template x-if="previewOpen && preview.mediaType === 'embed'">
                        <iframe x-bind:src="preview.url" x-bind:title="preview.name" loading="lazy"
                                sandbox="allow-scripts allow-same-origin allow-presentation"
                                allow="fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                    </template>
                </x-media-preview-modal>

                <dialog class="ui-modal" data-columns="2" aria-labelledby="template-dialog-title" data-ui-dialog-form data-field-id-prefix="template-form-"
                        data-msg-load-error="{{ __('lf.LF_course_template_dialog_load_error') }}"
            data-msg-discard-title="{{ __('lf.LF_course_template_dialog_discard_title') }}"
            data-msg-discard-text="{{ __('lf.LF_course_template_dialog_discard_text') }}"
            data-msg-discard="{{ __('lf.LF_course_template_dialog_discard') }}"
                        data-msg-save-error="{{ __('lf.LF_course_template_dialog_save_error') }}"
                        data-msg-session-expired="{{ __('lf.LF_course_template_session_expired') }}"
                        data-msg-required-input="{{ __('lf.LF_course_template_dialog_required_input') }}"
                        data-msg-required-select="{{ __('lf.LF_course_template_dialog_required_select') }}"></dialog>
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

        @if ($learningMappingState || $aiAuthoring)
            <section id="course-template-tab-learning" class="course-template-tab-panel" @if ($activeTab !== 'learning') hidden @endif>
                @if ($learningMappingState)
                    @include('course-templates.partials.learning-mappings')
                @endif
                @if ($aiAuthoring && config('ai.authoring.ui_enabled', false))
                    @include('course-templates.partials.ai-authoring-entry')
                @elseif ($aiAuthoring)
                    <p class="course-learning-ai-paused" role="note"><span aria-hidden="true">ⓘ</span><span>{{ __('lf.LF_course_template_ai_temporarily_hidden') }}</span></p>
                @endif
            </section>
        @endif

        <section id="course-template-tab-publish"
                 class="course-template-tab-panel"
                 @if ($activeTab !== 'publish') hidden @endif>
            {{-- Inline @php only: this view already uses @php(...), which cannot be mixed with @php blocks. Unchanged = the working revision produced the current Version. --}}
            @php($canPublish = request()->user()?->role === 'customer_admin')
            @php($publishBlockers = $publishReadiness->blockers())
            @php($publishWarnings = $publishReadiness->warnings())
            @php($nextVersionNumber = (int) ($latestVersion?->version_number ?? 0) + 1)
            @php($publishUnchanged = $currentVersion && (int) $currentVersion->source_working_revision === (int) $template->working_revision)
            <div class="lf-admin-ui lf-admin-ui--list ui-publish">
                <x-ui-list-toasts />

                <section class="mk-section" aria-labelledby="course-template-publish-title">
                    <div class="ui-summary-head">
                        <div>
                            <h2 id="course-template-publish-title">{{ __('lf.LF_course_template_publish_title') }}</h2>
                            <p class="ui-caption">{{ __('lf.LF_course_template_publish_next_version', ['version' => $nextVersionNumber]) }}</p>
                        </div>
                        <div class="ui-publish__action">
                            @if ($canPublish)
                                <form method="POST" action="{{ route($routePrefix.'.publish', $template->id) }}"
                                      data-lf-confirm="{{ __('lf.LF_course_template_publish_confirm', ['version' => $nextVersionNumber, 'revision' => $template->working_revision]) }}"
                                      data-lf-confirm-label="{{ __('lf.LF_course_template_publish_action') }}">
                                    @csrf
                                    <input type="hidden" name="working_revision" value="{{ $template->working_revision }}">
                                    <button type="submit"
                                            class="ui-button ui-button--primary cursor-interaction" data-publish-button
                                            @disabled(! $publishReadiness->isReady())
                                            @if (! $publishReadiness->isReady()) aria-disabled="true" aria-describedby="course-template-publish-disabled-help" @endif>
                                        <x-backend-icon name="upload" class="ui-icon" />{{ __('lf.LF_course_template_publish_action') }}
                                    </button>
                                </form>
                            @else
                                <button type="button" class="ui-button ui-button--primary" data-publish-button disabled aria-disabled="true"
                                        aria-describedby="course-template-publish-role-help">
                                    <x-backend-icon name="lock" class="ui-icon" />{{ __('lf.LF_course_template_publish_action') }}
                                </button>
                            @endif
                        </div>
                    </div>

                    <dl class="ui-summary ui-publish__summary" data-publish-summary>
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_publish_current_working') }}</dt>
                            <dd class="ui-summary__version-main">
                                <span class="ui-status ui-status--{{ $template->status }}">{{ __('lf.LF_course_template_common_'.$template->status) }}</span>
                                <span>{{ __('lf.LF_course_template_publish_revision', ['revision' => $template->working_revision]) }}</span>
                            </dd>
                        </div>
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_publish_current_version') }}</dt>
                            <dd>
                                @if ($currentVersion)
                                    <span class="ui-summary__version-main">
                                        <span>{{ __('lf.LF_course_template_version_number', ['version' => $currentVersion->version_number]) }}</span>
                                        <span class="ui-status ui-status--active">{{ __('lf.LF_course_template_version_current') }}</span>
                                    </span>
                                    @if ($canPublish)
                                        <span class="ui-summary__version-meta">
                                            <a class="ui-link" href="{{ route('admin.course-templates.versions.show', ['templateId' => $template->id, 'versionId' => $currentVersion->id]) }}">{{ __('lf.LF_course_template_history_view') }}</a>
                                        </span>
                                    @endif
                                @else
                                    <span class="ui-summary__empty">{{ __('lf.LF_course_template_publish_not_available') }}</span>
                                @endif
                            </dd>
                        </div>
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_publish_last_time') }}</dt>
                            <dd>
                                @if ($latestVersion?->published_at)
                                    {{ \Illuminate\Support\Carbon::parse($latestVersion->published_at)->format('d/m/Y H:i') }}
                                @else
                                    <span class="ui-summary__empty">{{ __('lf.LF_course_template_publish_not_available') }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                    @if ($currentVersion)
                        <p @class(['ui-publish__change', 'is-unchanged' => $publishUnchanged]) data-publish-change="{{ $publishUnchanged ? 'none' : 'pending' }}">
                            <x-backend-icon :name="$publishUnchanged ? 'check' : 'clock'" class="ui-icon" />
                            {{ $publishUnchanged
                                ? __('lf.LF_course_template_publish_no_changes', ['version' => $currentVersion->version_number])
                                : __('lf.LF_course_template_publish_changes_pending', ['version' => $currentVersion->version_number]) }}
                        </p>
                    @endif
                    @if ($canPublish && ! $publishReadiness->isReady())
                        <p id="course-template-publish-disabled-help" class="ui-publish__hint">
                            {{ trans_choice('lf.LF_course_template_publish_disabled_help', $publishBlockers->count(), ['count' => $publishBlockers->count()]) }}
                        </p>
                    @elseif (! $canPublish)
                        <p id="course-template-publish-role-help" class="ui-publish__hint">{{ __('lf.LF_course_template_publish_admin_only') }}</p>
                    @endif
                </section>

                @foreach ([
                    'blockers' => [$publishBlockers, 'danger', 'alert', 'LF_course_template_readiness_blocked_count', 'LF_course_template_readiness_blocked_help', 'data-readiness-code'],
                    'warnings' => [$publishWarnings, 'warning', 'status', 'LF_course_template_readiness_warning_count', 'LF_course_template_readiness_warning_help', 'data-readiness-warning-code'],
                ] as $kind => [$issues, $tone, $role, $countKey, $helpKey, $codeAttribute])
                    @if ($issues->isNotEmpty())
                        <section class="mk-section ui-publish-check ui-publish-check--{{ $tone }}" data-publish-check="{{ $kind }}"
                                 role="{{ $role }}" @if ($role === 'status') aria-live="polite" @endif
                                 aria-labelledby="course-template-readiness-{{ $kind }}-title">
                            <header class="ui-publish-check__head">
                                <h3 id="course-template-readiness-{{ $kind }}-title">{{ trans_choice('lf.'.$countKey, $issues->count(), ['count' => $issues->count()]) }}</h3>
                                <p class="ui-caption">{{ __('lf.'.$helpKey) }}</p>
                            </header>
                            <ol class="ui-publish-issues">
                                @foreach ($issues as $issue)
                                    <li {{ $codeAttribute }}="{{ $issue->code }}">
                                        <span class="ui-publish-issues__no" aria-hidden="true">{{ $loop->iteration }}</span>
                                        <div class="ui-publish-issues__body">
                                            <strong>{{ __($readinessIssueTitleKey($issue)) }}</strong>
                                            <span>{{ $issue->message() }}</span>
                                        </div>
                                        <a class="ui-button ui-button--sm ui-button--outline cursor-interaction" href="{{ $issue->targetUrl($routePrefix, (int) $template->id) }}">
                                            {{ __($readinessIssueActionKey($issue)) }}
                                        </a>
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endif
                @endforeach

                @if ($publishBlockers->isEmpty() && $publishWarnings->isEmpty())
                    <section class="mk-section ui-publish-check ui-publish-check--success" data-publish-check="ready"
                             role="status" aria-labelledby="course-template-readiness-ready-title">
                        <header class="ui-publish-check__head">
                            <h3 id="course-template-readiness-ready-title"><x-backend-icon name="check" class="ui-icon" />{{ __('lf.LF_course_template_readiness_ready') }}</h3>
                            <p class="ui-caption">{{ __('lf.LF_course_template_readiness_ready_help') }}</p>
                        </header>
                    </section>
                @endif
            </div>
        </section>

        <section id="course-template-tab-history"
                 class="course-template-tab-panel"
                 @if ($activeTab !== 'history') hidden @endif>
            {{-- Inline @php only (see the publish tab). An archived Template is read-only, so it cannot take a Version back into its draft. --}}
            @php($canManageVersions = request()->user()?->role === 'customer_admin')
            @php($canDuplicateVersion = $canManageVersions && $template->status !== \App\Support\CourseTemplateStatus::ARCHIVED)
            @php($draftHasUnpublishedChanges = ! $currentVersion || (int) $currentVersion->source_working_revision !== (int) $template->working_revision)
            <div class="lf-admin-ui lf-admin-ui--list ui-history">
                <section class="mk-section" aria-labelledby="course-template-history-title">
                    <div class="ui-summary-head">
                        <div>
                            <h2 id="course-template-history-title">{{ __('lf.LF_course_template_history_title') }}</h2>
                            <p class="ui-caption">{{ __('lf.LF_course_template_history_help') }}</p>
                        </div>
                        @if ($versions->total() > 0)
                            <span class="ui-caption" data-history-count>{{ trans_choice('lf.LF_course_template_history_count', $versions->total(), ['count' => $versions->total()]) }}</span>
                        @endif
                    </div>
                    @if ($canManageVersions && ! $canDuplicateVersion && $versions->total() > 0)
                        <p class="ui-history__note">{{ __('lf.LF_course_template_duplicate_archived_hint') }}</p>
                    @endif
                    <div class="mk-table-scroll" role="region" aria-labelledby="course-template-history-title" tabindex="0">
                        <table class="ui-history-table" aria-labelledby="course-template-history-title" data-history-table>
                            <colgroup>
                                <col class="mk-col-sequence">
                                <col>
                                <col class="mk-col-secondary">
                                <col class="mk-col-secondary">
                                <col class="mk-col-status">
                                <col class="mk-col-action">
                            </colgroup>
                            <thead>
                            <tr>
                                <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_template_history_version') }}</th>
                                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_template_history_published_at') }}</th>
                                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_template_history_published_by') }}</th>
                                <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_template_history_status') }}</th>
                                <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($versions as $version)
                                @php($versionLabel = __('lf.LF_course_template_version_number', ['version' => $version->version_number]))
                                <tr @class(['is-current' => $version->is_current])>
                                    <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $versions->firstItem() + $loop->index }}</td>
                                    <td class="mk-text-cell" data-label="{{ __('lf.LF_course_template_history_version') }}">
                                        <strong class="mk-name-text">{{ $versionLabel }}</strong>
                                        <span class="ui-caption ui-history__code">{{ $version->version_code }}</span>
                                    </td>
                                    <td class="mk-text-cell mk-text-cell--nowrap" data-label="{{ __('lf.LF_course_template_history_published_at') }}">
                                        {{ $version->published_at ? \Illuminate\Support\Carbon::parse($version->published_at)->format('d/m/Y H:i') : '—' }}
                                    </td>
                                    <td class="mk-text-cell" data-label="{{ __('lf.LF_course_template_history_published_by') }}">
                                        {{ $version->published_by_name ?? __('lf.LF_course_template_history_unknown_publisher') }}
                                    </td>
                                    <td class="ux-action-cell" data-label="{{ __('lf.LF_course_template_history_status') }}">
                                        @if ($version->is_current)
                                            <span class="ui-status ui-status--active" data-version-status="current">{{ __('lf.LF_course_template_version_in_use') }}</span>
                                        @else
                                            <span @class(['ui-status', 'ui-status--draft' => $version->status === 'published', 'ui-status--inactive' => $version->status === 'deprecated', 'ui-status--archived' => $version->status === 'archived'])
                                                  data-version-status="{{ $version->status }}">{{ __('lf.LF_course_template_version_status_'.$version->status) }}</span>
                                        @endif
                                    </td>
                                    <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                        @if ($canManageVersions)
                                            <span class="ui-history__actions">
                                                <a class="ux-row-edit cursor-interaction" data-history-view
                                                   href="{{ route('admin.course-templates.versions.show', ['templateId' => $template->id, 'versionId' => $version->id]) }}"
                                                   aria-label="{{ __('lf.LF_course_template_history_view') }}: {{ $versionLabel }}"
                                                   data-tooltip="{{ __('lf.LF_course_template_history_view') }}">
                                                    <x-backend-icon name="eye" class="ui-icon" />
                                                </a>
                                                @if ($canDuplicateVersion && in_array($version->status, ['published', 'deprecated', 'archived'], true))
                                                    <form method="POST"
                                                          action="{{ route('admin.course-templates.versions.duplicate-to-draft', ['templateId' => $template->id, 'versionId' => $version->id]) }}"
                                                          data-lf-confirm="{{ __('lf.LF_course_template_duplicate_confirm_named', ['version' => $version->version_number]) }}{{ $draftHasUnpublishedChanges ? ' '.__('lf.LF_course_template_duplicate_confirm_unpublished') : '' }}"
                                                          data-lf-confirm-label="{{ __('lf.LF_course_template_duplicate_action') }}">
                                                        @csrf
                                                        <button type="submit" class="ux-row-edit cursor-interaction" data-history-duplicate
                                                                aria-label="{{ __('lf.LF_course_template_duplicate_action') }}: {{ $versionLabel }}"
                                                                data-tooltip="{{ __('lf.LF_course_template_duplicate_action') }}">
                                                            <x-backend-icon name="copy" class="ui-icon" />
                                                        </button>
                                                    </form>
                                                @endif
                                            </span>
                                        @else
                                            <span class="ui-caption">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr class="mk-empty-row">
                                    <td class="mk-empty" colspan="6">
                                        <div role="status">
                                            <strong>{{ __('lf.LF_course_template_history_empty') }}</strong>
                                            <p class="ui-caption">{{ __('lf.LF_course_template_history_empty_help') }}</p>
                                            <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.edit', ['id' => $template->id, 'tab' => 'publish']) }}">{{ __('lf.LF_course_template_tab_publish') }}</a>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($versions->total() > 0)
                        <x-ui-list-foot :paginator="$versions"
                            :label="__('lf.LF_course_template_history_title')"
                            :page-size-label="__('lf.LF_course_template_index_page_size')"
                            :page-size-option="__('lf.LF_course_template_index_page_size_option', ['count' => $versions->perPage()])" />
                    @endif
                </section>
            </div>
        </section>
    </div>
@endsection
