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
                <span class="course-template-content-heading">{{ __('lf.LF_course_template_content_lessons_title') }}</span>
                <strong id="{{ $panelId }}-title">
                    {{ trans_choice('lf.LF_course_template_lesson_common_count', $lessons->count(), [
                        'count' => $lessons->count(),
                    ]) }}
                </strong>
                @if ($lessons->isNotEmpty())
                    <span class="lf-secondary-text">{{ trans_choice('lf.LF_course_template_activity_common_count', $totalActivities = $lessons->sum(fn (object $item): int => $activitiesByLesson->get($item->id, collect())->count()), ['count' => $totalActivities]) }}</span>
                @endif
            </div>
            @if ($lessons->isNotEmpty())
                @include('course-template-sections.partials.content-search', [
                    'placeholder' => __('lf.LF_course_template_content_search_lessons_placeholder'),
                ])
            @endif
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
                <x-backend-icon name="plus" class="ui-icon" />
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
            @php
                $activityPhases = [
                    'before_session' => 'available_before_session',
                    'during_session' => 'available_during_session',
                    'after_session' => 'available_after_session',
                    'anytime' => 'available_anytime',
                ];
                $activityPhaseCounts = [];
                foreach ($activityPhases as $phase => $column) {
                    $activityPhaseCounts[$phase] = $lessonActivities
                        ->filter(static fn ($activity): bool => (bool) ($activity->{$column} ?? false))
                        ->count();
                }
                $lessonPanelId = 'course-template-lesson-'.$lesson->id.'-activities';
                // An activity is either Anytime or open in one or more session phases. Phase tabs appear only
                // when the lesson has session activities; a mixed lesson adds an Anytime tab after them.
                $sessionActivityCount = $lessonActivities
                    ->filter(static fn ($activity): bool => ! ($activity->available_anytime ?? false))
                    ->count();
                $activityTabs = $sessionActivityCount > 0
                    ? array_keys(array_filter($activityPhaseCounts))
                    : [];
                $activityDefaultPhase = $activityTabs[0] ?? 'anytime';
                $activityCreateUrl = route($activityRoutePrefix.'.create', $lessonParameters);
            @endphp
            <article class="course-template-lesson-item"
                     x-data="courseTemplateLessonCollapse(
                         @js($template->customer_id),
                         @js($template->id),
                         @js($lesson->id),
                         @js($loop->first)
                     )"
                     x-bind:class="{ 'is-expanded': expanded }">
                <div class="course-template-lesson-summary"
                     x-on:click="if (! $event.target.closest('button, a, form, input')) toggle()">
                    <span class="course-template-lesson-number" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <div class="course-template-lesson-copy">
                        <strong>{{ $lesson->title }}</strong>
                        @if ($lesson->short_description || $lesson->description)
                            <span class="lf-secondary-text lf-line-clamp-2">
                                {{ $lesson->short_description ?: $lesson->description }}
                            </span>
                        @endif
                        <span class="course-template-lesson-meta">
                            <span>
                                <x-backend-icon name="layers" class="ui-icon" />
                                {{ trans_choice('lf.LF_course_template_activity_common_count', $lessonActivities->count(), ['count' => $lessonActivities->count()]) }}
                            </span>
                            @if ($sessionActivityCount > 0 && $activityPhaseCounts['anytime'] > 0)
                                <span>
                                    <x-backend-icon name="clock" class="ui-icon" />
                                    {{ __('lf.LF_course_template_activity_learning_mode_session') }} ({{ $sessionActivityCount }})
                                    · {{ __('lf.LF_course_template_activity_learning_mode_anytime') }} ({{ $activityPhaseCounts['anytime'] }})
                                </span>
                            @elseif ($lessonActivities->isNotEmpty())
                                <span>
                                    <x-backend-icon name="clock" class="ui-icon" />
                                    {{ __($sessionActivityCount > 0 ? 'lf.LF_course_template_activity_learning_mode_session' : 'lf.LF_course_template_activity_learning_mode_anytime') }}
                                </span>
                            @endif
                        </span>
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
                    <button type="button"
                            class="admin-link-button admin-text-action ui-row-action course-template-lesson-toggle"
                            aria-controls="{{ $lessonPanelId }}"
                            aria-expanded="{{ $loop->first ? 'true' : 'false' }}"
                            x-bind:aria-expanded="expanded.toString()"
                            aria-label="{{ __('lf.LF_course_template_lesson_common_toggle', ['title' => $lesson->title]) }}"
                            x-on:click="toggle()">
                        <x-backend-icon name="chevron-down" class="ui-icon" />
                    </button>
                </div>

                <section id="{{ $lessonPanelId }}"
                         class="course-template-activity-panel"
                         x-show="expanded"
                         @unless ($loop->first) x-cloak @endunless
                         x-data="{ phase: @js($activityDefaultPhase) }">
                    @if ($activityTabs !== [])
                        <div class="ui-tabs course-template-activity-tabs" role="tablist"
                             aria-label="{{ __('lf.LF_course_template_activity_common_list_title') }}">
                            @foreach ($activityTabs as $phase)
                                <button type="button" role="tab" class="ui-tabs__tab"
                                        id="course-template-lesson-{{ $lesson->id }}-tab-{{ $phase }}"
                                        aria-controls="course-template-lesson-{{ $lesson->id }}-activity-list"
                                        aria-selected="{{ $phase === $activityDefaultPhase ? 'true' : 'false' }}"
                                        x-bind:aria-selected="(phase === '{{ $phase }}').toString()"
                                        x-on:click="phase = '{{ $phase }}'">
                                    {{ __('lf.LF_course_template_activity_learning_availability_'.$phase) }}
                                    <span class="ui-tabs__count">{{ $activityPhaseCounts[$phase] }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                    <div class="course-template-activity-list" id="course-template-lesson-{{ $lesson->id }}-activity-list"
                         @if ($activityTabs !== []) role="tabpanel" x-bind:aria-labelledby="'course-template-lesson-{{ $lesson->id }}-tab-' + phase" @endif>
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
                            <div class="course-template-activity-item"
                                 @if ($activityTabs !== [])
                                     x-show="@js(array_keys(array_filter(array_map(static fn (string $column): bool => (bool) ($activity->{$column} ?? false), $activityPhases)))).includes(phase)"
                                 @endif>
                                <div class="course-template-activity-identity">
                                    <span class="course-template-activity-icon course-template-activity-icon--{{ match ($activity->activity_type) {
                                        'video', 'embedded_video' => 'video',
                                        'audio' => 'audio',
                                        'quiz', 'assignment' => 'quiz',
                                        'live_class', 'liveclass' => 'live',
                                        default => 'document',
                                    } }}" aria-hidden="true">
                                        <x-backend-icon :name="$activityIcon" class="course-template-activity-type-icon" />
                                    </span>
                                    <div class="course-template-activity-copy">
                                        <span class="course-template-activity-title-text">
                                            {{ $activity->title }}
                                        </span>
                                        @php
                                            $activitySeconds = $activity->estimated_duration_seconds ?? $activity->duration_seconds ?? null;
                                        @endphp
                                        <span class="course-template-activity-meta">
                                            {{ \Illuminate\Support\Facades\Lang::has('lf.LF_course_template_activity_common_type_'.$activity->activity_type) ? __('lf.LF_course_template_activity_common_type_'.$activity->activity_type) : $activity->activity_type }}
                                            @if ($activitySeconds)
                                                · {{ __('lf.LF_course_template_activity_common_duration_minutes', ['minutes' => (int) ceil($activitySeconds / 60)]) }}
                                            @endif
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
                                            {{-- Ready is the normal state, so it is read out but not shown; only work in progress and problems are badged. --}}
                                            <div @class(['course-template-activity-structured-status', 'ui-sr-only' => $structuredStatus === 'ready'])
                                                 data-activity-status="{{ $activity->id }}:structured" data-status="{{ $structuredStatus }}"
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
                                                <span @class(['lf-secondary-text', 'ui-sr-only' => $structuredStatus !== 'failed'])>
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
                                            @php
                                                $speechMessage = __($speechMessageKey, ['minutes' => config('media.processing.speech_to_text.'.($activity->activity_type === 'video' ? 'max_video_duration_seconds' : 'max_duration_seconds')) / 60, 'gib' => config('media.processing.speech_to_text.'.($activity->activity_type === 'video' ? 'max_video_source_bytes' : 'max_bytes')) / 1073741824]);
                                            @endphp
                                            <div @class(['course-template-activity-structured-status', 'ui-sr-only' => $speechStatus === 'ready'])
                                                 data-activity-status="{{ $activity->id }}:speech" data-status="{{ $speechStatus }}" role="status">
                                                <span @class([
                                                    'badge',
                                                    'badge-success' => $speechStatus === 'ready',
                                                    'badge-danger' => $speechStatus === 'failed',
                                                    'badge-info' => in_array($speechStatus, ['pending', 'processing'], true),
                                                    'badge-secondary' => in_array($speechStatus, ['absent', 'disabled', 'unqualified'], true),
                                                    'badge-disabled' => $speechStatus === 'disabled',
                                                ]) title="{{ $speechMessage }}">
                                                    {{ __($speechLabelKey) }}
                                                </span>
                                                {{-- Only a failure is explained in the row; the other explanations are the badge's hover text and the start dialog. --}}
                                                <span @class(['lf-secondary-text', 'ui-sr-only' => $speechStatus !== 'failed'])>{{ $speechMessage }}</span>
                                                @if (in_array($speechStatus, ['absent', 'disabled'], true) && $activity->activity_type === 'audio')
                                                    @php
                                                        $sttDialogId = 'course-template-activity-'.$activity->id.'-stt-dialog';
                                                    @endphp
                                                    <button type="button" class="admin-link-button course-template-activity-stt-open cursor-interaction"
                                                            aria-haspopup="dialog" aria-controls="{{ $sttDialogId }}"
                                                            x-on:click="document.getElementById(@js($sttDialogId)).showModal()">
                                                        {{ __('lf.LF_course_template_activity_stt_initialize') }}
                                                    </button>
                                                    <dialog id="{{ $sttDialogId }}" class="ui-modal course-template-activity-stt-dialog" data-columns="1"
                                                            aria-labelledby="{{ $sttDialogId }}-title" x-data="{ checked: 0 }">
                                                        {{-- Sent with fetch so the page stays where it is (resources/js/course-template-activity-status.js). --}}
                                                        <form method="POST" class="ui-modal__form"
                                                              action="{{ route($activityRoutePrefix.'.initialize-transcription', $activityParameters) }}"
                                                              data-activity-status-start="{{ $activity->id }}:speech"
                                                              data-error="{{ __('lf.LF_course_template_activity_stt_initialize_failed') }}">
                                                            @csrf
                                                            <header class="ui-modal__header">
                                                                <div>
                                                                    <h3 id="{{ $sttDialogId }}-title">{{ __('lf.LF_course_template_activity_stt_initialize') }}</h3>
                                                                </div>
                                                                <button class="ui-modal__close cursor-interaction" type="button" x-on:click="$root.close()"
                                                                        aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
                                                            </header>
                                                            <div class="ui-modal__body">
                                                                <p class="ui-field ui-field--full course-template-activity-stt-dialog-text">{{ $speechMessage }}</p>
                                                                <fieldset class="ui-field ui-field--full ui-fieldset-reset">
                                                                    <legend class="ui-field__label">{{ __('lf.LF_course_template_activity_stt_initialize_locale') }}</legend>
                                                                    <div class="ui-check-list ui-check-list--inline">
                                                                        @foreach (['vi', 'ko', 'en'] as $localeCode)
                                                                            <label class="ui-check">
                                                                                <input type="checkbox" name="processing_locales[]" value="{{ $localeCode }}"
                                                                                       x-on:change="checked += $event.target.checked ? 1 : -1">
                                                                                <span>{{ __('lf.LF_common_language_'.$localeCode) }} ({{ $localeCode }})</span>
                                                                            </label>
                                                                        @endforeach
                                                                    </div>
                                                                </fieldset>
                                                            </div>
                                                            <footer class="ui-modal__footer">
                                                                <span class="ui-caption ui-field__error" role="alert" data-activity-status-error></span>
                                                                <button type="button" class="ui-button cursor-interaction" x-on:click="$root.close()">
                                                                    {{ __('lf.LF_common_button_cancel') }}
                                                                </button>
                                                                <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="checked === 0">
                                                                    <x-backend-icon name="check" class="ui-icon" />
                                                                    {{ __('lf.LF_common_button_confirm') }}
                                                                </button>
                                                            </footer>
                                                        </form>
                                                    </dialog>
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
                    <button type="button" class="admin-link-button admin-text-action course-template-activity-add"
                            data-ui-dialog-form-open aria-haspopup="dialog"
                            data-url="{{ $activityCreateUrl }}?phase={{ $activityDefaultPhase }}"
                            x-bind:data-url="@js($activityCreateUrl.'?phase=') + phase">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_course_template_activity_common_add_action') }}
                    </button>
                </section>
            </article>
        @empty
            @if ($section)
                <p class="course-template-lesson-empty">{{ $emptyMessage }}</p>
            @else
                <div class="course-template-content-empty">
                    <span class="course-template-content-empty-icon" aria-hidden="true">
                        <x-backend-icon name="plus" />
                    </span>
                    <strong>{{ __('lf.LF_course_template_lesson_common_direct_empty_title') }}</strong>
                    <p>{{ $emptyMessage }}</p>
                    <button type="button" class="btn admin-primary-outline-action ui-button ui-button--outline"
                            data-ui-dialog-form-open aria-haspopup="dialog"
                            data-url="{{ route($lessonRoutePrefix.'.create', $lessonBaseParameters) }}">
                        <x-backend-icon name="plus" class="ui-icon" />
                        {{ __('lf.LF_course_template_lesson_common_add_action') }}
                    </button>
                </div>
            @endif
        @endforelse
    </div>

    @if (! $section && $lessons->isNotEmpty())
        <p class="course-template-content-no-results" x-show="noResults" x-cloak role="status">
            {{ __('lf.LF_course_template_content_search_empty') }}
        </p>
        <button type="button" class="course-template-content-add-row"
                data-ui-dialog-form-open aria-haspopup="dialog"
                data-url="{{ route($lessonRoutePrefix.'.create', $lessonBaseParameters) }}">
            <x-backend-icon name="plus" class="ui-icon" />
            {{ __('lf.LF_course_template_content_add_lesson_row') }}
        </button>
    @endif

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
