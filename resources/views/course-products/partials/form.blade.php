@php
    $formProduct = $product ?? null;
    $slugSource = old('title', $formProduct?->title);
    $slugFollowsTitle = $formProduct === null
        || (string) $formProduct->slug === \Illuminate\Support\Str::slug((string) $formProduct->title);
    $generatedSlug = $slugFollowsTitle
        ? \Illuminate\Support\Str::slug((string) $slugSource)
        : old('slug', $formProduct?->slug);
    $selectedProductType = old('product_type', $formProduct?->product_type ?? 'single_course');
    $selectedThumbnailType = old('thumbnail_type', $formProduct?->thumbnail_type ?? 'image');
    $selectedThumbnailVideoSource = old('thumbnail_video_source', $formProduct?->thumbnail_video_source);
    $selectedEnrollmentType = old('enrollment_type', $formProduct?->enrollment_type ?? 'paid');
    $selectedVisibility = old('visibility', $formProduct?->visibility ?? 'public');
    $selectedStatus = old('status', $formProduct?->status ?? 'draft');
    $isRefundable = (bool) old('is_refundable', $formProduct?->is_refundable ?? false);
    $showEnrollmentCount = (bool) old('show_enrollment_count', $formProduct?->show_enrollment_count ?? true);
    $isFeatured = (bool) old('is_featured', $formProduct?->is_featured ?? false);
    $isRequired = static fn (string $field): bool => in_array($field, $requiredFields, true);
    $dateValue = static function (?string $field) use ($formProduct): ?string {
        $value = old($field, $formProduct?->{$field});

        if (! $value) {
            return null;
        }

        return str_replace(' ', 'T', substr((string) $value, 0, 16));
    };
@endphp

<div class="backend-form-columns">
    <div class="backend-form-column">
