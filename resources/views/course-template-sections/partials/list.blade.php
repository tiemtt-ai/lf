<section id="course-template-sections"
         class="admin-card admin-form-card course-template-section-card"
         aria-labelledby="course-template-sections-title"
         x-data="{
             activeStructureTab: @js(
                 $sections->isNotEmpty() && $directLessons->isEmpty()
                     ? 'sections'
                     : 'direct'
             ),
             storageKey: @js(
                 'lf-course-template-'.$template->id.'-structure-tab'
             ),
             init() {
                 const savedTab = localStorage.getItem(this.storageKey);

                 if (['direct', 'sections'].includes(savedTab)) {
                     this.activeStructureTab = savedTab;
                 }
             },
             selectStructureTab(tab) {
                 this.activeStructureTab = tab;
                 localStorage.setItem(this.storageKey, tab);
             }
         }">
    @php
        $hasDirectLessons = $directLessons->isNotEmpty();
        $hasSections = $sections->isNotEmpty();
    @endphp

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

    <div id="course-template-direct-panel"
         class="course-template-structure-panel"
         role="tabpanel"
         aria-labelledby="course-template-direct-tab"
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
         x-show="activeStructureTab === 'sections'"
         x-cloak>
        <div class="course-template-section-action-bar"
             aria-label="{{ __('lf.LF_course_template_section_common_actions') }}">
            <strong>{{ __('lf.LF_course_template_section_common_area_title') }}</strong>
            <a href="{{ route($sectionRoutePrefix.'.create', $template->id) }}"
               class="btn btn-primary">
                {{ __('lf.LF_course_template_section_common_add_action') }}
            </a>
        </div>

        <div class="course-template-outline-sections">
            @forelse ($sections as $section)
                @php
                    $sectionLessons = $lessonsBySection->get(
                        $section->id,
                        collect()
                    );
                @endphp
                <article class="course-template-outline-section">
                    <header class="course-template-outline-section-header">
                        <div>
                            <h3 class="course-template-outline-section-title">
                                {{ $section->title }}
                            </h3>
                            <div class="course-template-outline-section-meta">
                                <span>
                                    {{ __('lf.LF_course_template_section_common_sort_order') }}:
                                    {{ $section->sort_order }}
                                </span>
                                @if ($section->parent_title)
                                    <span>
                                        {{ __('lf.LF_course_template_section_common_parent') }}:
                                        {{ $section->parent_title }}
                                    </span>
                                @endif
                                <span>
                                    {{ trans_choice(
                                        'lf.LF_course_template_lesson_common_count',
                                        $sectionLessons->count(),
                                        ['count' => $sectionLessons->count()]
                                    ) }}
                                </span>
                                <span @class([
                                    'badge',
                                    'badge-success' => $section->status === 'active',
                                    'badge-danger' => $section->status === 'archived',
                                ])>
                                    {{ __('lf.LF_course_template_section_common_'.$section->status) }}
                                </span>
                            </div>
                        </div>
                        <div class="admin-table-actions">
                            <a href="{{ route(
                                $sectionRoutePrefix.'.edit',
                                [$template->id, $section->id]
                            ) }}">
                                {{ __('lf.LF_course_template_section_common_edit') }}
                            </a>
                            <button type="button"
                                    class="admin-link-button"
                                    x-data
                                    x-on:click="$dispatch(
                                        'open-modal',
                                        'delete-course-template-section-{{ $section->id }}'
                                    )">
                                {{ __('lf.LF_common_button_delete') }}
                            </button>
                        </div>
                    </header>

                    @include('course-template-lessons.partials.list', [
                        'lessons' => $sectionLessons,
                        'section' => $section,
                        'lessonRoutePrefix' => $lessonRoutePrefix,
                        'activityRoutePrefix' => $activityRoutePrefix,
                        'panelId' => 'course-template-section-'.$section->id.'-lessons',
                        'panelTitle' => __('lf.LF_course_template_lesson_common_list_title'),
                        'emptyMessage' => __('lf.LF_course_template_lesson_common_empty'),
                    ])

                    <x-modal name="delete-course-template-section-{{ $section->id }}"
                             focusable>
                        <div class="lf-modal-card">
                            <h2>
                                {{ __('lf.LF_course_template_section_common_delete_confirm') }}
                            </h2>
                            <div class="lf-modal-actions">
                                <button type="button"
                                        class="btn"
                                        x-on:click="$dispatch(
                                            'close-modal',
                                            'delete-course-template-section-{{ $section->id }}'
                                        )">
                                    {{ __('lf.LF_course_template_section_common_delete_no') }}
                                </button>
                                <form method="POST"
                                      action="{{ route(
                                          $sectionRoutePrefix.'.destroy',
                                          [$template->id, $section->id]
                                      ) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-primary">
                                        {{ __('lf.LF_course_template_section_common_delete_yes') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </x-modal>
                </article>
            @empty
                <p class="course-template-outline-empty">
                    {{ __('lf.LF_course_template_section_common_empty') }}
                </p>
            @endforelse
        </div>
    </div>
</section>
