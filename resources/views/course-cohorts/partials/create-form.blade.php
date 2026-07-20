@php
    $selectedProductId = (string) old('product_id', '');
    $productSummaries = $products->mapWithKeys(fn ($product) => [(string) $product->id => [
        'versionCode' => $product->version_code,
        'lessonCount' => (int) $product->lesson_count,
        'activityCount' => (int) $product->activity_count,
    ]]);
@endphp

<div class="admin-form-flow"
     x-data="{
         selectedProductId: @js($selectedProductId),
         productSummaries: @js($productSummaries),
         get selectedVersion() {
             return this.productSummaries[this.selectedProductId] ?? null;
         }
     }">
    <section class="admin-form-standard-section" aria-labelledby="cohort-create-information">
        <header class="admin-form-section-header">
            <h2 id="cohort-create-information" class="admin-form-section-title">{{ __('lf.LF_course_cohort_create_group_information') }}</h2>
        </header>

        <div class="admin-form-field-grid">
            <div class="lf-form-group admin-form-field">
                <x-form-label for="product_id" :value="__('lf.LF_course_cohort_common_product')" required />
                <select id="product_id"
                        name="product_id"
                        class="lf-form-control"
                        x-model="selectedProductId"
                        required
                        @error('product_id') aria-invalid="true" aria-describedby="product_id_error product_id_help" @else aria-describedby="product_id_help" @enderror>
                    <option value="">{{ __('lf.LF_course_cohort_create_select_product') }}</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected($selectedProductId === (string) $product->id)>
                            {{ $product->title }} · {{ $product->product_code }}
                        </option>
                    @endforeach
                </select>
                <p id="product_id_help" class="lf-form-help">{{ __('lf.LF_course_cohort_create_product_help') }}</p>
                @error('product_id')<p id="product_id_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>

            <div class="lf-form-group admin-form-field">
                <span class="lf-form-label">{{ __('lf.LF_course_cohort_create_content_version') }}</span>
                <div class="admin-form-calculated-summary" aria-live="polite" aria-atomic="true">
                    <template x-if="!selectedVersion">
                        <span class="admin-form-calculated-summary-meta">{{ __('lf.LF_course_cohort_create_select_product_for_version') }}</span>
                    </template>
                    <template x-if="selectedVersion">
                        <div class="admin-form-calculated-summary-content">
                            <strong class="admin-form-calculated-summary-value"
                                    x-text="@js(__('lf.LF_course_cohort_create_version_prefix')).replace(':code', selectedVersion.versionCode)"></strong>
                            <span class="admin-form-calculated-summary-meta admin-form-calculated-summary-meta-row">
                                <span class="admin-form-calculated-summary-meta-item">{{ __('lf.LF_course_cohort_common_published') }}</span>
                                <span class="admin-form-calculated-summary-meta-item"
                                      x-text="@js(__('lf.LF_course_cohort_create_lesson_count')).replace(':count', selectedVersion.lessonCount)"></span>
                                <span class="admin-form-calculated-summary-meta-item"
                                      x-text="@js(__('lf.LF_course_cohort_create_activity_count')).replace(':count', selectedVersion.activityCount)"></span>
                            </span>
                        </div>
                    </template>
                </div>
                <p class="lf-form-help">{{ __('lf.LF_course_cohort_create_version_help') }}</p>
            </div>

            <div class="lf-form-group admin-form-field">
                <x-form-label for="name" :value="__('lf.LF_course_cohort_common_name')" required />
                <input id="name" type="text" name="name" class="lf-form-control"
                       value="{{ old('name') }}" maxlength="255" required
                       placeholder="{{ __('lf.LF_course_cohort_create_name_placeholder') }}"
                       @error('name') aria-invalid="true" aria-describedby="name_error" @enderror>
                @error('name')<p id="name_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>

            <div class="lf-form-group admin-form-field">
                <x-form-label for="capacity" :value="__('lf.LF_course_cohort_common_capacity')" />
                <input id="capacity" type="number" name="capacity" class="lf-form-control"
                       value="{{ old('capacity') }}" min="1" step="1" inputmode="numeric"
                       placeholder="{{ __('lf.LF_course_cohort_create_capacity_placeholder') }}"
                       @error('capacity') aria-invalid="true" aria-describedby="capacity_error capacity_help" @else aria-describedby="capacity_help" @enderror>
                <p id="capacity_help" class="lf-form-help">{{ __('lf.LF_course_cohort_create_capacity_help') }}</p>
                @error('capacity')<p id="capacity_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="cohort-create-dates">
        <header class="admin-form-section-header">
            <h2 id="cohort-create-dates" class="admin-form-section-title">{{ __('lf.LF_course_cohort_create_group_dates') }}</h2>
            <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_create_dates_help') }}</p>
        </header>
        <div class="admin-form-field-grid">
            <div class="lf-form-group admin-form-field">
                <x-form-label for="start_date" :value="__('lf.LF_course_cohort_common_start_date')" />
                <input id="start_date" type="date" name="start_date" class="lf-form-control" value="{{ old('start_date') }}"
                       @error('start_date') aria-invalid="true" aria-describedby="start_date_error" @enderror>
                @error('start_date')<p id="start_date_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>
            <div class="lf-form-group admin-form-field">
                <x-form-label for="end_date" :value="__('lf.LF_course_cohort_common_end_date')" />
                <input id="end_date" type="date" name="end_date" class="lf-form-control" value="{{ old('end_date') }}"
                       @error('end_date') aria-invalid="true" aria-describedby="end_date_error" @enderror>
                @error('end_date')<p id="end_date_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="cohort-create-additional">
        <header class="admin-form-section-header">
            <h2 id="cohort-create-additional" class="admin-form-section-title">{{ __('lf.LF_course_cohort_create_group_additional') }}</h2>
        </header>
        <div class="admin-form-field-grid">
            <div class="lf-form-group admin-form-field--full">
                <x-form-label for="notes" :value="__('lf.LF_course_cohort_create_internal_notes')" />
                <textarea id="notes" name="notes" class="lf-form-control" rows="4"
                          placeholder="{{ __('lf.LF_course_cohort_create_notes_placeholder') }}"
                          @error('notes') aria-invalid="true" aria-describedby="notes_error notes_help" @else aria-describedby="notes_help" @enderror>{{ old('notes') }}</textarea>
                <p id="notes_help" class="lf-form-help">{{ __('lf.LF_course_cohort_create_notes_help') }}</p>
                @error('notes')<p id="notes_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>
</div>

<footer class="admin-form-footer" data-actions-align="end">
    <div class="admin-form-footer-primary">
        <a href="{{ route($routePrefix.'.index') }}" class="btn btn-secondary">{{ __('lf.LF_common_button_cancel') }}</a>
        <button type="submit" class="btn btn-primary" x-bind:disabled="submitting" aria-live="polite">
            <span x-show="!submitting">{{ __('lf.LF_course_cohort_common_create') }}</span>
            <span x-show="submitting" x-cloak>{{ __('lf.LF_course_cohort_create_submitting') }}</span>
        </button>
    </div>
</footer>
