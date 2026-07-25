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
        && $formActivity->estimated_duration_seconds % 60 === 0
            ? intdiv($formActivity->estimated_duration_seconds, 60) : null;
    $completionRules = [
        'video' => ['view', 'watch_percent', 'manual'], 'audio' => ['view', 'watch_percent', 'manual'],
        'document' => ['view', 'manual'], 'embedded_video' => ['view', 'manual'],
        'quiz' => ['submit', 'pass', 'manual'], 'live_class' => ['manual'],
    ];
    $completionRuleLabels = collect(['view', 'watch_percent', 'submit', 'pass', 'join', 'manual'])
        ->mapWithKeys(fn (string $rule) => [$rule => __('lf.LF_course_template_activity_common_completion_'.$rule)])
        ->all();
@endphp

<div class="course-template-activity-form admin-form-flow"
     x-data="{
         activityType: @js($selectedActivityType ?? ''),
         completionRule: @js($selectedCompletionRule),
         unlockRule: @js($selectedUnlockRule),
         learningPhases: @js(array_values((array) $selectedLearningPhases)),
         completionRules: @js($completionRules),
         completionRuleLabels: @js($completionRuleLabels),
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
                     : this.$refs.activityMediaVideoPlayer;
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
        <div class="admin-form-field-grid">
    <div class="lf-form-group admin-form-field--full">
        <x-form-label for="title" :value="__('lf.LF_course_template_activity_common_name')" :required="true" />
        <input id="title" type="text" name="title" class="lf-form-control" value="{{ old('title', $formActivity?->title) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_name') }}" required maxlength="255">
    </div>
    <div class="lf-form-group admin-form-field--full">
        <x-form-label for="description" :value="__('lf.LF_course_template_activity_common_description')" />
        <textarea id="description" name="description" class="lf-form-control" rows="4" placeholder="{{ __('lf.LF_course_template_activity_placeholder_description') }}">{{ old('description', $formActivity?->description) }}</textarea>
    </div>
    <div class="lf-form-group">
        <x-form-label for="activity_type" :value="__('lf.LF_course_template_activity_common_type')" :required="true" />
        <select id="activity_type" name="activity_type" class="lf-form-control" x-model="activityType" :class="{ 'lf-select-placeholder': activityType === '' }" @change="completionRule = ''" required>
            <option value="" disabled @selected(blank($selectedActivityType))>{{ __('lf.LF_course_template_activity_common_select_type') }}</option>
            @foreach ($activityTypes as $activityType)<option value="{{ $activityType }}" @selected($selectedActivityType === $activityType)>{{ __('lf.LF_course_template_activity_common_type_'.$activityType) }}</option>@endforeach
        </select>
    </div>
    <div class="lf-form-group admin-form-field--full admin-form-conditional" x-show="activityType === 'video'" x-cloak>
        <x-form-label for="activity_video_file" :value="__('lf.LF_course_template_activity_media_replacement_video')" />
        @include('course-template-activities.partials.current-media', ['mediaType' => 'video'])
        <input id="activity_video_file" type="file" name="activity_video_file" class="lf-form-control authoring-media-upload admin-file-upload" accept="video/*">
        <x-upload-hint :formats="['MP4', 'WEBM', 'MOV', 'AVI']" />
    </div>
    <div class="lf-form-group admin-form-field--full admin-form-conditional" x-show="activityType === 'embedded_video'" x-cloak><x-form-label for="external_video_url" value="External video URL" /><input id="external_video_url" type="url" name="external_video_url" class="lf-form-control" value="{{ old('external_video_url', $formActivity?->external_video_url) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_video_url') }}"></div>
    <div class="lf-form-group admin-form-field--full admin-form-conditional" x-show="activityType === 'audio'" x-cloak>
        <x-form-label for="activity_audio_file" :value="__('lf.LF_course_template_activity_media_replacement_audio')" />
        @include('course-template-activities.partials.current-media', ['mediaType' => 'audio'])
        <input id="activity_audio_file" type="file" name="activity_audio_file" class="lf-form-control authoring-media-upload admin-file-upload" accept="audio/*">
        <x-upload-hint :formats="['MP3', 'WAV', 'M4A', 'AAC', 'OGG']" />
    </div>
    <div class="lf-form-group admin-form-field--full admin-form-conditional" x-show="activityType === 'document'" x-cloak>
        <x-form-label for="activity_document_file" :value="__('lf.LF_course_template_activity_media_replacement_document')" />
        @include('course-template-activities.partials.current-media', ['mediaType' => 'document'])
        <input id="activity_document_file" type="file" name="activity_document_file" class="lf-form-control authoring-media-upload admin-file-upload" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,application/pdf">
        <x-upload-hint :formats="['PDF', 'DOC', 'DOCX', 'XLS', 'XLSX', 'PPT', 'PPTX', 'TXT']" />
    </div>
    <div class="lf-form-group admin-form-field--full admin-form-conditional" x-show="activityType === 'quiz'" x-cloak><x-form-label for="assessment_quiz_id" value="Assessment Quiz ID" /><input id="assessment_quiz_id" type="number" min="1" name="assessment_quiz_id" class="lf-form-control" value="{{ old('assessment_quiz_id', $formActivity?->assessment_quiz_id) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_assessment') }}"></div>
    <div class="lf-form-group admin-form-field--full admin-form-conditional" x-show="activityType === 'live_class'" x-cloak><x-form-label for="live_class_url" value="Live class URL" /><input id="live_class_url" type="url" name="live_class_url" class="lf-form-control" value="{{ old('live_class_url', $formActivity?->live_class_url) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_live_class_url') }}"></div>
    <div class="lf-form-group">
        <x-form-label for="estimated_duration_minutes" :value="__('lf.LF_course_template_activity_common_estimated_duration_minutes')" />
        <input id="estimated_duration_minutes" type="number" min="1" step="1" name="estimated_duration_minutes" class="lf-form-control" value="{{ old('estimated_duration_minutes', $storedEstimateMinutes) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_estimated_duration') }}">
        <p class="lf-form-help lf-secondary-text">{{ __('lf.LF_course_template_activity_common_estimated_duration_help') }}</p>
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
    <div class="lf-form-group"><x-form-label for="completion_rule" :value="__('lf.LF_course_template_activity_common_completion_rule')" :required="true" /><select id="completion_rule" name="completion_rule" class="lf-form-control" x-model="completionRule" :class="{ 'lf-select-placeholder': completionRule === '' }" required><option value="" disabled x-text="activityType ? @js(__('lf.LF_course_template_activity_placeholder_completion_rule')) : @js(__('lf.LF_course_template_activity_common_select_type_first'))"></option><template x-for="rule in (completionRules[activityType] || [])" :key="rule"><option :value="rule" x-text="completionRuleLabels[rule]"></option></template></select></div>
    <div class="lf-form-group" x-show="['watch_percent', 'pass'].includes(completionRule)" x-cloak><x-form-label for="completion_threshold" :value="__('lf.LF_course_template_activity_common_completion_threshold')" /><input id="completion_threshold" type="number" min="1" max="100" name="completion_threshold" class="lf-form-control" value="{{ old('completion_threshold', $formActivity?->completion_threshold) }}" placeholder="{{ __('lf.LF_course_template_activity_placeholder_completion_threshold_percent') }}"></div>
    <div class="lf-form-group"><x-form-label for="is_preview" :value="__('lf.LF_course_template_activity_common_preview')" :required="true" /><select id="is_preview" name="is_preview" class="lf-form-control" required><option value="0" @selected($selectedPreview === '0')>{{ __('lf.LF_course_template_activity_common_no') }}</option><option value="1" @selected($selectedPreview === '1')>{{ __('lf.LF_course_template_activity_common_yes') }}</option></select></div>
    <div class="lf-form-group"><x-form-label for="unlock_rule" :value="__('lf.LF_course_template_activity_common_unlock_rule')" :required="true" /><select id="unlock_rule" name="unlock_rule" class="lf-form-control" x-model="unlockRule" :class="{ 'lf-select-placeholder': unlockRule === '' }" required><option value="" disabled>{{ __('lf.LF_course_template_activity_placeholder_unlock_rule') }}</option>@foreach (['none','previous_activity_completed'] as $rule)<option value="{{ $rule }}">{{ __('lf.LF_course_template_activity_common_unlock_'.$rule) }}</option>@endforeach</select></div>
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
