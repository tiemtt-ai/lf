@php
    $versions = collect($learningMappingState['versions']);
    $selection = $versions->first(fn ($version) => (int) $version->framework_id === (int) ($template->selected_learning_framework_id ?? 0) && (int) $version->framework_version_id === (int) ($template->selected_learning_framework_version_id ?? 0));
    $nodes = collect($learningMappingState['nodes']);
    $intents = collect($learningMappingState['intents']);
    $intentsBySource = $intents->groupBy(fn ($intent) => $intent->source_type.':'.$intent->source_id);
    $activitiesById = collect($activitiesByLesson)->flatten(1)->keyBy('id');
    $lessonsById = collect($directLessons)->merge(collect($lessonsBySection)->flatten(1))->keyBy('id');
    // The HTML page of each Activity, from the Course tree this page already has; a missing Activity has no link.
    $activityPages = $activitiesById->mapWithKeys(function ($activity) use ($lessonsById, $template, $directActivityRoutePrefix, $activityRoutePrefix) {
        $lesson = $lessonsById->get($activity->template_lesson_id);
        if ($lesson === null) {
            return [];
        }

        return [(int) $activity->id => $lesson->template_section_id === null
            ? route($directActivityRoutePrefix.'.show', [$template->id, $lesson->id, $activity->id])
            : route($activityRoutePrefix.'.show', [$template->id, $lesson->template_section_id, $lesson->id, $activity->id])];
    });
    $shownIntentIds = [];

    // Lessons in course order, laid out like the Content tab: direct lessons, then the Section outline
    // (numbers 1, 1.2, 1.2.1) with per-branch lesson and unmapped totals for the table of contents.
    $sectionsByParent = collect($sections)->groupBy(fn ($section) => (string) ($section->parent_section_id ?? 'root'));
    $lessonCount = 0;
    $mappedLessonCount = 0;
    $nodeLessonCounts = [];
    $makeLesson = function (object $lesson) use (&$lessonCount, &$mappedLessonCount, &$shownIntentIds, &$nodeLessonCounts, $activitiesByLesson, $intentsBySource, $activitiesById): array {
        $activities = collect($activitiesByLesson[$lesson->id] ?? []);
        $skills = collect($intentsBySource->get('course_template_lesson:'.$lesson->id, []))
            ->merge($activities->flatMap(fn ($activity) => $intentsBySource->get('course_template_activity:'.$activity->id, [])))
            ->map(function ($intent) use ($activitiesById) {
                $intent->activity_title = $intent->source_type === 'course_template_activity'
                    ? ($activitiesById->get((int) $intent->source_id)->title ?? null)
                    : null;

                return $intent;
            });
        array_push($shownIntentIds, ...$skills->pluck('id')->map(fn ($id) => (int) $id)->all());
        foreach ($skills->pluck('learning_node_id')->map(fn ($id) => (int) $id)->unique() as $nodeId) {
            $nodeLessonCounts[$nodeId] = ($nodeLessonCounts[$nodeId] ?? 0) + 1;
        }
        $lessonCount++;
        $mappedLessonCount += $skills->isNotEmpty() ? 1 : 0;

        return ['lesson' => $lesson, 'activities' => $activities, 'skills' => $skills];
    };
    $directRows = collect($directLessons)->sortBy('sort_order')->map(fn ($lesson) => [...$makeLesson($lesson), 'sections' => []])->values();
    $sectionRows = [];
    $sectionOutline = [];
    $sectionTotals = [];
    $walkSections = function (string $parentKey, int $depth, string $prefix, array $ancestors) use (&$walkSections, &$sectionRows, &$sectionOutline, &$sectionTotals, $sectionsByParent, $lessonsBySection, $makeLesson): array {
        $total = ['lessons' => 0, 'unmapped' => 0];
        foreach ($sectionsByParent->get($parentKey, collect())->values() as $index => $node) {
            $number = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
            $chain = [...$ancestors, (int) $node->id];
            $position = count($sectionOutline);
            $sectionOutline[] = ['section' => $node, 'depth' => $depth, 'number' => $number];
            $rows = collect($lessonsBySection[$node->id] ?? [])->sortBy('sort_order')->map(fn ($lesson) => [...$makeLesson($lesson), 'sections' => $chain])->values();
            $sectionRows[$node->id] = $rows;
            $branch = $walkSections((string) $node->id, $depth + 1, $number, $chain);
            $branch['lessons'] += $rows->count();
            $branch['unmapped'] += $rows->filter(fn ($row) => $row['skills']->isEmpty())->count();
            $sectionOutline[$position] += $branch;
            $sectionTotals[$node->id] = $branch;
            $total['lessons'] += $branch['lessons'];
            $total['unmapped'] += $branch['unmapped'];
        }

        return $total;
    };
    $walkSections('root', 0, '', []);
    $sectionOutlineNumbers = collect($sectionOutline)->mapWithKeys(fn (array $row) => [$row['section']->id => $row['number']])->all();
    $hasDirectLessons = $directRows->isNotEmpty();
    $hasSections = collect($sections)->isNotEmpty();
    $structureMode = $hasSections && ! $hasDirectLessons ? 'sections' : 'direct';
    $modeLessonCounts = ['direct' => $directRows->count(), 'sections' => collect($sectionRows)->flatten(1)->count()];
    // Links whose Lesson or Activity left the Course tree stay visible so they can still be removed.
    $orphanSkills = $intents->reject(fn ($intent) => in_array((int) $intent->id, $shownIntentIds, true))->values();
    $usedNodeCount = $intents->pluck('learning_node_id')->unique()->filter(fn ($id) => $nodes->contains('id', $id))->count();

    $roleLabels = [
        'practices' => __('lf.LF_course_template_learning_role_practices'),
        'assesses' => __('lf.LF_course_template_learning_role_assesses'),
    ];
    $versionLabel = fn ($version) => __('lf.LF_course_template_learning_framework_select_option', [
        'framework' => $version->framework_name,
        'version' => $version->title_snapshot ?: $version->version_code,
    ]);
    $percent = fn (int $part, int $whole): int => $whole > 0 ? (int) round($part / $whole * 100) : 0;
    $lower = fn (?string $text): string => mb_strtolower((string) $text);
    // What the filter and search work on (client-side only): one entry per lesson card, with the Sections it sits in.
    $lessonIndex = $directRows->merge(collect($sectionRows)->flatten(1))->mapWithKeys(fn ($row) => [$row['lesson']->id => [
        'mapped' => $row['skills']->isNotEmpty(),
        'sections' => $row['sections'],
        'text' => $lower($row['lesson']->title.' '.$row['skills']->map(fn ($skill) => $skill->name_snapshot.' '.$skill->code_snapshot)->implode(' ')),
    ]]);
