@php
    $formLesson = $lesson ?? null;
    $selectedPreview = (string) old('is_preview', $formLesson?->is_preview ?? 0);
    $selectedLessonType = old('lesson_type', $formLesson?->lesson_type ?? 'regular');
    $selectedUnlockRule = old('unlock_rule', $formLesson?->unlock_rule ?? 'none');
    $selectedPrerequisites = collect(old(
        'prerequisite_lesson_ids',
        $selectedPrerequisiteIds ?? []
    ))->map(fn ($id) => (string) $id)->all();
    $selectedPrerequisiteMatch = old(
        'prerequisite_match',
        $formLesson?->prerequisite_match ?? 'all'
    );
    $prerequisiteError = $errors->first('prerequisite_lesson_ids')
        ?: $errors->first('prerequisite_lesson_ids.*');
    $unlockAt = old('unlock_at', $formLesson?->unlock_at
        ? \Illuminate\Support\Carbon::parse($formLesson->unlock_at)->format('Y-m-d\TH:i') : null);
@endphp

<div class="admin-form-flow course-template-lesson-form"
     x-data="{
        unlockRule: @js($selectedUnlockRule),
        changeRule(value) {
            this.unlockRule = value;
            if (value !== 'date_based') this.$refs.unlockAt.value = '';
        }
     }">
    <section class="admin-form-standard-section" aria-labelledby="lesson-information">
        <header class="admin-form-section-header">
            <h2 id="lesson-information" class="admin-form-section-title">{{ __('lf.LF_course_template_lesson_group_basic') }}</h2>
            <p class="admin-form-section-help">{{ __('lf.LF_course_template_lesson_group_basic_help') }}</p>
        </header>

        <div class="admin-form-field-grid">
            <div class="lf-form-group admin-form-field--full">
                <x-form-label for="title" :value="__('lf.LF_course_template_lesson_common_name')" :required="true" />
                <input id="title" type="text" name="title" class="lf-form-control"
                       value="{{ old('title', $formLesson?->title) }}" maxlength="255" required
                       placeholder="{{ __('lf.LF_course_template_lesson_placeholder_name') }}"
                       @error('title') aria-invalid="true" aria-describedby="title_error" @enderror>
                @error('title')<p id="title_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>

            <div class="lf-form-group admin-form-field--full">
                <x-form-label for="short_description" :value="__('lf.LF_course_template_lesson_common_short_description')" />
                <textarea id="short_description" name="short_description" class="lf-form-control" rows="2"
                          maxlength="500" placeholder="{{ __('lf.LF_course_template_lesson_placeholder_short_description') }}"
                          @error('short_description') aria-invalid="true" aria-describedby="short_description_error" @enderror>{{ old('short_description', $formLesson?->short_description) }}</textarea>
                @error('short_description')<p id="short_description_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>

            <div class="lf-form-group admin-form-field--full">
                <x-form-label for="description" :value="__('lf.LF_course_template_lesson_common_description')" />
                <textarea id="description" name="description" class="lf-form-control" rows="4"
                          placeholder="{{ __('lf.LF_course_template_lesson_placeholder_description') }}"
                          @error('description') aria-invalid="true" aria-describedby="description_error" @enderror>{{ old('description', $formLesson?->description) }}</textarea>
                @error('description')<p id="description_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="lesson-display">
        <header class="admin-form-section-header">
            <h2 id="lesson-display" class="admin-form-section-title">{{ __('lf.LF_course_template_lesson_group_display') }}</h2>
            <p class="admin-form-section-help">{{ __('lf.LF_course_template_lesson_group_display_help') }}</p>
        </header>

        <div class="admin-form-field-grid">
            <div class="lf-form-group">
                <x-form-label for="lesson_type" :value="__('lf.LF_course_template_lesson_common_type')" :required="true" />
                <select id="lesson_type" name="lesson_type" class="lf-form-control" required>
                    @foreach (['regular', 'review', 'midterm_exam', 'final_exam', 'other_exam'] as $lessonType)
                        <option value="{{ $lessonType }}" @selected($selectedLessonType === $lessonType)>{{ __('lf.LF_course_template_lesson_common_role_'.$lessonType) }}</option>
                    @endforeach
                </select>
                <p class="lf-form-help">{{ __('lf.LF_course_template_lesson_common_type_help') }}</p>
            </div>

            <div class="lf-form-group">
                <x-form-label for="is_preview" :value="__('lf.LF_course_template_lesson_common_preview')" :required="true" />
                <select id="is_preview" name="is_preview" class="lf-form-control" required>
                    <option value="0" @selected($selectedPreview === '0')>{{ __('lf.LF_course_template_lesson_common_no') }}</option>
                    <option value="1" @selected($selectedPreview === '1')>{{ __('lf.LF_course_template_lesson_common_yes') }}</option>
                </select>
            </div>

            <div class="lf-form-group">
                <x-form-label for="sort_order" :value="__('lf.LF_course_template_lesson_common_sort_order')" />
                <input id="sort_order" type="number" min="0" name="sort_order" class="lf-form-control"
                       value="{{ old('sort_order', $formLesson?->sort_order ?? $suggestedSortOrder ?? null) }}"
                       placeholder="{{ __('lf.LF_course_template_lesson_placeholder_sort_order') }}"
                       @error('sort_order') aria-invalid="true" aria-describedby="sort_order_error" @enderror>
                <p class="lf-form-help">{{ __('lf.LF_course_template_lesson_common_sort_order_help') }}</p>
                @error('sort_order')<p id="sort_order_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="lesson-unlock">
        <header class="admin-form-section-header">
            <h2 id="lesson-unlock" class="admin-form-section-title">{{ __('lf.LF_course_template_lesson_group_unlock') }}</h2>
            <p class="admin-form-section-help">{{ __('lf.LF_course_template_lesson_group_unlock_help') }}</p>
        </header>

        <div class="admin-form-field-grid">
            <div class="lf-form-group">
                <x-form-label for="unlock_rule" :value="__('lf.LF_course_template_lesson_common_unlock_rule')" :required="true" />
                <select id="unlock_rule" name="unlock_rule" class="lf-form-control" required
                        x-model="unlockRule" @change="changeRule($event.target.value)">
                    @foreach (['none', 'all_previous_lessons_completed', 'selected_lessons_completed', 'date_based'] as $unlockRule)
                        <option value="{{ $unlockRule }}"
                                @disabled($unlockRule === 'all_previous_lessons_completed' && $prerequisiteLessons->isEmpty())>
                            {{ __('lf.LF_course_template_lesson_common_unlock_'.$unlockRule) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="lf-form-group" x-show="unlockRule === 'selected_lessons_completed'" x-cloak>
                <x-form-label for="prerequisite_match" :value="__('lf.LF_course_template_lesson_common_prerequisite_match')" :required="true" />
                <select id="prerequisite_match" name="prerequisite_match" class="lf-form-control"
                        x-bind:required="unlockRule === 'selected_lessons_completed'"
                        x-bind:aria-required="unlockRule === 'selected_lessons_completed' ? 'true' : 'false'">
                    <option value="all" @selected($selectedPrerequisiteMatch === 'all')>{{ __('lf.LF_course_template_lesson_common_prerequisite_match_all') }}</option>
                    <option value="any" @selected($selectedPrerequisiteMatch === 'any')>{{ __('lf.LF_course_template_lesson_common_prerequisite_match_any') }}</option>
                </select>
            </div>

            <fieldset class="lf-form-group admin-form-field--full"
                      x-show="unlockRule === 'selected_lessons_completed'" x-cloak
                      @if($prerequisiteError) aria-invalid="true" aria-describedby="prerequisite_lesson_ids_error" @endif>
                <input type="hidden" name="unlock_after_lesson_id" value="">
                <legend class="lf-form-label">{{ __('lf.LF_course_template_lesson_common_unlock_after_lessons') }}</legend>
                <div class="course-template-prerequisite-list">
                    @foreach ($prerequisiteLessons as $prerequisiteLesson)
                        <label class="lf-checkbox-option">
                            <input type="checkbox" name="prerequisite_lesson_ids[]"
                                   value="{{ $prerequisiteLesson->id }}"
                                   @checked(in_array((string) $prerequisiteLesson->id, $selectedPrerequisites, true))>
                            <span>{{ $prerequisiteLesson->option_label ?? $prerequisiteLesson->title }}</span>
                        </label>
                    @endforeach
                </div>
                @if($prerequisiteError)
                    <p id="prerequisite_lesson_ids_error" class="lf-form-error">{{ $prerequisiteError }}</p>
                @endif
            </fieldset>

            <div class="lf-form-group" x-show="unlockRule === 'date_based'" x-cloak>
                <x-form-label for="unlock_at" :value="__('lf.LF_course_template_lesson_common_unlock_at')" />
                <input id="unlock_at" type="datetime-local" name="unlock_at" class="lf-form-control"
                       value="{{ $unlockAt }}" x-ref="unlockAt">
            </div>
        </div>
    </section>
</div>