<section class="admin-form-section"
         aria-labelledby="course-product-basic-title"
         x-data="{
             generatedSlug: @js((string) $generatedSlug),
             slugFollowsTitle: @js($slugFollowsTitle),
             slugify(value) {
                 return value.toString()
                     .normalize('NFD')
                     .replace(/[\u0300-\u036f]/g, '')
                     .toLowerCase()
                     .trim()
                     .replace(/[^a-z0-9]+/g, '-')
                     .replace(/^-+|-+$/g, '');
             },
             syncSlug(value) {
                 if (this.slugFollowsTitle) {
                     this.generatedSlug = this.slugify(value);
                 }
             },
         }">
    <h2 id="course-product-basic-title" class="admin-form-section-title">
        {{ __('lf.LF_course_product_group_basic') }}
    </h2>

    @if ($formProduct?->product_code)
        <div class="lf-form-group">
            <span class="lf-form-label">
                {{ __('lf.LF_course_product_common_product_code') }}
            </span>
            <p class="lf-form-help">{{ $formProduct->product_code }}</p>
        </div>
    @else
        <p class="lf-form-help">
            {{ __('lf.LF_course_product_common_code_auto_help') }}
        </p>
    @endif

    <div class="lf-form-group">
        <x-form-label for="product_type"
                      :value="__('lf.LF_course_product_common_product_type')"
                      :required="$isRequired('product_type')" />
        <select id="product_type" name="product_type" class="lf-form-control" required>
            <option value="single_course" @selected($selectedProductType === 'single_course')>
                {{ __('lf.LF_course_product_common_type_single_course') }}
            </option>
            <option value="bundle" @selected($selectedProductType === 'bundle')>
                {{ __('lf.LF_course_product_common_type_bundle') }}
            </option>
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="title"
                      :value="__('lf.LF_course_product_common_title_field')"
                      :required="$isRequired('title')" />
        <input id="title" type="text" name="title" class="lf-form-control"
               value="{{ old('title', $formProduct?->title) }}"
               required
               maxlength="255"
               @input="syncSlug($event.target.value)">
    </div>

    <div class="lf-form-group">
        <x-form-label for="slug"
                      :value="__('lf.LF_course_product_common_slug')" />
        <input id="slug" type="text" name="slug" class="lf-form-control"
               value="{{ $generatedSlug }}"
               maxlength="255"
               readonly
               x-model="generatedSlug">
    </div>

    <div class="lf-form-group">
        <x-form-label for="short_description"
                      :value="__('lf.LF_course_product_common_short_description')"
                      :required="$isRequired('short_description')" />
        <textarea id="short_description" name="short_description" class="lf-form-control"
                  rows="2" maxlength="500">{{ old('short_description', $formProduct?->short_description) }}</textarea>
    </div>

    <div class="lf-form-group">
        <x-form-label for="description"
                      :value="__('lf.LF_course_product_common_description')"
                      :required="$isRequired('description')" />
        <textarea id="description" name="description" class="lf-form-control"
                  rows="5">{{ old('description', $formProduct?->description) }}</textarea>
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-product-commercial-title">
    <h2 id="course-product-commercial-title" class="admin-form-section-title">
        {{ __('lf.LF_course_product_group_commercial') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="price"
                      :value="__('lf.LF_course_product_common_price')"
                      :required="$isRequired('price')" />
        <input id="price" type="number" min="0" step="0.01" name="price" class="lf-form-control"
               value="{{ old('price', $formProduct?->price ?? 0) }}" required>
    </div>

    <div class="lf-form-group">
        <x-form-label for="sale_price"
                      :value="__('lf.LF_course_product_common_sale_price')"
                      :required="$isRequired('sale_price')" />
        <input id="sale_price" type="number" min="0" step="0.01" name="sale_price" class="lf-form-control"
               value="{{ old('sale_price', $formProduct?->sale_price) }}">
    </div>

    <div class="lf-form-group">
        <x-form-label for="sale_starts_at"
                      :value="__('lf.LF_course_product_common_sale_starts_at')"
                      :required="$isRequired('sale_starts_at')" />
        <input id="sale_starts_at" type="datetime-local" name="sale_starts_at" class="lf-form-control"
               value="{{ $dateValue('sale_starts_at') }}">
    </div>

    <div class="lf-form-group">
        <x-form-label for="sale_ends_at"
                      :value="__('lf.LF_course_product_common_sale_ends_at')"
                      :required="$isRequired('sale_ends_at')" />
        <input id="sale_ends_at" type="datetime-local" name="sale_ends_at" class="lf-form-control"
               value="{{ $dateValue('sale_ends_at') }}">
    </div>

    <div class="lf-form-group">
        <x-form-label for="currency"
                      :value="__('lf.LF_course_product_common_currency')"
                      :required="$isRequired('currency')" />
        <input id="currency" type="text" name="currency" class="lf-form-control"
               value="{{ old('currency', $formProduct?->currency ?? 'VND') }}" required maxlength="10">
    </div>

    <div class="lf-form-group">
        <x-form-label for="enrollment_type"
                      :value="__('lf.LF_course_product_common_enrollment_type')"
                      :required="$isRequired('enrollment_type')" />
        <select id="enrollment_type" name="enrollment_type" class="lf-form-control" required>
            @foreach (['free', 'paid', 'invitation'] as $enrollmentType)
                <option value="{{ $enrollmentType }}" @selected($selectedEnrollmentType === $enrollmentType)>
                    {{ __('lf.LF_course_product_common_enrollment_'.$enrollmentType) }}
                </option>
            @endforeach
        </select>
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-product-access-title">
    <h2 id="course-product-access-title" class="admin-form-section-title">
        {{ __('lf.LF_course_product_group_access') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="max_students"
                      :value="__('lf.LF_course_product_common_max_students')"
                      :required="$isRequired('max_students')" />
        <input id="max_students" type="number" min="0" name="max_students" class="lf-form-control"
               value="{{ old('max_students', $formProduct?->max_students) }}">
    </div>

    <div class="lf-form-group">
        <x-form-label for="access_duration_days"
                      :value="__('lf.LF_course_product_common_access_duration_days')"
                      :required="$isRequired('access_duration_days')" />
        <input id="access_duration_days" type="number" min="0" name="access_duration_days" class="lf-form-control"
               value="{{ old('access_duration_days', $formProduct?->access_duration_days) }}">
    </div>

    <div class="lf-form-group">
        <x-form-label for="review_duration_days"
                      :value="__('lf.LF_course_product_common_review_duration_days')"
                      :required="$isRequired('review_duration_days')" />
        <input id="review_duration_days" type="number" min="0" name="review_duration_days" class="lf-form-control"
               value="{{ old('review_duration_days', $formProduct?->review_duration_days) }}">
    </div>

    <div class="lf-form-group">
        <input type="hidden" name="is_refundable" value="0">
        <div class="admin-radio-group">
            <input id="is_refundable" type="checkbox" name="is_refundable" value="1"
                   @checked($isRefundable)>
            <label for="is_refundable">{{ __('lf.LF_course_product_common_is_refundable') }}</label>
        </div>
    </div>

    <div class="lf-form-group">
        <x-form-label for="refund_days"
                      :value="__('lf.LF_course_product_common_refund_days')"
                      :required="$isRequired('refund_days')" />
        <input id="refund_days" type="number" min="0" name="refund_days" class="lf-form-control"
               value="{{ old('refund_days', $formProduct?->refund_days) }}">
    </div>
</section>
    </div>

    <div class="backend-form-column">
<section class="admin-form-section" aria-labelledby="course-product-media-title">
    <h2 id="course-product-media-title" class="admin-form-section-title">
        {{ __('lf.LF_course_product_group_media') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="thumbnail_type"
                      :value="__('lf.LF_course_product_common_thumbnail_type')"
                      :required="$isRequired('thumbnail_type')" />
        <select id="thumbnail_type" name="thumbnail_type" class="lf-form-control" required>
            <option value="image" @selected($selectedThumbnailType === 'image')>
                {{ __('lf.LF_course_product_common_thumbnail_image_type') }}
            </option>
            <option value="video" @selected($selectedThumbnailType === 'video')>
                {{ __('lf.LF_course_product_common_thumbnail_video_type') }}
            </option>
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="thumbnail_image"
                      :value="__('lf.LF_course_product_common_thumbnail_image')"
                      :required="$isRequired('thumbnail_image')" />
        <input id="thumbnail_image" type="text" name="thumbnail_image" class="lf-form-control"
               value="{{ old('thumbnail_image', $formProduct?->thumbnail_image) }}" maxlength="500">
    </div>

    <div class="lf-form-group">
        <x-form-label for="cover_image_file"
                      value="Cover image upload" />
        @if ($coverImageMedia ?? null)
            <div class="lf-form-help">
                <img src="{{ $coverImageMedia->signed_url }}"
                     alt="{{ $coverImageMedia->display_name }}"
                     style="max-width: 180px; height: auto; display: block; margin-bottom: 8px;">
                <a href="{{ $coverImageMedia->signed_url }}" target="_blank" rel="noopener">
                    {{ $coverImageMedia->display_name }}
                </a>
            </div>
        @endif
        <input id="cover_image_file"
               type="file"
               name="cover_image_file"
               class="lf-form-control"
               accept="image/*">
        <x-upload-hint :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
    </div>

    <div class="lf-form-group">
        <x-form-label for="thumbnail_video_source"
                      :value="__('lf.LF_course_product_common_thumbnail_video_source')"
                      :required="$isRequired('thumbnail_video_source')" />
        <select id="thumbnail_video_source" name="thumbnail_video_source" class="lf-form-control">
            <option value="">{{ __('lf.LF_course_product_common_no_video_source') }}</option>
            <option value="youtube" @selected($selectedThumbnailVideoSource === 'youtube')>
                YouTube
            </option>
            <option value="aws" @selected($selectedThumbnailVideoSource === 'aws')>
                AWS
            </option>
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="thumbnail_video_url"
                      :value="__('lf.LF_course_product_common_thumbnail_video_url')"
                      :required="$isRequired('thumbnail_video_url')" />
        <input id="thumbnail_video_url" type="url" name="thumbnail_video_url" class="lf-form-control"
               value="{{ old('thumbnail_video_url', $formProduct?->thumbnail_video_url) }}" maxlength="1000">
    </div>

    <div class="lf-form-group">
        <x-form-label for="thumbnail_video_media_id"
                      :value="__('lf.LF_course_product_common_thumbnail_video_media_id')"
                      :required="$isRequired('thumbnail_video_media_id')" />
        <input id="thumbnail_video_media_id" type="number" min="1"
               name="thumbnail_video_media_id" class="lf-form-control"
               value="{{ old('thumbnail_video_media_id', $formProduct?->thumbnail_video_media_id) }}">
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-product-display-title">
    <h2 id="course-product-display-title" class="admin-form-section-title">
        {{ __('lf.LF_course_product_group_display') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="tags"
                      :value="__('lf.LF_course_product_common_tags')"
                      :required="$isRequired('tags')" />
        <textarea id="tags" name="tags" class="lf-form-control"
                  rows="3">{{ old('tags', $formProduct?->tags) }}</textarea>
    </div>

    <div class="lf-form-group">
        <x-form-label for="badge_type"
                      :value="__('lf.LF_course_product_common_badge_type')"
                      :required="$isRequired('badge_type')" />
        <input id="badge_type" type="text" name="badge_type" class="lf-form-control"
               value="{{ old('badge_type', $formProduct?->badge_type) }}" maxlength="50">
    </div>

    <div class="lf-form-group">
        <input type="hidden" name="show_enrollment_count" value="0">
        <div class="admin-radio-group">
            <input id="show_enrollment_count" type="checkbox" name="show_enrollment_count" value="1"
                   @checked($showEnrollmentCount)>
            <label for="show_enrollment_count">{{ __('lf.LF_course_product_common_show_enrollment_count') }}</label>
        </div>
    </div>

    <div class="lf-form-group">
        <x-form-label for="display_enrollment_count"
                      :value="__('lf.LF_course_product_common_display_enrollment_count')"
                      :required="$isRequired('display_enrollment_count')" />
        <input id="display_enrollment_count" type="number" min="0"
               name="display_enrollment_count" class="lf-form-control"
               value="{{ old('display_enrollment_count', $formProduct?->display_enrollment_count) }}">
    </div>

    <div class="lf-form-group">
        <input type="hidden" name="is_featured" value="0">
        <div class="admin-radio-group">
            <input id="is_featured" type="checkbox" name="is_featured" value="1"
                   @checked($isFeatured)>
            <label for="is_featured">{{ __('lf.LF_course_product_common_is_featured') }}</label>
        </div>
    </div>

    <div class="lf-form-group">
        <x-form-label for="sort_order"
                      :value="__('lf.LF_course_product_common_sort_order')"
                      :required="$isRequired('sort_order')" />
        <input id="sort_order" type="number" name="sort_order" class="lf-form-control"
               value="{{ old('sort_order', $formProduct?->sort_order ?? 0) }}" required>
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-product-visibility-title">
    <h2 id="course-product-visibility-title" class="admin-form-section-title">
        {{ __('lf.LF_course_product_group_visibility') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="visibility"
                      :value="__('lf.LF_course_product_common_visibility')"
                      :required="$isRequired('visibility')" />
        <select id="visibility" name="visibility" class="lf-form-control" required>
            @foreach (['public', 'private', 'hidden'] as $visibility)
                <option value="{{ $visibility }}" @selected($selectedVisibility === $visibility)>
                    {{ __('lf.LF_course_product_common_visibility_'.$visibility) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="available_from"
                      :value="__('lf.LF_course_product_common_available_from')"
                      :required="$isRequired('available_from')" />
        <input id="available_from" type="datetime-local" name="available_from" class="lf-form-control"
               value="{{ $dateValue('available_from') }}">
    </div>

    <div class="lf-form-group">
        <x-form-label for="available_until"
                      :value="__('lf.LF_course_product_common_available_until')"
                      :required="$isRequired('available_until')" />
        <input id="available_until" type="datetime-local" name="available_until" class="lf-form-control"
               value="{{ $dateValue('available_until') }}">
    </div>

    <div class="lf-form-group">
        <x-form-label for="registration_starts_at"
                      :value="__('lf.LF_course_product_common_registration_starts_at')"
                      :required="$isRequired('registration_starts_at')" />
        <input id="registration_starts_at" type="datetime-local"
               name="registration_starts_at" class="lf-form-control"
               value="{{ $dateValue('registration_starts_at') }}">
    </div>

    <div class="lf-form-group">
        <x-form-label for="registration_ends_at"
                      :value="__('lf.LF_course_product_common_registration_ends_at')"
                      :required="$isRequired('registration_ends_at')" />
        <input id="registration_ends_at" type="datetime-local"
               name="registration_ends_at" class="lf-form-control"
               value="{{ $dateValue('registration_ends_at') }}">
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-product-lifecycle-title">
    <h2 id="course-product-lifecycle-title" class="admin-form-section-title">
        {{ __('lf.LF_course_product_group_lifecycle') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="status"
                      :value="__('lf.LF_course_product_common_status')"
                      :required="$isRequired('status')" />
        <select id="status" name="status" class="lf-form-control" required>
            @foreach (['draft', 'active', 'inactive', 'archived'] as $status)
                <option value="{{ $status }}" @selected($selectedStatus === $status)>
                    {{ __('lf.LF_course_product_common_'.$status) }}
                </option>
            @endforeach
        </select>
    </div>
</section>
    </div>
</div>
