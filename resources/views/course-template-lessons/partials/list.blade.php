@php
    $section = $section ?? null;
    $lessonBaseParameters = $section
        ? [$template->id, $section->id]
        : [$template->id];
@endphp

<section id="{{ $panelId }}"
         class="course-template-lesson-panel"
         aria-labelledby="{{ $panelId }}-title">
    <div class="course-template-lesson-toolbar">
        <div>
            <h4 id="{{ $panelId }}-title">{{ $panelTitle }}</h4>
            <span>
                {{ trans_choice(
                    'lf.LF_course_template_lesson_common_count',
                    $lessons->count(),
                    ['count' => $lessons->count()]
                ) }}
            </span>
        </div>
        <a href="{{ route(
            $lessonRoutePrefix.'.create',
            $lessonBaseParameters
        ) }}"
           class="btn btn-primary">
            {{ __('lf.LF_course_template_lesson_common_add_action') }}
        </a>
    </div>

    <div class="course-template-lesson-list">
        @forelse ($lessons as $lesson)
            @php
                $lessonActivities = $activitiesByLesson->get(
                    $lesson->id,
                    collect()
                );
                $lessonParameters = array_merge(
                    $lessonBaseParameters,
                    [$lesson->id]
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
                            $lessonParameters
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
                            $lessonParameters
                        ) }}"
                           class="btn btn-primary">
                            {{ __('lf.LF_course_template_activity_common_add_action') }}
                        </a>
                    </div>

                    <div class="course-template-activity-list">
                        @forelse ($lessonActivities as $activity)
                            @php
                                $activityParameters = array_merge(
                                    $lessonParameters,
                                    [$activity->id]
                                );
                            @endphp
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
                                        $activityParameters
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
                                                      $activityParameters
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
                                      $lessonParameters
                                  ) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-primary">
                                    {{ __('lf.LF_course_template_lesson_common_delete_yes') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </x-modal>
            </article>
        @empty
            <p class="course-template-lesson-empty">{{ $emptyMessage }}</p>
        @endforelse
    </div>
</section>
