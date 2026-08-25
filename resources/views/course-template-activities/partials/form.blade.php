@php
    $formActivity = $activity ?? null;
    $selectedActivityType = old('activity_type', $formActivity?->activity_type);
    $selectedRequired = (string) old('is_required', $formActivity?->is_required ?? 1);
    $selectedCompletionRule = old('completion_rule', $formActivity?->completion_rule ?? '');
    $selectedPreview = (string) old('is_preview', $formActivity?->is_preview ?? 0);
    $selectedUnlockRule = old('unlock_rule', $formActivity?->unlock_rule ?? 'none');
    $selectedPrerequisiteId = old('unlock_after_activity_id', $formActivity?->unlock_after_activity_id);
    $selectedLearningPhases = old('learning_phases');
    if ($selectedLearningPhases === null) {
        $selectedLearningPhases = $formActivity
            ? array_keys(array_filter([
                'anytime' => (bool) ($formActivity->available_anytime ?? true),
                'before_session' => (bool) ($formActivity->available_before_session ?? false),
                'during_session' => (bool) ($formActivity->available_during_session ?? false),
                'after_session' => (bool) ($formActivity->available_after_session ?? false),
            ]))
            : ['anytime'];
    }
    $storedEstimateMinutes = $formActivity?->estimated_duration_seconds !== null
        ? (int) ceil($formActivity->estimated_duration_seconds / 60)
        : null;
    $selectedEstimateMinutes = old(
        'estimated_duration_minutes',
        $storedEstimateMinutes
    );
    $storedDurationSeconds = (int) ($formActivity?->duration_seconds ?? 0);
    $completionRules = [
        'video' => ['view', 'watch_percent', 'manual'], 'audio' => ['view', 'watch_percent', 'manual'],
        'document' => ['view', 'manual'], 'embedded_video' => ['view', 'manual'],
        'quiz' => ['submit', 'pass', 'manual'], 'live_class' => ['join', 'manual'],
    ];
    $completionRuleLabels = collect(['view', 'watch_percent', 'submit', 'pass', 'join', 'manual'])
        ->mapWithKeys(fn (string $rule) => [$rule => __('lf.LF_course_template_activity_common_completion_'.$rule)])
        ->all();
    $completionRuleLabelsByType = [
        'audio' => [
            'view' => __('lf.LF_course_template_activity_common_completion_audio_view'),
            'watch_percent' => __('lf.LF_course_template_activity_common_completion_audio_watch_percent'),
        ],
    ];
@endphp

