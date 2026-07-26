@php
    $section = $section ?? null;
    $lessonBaseParameters = $section
        ? [$template->id, $section->id]
        : [$template->id];
@endphp

<section id="{{ $panelId }}"
         x-data="{
             activityPreviewOpen: false,
             activityPreview: { title: '', url: '', type: '' },
             openActivityPreview(title, url, type) {
                 this.activityPreview = { title, url, type };
                 this.activityPreviewOpen = true;
                 this.$nextTick(() => {
                     const player = type === 'video'
                         ? this.$refs.outlinePreviewVideo
                         : (type === 'audio' ? this.$refs.outlinePreviewAudio : null);
                     player?.load();
                     player?.play().catch(() => {});
                 });
             },
             closeActivityPreview() {
                 [this.$refs.outlinePreviewVideo, this.$refs.outlinePreviewAudio]
                     .filter(Boolean)
                     .forEach((player) => player.pause());
                 this.activityPreviewOpen = false;
                 this.activityPreview = { title: '', url: '', type: '' };
             },
         }"
         x-on:keydown.escape.window="closeActivityPreview()"
         @class([
             'course-template-lesson-panel',
             'course-template-direct-lesson-panel' => ! $section,
         ])
         aria-labelledby="{{ $panelId }}-title">
    <div @class([
        'course-template-lesson-toolbar' => $section,
        'course-template-section-action-bar' => ! $section,
        'course-template-content-toolbar' => ! $section,
    ])>
        @if ($section)
            <h4 id="{{ $panelId }}-title">
                {{ $panelTitle }} ({{ $lessons->count() }})
            </h4>
        @else
            <strong id="{{ $panelId }}-title">
                {{ trans_choice('lf.LF_course_template_lesson_common_count', $lessons->count(), [
                    'count' => $lessons->count(),
                ]) }}
            </strong>
        @endif
        @if (($section && $section->allows_lessons) || (! $section && $lessons->isNotEmpty()))
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
                                    @if (($activity->view_behavior ?? null) === 'popup')
                                        <button type="button"
                                                class="admin-link-button admin-text-action"
                                                x-on:click="openActivityPreview(
                                                    @js($activity->title),
                                                    @js($activityViewUrl),
                                                    @js($activity->activity_type)
                                                )">
                                            {{ __('lf.LF_common_button_view') }}
                                        </button>
                                    @else
                                        <a class="admin-text-action" href="{{ $activityViewUrl }}"
                                           @if (in_array(($activity->view_behavior ?? null), ['new_tab'], true))
                                               target="_blank"
                                               rel="noopener noreferrer"
                                           @endif>
                                            {{ __('lf.LF_common_button_view') }}
                                        </a>
                                    @endif
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
            @if ($section)
                <p class="course-template-lesson-empty">{{ $emptyMessage }}</p>
            @else
                <div class="course-template-content-empty">
                    <span class="course-template-content-empty-icon" aria-hidden="true">＋</span>
                    <strong>{{ __('lf.LF_course_template_lesson_common_direct_empty_title') }}</strong>
                    <p>{{ $emptyMessage }}</p>
                    <a href="{{ route($lessonRoutePrefix.'.create', $lessonBaseParameters) }}"
                       class="btn admin-primary-outline-action">
                        {{ __('lf.LF_course_template_lesson_common_add_action') }}
                    </a>
                </div>
            @endif
        @endforelse
    </div>

    <div class="media-library-modal"
         x-cloak
         x-show="activityPreviewOpen"
         x-transition.opacity
         role="dialog"
         aria-modal="true"
         aria-labelledby="{{ $panelId }}-activity-preview-title">
        <button type="button"
                class="media-library-modal-backdrop"
                aria-label="{{ __('lf.LF_common_button_cancel') }}"
                x-on:click="closeActivityPreview()"></button>
        <div class="media-library-modal-panel">
            <div class="media-library-modal-header">
                <h2 id="{{ $panelId }}-activity-preview-title" x-text="activityPreview.title"></h2>
                <button type="button"
                        class="admin-link-button admin-text-action"
                        x-on:click="closeActivityPreview()">
                    {{ __('lf.LF_common_button_cancel') }}
                </button>
            </div>
            <div class="media-library-modal-body">
                <video x-ref="outlinePreviewVideo"
                       x-show="activityPreview.type === 'video'"
                       x-bind:src="activityPreview.type === 'video' ? activityPreview.url : ''"
                       controls preload="metadata"
                       class="media-library-modal-video"></video>
                <audio x-ref="outlinePreviewAudio"
                       x-show="activityPreview.type === 'audio'"
                       x-bind:src="activityPreview.type === 'audio' ? activityPreview.url : ''"
                       controls preload="metadata"
                       class="course-activity-media-audio-player"></audio>
            </div>
        </div>
    </div>
</section>
