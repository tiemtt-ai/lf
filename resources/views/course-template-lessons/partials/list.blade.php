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
            <div class="course-template-content-count">
                <strong id="{{ $panelId }}-title">
                    {{ trans_choice('lf.LF_course_template_lesson_common_count', $lessons->count(), [
                        'count' => $lessons->count(),
                    ]) }}
                </strong>
                @if ($lessons->isNotEmpty())
                    <span class="lf-secondary-text">{{ trans_choice('lf.LF_course_template_activity_common_count', $totalActivities = $lessons->sum(fn (object $item): int => $activitiesByLesson->get($item->id, collect())->count()), ['count' => $totalActivities]) }}</span>
                @endif
            </div>
        @endif
        @if (($section && $section->allows_lessons) || (! $section && $lessons->isNotEmpty()))
            <button type="button"
                    data-ui-dialog-form-open
                    data-url="{{ route($lessonRoutePrefix.'.create', $lessonBaseParameters) }}"
                    aria-haspopup="dialog"
                    @class([
                        'admin-link-button admin-text-action' => $section,
                        'btn admin-primary-outline-action ui-button ui-button--outline' => ! $section,
                    ])>
                {{ __($section
                    ? 'lf.LF_course_template_lesson_common_attach_action'
                    : 'lf.LF_course_template_lesson_common_add_action') }}
            </button>
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
                    <span class="course-template-lesson-number" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <div class="course-template-lesson-copy">
                        <strong>{{ $lesson->title }}</strong>
                        @if ($lesson->short_description || $lesson->description)
                            <span class="lf-secondary-text lf-line-clamp-2">
                                {{ $lesson->short_description ?: $lesson->description }}
                            </span>
                        @endif
                    </div>
                    <div class="admin-table-actions">
                        <button type="button" class="admin-link-button admin-text-action ui-row-action"
                                data-ui-dialog-form-open aria-haspopup="dialog"
                                data-url="{{ route($lessonRoutePrefix.'.edit', $lessonParameters) }}"
                                aria-label="{{ __('lf.LF_course_template_lesson_common_edit') }}: {{ $lesson->title }}"
                                data-tooltip="{{ __('lf.LF_course_template_lesson_common_edit') }}">
                            <x-backend-icon name="pencil" class="ui-icon" />
                            <span class="ui-sr-only">{{ __('lf.LF_course_template_lesson_common_edit') }}</span>
                        </button>
                        @php
                            $lessonBlocker = ($lessonDeleteBlockers ?? [])[(int) $lesson->id] ?? null;
                        @endphp
                        <button type="button"
                                class="admin-link-button admin-text-action ui-row-action ui-row-action--danger"
                                data-ui-row-delete
                                data-url="{{ route($lessonRoutePrefix.'.destroy', $lessonParameters) }}"
                                data-confirm-title="{{ __('lf.LF_course_template_lesson_delete_title') }}"
                                data-confirm-message="{{ __('lf.LF_course_template_lesson_delete_confirm', ['name' => $lesson->title]) }}"
                                data-confirm-label="{{ __('lf.LF_common_button_confirm') }}"
                                @if ($lessonBlocker) disabled aria-disabled="true" @endif
                                aria-label="{{ __('lf.LF_common_button_delete') }}: {{ $lesson->title }}"
                                data-tooltip="{{ $lessonBlocker ?? __('lf.LF_common_button_delete') }}">
                            <x-backend-icon name="trash-2" class="ui-icon" />
                            <span class="ui-sr-only">{{ __('lf.LF_common_button_delete') }}</span>
                        </button>
                    </div>
                </div>

                <section id="course-template-lesson-{{ $lesson->id }}-activities"
                         class="course-template-activity-panel"
                         x-data="{ open: true }"
                         aria-labelledby="course-template-lesson-{{ $lesson->id }}-activities-title">
                    <div class="course-template-activity-toolbar">
                        <h5 id="course-template-lesson-{{ $lesson->id }}-activities-title">
                            <button type="button" class="course-template-activity-fold"
                                    aria-controls="course-template-lesson-{{ $lesson->id }}-activity-list"
                                    x-bind:aria-expanded="open.toString()" aria-expanded="true"
                                    x-on:click="open = ! open"><x-backend-icon name="chevron-down" class="ui-icon course-template-activity-fold-icon" x-bind:class="{ 'is-collapsed': ! open }" />{{ __('lf.LF_course_template_activity_common_list_title') }} ({{ $lessonActivities->count() }})</button>
                        </h5>
                        <button type="button" class="admin-link-button admin-text-action"
                                data-ui-dialog-form-open aria-haspopup="dialog"
                                data-url="{{ route($activityRoutePrefix.'.create', $lessonParameters) }}">
                            {{ __('lf.LF_course_template_activity_common_add_action') }}
                        </button>
                    </div>

                    <div class="course-template-activity-list" id="course-template-lesson-{{ $lesson->id }}-activity-list" x-show="open">
                        @forelse ($lessonActivities as $activity)
                            @php
                                $activityParameters = array_merge(
                                    $lessonParameters,
                                    [$activity->id]
                                );
                                $activityIcon = match ($activity->activity_type) {
                                    'video' => 'file-video',
                                    'embedded_video' => 'square-play',
                                    'external_link' => 'link',
                                    'audio' => 'audio',
                                    'quiz', 'assignment' => 'clipboard-check',
                                    'live_class', 'liveclass' => 'video',
                                    default => 'file-text',
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
                                    <span class="course-template-activity-icon" aria-hidden="true">
                                        <x-backend-icon :name="$activityIcon" class="course-template-activity-type-icon" />
                                    </span>
                                    <div class="course-template-activity-copy">
                                        <span class="course-template-activity-title-text">
                                            {{ $activity->title }}
                                        </span>
                                        @if (($activity->structured_extraction_status ?? null) !== null)
                                            @php
                                                $structuredStatus = $activity->structured_extraction_status;
                                                $structuredMessageKey = match ($structuredStatus) {
                                                    'ready' => 'lf.LF_course_template_activity_structured_ready_help',
                                                    'failed' => match ($activity->structured_extraction_error_code) {
                                                        'unsupported_source' => 'lf.LF_media_processing_unsupported',
                                                        'corrupt_source', 'source_unavailable', 'provider_command_failed' => 'lf.LF_media_processing_invalid',
                                                        'structured_extraction_too_large' => 'lf.LF_course_template_activity_structured_failed_too_large_help',
                                                        'page_limit_exceeded' => 'lf.LF_course_template_activity_structured_failed_page_limit_help',
                                                        'provider_unavailable' => 'lf.LF_course_template_activity_structured_failed_provider_help',
                                                        'provider_timeout' => 'lf.LF_course_template_activity_structured_failed_timeout_help',
                                                        default => 'lf.LF_course_template_activity_structured_failed_help',
                                                    },
                                                    'initializing' => 'lf.LF_course_template_activity_structured_initializing_help',
                                                    'processing' => 'lf.LF_course_template_activity_structured_processing_help',
                                                    'absent' => 'lf.LF_course_template_activity_structured_absent_help',
                                                    default => 'lf.LF_course_template_activity_structured_pending_help',
                                                };
                                            @endphp
                                            <div class="course-template-activity-structured-status"
                                                 role="status">
                                                <span @class([
                                                    'badge',
                                                    'badge-success' => $structuredStatus === 'ready',
                                                    'badge-danger' => $structuredStatus === 'failed',
                                                    'badge-info' => in_array($structuredStatus, ['initializing', 'pending', 'processing'], true),
                                                    'badge-neutral' => $structuredStatus === 'absent',
                                                ])>
                                                    {{ __('lf.LF_course_template_activity_structured_'.$structuredStatus) }}
                                                </span>
                                                <span class="lf-secondary-text">
                                                    {{ __($structuredMessageKey) }}
                                                </span>
                                            </div>
                                        @endif
                                        @if (($activity->speech_to_text_status ?? null) !== null)
                                            @php
                                                $speechStatus = $activity->speech_to_text_status;
                                                $videoProcessing = $activity->activity_type === 'video';
                                                $speechLabelKey = $videoProcessing
                                                    ? 'lf.LF_course_template_activity_video_processing_'.$speechStatus
                                                    : 'lf.LF_course_template_activity_stt_'.$speechStatus;
                                                $speechMessageKey = $videoProcessing
                                                    ? match ($speechStatus) {
                                                        'failed' => match ($activity->speech_to_text_error_code) {
                                                            'unsupported_source' => 'lf.LF_media_processing_unsupported',
                                                            'corrupt_source', 'source_unavailable', 'transcript_invalid' => 'lf.LF_media_processing_invalid',
                                                            'audio_extraction_failed', 'audio_extraction_limit_exceeded' => 'lf.LF_media_processing_extraction',
                                                            'video_stt_disabled' => 'lf.LF_course_template_activity_video_stt_qualification_feature_disabled',
                                                            'video_stt_unqualified', 'extraction_profile_mismatch' => 'lf.LF_course_template_activity_stt_unqualified_help',
                                                            'video_limit_exceeded' => 'lf.LF_course_template_activity_video_stt_failed_limit_help',
                                                            'provider_timeout' => 'lf.LF_course_template_activity_stt_failed_timeout_help',
                                                            default => 'lf.LF_course_template_activity_video_processing_failed_help',
                                                        },
                                                        'unqualified' => 'lf.LF_course_template_activity_stt_unqualified_help',
                                                        'disabled' => 'lf.LF_course_template_activity_video_stt_disabled_help',
                                                        default => 'lf.LF_course_template_activity_video_processing_'.$speechStatus.'_help',
                                                    }
                                                    : match ($speechStatus) {
                                                    'ready' => 'lf.LF_course_template_activity_stt_ready_help',
                                                    'failed' => match ($activity->speech_to_text_error_code) {
                                                        'unsupported_source' => 'lf.LF_media_processing_unsupported',
                                                        'corrupt_source', 'source_unavailable', 'transcript_invalid' => 'lf.LF_media_processing_invalid',
                                                        'audio_extraction_failed', 'audio_extraction_limit_exceeded' => 'lf.LF_media_processing_extraction',
                                                        'video_stt_disabled' => 'lf.LF_course_template_activity_video_stt_qualification_feature_disabled',
                                                        'video_stt_unqualified', 'extraction_profile_mismatch' => 'lf.LF_course_template_activity_stt_unqualified_help',
                                                        'video_limit_exceeded' => 'lf.LF_course_template_activity_video_stt_failed_limit_help',
                                                        'audio_limit_exceeded' => 'lf.LF_course_template_activity_stt_failed_limit_help',
                                                        'locale_unavailable' => 'lf.LF_course_template_activity_stt_failed_locale_help',
                                                        'provider_unavailable' => 'lf.LF_course_template_activity_stt_failed_provider_help',
                                                        'provider_timeout' => 'lf.LF_course_template_activity_stt_failed_timeout_help',
                                                        default => 'lf.LF_course_template_activity_stt_failed_help',
                                                    },
                                                    'processing' => 'lf.LF_course_template_activity_stt_processing_help',
                                                    'unqualified' => 'lf.LF_course_template_activity_stt_unqualified_help',
                                                    'absent' => 'lf.LF_course_template_activity_stt_absent_help',
                                                    'disabled' => $activity->activity_type === 'video'
                                                        ? 'lf.LF_course_template_activity_video_stt_disabled_help'
                                                        : 'lf.LF_course_template_activity_stt_disabled_help',
                                                    default => 'lf.LF_course_template_activity_stt_pending_help',
                                                };
                                            @endphp
                                            <div class="course-template-activity-structured-status" role="status">
                                                <span @class([
                                                    'badge',
                                                    'badge-success' => $speechStatus === 'ready',
                                                    'badge-danger' => $speechStatus === 'failed',
                                                    'badge-info' => in_array($speechStatus, ['pending', 'processing'], true),
                                                    'badge-secondary' => in_array($speechStatus, ['absent', 'disabled', 'unqualified'], true),
                                                    'badge-disabled' => $speechStatus === 'disabled',
                                                ])>
                                                    {{ __($speechLabelKey) }}
                                                </span>
                                                <span class="lf-secondary-text">{{ __($speechMessageKey, ['minutes' => config('media.processing.speech_to_text.'.($activity->activity_type === 'video' ? 'max_video_duration_seconds' : 'max_duration_seconds')) / 60, 'gib' => config('media.processing.speech_to_text.'.($activity->activity_type === 'video' ? 'max_video_source_bytes' : 'max_bytes')) / 1073741824]) }}</span>
                                                @if (in_array($speechStatus, ['absent', 'disabled'], true) && $activity->activity_type === 'audio')
                                                    <form method="POST"
                                                          action="{{ route($activityRoutePrefix.'.initialize-transcription', $activityParameters) }}"
                                                          class="course-template-activity-stt-initialize-form">
                                                        @csrf
                                                        <fieldset class="admin-checkbox-list">
                                                        <legend class="sr-only" id="stt-locale-{{ $activity->id }}">
                                                            {{ __('lf.LF_course_template_activity_stt_initialize_locale') }}
                                                        </legend>
                                                        @foreach (['vi', 'ko', 'en'] as $localeCode)
                                                            <label class="admin-checkbox-option">
                                                                <input type="checkbox" name="processing_locales[]" value="{{ $localeCode }}">
                                                                <span>{{ __('lf.LF_common_language_'.$localeCode) }} ({{ $localeCode }})</span>
                                                            </label>
                                                        @endforeach
                                                        </fieldset>
                                                        <button type="submit" class="admin-text-action">
                                                            {{ __('lf.LF_course_template_activity_stt_initialize') }}
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                <div class="admin-table-actions">
                                    @if (($activity->view_behavior ?? null) === 'popup' || (($activity->view_kind ?? null) === 'none' && in_array($activity->activity_type, ['audio', 'video'], true)))
                                        <button type="button"
                                                class="admin-link-button admin-text-action ui-row-action"
                                                aria-label="{{ __('lf.LF_common_button_view') }}: {{ $activity->title }}"
                                                data-tooltip="{{ __('lf.LF_common_button_view') }}"
                                                x-on:click="openActivityPreview(
                                                    @js($activity->title),
                                                    @js(($activity->view_kind ?? null) === 'none' ? '' : $activityViewUrl),
                                                    @js(($activity->view_kind ?? null) === 'none' ? 'unavailable' : $activity->activity_type)
                                                )">
                                            <x-backend-icon name="eye" class="ui-icon" />
                                            <span class="ui-sr-only">{{ __('lf.LF_common_button_view') }}</span>
                                        </button>
                                    @elseif (! in_array(($activity->view_kind ?? 'readonly'), ['media', 'external'], true))
                                        <button type="button" class="admin-link-button admin-text-action ui-row-action"
                                                data-ui-dialog-form-open aria-haspopup="dialog"
                                                data-url="{{ $activityViewUrl }}"
                                                aria-label="{{ __('lf.LF_common_button_view') }}: {{ $activity->title }}"
                                                data-tooltip="{{ __('lf.LF_common_button_view') }}">
                                            <x-backend-icon name="eye" class="ui-icon" />
                                            <span class="ui-sr-only">{{ __('lf.LF_common_button_view') }}</span>
                                        </button>
                                    @else
                                        <a class="admin-text-action ui-row-action" href="{{ $activityViewUrl }}"
                                           aria-label="{{ __('lf.LF_common_button_view') }}: {{ $activity->title }}"
                                           data-tooltip="{{ __('lf.LF_common_button_view') }}"
                                           @if (in_array(($activity->view_behavior ?? null), ['new_tab'], true))
                                               target="_blank"
                                               rel="noopener noreferrer"
                                           @endif>
                                            <x-backend-icon name="eye" class="ui-icon" />
                                            <span class="ui-sr-only">{{ __('lf.LF_common_button_view') }}</span>
                                        </a>
                                    @endif
                                    <button type="button" class="admin-link-button admin-text-action ui-row-action"
                                            data-ui-dialog-form-open aria-haspopup="dialog"
                                            data-url="{{ route($activityRoutePrefix.'.edit', $activityParameters) }}"
                                            aria-label="{{ __('lf.LF_course_template_activity_common_edit') }}: {{ $activity->title }}"
                                            data-tooltip="{{ __('lf.LF_course_template_activity_common_edit') }}">
                                        <x-backend-icon name="pencil" class="ui-icon" />
                                        <span class="ui-sr-only">{{ __('lf.LF_course_template_activity_common_edit') }}</span>
                                    </button>
                                    @php
                                        $activityBlocker = ($activityDeleteBlockers ?? [])[(int) $activity->id] ?? null;
                                    @endphp
                                    <button type="button"
                                            class="admin-link-button admin-text-action ui-row-action ui-row-action--danger"
                                            data-ui-row-delete
                                            data-url="{{ route($activityRoutePrefix.'.destroy', $activityParameters) }}"
                                            data-confirm-title="{{ __('lf.LF_course_template_activity_delete_title') }}"
                                            data-confirm-message="{{ __('lf.LF_course_template_activity_delete_confirm', ['name' => $activity->title]) }}"
                                            data-confirm-label="{{ __('lf.LF_common_button_confirm') }}"
                                            @if ($activityBlocker) disabled aria-disabled="true" @endif
                                            aria-label="{{ __('lf.LF_common_button_delete') }}: {{ $activity->title }}"
                                            data-tooltip="{{ $activityBlocker ?? __('lf.LF_common_button_delete') }}">
                                        <x-backend-icon name="trash-2" class="ui-icon" />
                                        <span class="ui-sr-only">{{ __('lf.LF_common_button_delete') }}</span>
                                    </button>
                                </div>

                            </div>
                        @empty
                            <p class="course-template-activity-empty">
                                {{ __('lf.LF_course_template_activity_common_empty') }}
                            </p>
                        @endforelse
                    </div>
                </section>
            </article>
        @empty
            @if ($section)
                <p class="course-template-lesson-empty">{{ $emptyMessage }}</p>
            @else
                <div class="course-template-content-empty">
                    <span class="course-template-content-empty-icon" aria-hidden="true">＋</span>
                    <strong>{{ __('lf.LF_course_template_lesson_common_direct_empty_title') }}</strong>
                    <p>{{ $emptyMessage }}</p>
                    <button type="button" class="btn admin-primary-outline-action ui-button ui-button--outline"
                            data-ui-dialog-form-open aria-haspopup="dialog"
                            data-url="{{ route($lessonRoutePrefix.'.create', $lessonBaseParameters) }}">
                        {{ __('lf.LF_course_template_lesson_common_add_action') }}
                    </button>
                </div>
            @endif
        @endforelse
    </div>

    <x-media-preview-modal id="{{ $panelId }}-activity-preview-title" show="activityPreviewOpen" close="closeActivityPreview()" title="activityPreview.title" compact="['audio', 'unavailable'].includes(activityPreview.type)">

                <video x-ref="outlinePreviewVideo"
                       x-show="activityPreview.type === 'video'"
                       x-bind:src="activityPreview.type === 'video' ? activityPreview.url : ''"
                       controls preload="metadata"
                       class="media-library-modal-video"></video>
                <p x-show="activityPreview.type === 'unavailable'" role="status" class="lf-secondary-text">
                    {{ __('lf.LF_course_template_activity_media_unavailable') }}
                </p>
                <audio x-ref="outlinePreviewAudio"
                       x-show="activityPreview.type === 'audio'"
                       x-bind:src="activityPreview.type === 'audio' ? activityPreview.url : ''"
                       controls preload="metadata"
                       class="course-activity-media-audio-player"></audio>

</x-media-preview-modal>
</section>
