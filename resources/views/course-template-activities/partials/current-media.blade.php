@php
    $activityMediaPresentation = $currentActivityMedia ?? [];
    $presentation = ($activityMediaPresentation['type'] ?? null) === $mediaType
        ? $activityMediaPresentation
        : ['state' => 'empty', 'type' => $mediaType];
@endphp

@if (in_array($presentation['state'], ['available', 'unavailable'], true))
    <div class="course-activity-current-media" data-current-media-state="{{ $presentation['state'] }}">
        @if ($presentation['state'] === 'available')
        <x-authoring-media-row
            :presentation="$presentation['thumbnail']"
            alt=""
            :current-label="__('lf.LF_media_current_'.$mediaType)"
            :display-name="$presentation['media']->display_name"
            remove-name="remove_activity_media"
            :remove-label="__('lf.LF_course_template_activity_media_remove_current')">
            @if (in_array($mediaType, ['video', 'audio'], true))
                <button type="button"
                        class="authoring-media-overlay-action"
                        aria-label="{{ __('lf.LF_course_template_activity_media_view_label', ['type' => __('lf.LF_course_template_activity_common_type_'.$mediaType)]) }}"
                        x-on:click.stop="openActivityMediaPreview(
                            @js($presentation['media']->original_name),
                            @js($presentation['preview_url']),
                            @js($presentation['media']->mime_type),
                            @js($mediaType)
                        )">
                    <x-backend-icon name="eye" class="authoring-media-action-icon" />
                    <span class="sr-only">{{ __('lf.LF_media_file_common_preview_action') }}</span>
                </button>
            @else
                <a class="authoring-media-overlay-action"
                   href="{{ $presentation['preview_url'] }}"
                   target="_blank"
                   aria-label="{{ __('lf.LF_course_template_activity_media_view_label', ['type' => __('lf.LF_course_template_activity_common_type_'.$mediaType)]) }}"
                   rel="noopener noreferrer">
                    <x-backend-icon name="eye" class="authoring-media-action-icon" />
                    <span class="sr-only">{{ __('lf.LF_media_file_common_preview_action') }}</span>
                </a>
            @endif
        </x-authoring-media-row>
        @else
            <p class="lf-form-help course-activity-current-media-message">
                {{ __('lf.LF_course_template_activity_media_unavailable') }}
            </p>
        @endif
    </div>
@endif
