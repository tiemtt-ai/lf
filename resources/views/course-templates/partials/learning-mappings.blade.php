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
    <section @class(['course-learning__framework', 'course-learning__framework--selected' => $selection]) aria-labelledby="course-learning-framework-title">
        <div class="course-learning__framework-main">
            <h2 id="course-learning-framework-title" class="course-learning__eyebrow">{{ __('lf.LF_course_template_learning_framework_title') }}</h2>
            @if ($selection && $intents->isNotEmpty())
                <div class="course-learning__switch-help">
                    <span class="lf-form-help">{{ __('lf.LF_course_template_learning_framework_change') }}</span>
                    <x-info-tip :label="__('lf.LF_course_template_learning_switch_details')"
                                :text="__('lf.LF_course_template_learning_framework_switch_help')" />
                </div>
            @endif
            @if ($selection)
                <p class="course-learning__framework-name" data-course-learning-selection>
                    {{ __('lf.LF_course_template_learning_selected_framework', ['name' => $selection->framework_name]) }}
                </p>
                <p class="admin-form-section-help" data-course-learning-selected-version>
                    {{ __('lf.LF_course_template_learning_selected_version', ['version' => $selection->title_snapshot ?: $selection->version_code]) }}
                    <span class="badge badge-success">{{ __('lf.LF_course_template_learning_current_badge') }}</span>
                </p>
                <p class="admin-form-section-help">{{ __('lf.LF_course_template_learning_framework_help') }}</p>
            @elseif ($versions->isEmpty())
                <p class="admin-form-section-help">{{ __('lf.LF_course_template_learning_framework_empty') }}</p>
            @else
                <p class="admin-form-section-help">{{ __('lf.LF_course_template_learning_framework_none_help') }}</p>
            @endif
        </div>
        <div class="course-learning__framework-actions">
            @if ($selection && $template->status === 'draft')
                <form method="POST" action="{{ route($routePrefix.'.learning-framework.detach', $template->id) }}"
                      data-lf-confirm="{{ __('lf.LF_course_template_learning_detach_confirm', ['count' => $intents->count()]) }}"
                      data-lf-confirm-label="{{ __('lf.LF_course_template_learning_detach') }}" data-lf-confirm-tone="danger">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="working_revision" value="{{ $template->working_revision }}">
                    <button type="submit" class="btn btn-secondary"
                            title="{{ __('lf.LF_course_template_learning_detach_tooltip') }}">{{ __('lf.LF_course_template_learning_detach') }}</button>
                </form>
            @endif
            @if ($selection)
                <div x-data>
                    <button type="button" class="btn btn-secondary" @click="$refs.frameworkPreview.showModal()"
                            title="{{ __('lf.LF_course_template_learning_framework_view_tooltip') }}">{{ __('lf.LF_course_template_learning_framework_view') }}</button>
                    <dialog x-ref="frameworkPreview" class="course-learning__preview-dialog" aria-labelledby="course-framework-preview-title"
                            @click="if ($event.target === $el) $el.close()">
                        <header class="course-learning__preview-head">
                            <div>
                                <h2 id="course-framework-preview-title" class="admin-form-section-title">{{ $selection->framework_name }}</h2>
                                <p class="lf-form-help">{{ $selection->title_snapshot ?: $selection->version_code }} · {{ __('lf.LF_course_template_learning_current_badge') }}</p>
                            </div>
                            <button type="button" class="btn btn-secondary" @click="$refs.frameworkPreview.close()">{{ __('lf.LF_common_button_close') }}</button>
                        </header>
                        <div class="course-learning__preview-body">
                            <section class="course-learning__preview-node">
                                <h3 class="admin-form-subsection-title">{{ __('lf.LF_learning_simple_scale_title') }}</h3>
                                @php
                                    $previewLevels = json_decode($selection->mastery_scale_snapshot ?? '{}', true)['levels'] ?? [];
                                @endphp
                                <ol class="lf-framework__levels">
                                    @foreach ($previewLevels as $level)
                                        <li>
                                            <span>{{ \Illuminate\Support\Facades\Lang::has('lf.LF_learning_simple_level_'.$level['key']) ? __('lf.LF_learning_simple_level_'.$level['key']) : $level['key'] }}</span>
                                            <span>{{ (float) $level['threshold'] > 0 ? '≥ '.round((float) $level['threshold'] * 100).'%' : '0%' }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            </section>
                            @forelse ($nodes as $node)
                                <article class="course-learning__preview-node">
                                    <h3 class="admin-form-subsection-title">{{ $node->name_snapshot }}</h3>
                                    <p class="lf-form-help">{{ $node->code_snapshot }}</p>
                                    @if ($node->description_snapshot ?? null)<p>{{ $node->description_snapshot }}</p>@endif
                                </article>
                            @empty
                                <p>{{ __('lf.LF_course_template_learning_preview_no_nodes') }}</p>
                            @endforelse
                        </div>
                    </dialog>
                </div>
            @elseif ($versions->isEmpty())
                <a class="btn btn-primary" href="{{ route('admin.learning-frameworks.index') }}">{{ __('lf.LF_course_template_learning_framework_manage') }}</a>
            @endif
        </div>
    </section>

    @if ($versions->isNotEmpty())
        <section class="course-learning__change">
            <h3 class="admin-form-subsection-title">{{ $selection ? __('lf.LF_course_template_learning_framework_change') : __('lf.LF_course_template_learning_framework_initial_title') }}</h3>
            <p class="admin-form-section-help">{{ __($selection ? 'lf.LF_course_template_learning_select_help' : 'lf.LF_course_template_learning_initial_help') }}</p>
            @include('course-templates.partials.learning-framework-select')
        </section>
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
                        <colgroup><col class="course-learning__half-col"><col class="course-learning__half-col"></colgroup>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('lf.LF_course_template_learning_col_lesson') }}</th>
                                <th scope="col">{{ __('lf.LF_course_template_learning_col_skills') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @if ($row['type'] === 'section')
                                    <tr class="course-learning__section-row">
                                        <th scope="colgroup" colspan="2" class="course-learning__section-cell" data-depth="{{ min($row['depth'], 3) }}">{{ $row['title'] }}</th>
                                    </tr>
                                @else
                                    @php
                                        $lesson = $row['lesson'];
                                        $lessonNodeIds = $row['skills']->where('source_type', 'course_template_lesson')->where('mapping_role', 'teaches')->pluck('learning_node_id')->map(fn ($id) => (int) $id);
                                        $availableNodes = $nodes->reject(fn ($node) => $lessonNodeIds->contains((int) $node->id));
                                        $pickerId = 'course-learning-picker-'.$lesson->id;
                                    @endphp
                                    <tr id="{{ $pickerId }}-row" data-course-learning-lesson="{{ $lesson->id }}">
                                        <td class="course-learning__lesson-cell">
                                            <span class="course-learning__lesson-title">{{ $lesson->title }}</span>
                                            <span class="course-learning__meta">{{ trans_choice('lf.LF_course_template_learning_lesson_activities', $row['activities']->count(), ['count' => $row['activities']->count()]) }}</span>
                                        </td>
                                        <td class="course-learning__skills-cell">
                                            <ul class="course-learning__skills">
                                                @forelse ($row['skills'] as $skill)
                                                    @include('course-templates.partials.learning-skill-chip', ['mappingPicker' => $pickerId])
                                                @empty
                                                    <li class="course-learning__not-mapped">{{ __('lf.LF_course_template_learning_not_mapped') }}</li>
                                                @endforelse
                                            </ul>

                                            <div x-data class="course-learning__mapping-actions">
                                                <button type="button" class="btn btn-secondary" @click="$refs.mappingDialog.showModal()">
                                                    <span aria-hidden="true">+</span> {{ __('lf.LF_course_template_learning_add') }}
                                                </button>
                                                @if (session('mapping_picker') === $pickerId && session('mapping_feedback'))
                                                    <p class="course-learning__picker-feedback" role="status" x-data="{ visible: true }" x-init="setTimeout(() => visible = false, 3000)" x-show="visible" x-transition.opacity><span class="course-learning__success-icon" aria-hidden="true">✓</span><span>{{ session('mapping_feedback') }}</span></p>
                                                @endif
                                                <dialog id="{{ $pickerId }}" x-ref="mappingDialog" class="course-learning__preview-dialog course-learning__mapping-dialog"
                                                        aria-labelledby="{{ $pickerId }}-title" @click="if ($event.target === $el) $el.close()"
                                                        @if (old('mapping_picker') === $pickerId && $errors->any()) x-init="$nextTick(() => $el.showModal())" @endif>
                                                    <header class="course-learning__preview-head">
                                                        <div>
                                                            <h2 id="{{ $pickerId }}-title" class="admin-form-section-title">{{ __('lf.LF_course_template_learning_add') }}</h2>
                                                            <p class="course-learning__mapping-lesson">{{ $lesson->title }}</p>
                                                        </div>
                                                        <button type="button" class="btn btn-secondary" @click="$refs.mappingDialog.close()">{{ __('lf.LF_common_button_close') }}</button>
                                                    </header>
                                                @if (old('mapping_picker') === $pickerId && $errors->any())
                                                    <div class="course-learning__picker-feedback is-error" role="alert">
                                                        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                                                    </div>
                                                @endif
                                                @if ($availableNodes->isEmpty())
                                                    <p class="lf-form-help course-learning__picker-panel">{{ __('lf.LF_course_template_learning_all_mapped') }}</p>
                                                @else
                                                    <form method="POST"
                                                          action="{{ route($routePrefix.'.learning-mappings.store', $template->id) }}"
                                                          class="course-learning__picker-panel" data-lf-mapping-async
                                                          data-error-message="{{ __('lf.LF_course_template_learning_attach_network_error') }}"
                                                          data-refresh-error="{{ __('lf.LF_course_template_learning_attach_refresh_error') }}"
                                                          x-data="{ chosen: @js(old('mapping_picker') === $pickerId ? old('learning_node_ids', []) : []), invalid: false, saving: false }"
                                                          @mapping-saving="saving = $event.detail"
                                                          @submit="if (!chosen.length) { $event.preventDefault(); invalid = true; }"
                                                          aria-label="{{ __('lf.LF_course_template_learning_picker_label', ['lesson' => $lesson->title]) }}">
                                                        @csrf
                                                        <p class="course-learning__picker-feedback is-error" data-lf-mapping-error role="alert" hidden></p>
                                                        <input type="hidden" name="mapping_picker" value="{{ $pickerId }}">
                                                        <p class="course-learning__picker-feedback is-error" role="alert" x-show="invalid && !chosen.length" x-cloak>{{ __('lf.LF_course_template_learning_error_nodes_required') }}</p>
                                                        <input type="hidden" name="mapping_role" value="teaches">
                                                        <input type="hidden" name="source" value="course_template_lesson:{{ $lesson->id }}">
                                                        <fieldset class="course-learning__nodes">
                                                            <legend class="lf-form-label">{{ __('lf.LF_course_template_learning_nodes_legend') }}</legend>
                                                            <div class="course-learning__nodes-grid">
                                                            @foreach ($availableNodes as $node)
                                                                <label class="course-learning__node">
                                                                    <input type="checkbox" name="learning_node_ids[]" value="{{ $node->id }}" x-model="chosen">
                                                                    <span>
                                                                        <span class="course-learning__node-name">{{ $node->name_snapshot }}</span>
                                                                        <span class="course-learning__meta">{{ $node->code_snapshot }}</span>
                                                                    </span>
                                                                </label>
                                                            @endforeach
                                                            </div>
                                                        </fieldset>
                                                        <div class="course-learning__picker-actions">
                                                            <span class="lf-form-help" x-text="@js(__('lf.LF_course_template_learning_picker_count')).replace(':count', chosen.length)"></span>
                                                            <button type="button" class="btn btn-secondary" @click="$el.closest('form').reset(); chosen = []; invalid = false; $el.closest('dialog').close()">{{ __('lf.LF_common_button_cancel') }}</button>
                                                            <button type="submit" class="btn btn-primary" :disabled="chosen.length === 0 || saving" data-loading-label="{{ __('lf.LF_course_template_learning_attaching') }}">{{ __('lf.LF_course_template_learning_submit') }}</button>
                                                        </div>
                                                    </form>
                                                @endif
                                                </dialog>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                            @if ($orphanSkills->isNotEmpty())
                                <tr class="course-learning__section-row">
                                    <th scope="colgroup" colspan="2" class="course-learning__section-cell">{{ __('lf.LF_course_template_learning_orphans') }}</th>
                                </tr>
                                <tr data-course-learning-orphans>
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
