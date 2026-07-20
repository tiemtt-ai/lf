@php($isEditable = ! in_array($cohort->status, ['completed', 'archived'], true))

<section class="admin-form-standard-section" aria-labelledby="cohort-edit-information">
    <header class="admin-form-section-header">
        <h2 id="cohort-edit-information" class="admin-form-section-title">{{ __('lf.LF_course_cohort_create_group_information') }}</h2>
    </header>
    <div class="admin-form-field-grid">
        <div class="lf-form-group admin-form-field">
            <span class="lf-form-label">{{ __('lf.LF_course_cohort_common_code') }}</span>
            <div id="cohort-edit-code" class="cohort-edit-readonly-row" x-data="{ copied: false }">
                <strong class="cohort-edit-readonly-value">{{ $cohort->code }}</strong>
                <button type="button" class="cohort-edit-copy-action"
                        x-on:click="navigator.clipboard.writeText(@js($cohort->code)).then(() => { copied = true; setTimeout(() => copied = false, 1600) })"
                        x-bind:aria-label="copied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_edit_copy_code'))">
                    <span x-show="!copied">{{ __('lf.LF_course_cohort_edit_copy') }}</span>
                    <span x-cloak x-show="copied">{{ __('lf.LF_course_cohort_edit_copied') }}</span>
                </button>
            </div>
        </div>
        <div class="lf-form-group admin-form-field">
            <span class="lf-form-label">{{ __('lf.LF_course_cohort_common_status') }}</span>
            <div id="cohort-edit-status" class="cohort-edit-readonly-row">
                <span @class([
                    'badge',
                    'badge-success' => $cohort->status === 'active',
                    'badge-danger' => $cohort->status === 'archived',
                ])>{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</span>
            </div>
            <p class="lf-form-help">{{ __('lf.LF_course_cohort_edit_status_help') }}</p>
        </div>

        <div class="lf-form-group admin-form-field">
            <span class="lf-form-label">{{ __('lf.LF_course_cohort_common_product') }}</span>
            <div id="cohort-edit-product" class="admin-form-calculated-summary">
                <strong class="admin-form-calculated-summary-value">{{ $cohort->product_title ?: '—' }}</strong>
                @if ($cohort->product_code)
                    <span class="admin-form-calculated-summary-meta">{{ $cohort->product_code }} · {{ __('lf.LF_course_cohort_common_locked') }}</span>
                @endif
            </div>
        </div>
        <div class="lf-form-group admin-form-field">
            <span class="lf-form-label">{{ __('lf.LF_course_cohort_create_content_version') }}</span>
            <div id="cohort-edit-version" class="admin-form-calculated-summary">
                @if ($cohort->version_id)
                    <div class="admin-form-calculated-summary-content">
                        <strong class="admin-form-calculated-summary-value">{{ str_replace(':code', $cohort->version_code, __('lf.LF_course_cohort_create_version_prefix')) }}</strong>
                        <span class="admin-form-calculated-summary-meta admin-form-calculated-summary-meta-row">
                            <span class="admin-form-calculated-summary-meta-item">{{ __('lf.LF_course_cohort_common_published') }}</span>
                            <span class="admin-form-calculated-summary-meta-item">{{ __('lf.LF_course_cohort_create_lesson_count', ['count' => (int) $cohort->lesson_count]) }}</span>
                            <span class="admin-form-calculated-summary-meta-item">{{ __('lf.LF_course_cohort_create_activity_count', ['count' => (int) $cohort->activity_count]) }}</span>
                        </span>
                    </div>
                @else
                    <span class="admin-form-calculated-summary-meta">—</span>
                @endif
            </div>
            <p class="lf-form-help">{{ __('lf.LF_course_cohort_create_version_help') }}</p>
        </div>

        <div class="lf-form-group admin-form-field">
            <x-form-label for="name" :value="__('lf.LF_course_cohort_common_name')" required />
            <input id="name" type="text" name="name" class="lf-form-control" value="{{ old('name', $cohort->name) }}"
                   maxlength="255" required @readonly(! $isEditable)>
            @error('name')<p class="lf-form-error">{{ $message }}</p>@enderror
        </div>
        <div class="lf-form-group admin-form-field">
            <x-form-label for="capacity" :value="__('lf.LF_course_cohort_common_capacity')" />
            <input id="capacity" type="number" name="capacity" class="lf-form-control"
                   value="{{ old('capacity', $cohort->capacity) }}" min="1" step="1" inputmode="numeric" @readonly(! $isEditable)>
            <p class="lf-form-help">{{ __('lf.LF_course_cohort_create_capacity_help') }}</p>
            @error('capacity')<p class="lf-form-error">{{ $message }}</p>@enderror
        </div>
    </div>
</section>

<section class="admin-form-standard-section" aria-labelledby="cohort-edit-dates">
    <header class="admin-form-section-header">
        <h2 id="cohort-edit-dates" class="admin-form-section-title">{{ __('lf.LF_course_cohort_create_group_dates') }}</h2>
    </header>
    <div class="admin-form-field-grid">
        <div class="lf-form-group admin-form-field">
            <x-form-label for="start_date" :value="__('lf.LF_course_cohort_common_start_date')" />
            <input id="start_date" type="date" name="start_date" class="lf-form-control" value="{{ old('start_date', $cohort->start_date) }}" @readonly(! $isEditable)>
            @error('start_date')<p class="lf-form-error">{{ $message }}</p>@enderror
        </div>
        <div class="lf-form-group admin-form-field">
            <x-form-label for="end_date" :value="__('lf.LF_course_cohort_common_end_date')" />
            <input id="end_date" type="date" name="end_date" class="lf-form-control" value="{{ old('end_date', $cohort->end_date) }}" @readonly(! $isEditable)>
            @error('end_date')<p class="lf-form-error">{{ $message }}</p>@enderror
        </div>
    </div>
</section>

<section class="admin-form-standard-section" aria-labelledby="cohort-edit-additional">
    <header class="admin-form-section-header">
        <h2 id="cohort-edit-additional" class="admin-form-section-title">{{ __('lf.LF_course_cohort_create_group_additional') }}</h2>
    </header>
    <div class="admin-form-field-grid">
        <div class="lf-form-group admin-form-field admin-form-field--full">
            <x-form-label for="notes" :value="__('lf.LF_course_cohort_common_notes')" />
            <textarea id="notes" name="notes" class="lf-form-control" rows="4" @readonly(! $isEditable)>{{ old('notes', $cohort->notes) }}</textarea>
            <p class="lf-form-help">{{ __('lf.LF_course_cohort_create_notes_help') }}</p>
            @error('notes')<p class="lf-form-error">{{ $message }}</p>@enderror
        </div>
    </div>
</section>

<footer class="admin-form-footer" data-actions-align="end">
    <div class="admin-form-footer-primary">
        <a class="btn btn-secondary" href="{{ route($routePrefix.'.show', $cohort->id) }}">{{ __('lf.LF_common_button_cancel') }}</a>
        @if ($isEditable)
            <button type="submit" class="btn btn-primary" x-bind:disabled="submitting">
                <span x-show="!submitting">{{ $submitLabel }}</span>
                <span x-cloak x-show="submitting">{{ __('lf.LF_course_cohort_edit_saving') }}</span>
            </button>
        @endif
    </div>
</footer>