@endphp

<div id="course-learning" class="lf-admin-ui ui-learning">
    @if ($selection)
        <section class="ui-learning-card" aria-labelledby="course-learning-framework-title">
            <div class="ui-learning-framework">
                <div class="ui-learning-framework__id">
                    <span class="ui-learning-framework__icon" aria-hidden="true"><x-backend-icon name="layers" class="ui-icon" /></span>
                    <div>
                        <h2 id="course-learning-framework-title" class="ui-learning-framework__label">{{ __('lf.LF_course_template_learning_framework_title') }}</h2>
                        <p class="ui-learning-framework__name" data-course-learning-selection>{{ $selection->framework_name }}</p>
                        <div class="ui-learning-framework__tags" data-course-learning-selected-version>
                            <span class="ui-learning-badge">{{ $selection->title_snapshot ?: $selection->version_code }}</span>
                            <span class="ui-learning-badge ui-learning-badge--success"><x-backend-icon name="check" class="ui-icon" />{{ __('lf.LF_course_template_learning_current_badge') }}</span>
                            <span class="ui-learning-badge ui-learning-badge--muted">{{ __('lf.LF_course_template_learning_ui_node_count', ['count' => $nodes->count()]) }}</span>
                        </div>
                    </div>
                </div>
                <div class="ui-learning-framework__actions" x-data>
                    <button type="button" class="ui-button ui-button--outline cursor-interaction" x-on:click="$refs.frameworkPreview.showModal()"
                            title="{{ __('lf.LF_course_template_learning_framework_view_tooltip') }}">
                        <x-backend-icon name="eye" class="ui-icon" />{{ __('lf.LF_course_template_learning_framework_view') }}
                    </button>
                    @if ($versions->isNotEmpty() && $template->status === 'draft')
                        <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.frameworkChange.showModal()">
                            <x-backend-icon name="sliders" class="ui-icon" />{{ __('lf.LF_course_template_learning_ui_change') }}
                        </button>
                    @endif
                    @if ($template->status === 'draft')
                        <form method="POST" action="{{ route($routePrefix.'.learning-framework.detach', $template->id) }}"
                              data-lf-confirm="{{ __('lf.LF_course_template_learning_detach_confirm', ['count' => $intents->count()]) }}"
                              data-lf-confirm-label="{{ __('lf.LF_course_template_learning_detach') }}" data-lf-confirm-tone="danger">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="working_revision" value="{{ $template->working_revision }}">
                            <button type="submit" class="ui-button ui-button--danger-outline cursor-interaction"
                                    title="{{ __('lf.LF_course_template_learning_detach_tooltip') }}">
                                <x-backend-icon name="link" class="ui-icon" />{{ __('lf.LF_course_template_learning_detach') }}
                            </button>
                        </form>
                    @endif

                    {{-- Framework preview: the scale and every competency, with how many lessons use it. --}}
                    <dialog x-ref="frameworkPreview" class="ui-modal" data-columns="2" aria-labelledby="course-framework-preview-title"
                            x-on:click="if ($event.target === $el) $el.close()">
                        {{-- The search state lives inside: an x-data on the dialog itself would take its x-ref away from the buttons. --}}
                        <div class="ui-modal__form" x-data="{ q: '' }">
                            <header class="ui-modal__header">
                                <div>
                                    <h3 id="course-framework-preview-title">{{ $selection->framework_name }}</h3>
                                    <span class="ui-caption">{{ $selection->title_snapshot ?: $selection->version_code }} · {{ __('lf.LF_course_template_learning_current_badge') }} · {{ __('lf.LF_course_template_learning_ui_read_only') }}</span>
                                </div>
                                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$el.closest('dialog').close()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
                            </header>
                            <div class="ui-modal__body">
                                <h4 class="ui-learning-subtitle">{{ __('lf.LF_learning_simple_scale_title') }}</h4>
                                @php
                                    $previewLevels = json_decode($selection->mastery_scale_snapshot ?? '{}', true)['levels'] ?? [];
                                @endphp
                                <ol class="ui-learning-scale">
                                    @foreach ($previewLevels as $level)
                                        <li data-level="{{ $loop->index }}">
                                            <strong>{{ \Illuminate\Support\Facades\Lang::has('lf.LF_learning_simple_level_'.$level['key']) ? __('lf.LF_learning_simple_level_'.$level['key']) : $level['key'] }}</strong>
                                            <span>{{ (float) $level['threshold'] > 0 ? '≥ '.round((float) $level['threshold'] * 100).'%' : '0%' }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                                <div class="ui-learning-preview-head">
                                    <h4 class="ui-learning-subtitle">{{ __('lf.LF_course_template_learning_ui_nodes_title', ['count' => $nodes->count()]) }}</h4>
                                    <label class="ui-search ui-learning-search">
                                        <x-backend-icon name="search" class="ui-icon" />
                                        <input type="search" x-model="q" placeholder="{{ __('lf.LF_course_template_learning_ui_node_search') }}" aria-label="{{ __('lf.LF_course_template_learning_ui_node_search') }}">
                                    </label>
                                </div>
                                @if ($nodes->isEmpty())
                                    <p class="ui-caption">{{ __('lf.LF_course_template_learning_preview_no_nodes') }}</p>
                                @else
                                    <div class="mk-table-scroll">
                                        <table class="ui-learning-preview-table">
                                            <thead><tr>
                                                <th scope="col">{{ __('lf.LF_course_template_learning_ui_code') }}</th>
                                                <th scope="col">{{ __('lf.LF_course_template_learning_ui_node') }}</th>
                                                <th scope="col" class="ui-learning-preview-table__count">{{ __('lf.LF_course_template_learning_ui_lessons_used') }}</th>
                                            </tr></thead>
                                            <tbody>
                                                @foreach ($nodes as $node)
                                                    <tr x-show="! q || @js($lower($node->code_snapshot.' '.$node->name_snapshot)).includes(q.toLowerCase())">
                                                        <td><span class="ui-learning-code">{{ $node->code_snapshot }}</span></td>
                                                        <td>
                                                            <span class="ui-learning-node-name">{{ $node->name_snapshot }}</span>
                                                            @if ($node->description_snapshot ?? null)<span class="ui-caption ui-learning-block">{{ $node->description_snapshot }}</span>@endif
                                                        </td>
                                                        <td class="ui-learning-preview-table__count">{{ $nodeLessonCounts[(int) $node->id] ?? 0 }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                            <footer class="ui-modal__footer">
                                <button type="button" class="ui-button cursor-interaction" x-on:click="$el.closest('dialog').close()">{{ __('lf.LF_common_button_close') }}</button>
                            </footer>
                        </div>
                    </dialog>

                    @if ($versions->isNotEmpty() && $template->status === 'draft')
                        {{-- Change framework or version: confirmed here, with what it removes, instead of on every select change. --}}
                        <dialog x-ref="frameworkChange" class="ui-modal" data-columns="1" aria-labelledby="course-framework-change-title"
                                x-on:click="if ($event.target === $el) $el.close()">
                            @include('course-templates.partials.learning-framework-select', ['inDialog' => true])
                        </dialog>
                    @endif
                </div>

                <div class="ui-learning-stats">
                    <div class="ui-learning-stat">
                        <div class="ui-learning-stat__label">{{ __('lf.LF_course_template_learning_ui_stat_lessons') }}</div>
                        <div class="ui-learning-stat__value">{{ $mappedLessonCount }} <small>/ {{ $lessonCount }}</small></div>
                        <div class="ui-learning-progress" aria-hidden="true"><span style="width: {{ $percent($mappedLessonCount, $lessonCount) }}%"></span></div>
                    </div>
                    <div class="ui-learning-stat">
                        <div class="ui-learning-stat__label">{{ __('lf.LF_course_template_learning_ui_stat_nodes') }}</div>
                        <div class="ui-learning-stat__value">{{ $usedNodeCount }} <small>/ {{ $nodes->count() }}</small></div>
                        <div class="ui-learning-progress" aria-hidden="true"><span style="width: {{ $percent($usedNodeCount, $nodes->count()) }}%"></span></div>
                    </div>
                    <div class="ui-learning-stat">
                        <div class="ui-learning-stat__label">{{ __('lf.LF_course_template_learning_ui_stat_links') }}</div>
                        <div class="ui-learning-stat__value">{{ $intents->count() }}</div>
                        <div class="ui-caption">{{ __('lf.LF_course_template_learning_ui_stat_links_help') }}</div>
                    </div>
                </div>
                <p class="ui-learning-framework__help">{{ __('lf.LF_course_template_learning_framework_help') }}</p>
            </div>
        </section>
    @else
        <section class="ui-learning-card ui-learning-empty" aria-labelledby="course-learning-framework-title">
            <span class="ui-learning-framework__icon" aria-hidden="true"><x-backend-icon name="layers" class="ui-icon" /></span>
            <h2 id="course-learning-framework-title">{{ __('lf.LF_course_template_learning_ui_empty_title') }}</h2>
            @if ($versions->isEmpty())
                <p class="ui-caption">{{ __('lf.LF_course_template_learning_framework_empty') }}</p>
                <a class="ui-button ui-button--primary cursor-interaction" href="{{ route('admin.learning-frameworks.index') }}">{{ __('lf.LF_course_template_learning_framework_manage') }}</a>
            @else
                <p class="ui-caption">{{ __('lf.LF_course_template_learning_framework_none_help') }}</p>
                @include('course-templates.partials.learning-framework-select', ['inDialog' => false])
                <a class="ui-learning-link ui-caption" href="{{ route('admin.learning-frameworks.index') }}">{{ __('lf.LF_course_template_learning_framework_manage') }}</a>
            @endif
        </section>
    @endif

    @if ($selection && $orphanSkills->isNotEmpty())
        <section class="ui-learning-card ui-learning-orphans" aria-labelledby="course-learning-orphans-title" data-course-learning-orphans>
            <h3 id="course-learning-orphans-title">
                <x-backend-icon name="circle" class="ui-icon" />{{ __('lf.LF_course_template_learning_orphans') }}
                <span class="ui-learning-badge ui-learning-badge--warn">{{ $orphanSkills->count() }}</span>
            </h3>
            <p class="ui-caption">{{ __('lf.LF_course_template_learning_orphans_help') }}</p>
            <ul class="ui-learning-chips">
                @foreach ($orphanSkills as $skill)
                    @include('course-templates.partials.learning-skill-chip')
                @endforeach
            </ul>
        </section>
    @endif

    @if ($selection)
        {{--
            Learning map laid out like the Content tab (ui-structure): lesson cards in the Section outline on the left;
            structure switch, attach progress with the filter, and the table of contents on the right. Activities,
            phases and edit actions of the Content tab are left out: competencies attach to lessons here.
        --}}
        <section class="admin-card admin-form-card course-template-section-card lf-admin-ui lf-admin-ui--list ui-structure ui-learning-map"
                 aria-labelledby="course-learning-map-title"
                 x-data="{
                     ...courseTemplateStructure(@js($structureMode), @js('lf-course-template-'.$template->id.'-learning-structure-tab')),
                     filter: 'all', q: '',
                     lessons: @js($lessonIndex),
                     get filtering() { return this.filter !== 'all' || this.q.trim() !== ''; },
                     matches(lesson) {
                         if (this.filter !== 'all' && (this.filter === 'mapped') !== lesson.mapped) return false;
                         return ! this.q.trim() || lesson.text.includes(this.q.trim().toLowerCase());
                     },
                     lessonShown(id) { return this.matches(this.lessons[id]); },
                     sectionShown(id) {
                         return ! this.filtering || Object.values(this.lessons).some(lesson => lesson.sections.includes(id) && this.matches(lesson));
                     },
                     count(kind) { return Object.values(this.lessons).filter(lesson => kind === 'all' || (kind === 'mapped') === lesson.mapped).length; },
                     shownIn(mode) { return Object.values(this.lessons).filter(lesson => (mode === 'sections') === (lesson.sections.length > 0) && this.matches(lesson)).length; },
                     // The Content tab on the same page owns course-template-section-{id}, so the map reveals its own nodes.
                     revealSection(sectionId) {
                         const target = document.getElementById(`course-learning-section-${sectionId}`);
                         if (! target) return;
                         for (let node = target; node; node = node.parentElement?.closest('.course-template-outline-section')) {
                             Alpine.$data(node).setExpanded(true);
                         }
                         this.$nextTick(() => {
                             target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                             target.classList.add('is-flash');
                             window.setTimeout(() => target.classList.remove('is-flash'), 1200);
                         });
                     },
                     toggleAll() {
                         const folds = [...this.$root.querySelectorAll('.course-template-outline-section')];
                         const expand = folds.some(fold => ! Alpine.$data(fold).expanded);
                         folds.forEach(fold => Alpine.$data(fold).setExpanded(expand, true));
                     },
                 }">
            <header class="course-template-section-header">
                <h2 id="course-learning-map-title" class="admin-form-section-title">{{ __('lf.LF_course_template_learning_map_title') }}</h2>
                <p>{{ __('lf.LF_course_template_learning_ui_map_help') }}</p>
            </header>

            @if ($hasDirectLessons && $hasSections)
                <div class="course-template-structure-tabs" role="tablist" aria-label="{{ __('lf.LF_course_template_structure_tabs_label') }}">
                    @foreach (['direct', 'sections'] as $mode)
                        <button id="course-learning-{{ $mode }}-tab" type="button" role="tab"
                                aria-controls="course-learning-{{ $mode }}-panel"
                                x-bind:aria-selected="activeStructureTab === '{{ $mode }}'"
                                x-bind:class="{ 'is-active': activeStructureTab === '{{ $mode }}' }"
                                x-on:click="selectStructureTab('{{ $mode }}')">
                            {{ __('lf.LF_course_template_structure_tab_'.$mode) }}
                        </button>
                    @endforeach
                </div>
            @endif
            <p class="course-template-structure-tab-help">
                <span x-show="activeStructureTab === 'direct'">{{ __('lf.LF_course_template_structure_tab_direct_help') }}</span>
                <span x-show="activeStructureTab === 'sections'" x-cloak>{{ __('lf.LF_course_template_structure_tab_sections_help') }}</span>
            </p>

            @foreach (['direct', 'sections'] as $mode)
                <div id="course-learning-{{ $mode }}-panel" class="course-template-structure-panel" role="tabpanel"
                     @if ($hasDirectLessons && $hasSections) aria-labelledby="course-learning-{{ $mode }}-tab" @else aria-labelledby="course-learning-map-title" @endif
                     x-show="activeStructureTab === '{{ $mode }}'" @if ($mode !== $structureMode) x-cloak @endif>
                    <div class="course-template-section-action-bar course-template-content-toolbar">
                        <div class="course-template-content-count">
                            <span class="course-template-content-heading">{{ __($mode === 'direct' ? 'lf.LF_course_template_content_lessons_title' : 'lf.LF_course_template_content_sections_title') }}</span>
                            @if ($mode === 'sections')
                                <strong>{{ trans_choice('lf.LF_course_template_section_common_count', collect($sections)->count(), ['count' => collect($sections)->count()]) }}</strong>
                            @endif
                            <span @class(['lf-secondary-text' => $mode === 'sections'])>
                                {{ trans_choice('lf.LF_course_template_lesson_common_count', $modeLessonCounts[$mode], ['count' => $modeLessonCounts[$mode]]) }}
                            </span>
                        </div>
                        @if (($mode === 'direct' && $hasDirectLessons) || ($mode === 'sections' && $hasSections))
                            <div class="course-template-content-tools">
                                <label class="course-template-content-search">
                                    <x-backend-icon name="search" class="ui-icon" />
                                    <span class="ui-sr-only">{{ __('lf.LF_course_template_learning_ui_search') }}</span>
                                    <input type="search" x-model="q" placeholder="{{ __('lf.LF_course_template_learning_ui_search') }}">
                                </label>
                                @if ($mode === 'sections')
                                    <button type="button" class="admin-link-button admin-text-action ui-row-action course-template-content-fold-all"
                                            x-on:click="toggleAll()"
                                            aria-label="{{ __('lf.LF_course_template_content_toggle_all') }}"
                                            data-tooltip="{{ __('lf.LF_course_template_content_toggle_all') }}">
                                        <x-backend-icon name="chevrons-up-down" class="ui-icon" />
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($mode === 'direct')
                        {{-- 15 lessons per page; the filter and search still cover every lesson. --}}
                        <div x-data="lfLessonPager(15, @js('lf-course-template-'.$template->id.'-learning-direct-page'), { summary: @js(__('lf.LF_course_template_lesson_pager_summary')) })">
                            <div class="course-template-lesson-list" x-ref="list">
                                @forelse ($directRows as $row)
                                    @include('course-templates.partials.learning-lesson-card', ['position' => $loop->iteration])
                                @empty
                                    <p class="ui-learning-no-result">{{ __('lf.LF_course_template_learning_no_lessons') }}</p>
                                @endforelse
                            </div>
                            <x-ui-lesson-pager-foot />
                        </div>
                    @else
                        <div class="course-template-outline-sections">
                            @forelse ($sectionsByParent->get('root', collect()) as $section)
                                @include('course-templates.partials.learning-section-node', ['depth' => 0])
                            @empty
                                <p class="ui-learning-no-result">{{ __('lf.LF_course_template_learning_no_lessons') }}</p>
                            @endforelse
                        </div>
                    @endif
                    <p class="course-template-content-no-results" x-show="filtering && shownIn('{{ $mode }}') === 0" x-cloak role="status">
                        {{ __('lf.LF_course_template_learning_ui_no_result') }}
                    </p>
                </div>
            @endforeach

            {{-- Attach progress doubles as the filter: All / Not attached / Attached with their counts. --}}
            <section class="course-template-content-side-card ui-learning-progress-card" aria-labelledby="course-learning-progress-title">
                <h3 id="course-learning-progress-title">{{ __('lf.LF_course_template_learning_ui_progress_title') }}</h3>
                <div class="ui-learning-progress" aria-hidden="true"><span style="width: {{ $percent($mappedLessonCount, $lessonCount) }}%"></span></div>
                <p class="ui-caption">{{ __('lf.LF_course_template_learning_ui_progress_help', ['mapped' => $mappedLessonCount, 'total' => $lessonCount]) }}</p>
                <div class="ui-learning-filter" role="radiogroup" aria-label="{{ __('lf.LF_course_template_learning_ui_filter_label') }}">
                    @foreach (['all', 'unmapped', 'mapped'] as $filter)
                        <button type="button" role="radio" class="cursor-interaction" data-learning-filter="{{ $filter }}"
                                x-on:click="filter = '{{ $filter }}'"
                                x-bind:aria-checked="(filter === '{{ $filter }}').toString()"
                                x-bind:class="{ 'is-active': filter === '{{ $filter }}' }">
                            <span class="ui-learning-filter__dot ui-learning-filter__dot--{{ $filter }}" aria-hidden="true"></span>
                            {{ __('lf.LF_course_template_learning_ui_filter_'.$filter) }}
                            <strong x-text="count('{{ $filter }}')">{{ $filter === 'all' ? $lessonCount : ($filter === 'mapped' ? $mappedLessonCount : $lessonCount - $mappedLessonCount) }}</strong>
                        </button>
                    @endforeach
                </div>
            </section>

            @if ($hasSections)
                <nav class="course-template-content-side-card course-template-content-toc" aria-labelledby="course-learning-toc-title"
                     x-show="activeStructureTab === 'sections'" @if ($structureMode !== 'sections') x-cloak @endif>
                    <h3 id="course-learning-toc-title">{{ __('lf.LF_course_template_content_toc_title') }}</h3>
                    <ol>
                        @foreach ($sectionOutline as $tocRow)
                            <li>
                                <button type="button" style="--toc-depth: {{ $tocRow['depth'] }}" x-on:click="revealSection(@js($tocRow['section']->id))">
                                    <span class="course-template-content-toc-number">{{ $tocRow['number'] }}</span>
                                    <span @class(['course-template-content-toc-title', 'is-root' => $tocRow['depth'] === 0])>{{ $tocRow['section']->title }}</span>
                                    @if ($tocRow['lessons'] === 0)
                                        <span class="course-template-content-toc-count is-empty">{{ __('lf.LF_course_template_content_toc_empty') }}</span>
                                    @else
                                        <span @class(['course-template-content-toc-count', 'ui-learning-toc-unmapped' => $tocRow['unmapped'] > 0])
                                              title="{{ __('lf.LF_course_template_learning_ui_toc_count_title', ['mapped' => $tocRow['lessons'] - $tocRow['unmapped'], 'total' => $tocRow['lessons']]) }}">{{ $tocRow['lessons'] - $tocRow['unmapped'] }}/{{ $tocRow['lessons'] }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ol>
                </nav>
            @endif
        </section>
    @endif
</div>
