@php
    $activityMediaPresentation = $currentActivityMedia ?? [];
    $presentation = ($activityMediaPresentation['type'] ?? null) === $mediaType
        ? $activityMediaPresentation
        : ['state' => 'empty', 'type' => $mediaType];
@endphp

<div class="course-activity-current-media" data-current-media-state="{{ $presentation['state'] }}">
    @if ($presentation['state'] === 'available')
        <div class="course-template-preview-card course-activity-current-media-card">
            <x-media-thumbnail
                :presentation="$presentation['thumbnail']"
                alt="" />
            @if (in_array($mediaType, ['video', 'audio'], true))
                <button type="button"
                        class="admin-link-button admin-text-action"
                        aria-label="{{ __('lf.LF_course_template_activity_media_view_label', ['type' => __('lf.LF_course_template_activity_common_type_'.$mediaType)]) }}"
                        x-on:click="openActivityMediaPreview(
                            @js($presentation['media']->original_name),
                            @js($presentation['preview_url']),
                            @js($presentation['media']->mime_type),
                            @js($mediaType)
                        )">
                    {{ __('lf.LF_media_file_common_preview_action') }}
                </button>
            @else
                <a class="admin-text-action"
                   href="{{ $presentation['preview_url'] }}"
                   target="_blank"
                   aria-label="{{ __('lf.LF_course_template_activity_media_view_label', ['type' => __('lf.LF_course_template_activity_common_type_'.$mediaType)]) }}"
                   rel="noopener noreferrer">
                    {{ __('lf.LF_media_file_common_preview_action') }}
                </a>
            @endif
        </div>
    @elseif ($presentation['state'] === 'unavailable')
        <p class="lf-form-help course-activity-current-media-message">
            {{ __('lf.LF_course_template_activity_media_unavailable') }}
        </p>
        <p class="lf-form-help course-activity-current-media-required">
            {{ __('lf.LF_course_template_activity_media_required_before_publish') }}
        </p>
    @else
        <p class="lf-form-help course-activity-current-media-message">
            {{ __('lf.LF_course_template_activity_media_empty') }}
        </p>
        <p class="lf-form-help course-activity-current-media-required">
            {{ __('lf.LF_course_template_activity_media_required_before_publish') }}
        </p>
    @endif
</div>
