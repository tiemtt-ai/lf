@php
    $formActivity = $activity ?? null;
    $selectedActivityType = old('activity_type', $formActivity?->activity_type);
    $selectedRequired = (string) old('is_required', $formActivity?->is_required ?? 1);
    $selectedCompletionRule = old('completion_rule', $formActivity?->completion_rule ?? 'view');
    $selectedPreview = (string) old('is_preview', $formActivity?->is_preview ?? 0);
    $selectedUnlockRule = old('unlock_rule', $formActivity?->unlock_rule ?? 'none');
    $selectedPrerequisiteId = old('unlock_after_activity_id', $formActivity?->unlock_after_activity_id);
    $unlockAt = old('unlock_at', $formActivity?->unlock_at ? \Illuminate\Support\Carbon::parse($formActivity->unlock_at)->format('Y-m-d\TH:i') : null);
    $completionRules = [
        'video' => ['view', 'watch_percent', 'manual'], 'audio' => ['view', 'watch_percent', 'manual'],
        'document' => ['view', 'manual'], 'embedded_video' => ['view', 'manual'],
        'quiz' => ['submit', 'pass', 'manual'], 'live_class' => ['manual'],
    ];
    $completionRuleLabels = collect(['view', 'watch_percent', 'submit', 'pass', 'manual'])
        ->mapWithKeys(fn (string $rule) => [$rule => __('lf.LF_course_template_activity_common_completion_'.$rule)])
        ->all();
@endphp

