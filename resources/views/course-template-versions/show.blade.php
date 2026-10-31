@extends('layouts.backend')

@section('title', __('lf.LF_course_template_version_detail_title'))
@section('page_title', __('lf.LF_course_template_version_detail_title'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css'])
@endsection

@section('page_back')
    <x-page-back-link :href="route('admin.course-templates.edit', ['id' => $template->id, 'tab' => 'history'])" :label="__('lf.LF_course_template_version_detail_back')" />
@endsection

@section('content')
    <div class="course-template-version-detail"
         x-data="{
             previewOpen: false,
             preview: { name: '', url: '', type: '', mimeType: '' },
             openVersionPreview(name, url, type, mimeType = '') {
                 this.preview = { name, url, type, mimeType };
                 this.previewOpen = true;
             },
             closeVersionPreview() {
                 this.previewOpen = false;
                 this.preview = { name: '', url: '', type: '', mimeType: '' };
             },
         }">
        {{-- UI Kit header and saved information; the published content below mirrors the authoring Content tab. --}}
        <div class="lf-admin-ui lf-admin-ui--list ui-version">
            <section class="mk-section" aria-labelledby="course-version-title">
                <div class="ui-summary-head ui-version__head">
                    <div class="ui-version__identity">
                        <p class="ui-version__eyebrow">
                            {{ __('lf.LF_course_template_version_number', ['version' => $version->version_number]) }}
                            <span aria-hidden="true">·</span>
                            <span>{{ $version->version_code }}</span>
                        </p>
                        <h2 id="course-version-title">{{ $version->title_snapshot }}</h2>
                        <div class="ui-version__chips">
                            @if ($version->is_current)
                                <span class="ui-status ui-status--active">{{ __('lf.LF_version_detail_current_published') }}</span>
                            @else
                                <span @class(['ui-status', 'ui-status--draft' => $version->status === 'published', 'ui-status--inactive' => $version->status === 'deprecated', 'ui-status--archived' => $version->status === 'archived'])>{{ __('lf.LF_course_template_version_status_'.$version->status) }}</span>
                            @endif
                        </div>
                    </div>
                    {{-- An archived Template is read-only, so it cannot take a Version back into its draft. --}}
                    @if ($template->status !== 'archived' && in_array($version->status, ['published', 'deprecated', 'archived'], true))
                        <form method="POST"
                              action="{{ route('admin.course-templates.versions.duplicate-to-draft', ['templateId' => $template->id, 'versionId' => $version->id]) }}"
                              data-lf-confirm="{{ __('lf.LF_course_template_duplicate_confirm_named', ['version' => $version->version_number]) }}"
                              data-lf-confirm-label="{{ __('lf.LF_course_template_duplicate_action') }}">
                            @csrf
                            <button type="submit" class="ui-button ui-button--outline cursor-interaction">
                                <x-backend-icon name="copy" class="ui-icon" />{{ __('lf.LF_course_template_duplicate_action') }}
                            </button>
                        </form>
                    @endif
                </div>

                <dl class="ui-summary ui-version__facts">
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_course_template_history_published_at') }}</dt>
                        <dd>{{ \Illuminate\Support\Carbon::parse($version->published_at)->format('d/m/Y H:i') }}</dd>
                    </div>
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_course_template_history_published_by') }}</dt>
                        <dd>{{ $version->published_by_name ?? __('lf.LF_course_template_history_unknown_publisher') }}</dd>
                    </div>
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_version_detail_source_revision') }}</dt>
                        <dd>{{ $version->source_working_revision }}</dd>
                    </div>
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_course_template_version_detail_lesson_count') }}</dt>
                        <dd>{{ $version->lesson_count_snapshot }}</dd>
                    </div>
                </dl>
                <p class="ui-version__note">
                    <x-backend-icon name="lock" class="ui-icon" />
                    <span>{{ __('lf.LF_version_detail_immutable_note') }}</span>
                </p>
            </section>

            <section class="mk-section" aria-labelledby="course-version-information-title">
                <div class="ui-summary-head">
                    <h2 id="course-version-information-title">{{ __('lf.LF_course_template_version_detail_information') }}</h2>
                </div>
                <div class="ui-summary-group">
                    <dl class="ui-summary ui-version__info">
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_common_category') }}</dt>
                            <dd>{{ $version->category_name_snapshot ?? __('lf.LF_course_template_common_no_category') }}</dd>
                        </div>
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_common_publisher_name') }}</dt>
                            <dd>{{ $version->publisher_name_snapshot ?? __('lf.LF_course_template_publish_not_available') }}</dd>
                        </div>
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_common_difficulty_level') }}</dt>
                            <dd>{{ $version->difficulty_level_snapshot ? __('lf.LF_course_template_common_'.$version->difficulty_level_snapshot) : __('lf.LF_course_template_common_no_difficulty') }}</dd>
                        </div>
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_estimated_minutes_per_lesson') }}</dt>
                            <dd>{{ $version->estimated_minutes_per_lesson_snapshot ?? '—' }}</dd>
                        </div>
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_template_estimated_lesson_count') }}</dt>
                            <dd>{{ $version->estimated_lesson_count_snapshot ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>

                @if ($version->short_description_snapshot || $version->description_snapshot)
                    <div class="ui-summary-group">
                        <dl class="ui-summary">
                            @foreach ([
                                __('lf.LF_course_template_common_short_description') => $version->short_description_snapshot,
                                __('lf.LF_course_template_common_description') => $version->description_snapshot,
                            ] as $copyLabel => $copyValue)
                                @if ($copyValue)
                                    <div class="ui-summary__item ui-summary__item--wide">
                                        <dt class="course-version-copy-label">{{ $copyLabel }}</dt>
                                        <dd class="course-version-copy-content">{{ $copyValue }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                @endif

                <div class="ui-summary-group">
                    <h3>{{ __('lf.LF_course_template_version_detail_media') }}</h3>
                    <div class="ui-version__media">
                        @foreach ([
                            'image' => __('lf.LF_course_template_intro_image'),
                            'video' => __('lf.LF_course_template_intro_video'),
                            'document' => __('lf.LF_course_template_intro_document'),
                        ] as $slot => $label)
                            @php
                                $snapshot = $templateVersionMedia[$slot];
                            @endphp
                            <div class="ui-version__media-slot" data-version-media-slot="{{ $slot }}">
                                <p class="ui-field__label">{{ $label }}</p>
                                @if ($snapshot['state'] === 'empty')
                                    <p class="ui-version__media-empty">{{ __('lf.LF_course_template_version_detail_no_media') }}</p>
                                @elseif ($snapshot['state'] === 'unavailable')
                                    <p class="ui-version__media-empty is-unavailable">{{ __('lf.LF_version_detail_media_unavailable') }}</p>
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
                                    <x-authoring-media-row :presentation="$snapshot['thumbnail']" :alt="$mediaName" :display-name="$mediaName">
                                        @if ($snapshot['viewMode'] === 'new_tab_document')
                                            <a class="authoring-media-overlay-action" href="{{ $snapshot['url'] }}" target="_blank" rel="noopener noreferrer"
                                               aria-label="{{ __('lf.LF_media_file_common_preview_action') }}">
                                                <x-backend-icon name="eye" class="authoring-media-action-icon" />
                                            </a>
                                        @else
                                            <button type="button" class="authoring-media-overlay-action"
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
                </div>
            </section>
        </div>

        {{-- Read-only mirror of the authoring Content tab: same outline, search, folds, phase tabs and side cards, no edit actions. --}}
        <section id="course-version-content"
                 class="admin-card admin-form-card course-template-section-card lf-admin-ui lf-admin-ui--list ui-structure ui-version-content"
                 aria-labelledby="course-version-content-title"
                 x-data="courseTemplateStructure(
                     @js($sections->isNotEmpty() && $directLessons->isEmpty() ? 'sections' : 'direct'),
                     @js('lf-course-template-version-'.$version->id.'-content-tab')
                 )">
            @php
                $sectionsByParent = $sections->groupBy(
                    fn (object $section): string => (string) ($section->parent_version_section_id ?? 'root')
                );
                // Outline numbers (1, 1.2, 1.2.1), lesson totals per branch and the table of contents, as in the Content tab.
                $sectionOutline = [];
                $sectionLessonTotals = [];
                $walkSections = function (string $parentKey, int $depth, string $prefix) use (&$walkSections, &$sectionOutline, &$sectionLessonTotals, $sectionsByParent, $lessonsBySection): int {
                    $total = 0;
                    foreach ($sectionsByParent->get($parentKey, collect())->values() as $index => $node) {
                        $number = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
                        $position = count($sectionOutline);
                        $sectionOutline[] = ['section' => $node, 'depth' => $depth, 'number' => $number];
                        $branchTotal = $lessonsBySection->get($node->id, collect())->count()
                            + $walkSections((string) $node->id, $depth + 1, $number);
                        $sectionOutline[$position]['lessons'] = $branchTotal;
                        $sectionLessonTotals[$node->id] = $branchTotal;
                        $total += $branchTotal;
                    }

                    return $total;
                };
                $walkSections('root', 0, '');
                $sectionOutlineNumbers = collect($sectionOutline)->mapWithKeys(
                    fn (array $row): array => [$row['section']->id => $row['number']]
                )->all();
                $activityTypeGroup = static fn (?string $type): string => match ($type) {
                    'video', 'embedded_video' => 'video',
                    'audio' => 'audio',
                    'quiz', 'assignment' => 'quiz',
                    'live_class', 'liveclass' => 'live',
                    default => 'document',
                };
                $contentOverview = static function ($lessonRows) use ($activitiesByLesson): array {
                    $activities = $lessonRows->flatMap(fn (object $item) => $activitiesByLesson->get($item->id, collect()));

                    return [
                        'lessons' => $lessonRows->count(),
                        'activities' => $activities->count(),
                        'types' => $activities->countBy(fn (object $activity): string => (string) $activity->activity_type)->sortDesc(),
                    ];
                };
                $overviews = [
                    'direct' => $contentOverview($directLessons),
                    'sections' => $contentOverview($sections->flatMap(fn (object $node) => $lessonsBySection->get($node->id, collect()))),
                ];
                $foldScope = 'version-'.$version->id;
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
                        x-bind:aria-selected="activeStructureTab === 'direct'"
                        x-bind:tabindex="activeStructureTab === 'direct' ? 0 : -1"
                        x-bind:class="{ 'is-active': activeStructureTab === 'direct' }"
                        x-on:click="selectStructureTab('direct')"
                        x-on:keydown.right.prevent="selectStructureTab('sections')">
                    {{ __('lf.LF_course_template_structure_tab_direct') }}
                </button>
                <button id="course-version-sections-tab"
                        type="button"
                        role="tab"
                        aria-controls="course-version-sections-panel"
                        x-bind:aria-selected="activeStructureTab === 'sections'"
                        x-bind:tabindex="activeStructureTab === 'sections' ? 0 : -1"
                        x-bind:class="{ 'is-active': activeStructureTab === 'sections' }"
                        x-on:click="selectStructureTab('sections')"
                        x-on:keydown.left.prevent="selectStructureTab('direct')">
                    {{ __('lf.LF_course_template_structure_tab_sections') }}
                </button>
            </div>
            <p class="course-template-structure-tab-help" aria-live="polite">
                <span x-show="activeStructureTab === 'direct'">
                    {{ __('lf.LF_course_template_structure_tab_direct_help') }}
                </span>
                <span x-show="activeStructureTab === 'sections'" x-cloak>
                    {{ __('lf.LF_course_template_structure_tab_sections_help') }}
                </span>
            </p>

            <div id="course-version-direct-panel"
                 class="course-template-structure-panel"
                 role="tabpanel"
                 aria-labelledby="course-version-direct-tab"
                 x-data="courseTemplateContentTools('.course-template-lesson-item')"
                 x-show="activeStructureTab === 'direct'"
                 x-cloak>
                <section id="course-version-direct-lessons"
                         class="course-template-lesson-panel course-template-direct-lesson-panel"
                         aria-labelledby="course-version-direct-lessons-title">
                    <div class="course-template-section-action-bar course-template-content-toolbar">
                        <div class="course-template-content-count">
                            <span class="course-template-content-heading">{{ __('lf.LF_course_template_content_lessons_title') }}</span>
                            <strong id="course-version-direct-lessons-title">
                                {{ trans_choice('lf.LF_course_template_lesson_common_count', $directLessons->count(), ['count' => $directLessons->count()]) }}
                            </strong>
                            @if ($directLessons->isNotEmpty())
                                <span class="lf-secondary-text">{{ trans_choice('lf.LF_course_template_activity_common_count', $overviews['direct']['activities'], ['count' => $overviews['direct']['activities']]) }}</span>
                            @endif
                        </div>
                        @if ($directLessons->isNotEmpty())
                            @include('course-template-sections.partials.content-search', [
                                'placeholder' => __('lf.LF_course_template_content_search_lessons_placeholder'),
                            ])
                        @endif
                    </div>
                    <div class="course-template-lesson-list">
                        @forelse ($directLessons as $lesson)
                            @include('course-template-versions.partials.lesson')
                        @empty
                            <p class="course-template-outline-empty">{{ __('lf.LF_version_detail_no_direct_lessons') }}</p>
                        @endforelse
                    </div>
                    <p class="course-template-content-no-results" x-show="noResults" x-cloak role="status">
                        {{ __('lf.LF_course_template_content_search_empty') }}
                    </p>
                </section>
            </div>

            <div id="course-version-sections-panel"
                 class="course-template-structure-panel"
                 role="tabpanel"
                 aria-labelledby="course-version-sections-tab"
                 x-data="courseTemplateContentTools('.course-template-outline-section')"
                 x-show="activeStructureTab === 'sections'"
                 x-cloak>
                <div class="course-template-section-action-bar course-template-content-toolbar">
                    <div class="course-template-content-count">
                        <span class="course-template-content-heading">{{ __('lf.LF_course_template_content_sections_title') }}</span>
                        <strong>{{ trans_choice('lf.LF_course_template_section_common_count', $sections->count(), ['count' => $sections->count()]) }}</strong>
                        @if ($sections->isNotEmpty())
                            <span class="lf-secondary-text">{{ trans_choice('lf.LF_course_template_lesson_common_count', $overviews['sections']['lessons'], ['count' => $overviews['sections']['lessons']]) }}</span>
                            <span class="lf-secondary-text">{{ trans_choice('lf.LF_course_template_activity_common_count', $overviews['sections']['activities'], ['count' => $overviews['sections']['activities']]) }}</span>
                        @endif
                    </div>
                    @if ($sections->isNotEmpty())
                        @include('course-template-sections.partials.content-search', [
                            'placeholder' => __('lf.LF_course_template_content_search_sections_placeholder'),
                        ])
                    @endif
                </div>
                <div class="course-template-outline-sections">
                    @forelse ($sectionsByParent->get('root', collect()) as $section)
                        @include('course-template-versions.partials.section-node', ['depth' => 0])
                    @empty
                        <div class="course-template-content-empty course-version-content-empty">
                            <span class="course-template-content-empty-icon" aria-hidden="true">
                                <x-backend-icon name="hierarchy" />
                            </span>
                            <strong>{{ __('lf.LF_version_detail_no_sections') }}</strong>
                            <p>{{ __('lf.LF_version_detail_no_sections_help') }}</p>
                        </div>
                    @endforelse
                </div>
                <p class="course-template-content-no-results" x-show="noResults" x-cloak role="status">
                    {{ __('lf.LF_course_template_content_search_empty') }}
                </p>
            </div>

            @if ($sections->isNotEmpty())
                <nav class="course-template-content-side-card course-template-content-toc"
                     aria-labelledby="course-version-content-toc-title"
                     x-show="activeStructureTab === 'sections'"
                     x-cloak>
                    <h3 id="course-version-content-toc-title">{{ __('lf.LF_course_template_content_toc_title') }}</h3>
                    <ol>
                        @foreach ($sectionOutline as $row)
                            <li>
                                <button type="button"
                                        style="--toc-depth: {{ $row['depth'] }}"
                                        x-on:click="revealSection(@js($row['section']->id))">
                                    <span class="course-template-content-toc-number">{{ $row['number'] }}</span>
                                    <span @class(['course-template-content-toc-title', 'is-root' => $row['depth'] === 0])>{{ $row['section']->title_snapshot }}</span>
                                    <span @class(['course-template-content-toc-count', 'is-empty' => $row['lessons'] === 0])>
                                        {{ $row['lessons'] === 0 ? __('lf.LF_course_template_content_toc_empty') : $row['lessons'] }}
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ol>
                </nav>
            @endif

            @foreach ($overviews as $overviewMode => $overview)
                <section class="course-template-content-side-card course-template-content-overview"
                         aria-labelledby="course-version-content-overview-{{ $overviewMode }}"
                         x-show="activeStructureTab === @js($overviewMode)"
                         x-cloak>
                    <h3 id="course-version-content-overview-{{ $overviewMode }}">{{ __('lf.LF_course_template_content_overview_title') }}</h3>
                    <dl class="course-template-content-stats">
                        @if ($overviewMode === 'sections')
                            <div><dt>{{ __('lf.LF_course_template_content_stat_sections') }}</dt><dd>{{ $sections->count() }}</dd></div>
                        @endif
                        <div><dt>{{ __('lf.LF_course_template_content_stat_lessons') }}</dt><dd>{{ $overview['lessons'] }}</dd></div>
                        <div><dt>{{ __('lf.LF_course_template_content_stat_activities') }}</dt><dd>{{ $overview['activities'] }}</dd></div>
                    </dl>
                    @if ($overview['types']->isNotEmpty())
                        <ul class="course-template-content-breakdown">
                            @foreach ($overview['types'] as $type => $count)
                                <li>
                                    <span class="course-template-content-swatch course-template-content-swatch--{{ $activityTypeGroup($type) }}" aria-hidden="true"></span>
                                    {{ \Illuminate\Support\Facades\Lang::has('lf.LF_course_template_activity_common_type_'.$type) ? __('lf.LF_course_template_activity_common_type_'.$type) : $type }}
                                    <strong>{{ $count }}</strong>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </section>

        {{-- Same UI Kit preview popup (default size) as the Template information tab; x-if drops the player on close, which stops playback. --}}
        <div class="lf-admin-ui">
            <x-media-preview-modal ui-kit id="version-media-preview-title" show="previewOpen" close="closeVersionPreview()" title="preview.name" compact="false">
                <template x-if="previewOpen && preview.type === 'image'">
                    <img x-bind:src="preview.url" x-bind:alt="preview.name">
                </template>
                <template x-if="previewOpen && preview.type === 'video'">
                    <video controls preload="metadata" autoplay x-bind:src="preview.url"></video>
                </template>
                <template x-if="previewOpen && preview.type === 'audio'">
                    <audio controls preload="metadata" autoplay x-bind:src="preview.url"></audio>
                </template>
                <template x-if="previewOpen && preview.type === 'embed'">
                    <iframe x-bind:src="preview.url" x-bind:title="preview.name" loading="lazy"
                            sandbox="allow-scripts allow-same-origin allow-presentation"
                            allow="autoplay; fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                </template>
            </x-media-preview-modal>
        </div>
    </div>
@endsection