<div class="course-template-activity-form admin-form-flow"
     x-data="{
         activityType: @js($selectedActivityType ?? ''),
         initialActivityType: @js($formActivity?->activity_type),
         storedDurationSeconds: @js($storedDurationSeconds),
         storedEstimateMinutes: @js($storedEstimateMinutes),
         estimatedDurationMinutes: @js($selectedEstimateMinutes),
         mediaDurationSeconds: @js($storedDurationSeconds > 0 ? $storedDurationSeconds : null),
         mediaDurationState: @js($storedDurationSeconds > 0 ? 'ready' : 'empty'),
         mediaTypeError: '',
         completionRule: @js($selectedCompletionRule),
         unlockRule: @js($selectedUnlockRule),
         learningPhases: @js(array_values((array) $selectedLearningPhases)),
         completionRules: @js($completionRules),
         completionRuleLabels: @js($completionRuleLabels),
         completionRuleLabelsByType: @js($completionRuleLabelsByType),
         completionRuleLabel(rule) {
             return this.completionRuleLabelsByType[this.activityType]?.[rule]
                 ?? this.completionRuleLabels[rule];
         },
         activityTypeChanged() {
             this.completionRule = '';
             const automatic = ['video', 'audio'].includes(this.activityType);
             const unchanged = this.activityType === this.initialActivityType;
             this.mediaDurationSeconds = automatic && unchanged && this.storedDurationSeconds > 0
                 ? this.storedDurationSeconds
                 : null;
             this.mediaDurationState = this.mediaDurationSeconds ? 'ready' : 'empty';
             this.mediaTypeError = '';
             this.estimatedDurationMinutes = unchanged
                 ? this.storedEstimateMinutes
                 : null;
         },
         readMediaDuration(event, expectedType) {
             const file = event.target.files?.[0];
             if (! file) {
                 this.activityTypeChanged();
                 return;
             }
             const extension = file.name.split('.').pop()?.toLowerCase() || '';
             const allowedExtensions = {
                 video: ['mp4', 'webm', 'mov', 'avi'],
                 audio: ['mp3', 'wav', 'm4a', 'aac', 'ogg'],
             };
             const matchesType = file.type.startsWith(`${expectedType}/`)
                 && allowedExtensions[expectedType].includes(extension);
             if (! matchesType) {
                 event.target.value = '';
                 this.mediaDurationSeconds = null;
                 this.mediaDurationState = 'invalid_type';
                 this.mediaTypeError = expectedType;
                 return;
             }
             this.mediaTypeError = '';
             this.mediaDurationState = 'probing';
             this.mediaDurationSeconds = null;
             const objectUrl = URL.createObjectURL(file);
             const media = document.createElement(
                 this.activityType === 'audio' ? 'audio' : 'video'
             );
             media.preload = 'metadata';
             media.onloadedmetadata = () => {
                 const duration = Number.isFinite(media.duration) && media.duration > 0
                     ? Math.ceil(media.duration)
                     : null;
                 this.mediaDurationSeconds = duration;
                 this.mediaDurationState = duration ? 'ready' : 'unavailable';
                 this.estimatedDurationMinutes = duration
                     ? Math.ceil(duration / 60)
                     : null;
                 URL.revokeObjectURL(objectUrl);
             };
             media.onerror = () => {
                 this.mediaDurationSeconds = null;
                 this.mediaDurationState = 'unavailable';
                 URL.revokeObjectURL(objectUrl);
             };
             media.src = objectUrl;
         },
         formattedMediaDuration() {
             if (! this.mediaDurationSeconds) return '—';
             const hours = Math.floor(this.mediaDurationSeconds / 3600);
             const minutes = Math.floor((this.mediaDurationSeconds % 3600) / 60);
             const seconds = this.mediaDurationSeconds % 60;
             return [
                 hours ? `${hours} giờ` : '',
                 minutes ? `${minutes} phút` : '',
                 seconds || (! hours && ! minutes) ? `${seconds} giây` : '',
             ].filter(Boolean).join(' ');
         },
         toggleLearningPhase(phase) {
             if (phase === 'anytime' && this.learningPhases.includes('anytime')) {
                 this.learningPhases = ['anytime'];
                 return;
             }
             if (phase !== 'anytime' && this.learningPhases.includes(phase)) {
                 this.learningPhases = this.learningPhases.filter((item) => item !== 'anytime');
             }
         },
         mediaPreviewOpen: false,
         mediaPreview: { name: '', url: '', mimeType: '', type: '' },
         openActivityMediaPreview(name, url, mimeType, type) {
             this.closeActivityMediaPreview();
             this.mediaPreview = { name, url, mimeType, type };
             this.mediaPreviewOpen = true;
             this.$nextTick(() => {
                 const player = type === 'audio'
                     ? this.$refs.activityMediaAudioPlayer
                     : (type === 'video' ? this.$refs.activityMediaVideoPlayer : null);
                 if (! player) return;
                 player.load();
                 player.play().catch(() => {});
             });
         },
         closeActivityMediaPreview() {
             [this.$refs.activityMediaVideoPlayer, this.$refs.activityMediaAudioPlayer]
                 .filter(Boolean)
                 .forEach((player) => {
                     player.pause();
                     player.removeAttribute('src');
                     player.load();
                 });
             this.mediaPreviewOpen = false;
             this.mediaPreview = { name: '', url: '', mimeType: '', type: '' };
         },
     }"
     x-on:keydown.escape.window="closeActivityMediaPreview()">
    <section class="admin-form-standard-section"
             aria-labelledby="activity-information-section-title">
        <header class="admin-form-section-header">
            <h2 id="activity-information-section-title" class="admin-form-section-title">
                {{ __('lf.LF_course_template_activity_section_information') }}
            </h2>
            <p class="admin-form-section-help">
                {{ __('lf.LF_course_template_activity_section_information_help') }}
            </p>
        </header>
        <div class="admin-form-field-grid course-template-activity-information-grid">
    <div class="lf-form-group admin-form-field--full">
        <x-form-label for="title" :value="__('lf.LF_course_template_activity_common_name')" :required="true" />
        <input id="title" type="text" name="title" class="lf-form-control" value="{{ old('title', $formActivity?->title) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_name') }}" required maxlength="255">
    </div>
    <div class="lf-form-group admin-form-field--full">
        <x-form-label for="description" :value="__('lf.LF_course_template_activity_common_description')" />
        <textarea id="description" name="description" class="lf-form-control" rows="4" placeholder="{{ __('lf.LF_course_template_activity_placeholder_description') }}">{{ old('description', $formActivity?->description) }}</textarea>
    </div>
    <div class="lf-form-group course-template-activity-type-field">
        <x-form-label for="activity_type" :value="__('lf.LF_course_template_activity_common_type')" :required="true" />
        <select id="activity_type" name="activity_type" class="lf-form-control" x-model="activityType" :class="{ 'is-lf-placeholder': activityType === '', 'has-value': activityType !== '' }" @change="activityTypeChanged()" required>
            <option value="" disabled @selected(blank($selectedActivityType))>{{ __('lf.LF_course_template_activity_common_select_type') }}</option>
            @foreach ($activityTypes as $activityType)<option value="{{ $activityType }}" @selected($selectedActivityType === $activityType)>{{ __('lf.LF_course_template_activity_common_type_'.$activityType) }}</option>@endforeach
        </select>
    </div>
    <div class="lf-form-group admin-form-conditional course-template-activity-source-field" x-show="activityType === 'video'" x-cloak>
        <div class="authoring-media-picker-row">
            @include('course-template-activities.partials.current-media', ['mediaType' => 'video'])
            <x-authoring-media-upload id="activity_video_file" name="activity_video_file" :label="($currentActivityMedia['type'] ?? null) === 'video' ? __('lf.LF_media_replace_video') : __('lf.LF_media_upload_video')" accept=".mp4,.webm,.mov,.avi,video/mp4,video/webm,video/quicktime,video/x-msvideo" x-on:change="readMediaDuration($event, 'video')" />
        </div>
        <x-upload-hint :formats="['MP4', 'WEBM', 'MOV', 'AVI']" />
        <p class="lf-form-error"
           role="alert"
           x-show="mediaDurationState === 'invalid_type' && mediaTypeError === 'video'">
            {{ __('lf.LF_course_template_activity_media_invalid_video') }}
        </p>
    </div>
    <div class="lf-form-group admin-form-conditional course-template-activity-source-field" x-show="activityType === 'embedded_video'" x-cloak><x-form-label for="external_video_url" value="External video URL" /><input id="external_video_url" type="url" name="external_video_url" class="lf-form-control" value="{{ old('external_video_url', $formActivity?->external_video_url) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_video_url') }}"></div>
    <div class="lf-form-group admin-form-conditional course-template-activity-source-field" x-show="activityType === 'audio'" x-cloak>
        <div class="authoring-media-picker-row">
            @include('course-template-activities.partials.current-media', ['mediaType' => 'audio'])
            <x-authoring-media-upload id="activity_audio_file" name="activity_audio_file" :label="($currentActivityMedia['type'] ?? null) === 'audio' ? __('lf.LF_media_replace_audio') : __('lf.LF_media_upload_audio')" accept=".mp3,.wav,.m4a,.aac,.ogg" x-on:change="readMediaDuration($event, 'audio')" />
        </div>
        <x-upload-hint :formats="['MP3', 'WAV', 'M4A', 'AAC', 'OGG']" />
        <p class="lf-form-error"
           role="alert"
           x-show="mediaDurationState === 'invalid_type' && mediaTypeError === 'audio'">
            {{ __('lf.LF_course_template_activity_media_invalid_audio') }}
        </p>
    </div>
    <div class="lf-form-group admin-form-conditional course-template-activity-source-field" x-show="activityType === 'document'" x-cloak>
        <div class="authoring-media-picker-row">
            @include('course-template-activities.partials.current-media', ['mediaType' => 'document'])
            <x-authoring-media-upload id="activity_document_file" name="activity_document_file" :label="($currentActivityMedia['type'] ?? null) === 'document' ? __('lf.LF_media_replace_document') : __('lf.LF_media_upload_document')" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,application/pdf" />
        </div>
        <x-upload-hint :formats="['PDF', 'DOC', 'DOCX', 'XLS', 'XLSX', 'PPT', 'PPTX', 'TXT']" />
    </div>
    <div class="lf-form-group admin-form-conditional course-template-activity-source-field"
         x-show="['video', 'audio', 'document'].includes(activityType)"
         x-cloak>
        <x-form-label for="processing_locale" value="Ngôn ngữ nội dung (BCP 47)" />
        <input id="processing_locale"
               type="text"
               name="processing_locale"
               class="lf-form-control"
               value="{{ old('processing_locale') }}"
               maxlength="20"
               placeholder="vi, ko, en-US">
        <p class="lf-form-help lf-secondary-text">Bắt buộc khi tải Media mới; không tự suy luận từ trình duyệt hoặc mô hình.</p>
    </div>
    <div class="lf-form-group admin-form-conditional course-template-activity-source-field" x-show="activityType === 'quiz'" x-cloak><x-form-label for="assessment_quiz_id" value="Assessment Quiz ID" /><input id="assessment_quiz_id" type="number" min="1" name="assessment_quiz_id" class="lf-form-control" value="{{ old('assessment_quiz_id', $formActivity?->assessment_quiz_id) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_assessment') }}"></div>
    <div class="lf-form-group admin-form-conditional course-template-activity-source-field" x-show="activityType === 'live_class'" x-cloak><x-form-label for="live_class_url" value="Live class URL" /><input id="live_class_url" type="url" name="live_class_url" class="lf-form-control" value="{{ old('live_class_url', $formActivity?->live_class_url) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_live_class_url') }}"></div>
    <div class="lf-form-group course-template-activity-duration-field">
        <x-form-label for="estimated_duration_minutes" :value="__('lf.LF_course_template_activity_common_estimated_duration_minutes')" />
        <input id="estimated_duration_minutes"
               type="number"
               min="1"
               step="1"
               name="estimated_duration_minutes"
               class="lf-form-control"
               x-model.number="estimatedDurationMinutes"
               placeholder="{{ __('lf.LF_course_template_activity_placeholder_estimated_duration') }}">
        <p class="lf-form-help lf-secondary-text"
           x-show="!['video', 'audio'].includes(activityType)">
            {{ __('lf.LF_course_template_activity_common_estimated_duration_help') }}
        </p>
        <p class="lf-form-help lf-secondary-text"
           aria-live="polite"
           x-show="['video', 'audio'].includes(activityType) && mediaDurationState === 'ready'">
            {{ __('lf.LF_course_template_activity_media_actual_duration') }}
            <strong x-text="formattedMediaDuration()"></strong>.
            {{ __('lf.LF_course_template_activity_media_estimated_duration_help') }}
        </p>
        <p class="lf-form-help lf-secondary-text"
           x-show="['video', 'audio'].includes(activityType) && mediaDurationState === 'probing'">
            {{ __('lf.LF_course_template_activity_media_duration_probing') }}
        </p>
        <p class="lf-form-help lf-secondary-text"
           x-show="['video', 'audio'].includes(activityType) && ['empty', 'unavailable'].includes(mediaDurationState)">
            {{ __('lf.LF_course_template_activity_media_duration_unknown') }}
        </p>
    </div>
        </div>
    </section>

    <section class="admin-form-standard-section"
             aria-labelledby="activity-availability-section-title">
        <header class="admin-form-section-header">
            <h2 id="activity-availability-section-title" class="admin-form-section-title">
                {{ __('lf.LF_course_template_activity_section_availability') }}
            </h2>
            <p class="admin-form-section-help">
                {{ __('lf.LF_course_template_activity_learning_availability_help') }}
            </p>
        </header>
        <div class="admin-form-field-grid">
    <fieldset class="lf-form-group admin-form-field--full">
        <legend class="lf-form-label">
            {{ __('lf.LF_course_template_activity_learning_availability') }}
            <span class="lf-required-indicator" aria-hidden="true">*</span>
        </legend>
        <input type="hidden" name="learning_phases_present" value="1">
        <div class="admin-checkbox-list">
            @foreach (['anytime', 'before_session', 'during_session', 'after_session'] as $phase)
                <label class="admin-checkbox-option admin-form-option-panel admin-form-option-panel--compact">
                    <input type="checkbox"
                           name="learning_phases[]"
                           value="{{ $phase }}"
                           x-model="learningPhases"
                           x-on:change="toggleLearningPhase(@js($phase))">
                    <span>{{ __('lf.LF_course_template_activity_learning_availability_'.$phase) }}</span>
                </label>
            @endforeach
        </div>
        @error('learning_phases')
            <p class="lf-form-error" role="alert">{{ $message }}</p>
        @enderror
    </fieldset>
        </div>
    </section>

    <section class="admin-form-standard-section"
             aria-labelledby="activity-rules-section-title">
        <header class="admin-form-section-header">
            <h2 id="activity-rules-section-title" class="admin-form-section-title">
                {{ __('lf.LF_course_template_activity_section_rules') }}
            </h2>
            <p class="admin-form-section-help">
                {{ __('lf.LF_course_template_activity_section_rules_help') }}
            </p>
        </header>
        <div class="admin-form-field-grid">
    <div class="lf-form-group"><x-form-label for="completion_rule" :value="__('lf.LF_course_template_activity_common_completion_rule')" :required="true" /><select id="completion_rule" name="completion_rule" class="lf-form-control" x-model="completionRule" :class="{ 'is-lf-placeholder': completionRule === '', 'has-value': completionRule !== '' }" required><option value="" disabled x-text="activityType ? @js(__('lf.LF_course_template_activity_placeholder_completion_rule')) : @js(__('lf.LF_course_template_activity_common_select_type_first'))"></option><template x-for="rule in (completionRules[activityType] || [])" :key="rule"><option :value="rule" x-text="completionRuleLabel(rule)"></option></template></select></div>
    <div class="lf-form-group" x-show="['watch_percent', 'pass'].includes(completionRule)" x-cloak><x-form-label for="completion_threshold" :value="__('lf.LF_course_template_activity_common_completion_threshold')" /><input id="completion_threshold" type="number" min="1" max="100" name="completion_threshold" class="lf-form-control" value="{{ old('completion_threshold', $formActivity?->completion_threshold) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_completion_threshold_percent') }}"></div>
    <div class="lf-form-group"><x-form-label for="is_preview" :value="__('lf.LF_course_template_activity_common_preview')" :required="true" /><select id="is_preview" name="is_preview" class="lf-form-control" required><option value="0" @selected($selectedPreview === '0')>{{ __('lf.LF_course_template_activity_common_no') }}</option><option value="1" @selected($selectedPreview === '1')>{{ __('lf.LF_course_template_activity_common_yes') }}</option></select></div>
    <div class="lf-form-group"><x-form-label for="unlock_rule" :value="__('lf.LF_course_template_activity_common_unlock_rule')" :required="true" /><select id="unlock_rule" name="unlock_rule" class="lf-form-control" x-model="unlockRule" :class="{ 'is-lf-placeholder': unlockRule === '', 'has-value': unlockRule !== '' }" required><option value="" disabled>{{ __('lf.LF_course_template_activity_placeholder_unlock_rule') }}</option>@foreach (['none','previous_activity_completed'] as $rule)<option value="{{ $rule }}">{{ __('lf.LF_course_template_activity_common_unlock_'.$rule) }}</option>@endforeach</select></div>
    <div class="lf-form-group" x-show="unlockRule === 'previous_activity_completed'" x-cloak><x-form-label for="unlock_after_activity_id" :value="__('lf.LF_course_template_activity_common_unlock_after_activity')" /><select id="unlock_after_activity_id" name="unlock_after_activity_id" class="lf-form-control"><option value="">{{ __('lf.LF_course_template_activity_placeholder_prerequisite') }}</option>@foreach ($prerequisiteActivities as $item)<option value="{{ $item->id }}" @selected((string)$selectedPrerequisiteId === (string)$item->id)>{{ $item->title }}</option>@endforeach</select></div>
    <div class="lf-form-group"><x-form-label for="is_required" :value="__('lf.LF_course_template_activity_common_required')" :required="true" /><select id="is_required" name="is_required" class="lf-form-control" required><option value="" disabled>{{ __('lf.LF_course_template_activity_placeholder_yes_no') }}</option><option value="1" @selected($selectedRequired === '1')>{{ __('lf.LF_course_template_activity_common_yes') }}</option><option value="0" @selected($selectedRequired === '0')>{{ __('lf.LF_course_template_activity_common_no') }}</option></select></div>
    <div class="lf-form-group"><x-form-label for="sort_order" :value="__('lf.LF_course_template_activity_common_sort_order')" /><input id="sort_order" type="number" min="0" name="sort_order" class="lf-form-control" value="{{ old('sort_order', $formActivity?->sort_order ?? $suggestedSortOrder ?? null) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_sort_order') }}"></div>
        </div>
    </section>
    <div class="media-library-modal"
         x-cloak
         x-show="mediaPreviewOpen"
         x-transition.opacity
         role="dialog"
         aria-modal="true"
         aria-labelledby="course-activity-media-preview-title">
        <button type="button"
                class="media-library-modal-backdrop"
                aria-label="{{ __('lf.LF_common_button_cancel') }}"
                x-on:click="closeActivityMediaPreview()"></button>
        <div class="media-library-modal-panel">
            <div class="media-library-modal-header">
                <h2 id="course-activity-media-preview-title" x-text="mediaPreview.name"></h2>
                <button type="button"
                        class="admin-link-button admin-text-action"
                        x-on:click="closeActivityMediaPreview()">
                    {{ __('lf.LF_common_button_cancel') }}
                </button>
            </div>
            <div class="media-library-modal-body">
                <video x-ref="activityMediaVideoPlayer"
                       x-show="mediaPreview.type === 'video'"
                       x-bind:src="mediaPreview.type === 'video' ? mediaPreview.url : ''"
                       controls preload="metadata"
                       class="media-library-modal-video"></video>
                <audio x-ref="activityMediaAudioPlayer"
                       x-show="mediaPreview.type === 'audio'"
                       x-bind:src="mediaPreview.type === 'audio' ? mediaPreview.url : ''"
                       controls preload="metadata"
                       class="course-activity-media-audio-player"></audio>
            </div>
        </div>
    </div>
</div>
