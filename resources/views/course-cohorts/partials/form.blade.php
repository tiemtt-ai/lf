@php($isEditable = ! in_array($cohort->status, ['completed', 'archived'], true))

<dl class="ui-summary ui-enroll-summary">
@include('course-cohorts.partials.context-facts')
@include('course-cohorts.partials.product-registration-window', ['registrationWindowTitleId' => 'cohort-edit-product-registration-window'])
</dl>

<div class="ui-cohort-form" aria-label="{{ __('lf.LF_course_cohort_create_group_information') }}">
    <div class="ui-field">
        <label class="ui-field__label" for="name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_common_name') }}</label>
        <input id="name" type="text" name="name" value="{{ old('name', $cohort->name) }}" maxlength="255" required @readonly(! $isEditable)>
        @error('name')<p class="ui-field__error">{{ $message }}</p>@enderror
    </div>
    <div class="ui-field">
        <label class="ui-field__label" for="capacity">{{ __('lf.LF_course_cohort_common_capacity') }}</label>
        <input id="capacity" type="number" name="capacity" value="{{ old('capacity', $cohort->capacity) }}" min="1" step="1" inputmode="numeric" @readonly(! $isEditable)>
        <p class="ui-caption">{{ __('lf.LF_course_cohort_create_capacity_help') }}</p>
        @error('capacity')<p class="ui-field__error">{{ $message }}</p>@enderror
    </div>
    <div class="ui-field">
        <label class="ui-field__label" for="start_date">{{ __('lf.LF_course_cohort_common_start_date') }}</label>
        <input id="start_date" type="date" name="start_date" value="{{ old('start_date', $cohort->start_date) }}" @readonly(! $isEditable)>
        @error('start_date')<p class="ui-field__error">{{ $message }}</p>@enderror
    </div>
    <div class="ui-field">
        <label class="ui-field__label" for="end_date">{{ __('lf.LF_course_cohort_common_end_date') }}</label>
        <input id="end_date" type="date" name="end_date" value="{{ old('end_date', $cohort->end_date) }}" @readonly(! $isEditable)>
        @error('end_date')<p class="ui-field__error">{{ $message }}</p>@enderror
    </div>
    <div class="ui-field ui-field--full">
        <label class="ui-field__label" for="notes">{{ __('lf.LF_course_cohort_common_notes') }}</label>
        <textarea id="notes" name="notes" rows="3" @readonly(! $isEditable)>{{ old('notes', $cohort->notes) }}</textarea>
        <p class="ui-caption">{{ __('lf.LF_course_cohort_create_notes_help') }}</p>
        @error('notes')<p class="ui-field__error">{{ $message }}</p>@enderror
    </div>
</div>

<footer class="ui-cohort-form__actions">
    <a class="ui-button cursor-interaction" href="{{ route($routePrefix.'.show', $cohort->id) }}">{{ __('lf.LF_common_button_cancel') }}</a>
    @if ($isEditable)
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="submitting">
            <span x-show="!submitting">{{ $submitLabel }}</span>
            <span x-cloak x-show="submitting">{{ __('lf.LF_course_cohort_edit_saving') }}</span>
        </button>
    @endif
</footer>
