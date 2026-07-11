@php
    $formLesson = $lesson ?? null;
    $selectedPreview = (string) old('is_preview', $formLesson?->is_preview ?? 0);
    $selectedUnlockRule = old('unlock_rule', $formLesson?->unlock_rule ?? 'none');
    $selectedPrerequisiteId = old('unlock_after_lesson_id', $formLesson?->unlock_after_lesson_id);
    $unlockAt = old('unlock_at', $formLesson?->unlock_at
        ? \Illuminate\Support\Carbon::parse($formLesson->unlock_at)->format('Y-m-d\TH:i') : null);
@endphp

<div class="course-template-lesson-form"
     x-data="{
        unlockRule: @js($selectedUnlockRule),
        changeRule(value) {
            this.unlockRule = value;
            if (value !== 'previous_lesson_completed') this.$refs.prerequisite.value = '';
            if (value !== 'date_based') this.$refs.unlockAt.value = '';
        }
     }">
    <div class="lf-form-group course-template-lesson-form-wide">
        <x-form-label for="title" :value="__('lf.LF_course_template_lesson_common_name')" :required="true" />
        <input id="title" type="text" name="title" class="lf-form-control" value="{{ old('title', $formLesson?->title) }}" required maxlength="255">
    </div>
    <div class="lf-form-group course-template-lesson-form-wide">
        <x-form-label for="short_description" :value="__('lf.LF_course_template_lesson_common_short_description')" />
        <textarea id="short_description" name="short_description" class="lf-form-control" rows="2" maxlength="500">{{ old('short_description', $formLesson?->short_description) }}</textarea>
    </div>
    <div class="lf-form-group course-template-lesson-form-wide">
        <x-form-label for="description" :value="__('lf.LF_course_template_lesson_common_description')" />
        <textarea id="description" name="description" class="lf-form-control" rows="5">{{ old('description', $formLesson?->description) }}</textarea>
    </div>
    <div class="lf-form-group">
        <x-form-label for="sort_order" :value="__('lf.LF_course_template_lesson_common_sort_order')" />
        <input id="sort_order" type="number" min="0" name="sort_order" class="lf-form-control" value="{{ old('sort_order', $formLesson?->sort_order ?? $suggestedSortOrder ?? null) }}">
    </div>
    <div class="lf-form-group">
        <x-form-label for="is_preview" :value="__('lf.LF_course_template_lesson_common_preview')" :required="true" />
        <select id="is_preview" name="is_preview" class="lf-form-control" required>
            <option value="0" @selected($selectedPreview === '0')>{{ __('lf.LF_course_template_lesson_common_no') }}</option>
            <option value="1" @selected($selectedPreview === '1')>{{ __('lf.LF_course_template_lesson_common_yes') }}</option>
        </select>
    </div>
    <div class="lf-form-group">
        <x-form-label for="unlock_rule" :value="__('lf.LF_course_template_lesson_common_unlock_rule')" :required="true" />
        <select id="unlock_rule" name="unlock_rule" class="lf-form-control" required x-model="unlockRule" @change="changeRule($event.target.value)">
            @foreach (['none', 'previous_lesson_completed', 'date_based'] as $unlockRule)
                <option value="{{ $unlockRule }}">{{ __('lf.LF_course_template_lesson_common_unlock_'.$unlockRule) }}</option>
            @endforeach
        </select>
    </div>
    <div class="lf-form-group" x-show="unlockRule === 'previous_lesson_completed'" x-cloak>
        <x-form-label for="unlock_after_lesson_id" :value="__('lf.LF_course_template_lesson_common_unlock_after_lesson')" />
        <select id="unlock_after_lesson_id" name="unlock_after_lesson_id" class="lf-form-control" x-ref="prerequisite">
            <option value="">{{ __('lf.LF_course_template_lesson_common_no_prerequisite') }}</option>
            @foreach ($prerequisiteLessons as $prerequisiteLesson)
                <option value="{{ $prerequisiteLesson->id }}" @selected((string) $selectedPrerequisiteId === (string) $prerequisiteLesson->id)>{{ $prerequisiteLesson->option_label ?? $prerequisiteLesson->title }}</option>
            @endforeach
        </select>
    </div>
    <div class="lf-form-group" x-show="unlockRule === 'date_based'" x-cloak>
        <x-form-label for="unlock_at" :value="__('lf.LF_course_template_lesson_common_unlock_at')" />
        <input id="unlock_at" type="datetime-local" name="unlock_at" class="lf-form-control" value="{{ $unlockAt }}" x-ref="unlockAt">
    </div>
</div>
