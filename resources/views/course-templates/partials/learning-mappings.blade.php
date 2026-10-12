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

    // Lessons in course order: lessons outside a section first, then every section depth-first.
    $sectionsByParent = collect($sections)->groupBy(fn ($section) => (int) ($section->parent_section_id ?? 0));
    $rows = [];
    $lessonCount = 0;
    $mappedLessonCount = 0;
    $addLesson = function (object $lesson) use (&$rows, &$lessonCount, &$mappedLessonCount, &$shownIntentIds, $activitiesByLesson, $intentsBySource, $activitiesById): void {
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
        $lessonCount++;
        $mappedLessonCount += $skills->isNotEmpty() ? 1 : 0;
        $rows[] = ['type' => 'lesson', 'number' => $lessonCount, 'lesson' => $lesson, 'activities' => $activities, 'skills' => $skills];
    };
    $walkSections = function (int $parentId, int $depth) use (&$walkSections, &$rows, $sectionsByParent, $lessonsBySection, $addLesson): void {
        foreach ($sectionsByParent->get($parentId, []) as $section) {
            $rows[] = ['type' => 'section', 'title' => $section->title, 'depth' => $depth];
            foreach (collect($lessonsBySection[$section->id] ?? [])->sortBy('sort_order') as $lesson) {
                $addLesson($lesson);
            }
            $walkSections((int) $section->id, $depth + 1);
        }
    };
    if (collect($directLessons)->isNotEmpty() && collect($sections)->isNotEmpty()) {
        $rows[] = ['type' => 'section', 'title' => __('lf.LF_course_template_learning_direct_lessons'), 'depth' => 0];
    }
    foreach (collect($directLessons)->sortBy('sort_order') as $lesson) {
        $addLesson($lesson);
    }
    $walkSections(0, 0);
    // Links whose Lesson or Activity left the Course tree stay visible so they can still be removed.
    $orphanSkills = $intents->reject(fn ($intent) => in_array((int) $intent->id, $shownIntentIds, true))->values();

    $upgrade = $learningMappingState['upgrade'] ?? null;
    $roleLabels = [
        'practices' => __('lf.LF_course_template_learning_role_practices'),
        'assesses' => __('lf.LF_course_template_learning_role_assesses'),
    ];
    $versionLabel = fn ($version) => __('lf.LF_course_template_learning_framework_select_option', [
        'framework' => $version->framework_name,
        'version' => $version->title_snapshot ?: $version->version_code,
    ]);
@endphp

