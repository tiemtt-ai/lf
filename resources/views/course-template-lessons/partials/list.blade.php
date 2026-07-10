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
        <h4 id="{{ $panelId }}-title">
            {{ $panelTitle }} ({{ $lessons->count() }})
        </h4>
        @if (! $section || $section->allows_lessons)
            <a href="{{ route(
                $lessonRoutePrefix.'.create',
                $lessonBaseParameters
            ) }}"
               @class([
                   'admin-text-action' => $section,
                   'btn admin-primary-outline-action' => ! $section,
               ])>
                {{ __($section
                    ? 'lf.LF_course_template_lesson_common_attach_action'
                    : 'lf.LF_course_template_lesson_common_add_action') }}
            </a>
        @endif
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
                        @if ($lesson->short_description || $lesson->description)
                            <span class="lf-secondary-text lf-line-clamp-2">
                                {{ $lesson->short_description ?: $lesson->description }}
                            </span>
                        @endif
                    </div>
                    <div class="admin-table-actions">
                        <a class="admin-text-action" href="{{ route(
                            $lessonRoutePrefix.'.edit',
                            $lessonParameters
                        ) }}">
                            {{ __('lf.LF_course_template_lesson_common_edit') }}
                        </a>
                        <button type="button"
                                class="admin-link-button admin-text-action"
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
                        <h5 id="course-template-lesson-{{ $lesson->id }}-activities-title">{{ __('lf.LF_course_template_activity_common_list_title') }} ({{ $lessonActivities->count() }})</h5>
                        <a href="{{ route(
                            $activityRoutePrefix.'.create',
                            $lessonParameters
                        ) }}"
                           class="admin-text-action">
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
                                $activityIcon = match ($activity->activity_type) {
                                    'video', 'liveclass' => '🎥',
                                    'quiz', 'assignment' => '📝',
                                    'audio' => '🎧',
                                    'external_link' => '🔗',
                                    default => '📄',
                                };
                                $activityViewUrl = match ($activity->view_kind ?? 'readonly') {
                                    'media', 'external' => $activity->view_url,
                                    default => route(
                                        $activityRoutePrefix.'.show',
                                        $activityParameters
                                    ),
                                };
                            @endphp
                            <div class="course-template-activity-item">
                                <div class="course-template-activity-identity">
                                    <span class="course-template-activity-icon"
                                          aria-hidden="true">{{ $activityIcon }}</span>
                                    <span class="course-template-activity-title-text">
                                        {{ $activity->title }}
                                    </span>
                                </div>
                                <div class="admin-table-actions">
                                    <a class="admin-text-action" href="{{ $activityViewUrl }}"
                                       @if (in_array($activity->view_kind, ['media', 'external'], true))
                                           target="_blank"
                                           rel="noopener noreferrer"
                                       @endif>
                                        {{ __('lf.LF_common_button_view') }}
                                    </a>
                                    <a class="admin-text-action" href="{{ route(
                                        $activityRoutePrefix.'.edit',
                                        $activityParameters
                                    ) }}">
                                        {{ __('lf.LF_course_template_activity_common_edit') }}
                                    </a>
                                    <button type="button"
                                            class="admin-link-button admin-text-action"
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
