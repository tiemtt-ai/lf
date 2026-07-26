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
        $sectionsByParent = $sections->groupBy(
            fn (object $section): string => (string) ($section->parent_section_id ?? 'root')
        );
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
        <div class="course-template-section-action-bar course-template-content-toolbar"
             aria-label="{{ __('lf.LF_course_template_section_common_actions') }}">
            <strong>{{ trans_choice('lf.LF_course_template_section_common_count', $sections->count(), ['count' => $sections->count()]) }}</strong>
            @if ($hasSections)
                <a href="{{ route($sectionRoutePrefix.'.create', $template->id) }}"
                   class="btn admin-primary-outline-action">
                    {{ __('lf.LF_course_template_section_common_add_action') }}
                </a>
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
                    <a href="{{ route($sectionRoutePrefix.'.create', $template->id) }}"
                       class="btn admin-primary-outline-action">
                        {{ __('lf.LF_course_template_section_common_add_action') }}
                    </a>
                </div>
            @endforelse
        </div>
    </div>
</section>
