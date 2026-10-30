@php
    $formLesson = $lesson ?? null;
    $isRequired = static fn (string $field): bool => in_array($field, $requiredFields, true);
    $star = '<span class="ui-field__required" aria-hidden="true">*</span>';
    $selectedPreview = (string) ($formLesson?->is_preview ?? 0);
    $selectedLessonType = $formLesson?->lesson_type ?? 'regular';
    $selectedUnlockRule = $formLesson?->unlock_rule ?? 'none';
    $selectedPrerequisites = collect($selectedPrerequisiteIds ?? [])->map(fn ($id) => (string) $id)->all();
    $selectedPrerequisiteMatch = $formLesson?->prerequisite_match ?? 'all';
    $unlockAt = $formLesson?->unlock_at
        ? \Illuminate\Support\Carbon::parse($formLesson->unlock_at)->format('Y-m-d\TH:i')
        : null;
    $selectAttributes = 'data-ui-kit-select data-ui-empty-label="'.e(__('lf.LF_course_template_lesson_dialog_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_common_button_clear')).'"';
@endphp

<div x-data="{
         unlockRule: @js($selectedUnlockRule),
         changeRule(value) {
             this.unlockRule = value;
             if (value !== 'date_based' && this.$refs.unlockAt) this.$refs.unlockAt.value = '';
         },
     }">
    <div class="ui-modal__grid">
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="lesson-form-title">{!! $star !!}{{ __('lf.LF_course_template_lesson_common_name') }}</label>
            <input id="lesson-form-title" type="text" name="title" value="{{ $formLesson?->title }}" maxlength="255" required
                   placeholder="{{ __('lf.LF_course_template_lesson_placeholder_name') }}">
        </div>

        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="lesson-form-short_description">{{ __('lf.LF_course_template_lesson_common_short_description') }}</label>
            <textarea id="lesson-form-short_description" name="short_description" rows="2" maxlength="500"
                      placeholder="{{ __('lf.LF_course_template_lesson_placeholder_short_description') }}">{{ $formLesson?->short_description }}</textarea>
        </div>

        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="lesson-form-description">{{ __('lf.LF_course_template_lesson_common_description') }}</label>
            <textarea id="lesson-form-description" name="description" rows="4"
                      placeholder="{{ __('lf.LF_course_template_lesson_placeholder_description') }}">{{ $formLesson?->description }}</textarea>
        </div>

        {{-- Row 1: type, preview, order. Row 2: the unlock rule and what it opens (match + lessons, or the date). --}}
        <div class="ui-field">
            <label class="ui-field__label" for="lesson-form-lesson_type">{!! $star !!}{{ __('lf.LF_course_template_lesson_common_type') }}</label>
            <select id="lesson-form-lesson_type" name="lesson_type" required {!! $selectAttributes !!}>
                @foreach (['regular', 'review', 'midterm_exam', 'final_exam', 'other_exam'] as $lessonType)
                    <option value="{{ $lessonType }}" @selected($selectedLessonType === $lessonType)>{{ __('lf.LF_course_template_lesson_common_role_'.$lessonType) }}</option>
                @endforeach
            </select>
            <span class="ui-field__hint">{{ __('lf.LF_course_template_lesson_common_type_help') }}</span>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="lesson-form-is_preview">{!! $star !!}{{ __('lf.LF_course_template_lesson_common_preview') }}</label>
            <select id="lesson-form-is_preview" name="is_preview" required {!! $selectAttributes !!}>
                <option value="0" @selected($selectedPreview === '0')>{{ __('lf.LF_course_template_lesson_common_no') }}</option>
                <option value="1" @selected($selectedPreview === '1')>{{ __('lf.LF_course_template_lesson_common_yes') }}</option>
            </select>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="lesson-form-sort_order">@if ($formLesson){!! $star !!}@endif{{ __('lf.LF_course_template_lesson_common_sort_order') }}</label>
            <input id="lesson-form-sort_order" type="number" min="0" name="sort_order"
                   value="{{ $formLesson?->sort_order ?? $suggestedSortOrder ?? null }}"
                   placeholder="{{ __('lf.LF_course_template_lesson_placeholder_sort_order') }}" @if ($formLesson) required @endif>
            <span class="ui-field__hint">{{ __('lf.LF_course_template_lesson_common_sort_order_help') }}</span>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="lesson-form-unlock_rule">{!! $star !!}{{ __('lf.LF_course_template_lesson_common_unlock_rule') }}</label>
            <select id="lesson-form-unlock_rule" name="unlock_rule" required {!! $selectAttributes !!}
                    x-on:change="changeRule($event.target.value)">
                @foreach (['none', 'all_previous_lessons_completed', 'selected_lessons_completed', 'date_based'] as $unlockRule)
                    <option value="{{ $unlockRule }}" @selected($selectedUnlockRule === $unlockRule)
                            @disabled($unlockRule === 'all_previous_lessons_completed' && $prerequisiteLessons->isEmpty())>
                        {{ __('lf.LF_course_template_lesson_common_unlock_'.$unlockRule) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="ui-field" x-show="unlockRule === 'selected_lessons_completed'" x-cloak>
            <label class="ui-field__label" for="lesson-form-prerequisite_match">{!! $star !!}{{ __('lf.LF_course_template_lesson_common_prerequisite_match') }}</label>
            <select id="lesson-form-prerequisite_match" name="prerequisite_match" {!! $selectAttributes !!}
                    x-bind:required="unlockRule === 'selected_lessons_completed'">
                <option value="all" @selected($selectedPrerequisiteMatch === 'all')>{{ __('lf.LF_course_template_lesson_common_prerequisite_match_all') }}</option>
                <option value="any" @selected($selectedPrerequisiteMatch === 'any')>{{ __('lf.LF_course_template_lesson_common_prerequisite_match_any') }}</option>
            </select>
        </div>

        <div class="ui-field" x-show="unlockRule === 'date_based'" x-cloak>
            <label class="ui-field__label" for="lesson-form-unlock_at">{{ __('lf.LF_course_template_lesson_common_unlock_at') }}</label>
            <input id="lesson-form-unlock_at" type="datetime-local" name="unlock_at" value="{{ $unlockAt }}" x-ref="unlockAt">
        </div>

        <div class="ui-field" x-show="unlockRule === 'selected_lessons_completed'" x-cloak
             data-ui-error-for="prerequisite_lesson_ids">
            <input type="hidden" name="unlock_after_lesson_id" value="">
            <label class="ui-field__label" for="lesson-form-prerequisite_lesson_ids">{!! $star !!}{{ __('lf.LF_course_template_lesson_common_unlock_after_lessons') }}</label>
            <select id="lesson-form-prerequisite_lesson_ids" name="prerequisite_lesson_ids[]" multiple {!! $selectAttributes !!}
                    data-ui-placeholder="{{ __('lf.LF_course_template_lesson_placeholder_prerequisites') }}"
                    x-bind:required="unlockRule === 'selected_lessons_completed'">
                @foreach ($prerequisiteLessons as $prerequisiteLesson)
                    <option value="{{ $prerequisiteLesson->id }}" @selected(in_array((string) $prerequisiteLesson->id, $selectedPrerequisites, true))>
                        {{ $prerequisiteLesson->option_label ?? $prerequisiteLesson->title }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>
</div>
