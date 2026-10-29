<section id="course-template-sections"
         class="admin-card admin-form-card course-template-section-card lf-admin-ui lf-admin-ui--list ui-structure"
         aria-labelledby="course-template-sections-title"
         data-ui-dialog-form-scope data-ui-row-actions
         data-csrf="{{ csrf_token() }}"
         data-msg-delete-error="{{ __('lf.LF_course_template_lesson_delete_error') }}"
         data-msg-session-expired="{{ __('lf.LF_course_template_lesson_dialog_session_expired') }}"
         x-data="courseTemplateStructure(
             @js($sections->isNotEmpty() && $directLessons->isEmpty() ? 'sections' : 'direct'),
             @js('lf-course-template-'.$template->id.'-structure-tab')
         )">
    @php
        $hasDirectLessons = $directLessons->isNotEmpty();
        $hasSections = $sections->isNotEmpty();
        $sectionsByParent = $sections->groupBy(
            fn (object $section): string => (string) ($section->parent_section_id ?? 'root')
        );

        // Outline numbers (1, 1.2, 1.2.1), lesson totals per branch and the table of contents.
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
            $activities = $lessonRows->flatMap(
                fn (object $item) => $activitiesByLesson->get($item->id, collect())
            );

            return [
                'lessons' => $lessonRows->count(),
                'activities' => $activities->count(),
                'types' => $activities->countBy(fn (object $activity): string => (string) $activity->activity_type)->sortDesc(),
            ];
        };
        $lessonsInSections = $sections->flatMap(fn (object $node) => $lessonsBySection->get($node->id, collect()));
        $overviews = [
            'direct' => $contentOverview($directLessons),
            'sections' => $contentOverview($lessonsInSections),
        ];
    @endphp

    <x-ui-list-toasts />

    <header class="course-template-section-header">
        <h2 id="course-template-sections-title" class="admin-form-section-title">
            {{ __('lf.LF_course_template_section_common_structure_title') }}
        </h2>
        <p>{{ __('lf.LF_course_template_section_common_structure_help') }}</p>
    </header>

    @if ($hasDirectLessons && $hasSections)
        <div class="course-template-structure-note" role="status">
            <p>
                {{ __('lf.LF_course_template_mode_mixed_note') }}
            </p>
            <p>{{ __('lf.LF_course_template_mode_mixed_help') }}</p>
        </div>
    @endif

    <div class="course-template-structure-tabs"
         role="tablist"
         aria-label="{{ __('lf.LF_course_template_structure_tabs_label') }}">
        <button id="course-template-direct-tab"
                type="button"
                role="tab"
                aria-controls="course-template-direct-panel"
                x-bind:aria-selected="activeStructureTab === 'direct'"
                x-bind:tabindex="activeStructureTab === 'direct' ? 0 : -1"
                x-bind:class="{ 'is-active': activeStructureTab === 'direct' }"
                x-on:click="selectStructureTab('direct')"
                x-on:keydown.right.prevent="selectStructureTab('sections')">
            {{ __('lf.LF_course_template_structure_tab_direct') }}
        </button>
        <button id="course-template-sections-tab"
                type="button"
                role="tab"
                aria-controls="course-template-sections-panel"
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

    <div id="course-template-direct-panel"
         class="course-template-structure-panel"
         role="tabpanel"
         aria-labelledby="course-template-direct-tab"
         x-data="courseTemplateContentTools('.course-template-lesson-item')"
         x-show="activeStructureTab === 'direct'"
         x-cloak>
        @include('course-template-lessons.partials.list', [
            'lessons' => $directLessons,
            'section' => null,
            'lessonRoutePrefix' => $directLessonRoutePrefix,
            'activityRoutePrefix' => $directActivityRoutePrefix,
            'panelId' => 'course-template-direct-lessons',
            'panelTitle' => __('lf.LF_course_template_lesson_common_direct_title'),
            'emptyMessage' => __('lf.LF_course_template_lesson_common_direct_empty'),
        ])
    </div>

    <div id="course-template-sections-panel"
         class="course-template-structure-panel"
         role="tabpanel"
         aria-labelledby="course-template-sections-tab"
         x-data="courseTemplateContentTools('.course-template-outline-section')"
         x-show="activeStructureTab === 'sections'"
         x-cloak>
        <div class="course-template-section-action-bar course-template-content-toolbar"
             aria-label="{{ __('lf.LF_course_template_section_common_actions') }}">
            <div class="course-template-content-count">
                <span class="course-template-content-heading">{{ __('lf.LF_course_template_content_sections_title') }}</span>
                <strong>{{ trans_choice('lf.LF_course_template_section_common_count', $sections->count(), ['count' => $sections->count()]) }}</strong>
                @if ($hasSections)
                    <span class="lf-secondary-text">{{ trans_choice('lf.LF_course_template_lesson_common_count', $overviews['sections']['lessons'], ['count' => $overviews['sections']['lessons']]) }}</span>
                    <span class="lf-secondary-text">{{ trans_choice('lf.LF_course_template_activity_common_count', $overviews['sections']['activities'], ['count' => $overviews['sections']['activities']]) }}</span>
                @endif
            </div>
            @if ($hasSections)
                @include('course-template-sections.partials.content-search', [
                    'placeholder' => __('lf.LF_course_template_content_search_sections_placeholder'),
                ])
                <button type="button" class="btn admin-primary-outline-action ui-button ui-button--outline"
                        data-ui-dialog-form-open aria-haspopup="dialog"
                        data-url="{{ route($sectionRoutePrefix.'.create', $template->id) }}">
                    <x-backend-icon name="plus" class="ui-icon" />
                    {{ __('lf.LF_course_template_section_common_add_action') }}
                </button>
            @endif
        </div>

        <div class="course-template-outline-sections">
            @forelse ($sectionsByParent->get('root', collect()) as $section)
                @include('course-template-sections.partials.section-node', [
                    'depth' => 0,
                ])
            @empty
                <div class="course-template-content-empty">
                    <span class="course-template-content-empty-icon" aria-hidden="true">
                        <x-backend-icon name="hierarchy" />
                    </span>
                    <strong>{{ __('lf.LF_course_template_section_common_empty_title') }}</strong>
                    <p>{{ __('lf.LF_course_template_section_common_empty') }}</p>
                    <button type="button" class="btn admin-primary-outline-action ui-button ui-button--outline"
                            data-ui-dialog-form-open aria-haspopup="dialog"
                            data-url="{{ route($sectionRoutePrefix.'.create', $template->id) }}">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_course_template_section_common_add_action') }}
                    </button>
                </div>
            @endforelse
        </div>
        @if ($hasSections)
            <p class="course-template-content-no-results" x-show="noResults" x-cloak role="status">
                {{ __('lf.LF_course_template_content_search_empty') }}
            </p>
            <button type="button" class="course-template-content-add-row"
                    data-ui-dialog-form-open aria-haspopup="dialog"
                    data-url="{{ route($sectionRoutePrefix.'.create', $template->id) }}">
                <x-backend-icon name="plus" class="ui-icon" />
                {{ __('lf.LF_course_template_content_add_root_section') }}
            </button>
        @endif
    </div>

    @if ($hasSections)
        <nav class="course-template-content-side-card course-template-content-toc"
             aria-labelledby="course-template-content-toc-title"
             x-show="activeStructureTab === 'sections'"
             x-cloak>
            <h3 id="course-template-content-toc-title">{{ __('lf.LF_course_template_content_toc_title') }}</h3>
            <ol>
                @foreach ($sectionOutline as $row)
                    <li>
                        <button type="button"
                                style="--toc-depth: {{ $row['depth'] }}"
                                x-on:click="revealSection(@js($row['section']->id))">
                            <span class="course-template-content-toc-number">{{ $row['number'] }}</span>
                            <span @class(['course-template-content-toc-title', 'is-root' => $row['depth'] === 0])>{{ $row['section']->title }}</span>
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
                 aria-labelledby="course-template-content-overview-{{ $overviewMode }}"
                 x-show="activeStructureTab === @js($overviewMode)"
                 x-cloak>
            <h3 id="course-template-content-overview-{{ $overviewMode }}">{{ __('lf.LF_course_template_content_overview_title') }}</h3>
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

    <dialog class="ui-modal" data-columns="2" aria-labelledby="structure-dialog-title" data-ui-dialog-form data-field-id-prefix="lesson-form-"
            data-msg-load-error="{{ __('lf.LF_course_template_lesson_dialog_load_error') }}"
            data-msg-discard-title="{{ __('lf.LF_course_template_lesson_dialog_discard_title') }}"
            data-msg-discard-text="{{ __('lf.LF_course_template_lesson_dialog_discard_text') }}"
            data-msg-discard="{{ __('lf.LF_course_template_lesson_dialog_discard') }}"
            data-msg-save-error="{{ __('lf.LF_course_template_lesson_dialog_save_error') }}"
            data-msg-session-expired="{{ __('lf.LF_course_template_lesson_dialog_session_expired') }}"
            data-msg-required-input="{{ __('lf.LF_course_template_lesson_dialog_required_input') }}"
            data-msg-required-select="{{ __('lf.LF_course_template_lesson_dialog_required_select') }}"></dialog>
</section>
