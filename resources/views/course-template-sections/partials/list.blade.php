<section id="course-template-sections"
         class="admin-card admin-form-card course-template-section-card"
         aria-labelledby="course-template-sections-title">
    <header class="course-template-section-header">
        <h2 id="course-template-sections-title" class="admin-form-section-title">
            {{ __('lf.LF_course_template_section_common_structure_title') }}
        </h2>
        <p>{{ __('lf.LF_course_template_section_common_structure_help') }}</p>
    </header>

    <div class="course-template-section-action-bar"
         aria-label="{{ __('lf.LF_course_template_section_common_actions') }}">
        <strong>{{ __('lf.LF_course_template_section_common_actions') }}</strong>
        <a href="{{ route($sectionRoutePrefix.'.create', $template->id) }}"
           class="btn btn-primary">
            {{ __('lf.LF_course_template_section_common_add_action') }}
        </a>
    </div>

    <div class="course-template-section-list">
        <h3 class="course-template-section-list-title">
            {{ __('lf.LF_course_template_section_common_list_title') }}
        </h3>

        <div class="admin-table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>{{ __('lf.LF_course_template_section_common_sort_order') }}</th>
                    <th>{{ __('lf.LF_course_template_section_common_name') }}</th>
                    <th>{{ __('lf.LF_course_template_section_common_parent') }}</th>
                    <th>{{ __('lf.LF_course_template_section_common_total_lessons') }}</th>
                    <th>{{ __('lf.LF_course_template_section_common_status') }}</th>
                    <th>{{ __('lf.LF_common_label_common_action') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($sections as $section)
                    @php
                        $sectionLessons = $lessonsBySection->get(
                            $section->id,
                            collect()
                        );
                    @endphp
                    <tr>
                        <td>{{ $section->sort_order }}</td>
                        <td>{{ $section->title }}</td>
                        <td>{{ $section->parent_title ?? '—' }}</td>
                        <td>{{ $sectionLessons->count() }}</td>
                        <td>
                            <span @class([
                                'badge',
                                'badge-success' => $section->status === 'active',
                                'badge-danger' => $section->status === 'archived',
                            ])>
                                {{ __('lf.LF_course_template_section_common_'.$section->status) }}
                            </span>
                        </td>
                        <td>
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
                        </td>
                    </tr>
                    <tr class="course-template-lesson-row">
                        <td colspan="6">
                            <section id="course-template-section-{{ $section->id }}-lessons"
                                     class="course-template-lesson-panel"
                                     aria-labelledby="course-template-section-{{ $section->id }}-lessons-title">
                                <div class="course-template-lesson-toolbar">
                                    <div>
                                        <h4 id="course-template-section-{{ $section->id }}-lessons-title">
                                            {{ __('lf.LF_course_template_lesson_common_list_title') }}
                                        </h4>
                                        <span>
                                            {{ trans_choice(
                                                'lf.LF_course_template_lesson_common_count',
                                                $sectionLessons->count(),
                                                ['count' => $sectionLessons->count()]
                                            ) }}
                                        </span>
                                    </div>
                                    <a href="{{ route(
                                        $lessonRoutePrefix.'.create',
                                        [$template->id, $section->id]
                                    ) }}"
                                       class="btn btn-primary">
                                        {{ __('lf.LF_course_template_lesson_common_add_action') }}
                                    </a>
                                </div>

                                <div class="course-template-lesson-list">
                                    @forelse ($sectionLessons as $lesson)
                                        @php
                                            $lessonActivities = $activitiesByLesson->get(
                                                $lesson->id,
                                                collect()
                                            );
                                        @endphp
                                        <article class="course-template-lesson-item">
                                            <div class="course-template-lesson-summary">
                                                <div>
                                                    <strong>{{ $lesson->title }}</strong>
                                                    <span>
                                                        {{ __('lf.LF_course_template_lesson_common_order_value', [
                                                            'order' => $lesson->sort_order,
                                                        ]) }}
                                                        ·
                                                        {{ __('lf.LF_course_template_lesson_common_'.$lesson->status) }}
                                                    </span>
                                                </div>
                                                <div class="admin-table-actions">
                                                    <a href="{{ route(
                                                        $lessonRoutePrefix.'.edit',
                                                        [
                                                            $template->id,
                                                            $section->id,
                                                            $lesson->id,
                                                        ]
                                                    ) }}">
                                                        {{ __('lf.LF_course_template_lesson_common_edit') }}
                                                    </a>
                                                    <button type="button"
                                                            class="admin-link-button"
                                                            x-data
                                                            x-on:click="$dispatch(
                                                                'open-modal',
                                                                'delete-course-template-lesson-{{ $lesson->id }}'
                                                            )">
                                                        {{ __('lf.LF_common_button_delete') }}
                                                    </button>
                                                </div>
                                            </div>

                                            <section id="course-template-lesson-{{ $lesson->id }}-activities"
                                                     class="course-template-activity-panel"
                                                     aria-labelledby="course-template-lesson-{{ $lesson->id }}-activities-title">
                                                <div class="course-template-activity-toolbar">
                                                    <div>
                                                        <h5 id="course-template-lesson-{{ $lesson->id }}-activities-title">
                                                            {{ __('lf.LF_course_template_activity_common_list_title') }}
                                                        </h5>
                                                        <span>
                                                            {{ trans_choice(
                                                                'lf.LF_course_template_activity_common_count',
                                                                $lessonActivities->count(),
                                                                ['count' => $lessonActivities->count()]
                                                            ) }}
                                                        </span>
                                                    </div>
                                                    <a href="{{ route(
                                                        $activityRoutePrefix.'.create',
                                                        [
                                                            $template->id,
                                                            $section->id,
                                                            $lesson->id,
                                                        ]
                                                    ) }}"
                                                       class="btn btn-primary">
                                                        {{ __('lf.LF_course_template_activity_common_add_action') }}
                                                    </a>
                                                </div>

                                                <div class="course-template-activity-list">
                                                    @forelse ($lessonActivities as $activity)
                                                        <div class="course-template-activity-item">
                                                            <div>
                                                                <strong>{{ $activity->title }}</strong>
                                                                <span>
                                                                    {{ __('lf.LF_course_template_activity_common_summary', [
                                                                        'type' => __(
                                                                            'lf.LF_course_template_activity_common_type_'.$activity->activity_type
                                                                        ),
                                                                        'order' => $activity->sort_order,
                                                                        'status' => __(
                                                                            'lf.LF_course_template_activity_common_'.$activity->status
                                                                        ),
                                                                    ]) }}
                                                                </span>
                                                            </div>
                                                            <div class="admin-table-actions">
                                                                <a href="{{ route(
                                                                    $activityRoutePrefix.'.edit',
                                                                    [
                                                                        $template->id,
                                                                        $section->id,
                                                                        $lesson->id,
                                                                        $activity->id,
                                                                    ]
                                                                ) }}">
                                                                    {{ __('lf.LF_course_template_activity_common_edit') }}
                                                                </a>
                                                                <button type="button"
                                                                        class="admin-link-button"
                                                                        x-data
                                                                        x-on:click="$dispatch(
                                                                            'open-modal',
                                                                            'delete-course-template-activity-{{ $activity->id }}'
                                                                        )">
                                                                    {{ __('lf.LF_common_button_delete') }}
                                                                </button>
                                                            </div>

                                                            <x-modal name="delete-course-template-activity-{{ $activity->id }}"
                                                                     focusable>
                                                                <div class="lf-modal-card">
                                                                    <h2>
                                                                        {{ __('lf.LF_course_template_activity_common_delete_confirm') }}
                                                                    </h2>
                                                                    <div class="lf-modal-actions">
                                                                        <button type="button"
                                                                                class="btn"
                                                                                x-on:click="$dispatch(
                                                                                    'close-modal',
                                                                                    'delete-course-template-activity-{{ $activity->id }}'
                                                                                )">
                                                                            {{ __('lf.LF_course_template_activity_common_delete_no') }}
                                                                        </button>
                                                                        <form method="POST"
                                                                              action="{{ route(
                                                                                  $activityRoutePrefix.'.destroy',
                                                                                  [
                                                                                      $template->id,
                                                                                      $section->id,
                                                                                      $lesson->id,
                                                                                      $activity->id,
                                                                                  ]
                                                                              ) }}">
                                                                            @csrf
                                                                            @method('DELETE')
                                                                            <button type="submit"
                                                                                    class="btn btn-primary">
                                                                                {{ __('lf.LF_course_template_activity_common_delete_yes') }}
                                                                            </button>
                                                                        </form>
                                                                    </div>
                                                                </div>
                                                            </x-modal>
                                                        </div>
                                                    @empty
                                                        <p class="course-template-activity-empty">
                                                            {{ __('lf.LF_course_template_activity_common_empty') }}
                                                        </p>
                                                    @endforelse
                                                </div>
                                            </section>

                                            <x-modal name="delete-course-template-lesson-{{ $lesson->id }}"
                                                     focusable>
                                                <div class="lf-modal-card">
                                                    <h2>
                                                        {{ __('lf.LF_course_template_lesson_common_delete_confirm') }}
                                                    </h2>
                                                    <div class="lf-modal-actions">
                                                        <button type="button"
                                                                class="btn"
                                                                x-on:click="$dispatch(
                                                                    'close-modal',
                                                                    'delete-course-template-lesson-{{ $lesson->id }}'
                                                                )">
                                                            {{ __('lf.LF_course_template_lesson_common_delete_no') }}
                                                        </button>
                                                        <form method="POST"
                                                              action="{{ route(
                                                                  $lessonRoutePrefix.'.destroy',
                                                                  [
                                                                      $template->id,
                                                                      $section->id,
                                                                      $lesson->id,
                                                                  ]
                                                              ) }}">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit"
                                                                    class="btn btn-primary">
                                                                {{ __('lf.LF_course_template_lesson_common_delete_yes') }}
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </x-modal>
                                        </article>
                                    @empty
                                        <p class="course-template-lesson-empty">
                                            {{ __('lf.LF_course_template_lesson_common_empty') }}
                                        </p>
                                    @endforelse
                                </div>
                            </section>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            {{ __('lf.LF_course_template_section_common_empty') }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
