@php
    $lessonActivities = $activitiesByLesson->get($lesson->id, collect());
    $lessonDescription = trim((string) $lesson->short_description_snapshot) !== ''
        ? $lesson->short_description_snapshot
        : (trim((string) $lesson->description_snapshot) !== ''
            ? $lesson->description_snapshot
            : null);
@endphp

<article class="course-template-lesson-item"
         data-version-lesson-id="{{ $lesson->id }}">
    <div class="course-template-lesson-summary">
        <div>
            <strong>{{ $lesson->title_snapshot }}</strong>
            @if ($lessonDescription !== null)
                <span class="lf-secondary-text lf-line-clamp-2">
                    {{ $lessonDescription }}
                </span>
            @endif
        </div>
    </div>

    <section id="course-version-lesson-{{ $lesson->id }}-activities"
             class="course-template-activity-panel"
             aria-labelledby="course-version-lesson-{{ $lesson->id }}-activities-title">
        <div class="course-template-activity-toolbar">
            <h5 id="course-version-lesson-{{ $lesson->id }}-activities-title">
                {{ __('lf.LF_course_template_activity_common_list_title') }}
                ({{ $lessonActivities->count() }})
            </h5>
        </div>

        <div class="course-template-activity-list">
            @forelse ($lessonActivities as $activity)
                @php
                    $activityView = $presentedActivities[$activity->id];
                    $activityIcon = match ($activity->activity_type) {
                        'video', 'embedded_video', 'live_class' => '🎥',
                        'quiz', 'assignment' => '📝',
                        'audio' => '🎧',
                        'external_link' => '🔗',
                        default => '📄',
                    };
                @endphp

                <div class="course-template-activity-item"
                     data-version-activity-id="{{ $activity->id }}">
                    <div class="course-template-activity-identity">
                        <span class="course-template-activity-icon"
                              aria-hidden="true">{{ $activityIcon }}</span>
                        <span class="course-template-activity-title-text">
                            {{ $activity->title_snapshot }}
                        </span>
                    </div>
                    @if ($activityView['mediaUrl'] || $activityView['embeddedUrl'])
                        <div class="admin-table-actions">
                            <button type="button"
                                    class="admin-link-button admin-text-action"
                                    data-preview-name="{{ $activity->title_snapshot }}"
                                    data-preview-url="{{ $activityView['mediaUrl'] ?: $activityView['embeddedUrl'] }}"
                                    data-preview-type="{{ $activityView['mediaUrl'] ? $activity->activity_type : 'embed' }}"
                                    data-preview-mime="{{ $activityView['embeddedUrl'] ? 'text/html' : '' }}"
                                    x-on:click="openVersionPreview($el.dataset.previewName, $el.dataset.previewUrl, $el.dataset.previewType, $el.dataset.previewMime)">
                                {{ __('lf.LF_media_file_common_preview_action') }}
                            </button>
                        </div>
                    @endif
                </div>
            @empty
                <p class="course-template-activity-empty">
                    {{ __('lf.LF_course_template_activity_common_empty') }}
                </p>
            @endforelse
        </div>
    </section>
</article>
