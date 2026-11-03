@php
    $selectedProductId = (string) old('product_id', '');
    $registrationDate = static fn ($value): string => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i') : '—';
    $productSummaries = $products->mapWithKeys(fn ($product) => [(string) $product->id => [
        'registration' => $product->registration_starts_at || $product->registration_ends_at
            ? $registrationDate($product->registration_starts_at).' → '.$registrationDate($product->registration_ends_at)
            : __('lf.LF_course_cohort_product_registration_unlimited'),
        'registrationEnded' => $product->registration_ends_at && \Illuminate\Support\Carbon::parse($product->registration_ends_at)->isPast(),
        'versionCode' => $product->version_code,
        'viewUrl' => route('admin.course-templates.versions.show', [$product->template_id, $product->version_id]),
        'lessonCount' => (int) $product->lesson_count,
        'activityCount' => (int) $product->activity_count,
    ]]);
@endphp

<div class="ui-cohort-form"
     x-data="{
         selectedProductId: @js($selectedProductId),
         productSummaries: @js($productSummaries),
         get selectedVersion() {
             return this.productSummaries[this.selectedProductId] ?? null;
         }
     }">
    <div class="ui-field">
        <label class="ui-field__label" for="product_id"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_common_product') }}</label>
        <select id="product_id" name="product_id" data-ui-kit-select x-model="selectedProductId" required
                data-ui-empty-label="{{ __('lf.LF_course_enrollment_index_no_options') }}"
                @error('product_id') aria-invalid="true" aria-describedby="product_id_error" @enderror>
            <option value="">{{ __('lf.LF_course_cohort_create_select_product') }}</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}" @selected($selectedProductId === (string) $product->id)>
                    {{ $product->title }} · {{ $product->product_code }} · {{ __(\App\Support\CourseType::labelKey((string) $product->course_type)) }}
                </option>
            @endforeach
        </select>
        <p class="ui-caption">{{ __('lf.LF_course_cohort_create_product_help') }}</p>
        <p class="ui-caption ui-cohort-registration-note" x-show="selectedVersion" x-cloak>
            {{ __('lf.LF_course_cohort_product_registration_window') }}: <span x-text="selectedVersion?.registration"></span>
            <strong x-show="selectedVersion?.registrationEnded">· {{ __('lf.LF_course_cohort_product_registration_ended') }}</strong>
        </p>
        @error('product_id')<p class="ui-field__error">{{ $message }}</p>@enderror
    </div>
    <div class="ui-field">
        <span class="ui-field__label">{{ __('lf.LF_course_cohort_create_content_version') }}</span>
        <div id="cohort-create-content-version" class="ui-enroll-readonly" aria-live="polite" aria-atomic="true">
            <template x-if="!selectedVersion">
                <span class="ui-caption">{{ __('lf.LF_course_cohort_create_select_product_for_version') }}</span>
            </template>
            <template x-if="selectedVersion">
                <span class="ui-cohort-version">
                    <span>
                    <strong x-text="@js(__('lf.LF_course_cohort_create_version_prefix')).replace(':code', selectedVersion.versionCode)"></strong>
                    <span class="ui-caption ui-framework-description">
                        {{ __('lf.LF_course_cohort_common_published') }}
                        · <span x-text="@js(__('lf.LF_course_cohort_create_lesson_count')).replace(':count', selectedVersion.lessonCount)"></span>
                        · <span x-text="@js(__('lf.LF_course_cohort_create_activity_count')).replace(':count', selectedVersion.activityCount)"></span>
                    </span>
                    </span>
                    <a :href="selectedVersion.viewUrl" class="ui-enroll-link ui-cohort-version-link" target="_blank" rel="noopener" aria-label="{{ __('lf.LF_product_v2_view_version_new_tab') }}">
                        <x-backend-icon name="eye" class="ui-icon" />{{ __('lf.LF_course_product_item_common_view_version') }}
                    </a>
                </span>
            </template>
        </div>
        <p class="ui-caption">{{ __('lf.LF_course_cohort_create_version_help') }}</p>
    </div>
    <div class="ui-field">
        <label class="ui-field__label" for="name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_common_name') }}</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" maxlength="255" required
               placeholder="{{ __('lf.LF_course_cohort_create_name_placeholder') }}"
               @error('name') aria-invalid="true" aria-describedby="name_error" @enderror>
        @error('name')<p class="ui-field__error">{{ $message }}</p>@enderror
    </div>
    <div class="ui-field">
        <label class="ui-field__label" for="capacity">{{ __('lf.LF_course_cohort_common_capacity') }}</label>
        <input id="capacity" type="number" name="capacity" value="{{ old('capacity') }}" min="1" step="1" inputmode="numeric"
               placeholder="{{ __('lf.LF_course_cohort_create_capacity_placeholder') }}"
               @error('capacity') aria-invalid="true" aria-describedby="capacity_error" @enderror>
        <p class="ui-caption">{{ __('lf.LF_course_cohort_create_capacity_help') }}</p>
        @error('capacity')<p class="ui-field__error">{{ $message }}</p>@enderror
    </div>
    <div class="ui-field">
        <label class="ui-field__label" for="start_date"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_common_start_date') }}</label>
        <x-ui-date-input id="start_date" name="start_date" :value="old('start_date')" required />
        @error('start_date')<p class="ui-field__error">{{ $message }}</p>@enderror
    </div>
    <div class="ui-field">
        <label class="ui-field__label" for="end_date"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_common_end_date') }}</label>
        <x-ui-date-input id="end_date" name="end_date" :value="old('end_date')" required />
        @error('end_date')<p class="ui-field__error">{{ $message }}</p>@enderror
    </div>
    <div class="ui-field ui-field--full">
        <label class="ui-field__label" for="notes">{{ __('lf.LF_course_cohort_create_internal_notes') }}</label>
        <textarea id="notes" name="notes" rows="3" placeholder="{{ __('lf.LF_course_cohort_create_notes_placeholder') }}"
                  @error('notes') aria-invalid="true" aria-describedby="notes_error" @enderror>{{ old('notes') }}</textarea>
        <p class="ui-caption">{{ __('lf.LF_course_cohort_create_notes_help') }}</p>
        @error('notes')<p class="ui-field__error">{{ $message }}</p>@enderror
    </div>
</div>

<footer class="ui-cohort-form__actions">
    <a href="{{ route($routePrefix.'.index') }}" class="ui-button cursor-interaction">{{ __('lf.LF_common_button_cancel') }}</a>
    <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="submitting" aria-live="polite">
        <span x-show="!submitting">{{ __('lf.LF_course_cohort_create_and_continue') }}</span>
        <span x-show="submitting" x-cloak>{{ __('lf.LF_course_cohort_create_submitting') }}</span>
    </button>
</footer>