<div id="course-learning" class="admin-card admin-form-card admin-form-surface course-learning">
    <section class="course-learning__framework" aria-labelledby="course-learning-framework-title">
        <div class="course-learning__framework-main">
            <h2 id="course-learning-framework-title" class="course-learning__eyebrow">{{ __('lf.LF_course_template_learning_framework_title') }}</h2>
            @if ($selection)
                <p class="course-learning__framework-name" data-course-learning-selection>
                    {{ __('lf.LF_course_template_learning_framework_version', ['framework' => $selection->framework_name, 'version' => $selection->title_snapshot ?: $selection->version_code]) }}
                </p>
                <p class="admin-form-section-help">{{ __('lf.LF_course_template_learning_framework_help') }}</p>
            @elseif ($versions->isEmpty())
                <p class="admin-form-section-help">{{ __('lf.LF_course_template_learning_framework_empty') }}</p>
            @else
                <p class="admin-form-section-help">{{ __('lf.LF_course_template_learning_framework_none_help') }}</p>
            @endif
        </div>
        <div class="course-learning__framework-actions">
            @if ($selection)
                <a class="btn btn-secondary" href="{{ route('admin.learning-frameworks.show', $selection->framework_id) }}">{{ __('lf.LF_course_template_learning_framework_view') }}</a>
            @elseif ($versions->isEmpty())
                <a class="btn btn-primary" href="{{ route('admin.learning-frameworks.index') }}">{{ __('lf.LF_course_template_learning_framework_manage') }}</a>
            @endif
        </div>
    </section>

    @if ($selection && $upgrade)
        <section class="course-learning__upgrade" aria-labelledby="course-learning-upgrade-title" data-course-learning-upgrade="{{ $upgrade['version']->framework_version_id }}">
            <div class="course-learning__upgrade-text">
                <h3 id="course-learning-upgrade-title" class="course-learning__upgrade-title">
                    {{ __('lf.LF_course_template_learning_upgrade_title', ['number' => $upgrade['version']->version_number, 'version' => $upgrade['version']->title_snapshot ?: $upgrade['version']->version_code]) }}
                </h3>
                @if ($intents->isNotEmpty())
                    <p>{{ trans_choice('lf.LF_course_template_learning_upgrade_kept', $upgrade['kept']->count(), ['count' => $upgrade['kept']->count()]) }}</p>
                @endif
                @if ($upgrade['removed']->isNotEmpty())
                    <p data-course-learning-upgrade-removed>{{ __('lf.LF_course_template_learning_upgrade_removed', ['count' => $upgrade['removed']->count(), 'names' => $upgrade['removed']->map(fn ($intent) => $intent->name_snapshot)->filter()->unique()->implode(', ')]) }}</p>
                @endif
                @if ($upgrade['ai_blocked'])
                    <p>{{ __('lf.LF_course_template_learning_error_upgrade_ai') }}</p>
                @endif
            </div>
            @unless ($upgrade['ai_blocked'])
                <form method="POST" action="{{ route($routePrefix.'.learning-framework.upgrade', $template->id) }}"
                      @if ($upgrade['removed']->isNotEmpty())
                          data-lf-confirm="{{ __('lf.LF_course_template_learning_upgrade_confirm', ['count' => $upgrade['removed']->count()]) }}"
                          data-lf-confirm-label="{{ __('lf.LF_course_template_learning_upgrade_submit', ['number' => $upgrade['version']->version_number]) }}"
                      @endif>
                    @csrf
                    <input type="hidden" name="framework_version_id" value="{{ $upgrade['version']->framework_version_id }}">
                    <input type="hidden" name="working_revision" value="{{ $template->working_revision }}">
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_course_template_learning_upgrade_submit', ['number' => $upgrade['version']->version_number]) }}</button>
                </form>
            @endunless
        </section>
    @endif

    @if ($versions->isNotEmpty() && (! $selection || $intents->isEmpty()))
        @if ($selection)
            <details class="course-learning__change">
                <summary class="course-learning__change-summary">{{ __('lf.LF_course_template_learning_framework_change') }}</summary>
                @include('course-templates.partials.learning-framework-select')
            </details>
        @else
            @include('course-templates.partials.learning-framework-select')
        @endif
    @elseif ($selection && ! $upgrade)
        <p class="lf-form-help course-learning__change-locked">{{ __('lf.LF_course_template_learning_framework_change_locked') }}</p>
    @endif

    @if ($selection)
        <section class="course-learning__map" aria-labelledby="course-learning-map-title">
            <header class="course-learning__map-header">
                <h3 id="course-learning-map-title" class="admin-form-section-title">{{ __('lf.LF_course_template_learning_map_title') }}</h3>
                @if ($lessonCount > 0)
                    <span class="course-learning__count">{{ __('lf.LF_course_template_learning_map_count', ['mapped' => $mappedLessonCount, 'total' => $lessonCount]) }}</span>
                @endif
            </header>

            @if ($lessonCount === 0)
                <p class="admin-form-empty-state">{{ __('lf.LF_course_template_learning_no_lessons') }}</p>
            @else
                <div class="admin-table-wrap course-learning__table-wrap">
                    <table class="table course-learning__table">
                        <thead>
                            <tr>
                                <th scope="col" class="admin-table-sequence">{{ __('lf.LF_course_template_learning_col_no') }}</th>
                                <th scope="col">{{ __('lf.LF_course_template_learning_col_lesson') }}</th>
                                <th scope="col">{{ __('lf.LF_course_template_learning_col_skills') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @if ($row['type'] === 'section')
                                    <tr class="course-learning__section-row">
                                        <th scope="colgroup" colspan="3" class="course-learning__section-cell" data-depth="{{ min($row['depth'], 3) }}">{{ $row['title'] }}</th>
                                    </tr>
                                @else
                                    @php
                                        $lesson = $row['lesson'];
                                        $lessonNodeIds = $row['skills']->where('source_type', 'course_template_lesson')->where('mapping_role', 'teaches')->pluck('learning_node_id')->map(fn ($id) => (int) $id);
                                        $availableNodes = $nodes->reject(fn ($node) => $lessonNodeIds->contains((int) $node->id));
                                        $pickerId = 'course-learning-picker-'.$lesson->id;
                                    @endphp
                                    <tr data-course-learning-lesson="{{ $lesson->id }}">
                                        <td class="admin-table-sequence">{{ $row['number'] }}</td>
                                        <td class="course-learning__lesson-cell">
                                            <span class="course-learning__lesson-title">{{ $lesson->title }}</span>
                                            <span class="course-learning__meta">{{ trans_choice('lf.LF_course_template_learning_lesson_activities', $row['activities']->count(), ['count' => $row['activities']->count()]) }}</span>
                                        </td>
                                        <td class="course-learning__skills-cell">
                                            <ul class="course-learning__skills">
                                                @forelse ($row['skills'] as $skill)
                                                    @include('course-templates.partials.learning-skill-chip')
                                                @empty
                                                    <li class="course-learning__not-mapped">{{ __('lf.LF_course_template_learning_not_mapped') }}</li>
                                                @endforelse
                                            </ul>

                                            <details class="course-learning__picker">
                                                <summary class="btn btn-secondary course-learning__picker-toggle">
                                                    <span aria-hidden="true">+</span> {{ __('lf.LF_course_template_learning_add') }}
                                                </summary>
                                                @if ($availableNodes->isEmpty())
                                                    <p class="lf-form-help course-learning__picker-panel">{{ __('lf.LF_course_template_learning_all_mapped') }}</p>
                                                @else
                                                    <form method="POST"
                                                          action="{{ route($routePrefix.'.learning-mappings.store', $template->id) }}"
                                                          class="course-learning__picker-panel"
                                                          aria-label="{{ __('lf.LF_course_template_learning_picker_label', ['lesson' => $lesson->title]) }}">
                                                        @csrf
                                                        <input type="hidden" name="mapping_role" value="teaches">
                                                        @if ($row['activities']->isEmpty())
                                                            <input type="hidden" name="source" value="course_template_lesson:{{ $lesson->id }}">
                                                        @else
                                                            <div class="lf-form-group">
                                                                <label class="lf-form-label" for="{{ $pickerId }}-target">{{ __('lf.LF_course_template_learning_target_label') }}</label>
                                                                <select id="{{ $pickerId }}-target" name="source" class="lf-form-control">
                                                                    <option value="course_template_lesson:{{ $lesson->id }}">{{ __('lf.LF_course_template_learning_target_lesson') }}</option>
                                                                    @foreach ($row['activities'] as $activity)
                                                                        <option value="course_template_activity:{{ $activity->id }}">{{ __('lf.LF_course_template_learning_target_activity', ['title' => $activity->title]) }}</option>
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                        @endif
                                                        <fieldset class="course-learning__nodes">
                                                            <legend class="lf-form-label">{{ __('lf.LF_course_template_learning_nodes_legend') }}</legend>
                                                            @foreach ($availableNodes as $node)
                                                                <label class="course-learning__node">
                                                                    <input type="checkbox" name="learning_node_ids[]" value="{{ $node->id }}">
                                                                    <span>
                                                                        <span class="course-learning__node-name">{{ $node->name_snapshot }}</span>
                                                                        <span class="course-learning__meta">{{ $node->code_snapshot }}</span>
                                                                    </span>
                                                                </label>
                                                            @endforeach
                                                        </fieldset>
                                                        <div class="course-learning__picker-actions">
                                                            <button type="submit" class="btn btn-primary">{{ __('lf.LF_course_template_learning_submit') }}</button>
                                                        </div>
                                                    </form>
                                                @endif
                                            </details>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                            @if ($orphanSkills->isNotEmpty())
                                <tr class="course-learning__section-row">
                                    <th scope="colgroup" colspan="3" class="course-learning__section-cell">{{ __('lf.LF_course_template_learning_orphans') }}</th>
                                </tr>
                                <tr data-course-learning-orphans>
                                    <td class="admin-table-sequence"></td>
                                    <td class="course-learning__lesson-cell">
                                        <span class="course-learning__meta">{{ __('lf.LF_course_template_learning_orphans_help') }}</span>
                                    </td>
                                    <td class="course-learning__skills-cell">
                                        <ul class="course-learning__skills">
                                            @foreach ($orphanSkills as $skill)
                                                @include('course-templates.partials.learning-skill-chip')
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