<div class="course-template-activity-form"
     x-data="{ activityType: @js($selectedActivityType ?? ''), completionRule: @js($selectedCompletionRule), unlockRule: @js($selectedUnlockRule), completionRules: @js($completionRules), completionRuleLabels: @js($completionRuleLabels) }">
    <div class="lf-form-group course-template-activity-form-wide">
        <x-form-label for="title" :value="__('lf.LF_course_template_activity_common_name')" :required="true" />
        <input id="title" type="text" name="title" class="lf-form-control" value="{{ old('title', $formActivity?->title) }}" required maxlength="255">
    </div>
    <div class="lf-form-group course-template-activity-form-wide">
        <x-form-label for="description" :value="__('lf.LF_course_template_activity_common_description')" />
        <textarea id="description" name="description" class="lf-form-control" rows="4">{{ old('description', $formActivity?->description) }}</textarea>
    </div>
    <div class="lf-form-group">
        <x-form-label for="activity_type" :value="__('lf.LF_course_template_activity_common_type')" :required="true" />
        <select id="activity_type" name="activity_type" class="lf-form-control" x-model="activityType" @change="completionRule = (completionRules[activityType] || [])[0] || ''" required>
            <option value="" disabled @selected(blank($selectedActivityType))>{{ __('lf.LF_course_template_activity_common_select_type') }}</option>
            @foreach ($activityTypes as $activityType)<option value="{{ $activityType }}" @selected($selectedActivityType === $activityType)>{{ __('lf.LF_course_template_activity_common_type_'.$activityType) }}</option>@endforeach
        </select>
    </div>
    <div class="lf-form-group" x-show="activityType === 'video'" x-cloak><x-form-label for="activity_video_file" value="Video file" /><input id="activity_video_file" type="file" name="activity_video_file" class="lf-form-control" accept="video/*"><x-upload-hint :formats="['MP4', 'WEBM', 'MOV', 'AVI']" /></div>
    <div class="lf-form-group" x-show="activityType === 'embedded_video'" x-cloak><x-form-label for="external_video_url" value="External video URL" /><input id="external_video_url" type="url" name="external_video_url" class="lf-form-control" value="{{ old('external_video_url', $formActivity?->external_video_url) }}"></div>
    <div class="lf-form-group" x-show="activityType === 'audio'" x-cloak><x-form-label for="activity_audio_file" value="Audio file" /><input id="activity_audio_file" type="file" name="activity_audio_file" class="lf-form-control" accept="audio/*"><x-upload-hint :formats="['MP3', 'WAV', 'M4A', 'AAC', 'OGG']" /></div>
    <div class="lf-form-group" x-show="activityType === 'document'" x-cloak><x-form-label for="activity_document_file" value="Document file" /><input id="activity_document_file" type="file" name="activity_document_file" class="lf-form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,application/pdf"><x-upload-hint :formats="['PDF', 'DOC', 'DOCX', 'XLS', 'XLSX', 'PPT', 'PPTX', 'TXT']" /></div>
    <div class="lf-form-group" x-show="activityType === 'quiz'" x-cloak><x-form-label for="assessment_quiz_id" value="Assessment Quiz ID" /><input id="assessment_quiz_id" type="number" min="1" name="assessment_quiz_id" class="lf-form-control" value="{{ old('assessment_quiz_id', $formActivity?->assessment_quiz_id) }}"></div>
    <div class="lf-form-group" x-show="activityType === 'live_class'" x-cloak><x-form-label for="live_class_url" value="Live class URL" /><input id="live_class_url" type="url" name="live_class_url" class="lf-form-control" value="{{ old('live_class_url', $formActivity?->live_class_url) }}"></div>
    <div class="lf-form-group"><x-form-label for="is_required" :value="__('lf.LF_course_template_activity_common_required')" :required="true" /><select id="is_required" name="is_required" class="lf-form-control" required><option value="1" @selected($selectedRequired === '1')>{{ __('lf.LF_course_template_activity_common_yes') }}</option><option value="0" @selected($selectedRequired === '0')>{{ __('lf.LF_course_template_activity_common_no') }}</option></select></div>
    <div class="lf-form-group"><x-form-label for="completion_rule" :value="__('lf.LF_course_template_activity_common_completion_rule')" :required="true" /><select id="completion_rule" name="completion_rule" class="lf-form-control" x-model="completionRule" required><option value="" disabled x-show="!activityType">{{ __('lf.LF_course_template_activity_common_select_type_first') }}</option><template x-for="rule in (completionRules[activityType] || [])" :key="rule"><option :value="rule" x-text="completionRuleLabels[rule]"></option></template></select></div>
    <div class="lf-form-group" x-show="['watch_percent', 'pass'].includes(completionRule)" x-cloak><x-form-label for="completion_threshold" :value="__('lf.LF_course_template_activity_common_completion_threshold')" /><input id="completion_threshold" type="number" min="0" max="100" name="completion_threshold" class="lf-form-control" value="{{ old('completion_threshold', $formActivity?->completion_threshold) }}"></div>
    <div class="lf-form-group"><x-form-label for="is_preview" :value="__('lf.LF_course_template_activity_common_preview')" :required="true" /><select id="is_preview" name="is_preview" class="lf-form-control" required><option value="0" @selected($selectedPreview === '0')>{{ __('lf.LF_course_template_activity_common_no') }}</option><option value="1" @selected($selectedPreview === '1')>{{ __('lf.LF_course_template_activity_common_yes') }}</option></select></div>
    <div class="lf-form-group"><x-form-label for="unlock_rule" :value="__('lf.LF_course_template_activity_common_unlock_rule')" :required="true" /><select id="unlock_rule" name="unlock_rule" class="lf-form-control" x-model="unlockRule" required>@foreach (['none','previous_activity_completed','date_based'] as $rule)<option value="{{ $rule }}">{{ __('lf.LF_course_template_activity_common_unlock_'.$rule) }}</option>@endforeach</select></div>
    <div class="lf-form-group" x-show="unlockRule === 'previous_activity_completed'" x-cloak><x-form-label for="unlock_after_activity_id" :value="__('lf.LF_course_template_activity_common_unlock_after_activity')" /><select id="unlock_after_activity_id" name="unlock_after_activity_id" class="lf-form-control"><option value="">{{ __('lf.LF_course_template_activity_common_no_prerequisite') }}</option>@foreach ($prerequisiteActivities as $item)<option value="{{ $item->id }}" @selected((string)$selectedPrerequisiteId === (string)$item->id)>{{ $item->title }}</option>@endforeach</select></div>
    <div class="lf-form-group" x-show="unlockRule === 'date_based'" x-cloak><x-form-label for="unlock_at" :value="__('lf.LF_course_template_activity_common_unlock_at')" /><input id="unlock_at" type="datetime-local" name="unlock_at" class="lf-form-control" value="{{ $unlockAt }}"></div>
</div>
