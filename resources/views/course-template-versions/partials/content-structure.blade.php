{{-- Read-only mirror of the authoring Content tab: same outline, search, folds, phase tabs and side cards, no edit actions. --}}
        @php
            // A class shows the kind its Version already is; only a Version holding both needs the switch.
            $showStructureSwitch = ! isset($sessionTree) || ($directLessons->isNotEmpty() && $sections->isNotEmpty());
        @endphp
        <section id="course-version-content"
                 @class(['admin-card admin-form-card course-template-section-card lf-admin-ui lf-admin-ui--list ui-structure ui-version-content', 'ui-structure--one-kind' => ! $showStructureSwitch, 'ui-structure--cohort' => isset($sessionTree)])
                 aria-labelledby="course-version-content-title"
                 x-data="courseTemplateStructure(
                     @js($sections->isNotEmpty() && $directLessons->isEmpty() ? 'sections' : 'direct'),
                     @js($showStructureSwitch ? ($contentKey ?? 'lf-course-template-version-'.$version->id).'-content-tab' : null)
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
                $foldScope = $foldScope ?? 'version-'.$version->id;
            @endphp

            <header class="course-template-section-header course-version-structure-header">
                <h2 id="course-version-content-title" class="admin-form-section-title">
                    {{ __('lf.LF_course_template_version_detail_content') }}
                </h2>
                <p>{{ $structureHelp ?? __('lf.LF_version_detail_content_help') }}</p>
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
                 @if ($showStructureSwitch) role="tabpanel" aria-labelledby="course-version-direct-tab" @endif
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
                    {{-- 15 lessons per page, as in the Content tab; search and fold-all still cover every lesson. --}}
                    <div x-data="lfLessonPager(15, @js(($contentKey ?? 'lf-course-template-version-'.$version->id).'-direct-page'), { summary: @js(__('lf.LF_course_template_lesson_pager_summary')) })"
                         @isset($sessionTree) x-on:course-template-order-changed="$event.target === $refs.list && refresh($event.detail.item)" @endisset>
                        <div class="course-template-lesson-list" x-ref="list"
                             @isset($sessionTree) @include('course-cohorts.partials.tabs.session-tree-lesson-order', ['orderLessons' => $directLessons, 'orderSectionId' => null]) @endisset>
                            @forelse ($directLessons as $lesson)
                                @include('course-template-versions.partials.lesson')
                            @empty
                                <p class="course-template-outline-empty">{{ __('lf.LF_version_detail_no_direct_lessons') }}</p>
                            @endforelse
                        </div>
                        <x-ui-lesson-pager-foot />
                    </div>
                    <p class="course-template-content-no-results" x-show="noResults" x-cloak role="status">
                        {{ __('lf.LF_course_template_content_search_empty') }}
                    </p>
                </section>
            </div>

            <div id="course-version-sections-panel"
                 class="course-template-structure-panel"
                 @if ($showStructureSwitch) role="tabpanel" aria-labelledby="course-version-sections-tab" @endif
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

