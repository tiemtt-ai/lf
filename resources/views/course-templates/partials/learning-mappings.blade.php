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

    // Lessons in course order, grouped by their section path: lessons outside a section first, then every section depth-first.
    $sectionsByParent = collect($sections)->groupBy(fn ($section) => (int) ($section->parent_section_id ?? 0));
    $groups = [];
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

        return ['number' => $lessonCount, 'lesson' => $lesson, 'activities' => $activities, 'skills' => $skills];
    };
    $walkSections = function (int $parentId, array $path) use (&$walkSections, &$groups, $sectionsByParent, $lessonsBySection, $makeLesson): void {
        foreach ($sectionsByParent->get($parentId, []) as $section) {
            $sectionPath = [...$path, $section->title];
            $groups[] = [
                'id' => 'section-'.$section->id,
                'path' => $sectionPath,
                'lessons' => collect($lessonsBySection[$section->id] ?? [])->sortBy('sort_order')->map($makeLesson)->values()->all(),
            ];
            $walkSections((int) $section->id, $sectionPath);
        }
    };
    if (collect($directLessons)->isNotEmpty()) {
        $groups[] = [
            'id' => 'direct',
            'path' => collect($sections)->isNotEmpty() ? [__('lf.LF_course_template_learning_direct_lessons')] : [],
            'lessons' => collect($directLessons)->sortBy('sort_order')->map($makeLesson)->values()->all(),
        ];
    }
    $walkSections(0, []);
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
    // What the map's tabs, search and group toggles work on: client-side only, over the rows rendered below.
    $lessonIndex = collect($groups)->flatMap(fn ($group) => collect($group['lessons'])->map(fn ($row) => [
        'group' => $group['id'],
        'mapped' => $row['skills']->isNotEmpty(),
        'text' => $lower($row['lesson']->title.' '.$row['skills']->map(fn ($skill) => $skill->name_snapshot.' '.$skill->code_snapshot)->implode(' ')),
    ]))->values();
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
                    @if ($versions->isNotEmpty())
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

                    @if ($versions->isNotEmpty())
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
        <section class="mk-section ui-learning-map" aria-labelledby="course-learning-map-title"
                 x-data="{
                     filter: 'all', q: '', collapsed: [],
                     lessons: @js($lessonIndex),
                     groupIds: @js(collect($groups)->pluck('id')->values()),
                     matches(lesson) {
                         if (this.filter !== 'all' && (this.filter === 'mapped') !== lesson.mapped) return false;
                         return ! this.q || lesson.text.includes(this.q.trim().toLowerCase());
                     },
                     shown(index) { return this.matches(this.lessons[index]) && ! this.collapsed.includes(this.lessons[index].group); },
                     groupVisible(id) { return (this.filter === 'all' && ! this.q) || this.lessons.some(lesson => lesson.group === id && this.matches(lesson)); },
                     toggle(id) { this.collapsed = this.collapsed.includes(id) ? this.collapsed.filter(item => item !== id) : [...this.collapsed, id]; },
                     get allCollapsed() { return this.groupIds.length > 0 && this.collapsed.length === this.groupIds.length; },
                     toggleAll() { this.collapsed = this.allCollapsed ? [] : [...this.groupIds]; },
                     count(kind) { return this.lessons.filter(lesson => kind === 'all' || (kind === 'mapped') === lesson.mapped).length; },
                     get shownCount() { return this.lessons.filter(lesson => this.matches(lesson)).length; },
                 }">
            <div class="ui-summary-head">
                <div>
                    <h3 id="course-learning-map-title">{{ __('lf.LF_course_template_learning_map_title') }}</h3>
                    <p class="ui-caption">{{ __('lf.LF_course_template_learning_ui_map_help') }}</p>
                </div>
            </div>

            @if ($lessonCount === 0)
                <p class="ui-learning-no-result">{{ __('lf.LF_course_template_learning_no_lessons') }}</p>
            @else
                <div class="ui-tabs ui-learning-tabs" role="tablist" aria-label="{{ __('lf.LF_course_template_learning_ui_filter_label') }}">
                    @foreach (['all', 'unmapped', 'mapped'] as $filter)
                        <button type="button" role="tab" class="ui-tabs__tab cursor-interaction" x-on:click="filter = '{{ $filter }}'"
                                x-bind:aria-selected="(filter === '{{ $filter }}').toString()" aria-controls="course-learning-map-panel">
                            {{ __('lf.LF_course_template_learning_ui_filter_'.$filter) }}
                            <span class="ui-learning-count" x-text="count('{{ $filter }}')"></span>
                        </button>
                    @endforeach
                </div>
                <div id="course-learning-map-panel" role="tabpanel">
                    <div class="mk-tablebar ui-learning-toolbar">
                        <label class="ui-search ui-learning-search">
                            <x-backend-icon name="search" class="ui-icon" />
                            <input type="search" x-model="q" placeholder="{{ __('lf.LF_course_template_learning_ui_search') }}" aria-label="{{ __('lf.LF_course_template_learning_ui_search') }}">
                        </label>
                        @if (collect($groups)->contains(fn ($group) => $group['path'] !== []))
                            <button type="button" class="ui-button cursor-interaction" x-on:click="toggleAll()" x-bind:aria-pressed="allCollapsed.toString()">
                                <x-backend-icon name="chevrons-up-down" class="ui-icon" />
                                <span x-text="allCollapsed ? @js(__('lf.LF_course_template_learning_ui_expand_all')) : @js(__('lf.LF_course_template_learning_ui_collapse_all'))"></span>
                            </button>
                        @endif
                        <span class="ui-caption ui-learning-toolbar__count" aria-live="polite"
                              x-text="@js(__('lf.LF_course_template_learning_ui_result_count', ['shown' => '__S__', 'total' => '__T__'])).replace('__S__', shownCount).replace('__T__', lessons.length)"></span>
                    </div>

                    <div class="mk-table-scroll">
                        <table class="ui-learning-table" aria-labelledby="course-learning-map-title">
                            <thead>
                                <tr>
                                    <th scope="col" class="ui-learning-table__no">{{ __('lf.LF_course_template_learning_col_no') }}</th>
                                    <th scope="col" class="ui-learning-table__lesson">{{ __('lf.LF_course_template_learning_col_lesson') }}</th>
                                    <th scope="col">{{ __('lf.LF_course_template_learning_col_skills') }}</th>
                                    <th scope="col" class="ui-learning-table__action">{{ __('lf.table_actions') }}</th>
                                </tr>
                            </thead>
                            @php
                                $lessonPosition = 0;
                            @endphp
                            @foreach ($groups as $group)
                                <tbody>
                                    @if ($group['path'] !== [])
                                        @php
                                            $groupMapped = collect($group['lessons'])->filter(fn ($row) => $row['skills']->isNotEmpty())->count();
                                        @endphp
                                        <tr class="ui-learning-group" x-show="groupVisible(@js($group['id']))">
                                            <td colspan="4">
                                                <button type="button" class="ui-learning-group__toggle cursor-interaction" x-on:click="toggle(@js($group['id']))"
                                                        x-bind:aria-expanded="(! collapsed.includes(@js($group['id']))).toString()">
                                                    <x-backend-icon name="chevron-down" class="ui-icon" />
                                                    <span class="ui-learning-group__path">
                                                        @foreach ($group['path'] as $part)
                                                            @if (! $loop->last)
                                                                <span class="ui-learning-group__parent">{{ $part }}</span><span class="ui-learning-group__sep" aria-hidden="true">›</span>
                                                            @else
                                                                <span>{{ $part }}</span>
                                                            @endif
                                                        @endforeach
                                                    </span>
                                                    <span class="ui-learning-group__meta">
                                                        {{ count($group['lessons']) > 0
                                                            ? __('lf.LF_course_template_learning_ui_group_meta', ['lessons' => count($group['lessons']), 'mapped' => $groupMapped])
                                                            : __('lf.LF_course_template_learning_ui_group_no_lessons') }}
                                                    </span>
                                                </button>
                                            </td>
                                        </tr>
                                    @endif
                                    @if ($group['lessons'] === [] && $group['path'] !== [])
                                        <tr class="ui-learning-empty-row" x-show="filter === 'all' && ! q && ! collapsed.includes(@js($group['id']))">
                                            <td colspan="4">{{ __('lf.LF_course_template_learning_ui_group_empty') }}</td>
                                        </tr>
                                    @endif
                                    @foreach ($group['lessons'] as $row)
                                        @php
                                            $lesson = $row['lesson'];
                                            $lessonNodeIds = $row['skills']->where('source_type', 'course_template_lesson')->where('mapping_role', 'teaches')->pluck('learning_node_id')->map(fn ($id) => (int) $id);
                                            $pickerId = 'course-learning-picker-'.$lesson->id;
                                            $rowIndex = $lessonPosition++;
                                        @endphp
                                        <tr id="{{ $pickerId }}-row" class="ui-learning-lesson" data-course-learning-lesson="{{ $lesson->id }}" x-show="shown({{ $rowIndex }})">
                                            <td class="ui-learning-table__no">{{ $row['number'] }}</td>
                                            <td class="ui-learning-table__lesson">
                                                <span class="ui-learning-lesson__title">{{ $lesson->title }}</span>
                                                <span class="ui-caption ui-learning-block">{{ trans_choice('lf.LF_course_template_learning_lesson_activities', $row['activities']->count(), ['count' => $row['activities']->count()]) }}</span>
                                            </td>
                                            <td class="ui-learning-table__skills">
                                                @if ($row['skills']->isEmpty())
                                                    <span class="ui-learning-unmapped">{{ __('lf.LF_course_template_learning_not_mapped') }}</span>
                                                @else
                                                    <ul class="ui-learning-chips">
                                                        @foreach ($row['skills'] as $skill)
                                                            @include('course-templates.partials.learning-skill-chip', ['mappingPicker' => $pickerId])
                                                        @endforeach
                                                    </ul>
                                                @endif
                                                @if (session('mapping_picker') === $pickerId && session('mapping_feedback'))
                                                    <p class="ui-learning-feedback" role="status" x-data="{ visible: true }" x-init="setTimeout(() => visible = false, 3000)" x-show="visible" x-transition.opacity>
                                                        <x-backend-icon name="check" class="ui-icon" /><span>{{ session('mapping_feedback') }}</span>
                                                    </p>
                                                @endif
                                            </td>
                                            <td class="ui-learning-table__action" x-data="{ open() { $refs.mappingDialog.showModal(); } }">
                                                <button type="button" class="ui-button ui-button--outline ui-button--sm cursor-interaction" x-on:click="open()"
                                                        aria-label="{{ __('lf.LF_course_template_learning_picker_label', ['lesson' => $lesson->title]) }}">
                                                    <x-backend-icon name="plus" class="ui-icon" />{{ __('lf.LF_course_template_learning_add') }}
                                                </button>
                                                @include('course-templates.partials.learning-node-picker')
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            @endforeach
                            <tbody>
                                <tr x-show="shownCount === 0" x-cloak>
                                    <td colspan="4" class="ui-learning-no-result">{{ __('lf.LF_course_template_learning_ui_no_result') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>
    @endif
</div>
