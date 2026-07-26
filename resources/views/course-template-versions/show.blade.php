@extends('layouts.backend')

@section('title', __('lf.LF_course_template_version_detail_title'))
@section('page_title', __('lf.LF_course_template_version_detail_title'))

@section('content')
    <div class="course-template-version-detail"
         x-data="{
             previewOpen: false,
             preview: { name: '', url: '', type: '', mimeType: '' },
             openVersionPreview(name, url, type, mimeType = '') {
                 this.closeVersionPreview();
                 this.preview = { name, url, type, mimeType };
                 this.previewOpen = true;
                 this.$nextTick(() => {
                     const player = type === 'video'
                         ? this.$refs.versionPreviewVideo
                         : (type === 'audio' ? this.$refs.versionPreviewAudio : null);
                     if (player) {
                         const playback = player.play();
                         if (playback && typeof playback.catch === 'function') {
                             playback.catch(() => {});
                         }
                     }
                 });
             },
             closeVersionPreview() {
                 const video = this.$refs.versionPreviewVideo;
                 const audio = this.$refs.versionPreviewAudio;
                 if (video) { video.pause(); video.removeAttribute('src'); video.load(); }
                 if (audio) { audio.pause(); audio.removeAttribute('src'); audio.load(); }
                 this.previewOpen = false;
                 this.preview = { name: '', url: '', type: '', mimeType: '' };
             },
         }"
         x-on:keydown.escape.window="closeVersionPreview()">
        <div class="course-version-toolbar">
            <a href="{{ route(
                'admin.course-templates.edit',
                ['id' => $template->id, 'tab' => 'history']
            ) }}">
                ← {{ __('lf.LF_course_template_version_detail_back') }}
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
                    <button type="submit" class="btn btn-secondary">
                        {{ __('lf.LF_course_template_duplicate_action') }}
                    </button>
                </form>
            @endif
        </div>

        <section class="admin-card admin-form-card course-version-summary-card">
            <div class="course-version-title-row">
                <div>
                    <p class="course-version-eyebrow">
                        {{ __('lf.LF_course_template_version_number', [
                            'version' => $version->version_number,
                        ]) }}
                    </p>
                    <h2>{{ $version->title_snapshot }}</h2>
                </div>

                <div class="course-version-identity-badges">
                    <span class="badge">
                        {{ __('lf.LF_course_template_version_status_'.$version->status) }}
                    </span>
                    @if ($version->is_current)
                        <span class="badge badge-success">
                            {{ __('lf.LF_version_detail_current_published') }}
                        </span>
                    @endif
                </div>
            </div>

            <dl class="course-version-summary-grid">
                <div>
                    <dt>{{ __('lf.LF_course_template_version_detail_code') }}</dt>
                    <dd>{{ $version->version_code }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_history_published_at') }}</dt>
                    <dd>{{ \Illuminate\Support\Carbon::parse(
                        $version->published_at
                    )->format('d/m/Y H:i') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_history_published_by') }}</dt>
                    <dd>{{ $version->published_by_name
                        ?? __('lf.LF_course_template_history_unknown_publisher') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_version_detail_source_revision') }}</dt>
                    <dd>{{ $version->source_working_revision }}</dd>
                </div>
            </dl>
            <p class="course-version-immutable-note">
                {{ __('lf.LF_version_detail_immutable_note') }}
            </p>
        </section>

        <section class="admin-card admin-form-card course-version-information-card">
            <h2 class="admin-form-section-title">
                {{ __('lf.LF_course_template_version_detail_information') }}
            </h2>

            <dl class="course-version-information-grid">
                <div>
                    <dt>{{ __('lf.LF_course_template_common_name') }}</dt>
                    <dd>{{ $version->title_snapshot }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_common_category') }}</dt>
                    <dd>{{ $version->category_name_snapshot
                        ?? __('lf.LF_course_template_common_no_category') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_common_publisher_name') }}</dt>
                    <dd>{{ $version->publisher_name_snapshot
                        ?? __('lf.LF_course_template_publish_not_available') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_common_difficulty_level') }}</dt>
                    <dd>{{ $version->difficulty_level_snapshot
                        ? __('lf.LF_course_template_common_'.$version->difficulty_level_snapshot)
                        : __('lf.LF_course_template_common_no_difficulty') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_estimated_minutes_per_lesson') }}</dt>
                    <dd>{{ $version->estimated_minutes_per_lesson_snapshot ?? '—' }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_estimated_lesson_count') }}</dt>
                    <dd>{{ $version->estimated_lesson_count_snapshot ?? '—' }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_course_template_version_detail_lesson_count') }}</dt>
                    <dd>{{ $version->lesson_count_snapshot }}</dd>
                </div>
            </dl>

            @if ($version->short_description_snapshot)
                <div class="course-version-copy">
                    <p class="course-version-copy-label">
                        {{ __('lf.LF_course_template_common_short_description') }}
                    </p>
                    <div class="course-version-copy-content">{{ $version->short_description_snapshot }}</div>
                </div>
            @endif

            @if ($version->description_snapshot)
                <div class="course-version-copy">
                    <p class="course-version-copy-label">
                        {{ __('lf.LF_course_template_common_description') }}
                    </p>
                    <div class="course-version-copy-content">{{ $version->description_snapshot }}</div>
                </div>
            @endif

            <h3 class="course-version-subsection-title">
                {{ __('lf.LF_course_template_version_detail_media') }}
            </h3>
            <div class="course-version-media-grid">
                @foreach ([
                    'image' => __('lf.LF_course_template_intro_image'),
                    'video' => __('lf.LF_course_template_intro_video'),
                    'document' => __('lf.LF_course_template_intro_document'),
                ] as $slot => $label)
                    @php
                        $snapshot = $templateVersionMedia[$slot];
                    @endphp
                    <div class="course-version-media-field" data-version-media-slot="{{ $slot }}">
                        <p class="authoring-media-field-title">{{ $label }}</p>
                        @if ($snapshot['state'] === 'empty')
                            <p class="course-version-media-empty">
                                {{ __('lf.LF_course_template_version_detail_no_media') }}
                            </p>
                        @elseif ($snapshot['state'] === 'unavailable')
                            <p class="course-version-media-empty is-unavailable">
                                {{ __('lf.LF_version_detail_media_unavailable') }}
                            </p>
                        @else
                            @php
                                $mediaName = $snapshot['media']->display_name ?? $label;
                                $previewType = match ($snapshot['viewMode']) {
                                    'modal_image' => 'image',
                                    'modal_video' => 'video',
                                    'modal_embed' => 'embed',
                                    default => null,
                                };
                            @endphp
                            <x-authoring-media-row
                                :presentation="$snapshot['thumbnail']"
                                :alt="$mediaName"
                                :display-name="$mediaName">
                                @if ($snapshot['viewMode'] === 'new_tab_document')
                                    <a class="authoring-media-overlay-action"
                                       href="{{ $snapshot['url'] }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       aria-label="{{ __('lf.LF_media_file_common_preview_action') }}">
                                        <x-backend-icon name="eye" class="authoring-media-action-icon" />
                                    </a>
                                @else
                                    <button type="button"
                                            class="authoring-media-overlay-action"
                                            data-preview-name="{{ $mediaName }}"
                                            data-preview-url="{{ $snapshot['url'] }}"
                                            data-preview-type="{{ $previewType }}"
                                            data-preview-mime="{{ $snapshot['media']->mime_type ?? 'text/html' }}"
                                            aria-label="{{ __('lf.LF_media_file_common_preview_action') }}"
                                            x-on:click="openVersionPreview($el.dataset.previewName, $el.dataset.previewUrl, $el.dataset.previewType, $el.dataset.previewMime)">
                                        <x-backend-icon name="eye" class="authoring-media-action-icon" />
                                    </button>
                                @endif
                            </x-authoring-media-row>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section id="course-version-content"
                 class="admin-card admin-form-card course-template-section-card"
                 aria-labelledby="course-version-content-title"
                 x-data="{
                     activeContentTab: @js(
                         $sections->isNotEmpty() && $directLessons->isEmpty()
                             ? 'sections'
                             : 'direct'
                     ),
                     storageKey: @js(
                         'lf-course-template-version-'.$version->id.'-content-tab'
                     ),
                     init() {
                         const savedTab = localStorage.getItem(this.storageKey);

                         if (['direct', 'sections'].includes(savedTab)) {
                             this.activeContentTab = savedTab;
                         }
                     },
                     selectContentTab(tab) {
                         this.activeContentTab = tab;
                         localStorage.setItem(this.storageKey, tab);
                     }
                 }">
            @php
                $sectionsByParent = $sections->groupBy(
                    fn (object $section): string => (string) ($section->parent_version_section_id ?? 'root')
                );
            @endphp

            <header class="course-template-section-header course-version-structure-header">
                <h2 id="course-version-content-title" class="admin-form-section-title">
                    {{ __('lf.LF_course_template_version_detail_content') }}
                </h2>
                <p>{{ __('lf.LF_version_detail_content_help') }}</p>
            </header>

            @if ($directLessons->isNotEmpty() && $sections->isNotEmpty())
                <div class="course-template-structure-note" role="status">
                    <p>{{ __('lf.LF_course_template_mode_mixed_note') }}</p>
                    <p>{{ __('lf.LF_version_detail_mode_mixed_help') }}</p>
                </div>
            @endif

            <div class="course-template-structure-tabs"
                 role="tablist"
                 aria-label="{{ __('lf.LF_course_template_structure_tabs_label') }}">
                <button id="course-version-direct-tab"
                        type="button"
                        role="tab"
                        aria-controls="course-version-direct-panel"
                        x-bind:aria-selected="activeContentTab === 'direct'"
                        x-bind:tabindex="activeContentTab === 'direct' ? 0 : -1"
                        x-bind:class="{ 'is-active': activeContentTab === 'direct' }"
                        x-on:click="selectContentTab('direct')"
                        x-on:keydown.right.prevent="selectContentTab('sections')">
                    {{ __('lf.LF_course_template_structure_tab_direct') }}
                </button>
                <button id="course-version-sections-tab"
                        type="button"
                        role="tab"
                        aria-controls="course-version-sections-panel"
                        x-bind:aria-selected="activeContentTab === 'sections'"
                        x-bind:tabindex="activeContentTab === 'sections' ? 0 : -1"
                        x-bind:class="{ 'is-active': activeContentTab === 'sections' }"
                        x-on:click="selectContentTab('sections')"
                        x-on:keydown.left.prevent="selectContentTab('direct')">
                    {{ __('lf.LF_course_template_structure_tab_sections') }}
                </button>
            </div>
            <p class="course-template-structure-tab-help" aria-live="polite">
                <span x-show="activeContentTab === 'direct'">
                    {{ __('lf.LF_course_template_structure_tab_direct_help') }}
                </span>
                <span x-show="activeContentTab === 'sections'" x-cloak>
                    {{ __('lf.LF_course_template_structure_tab_sections_help') }}
                </span>
            </p>

            <div id="course-version-direct-panel"
                 class="course-template-structure-panel"
                 role="tabpanel"
                 aria-labelledby="course-version-direct-tab"
                 x-show="activeContentTab === 'direct'"
                 x-cloak>
                <section id="course-version-direct-lessons"
                         class="course-template-lesson-panel course-template-direct-lesson-panel"
                         aria-labelledby="course-version-direct-lessons-title">
                    <div class="course-template-section-action-bar course-template-content-toolbar">
                        <strong id="course-version-direct-lessons-title">
                            {{ trans_choice(
                                'lf.LF_course_template_lesson_common_count',
                                $directLessons->count(),
                                ['count' => $directLessons->count()]
                            ) }}
                        </strong>
                    </div>
                    <div class="course-template-lesson-list">
                        @forelse ($directLessons as $lesson)
                            @include('course-template-versions.partials.lesson')
                        @empty
                            <p class="course-template-outline-empty">
                                {{ __('lf.LF_version_detail_no_direct_lessons') }}
                            </p>
                        @endforelse
                    </div>
                </section>
            </div>

            <div id="course-version-sections-panel"
                 class="course-template-structure-panel"
                 role="tabpanel"
                 aria-labelledby="course-version-sections-tab"
                 x-show="activeContentTab === 'sections'"
                 x-cloak>
                @if ($sections->isNotEmpty())
                    <div class="course-template-section-action-bar course-template-content-toolbar">
                        <strong>
                            {{ trans_choice(
                                'lf.LF_course_template_section_common_count',
                                $sections->count(),
                                ['count' => $sections->count()]
                            ) }}
                        </strong>
                    </div>
                @endif
                <div class="course-template-outline-sections">
                    @forelse ($sectionsByParent->get('root', collect()) as $section)
                        @include('course-template-versions.partials.section-node', [
                            'depth' => 0,
                        ])
                    @empty
                        <div class="course-template-content-empty course-version-content-empty">
                            <strong>{{ __('lf.LF_version_detail_no_sections') }}</strong>
                            <p>{{ __('lf.LF_version_detail_no_sections_help') }}</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

        <div class="media-library-modal"
             x-cloak
             x-show="previewOpen"
             x-on:click.self="closeVersionPreview()"
             role="dialog"
             aria-modal="true"
             x-bind:aria-label="preview.name">
            <button type="button" class="media-library-modal-backdrop"
                    x-on:click="closeVersionPreview()"
                    aria-label="{{ __('lf.LF_common_button_close') }}"></button>
            <div class="media-library-modal-panel">
                <div class="media-library-modal-header">
                    <h2 x-text="preview.name"></h2>
                    <button type="button" class="btn" x-on:click="closeVersionPreview()">
                        {{ __('lf.LF_common_button_close') }}
                    </button>
                </div>
                <div class="media-library-modal-body">
                    <img x-show="preview.type === 'image'" x-bind:src="preview.type === 'image' ? preview.url : ''"
                         x-bind:alt="preview.name" class="media-library-modal-image">
                    <video x-ref="versionPreviewVideo" x-show="preview.type === 'video'"
                           x-bind:src="preview.type === 'video' ? preview.url : ''"
                           controls preload="metadata" class="media-library-modal-video"></video>
                    <audio x-ref="versionPreviewAudio" x-show="preview.type === 'audio'"
                           x-bind:src="preview.type === 'audio' ? preview.url : ''"
                           controls preload="metadata" class="course-activity-media-audio-player"></audio>
                    <iframe x-show="preview.type === 'embed'"
                            x-bind:src="preview.type === 'embed' ? preview.url : ''"
                            x-bind:title="preview.name"
                            class="media-library-modal-video"
                            loading="lazy"
                            sandbox="allow-scripts allow-same-origin allow-presentation"
                            allow="autoplay; fullscreen; picture-in-picture"></iframe>
                </div>
            </div>
        </div>
    </div>
@endsection
