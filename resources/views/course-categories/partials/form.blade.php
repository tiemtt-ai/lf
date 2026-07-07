@php
    $formCategory = $category ?? null;
    $slugSource = old('name', $formCategory?->name);
    $slugFollowsName = $formCategory === null
        || (string) $formCategory->slug === \Illuminate\Support\Str::slug((string) $formCategory->name);
    $generatedSlug = $slugFollowsName
        ? \Illuminate\Support\Str::slug((string) $slugSource)
        : old('slug', $formCategory?->slug);
    $selectedParentId = old('parent_id', $formCategory?->parent_id);
    $selectedStatus = old('status', $formCategory?->status ?? 'active');
    $isFeatured = (bool) old('is_featured', $formCategory?->is_featured ?? false);
@endphp

<section class="admin-form-section"
         aria-labelledby="course-category-basic-title"
         x-data="{
             generatedSlug: @js((string) $generatedSlug),
             slugFollowsName: @js($slugFollowsName),
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
                 if (this.slugFollowsName) {
                     this.generatedSlug = this.slugify(value);
                 }
             },
         }">
    <h2 id="course-category-basic-title" class="admin-form-section-title">
        {{ __('lf.LF_course_category_group_general') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="name"
                      :value="__('lf.LF_course_category_common_name')"
                      required />
        <input id="name" type="text" name="name" class="lf-form-control"
               value="{{ old('name', $formCategory?->name) }}"
               required
               maxlength="255"
               @input="syncSlug($event.target.value)">
    </div>

    <div class="lf-form-group">
        <x-form-label for="slug"
                      :value="__('lf.LF_course_category_common_slug')" />
        <input id="slug" type="text" name="slug" class="lf-form-control"
               value="{{ $generatedSlug }}"
               maxlength="255"
               readonly
               x-model="generatedSlug">
    </div>

    <div class="lf-form-group">
        <x-form-label for="parent_id"
                      :value="__('lf.LF_course_category_common_parent')" />
        <select id="parent_id" name="parent_id" class="lf-form-control">
            <option value="">{{ __('lf.LF_course_category_common_root') }}</option>
            @foreach ($parentCategories as $parentCategory)
                <option value="{{ $parentCategory->id }}"
                        @selected((string) $selectedParentId === (string) $parentCategory->id)>
                    {{ $parentCategory->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="status"
                      :value="__('lf.LF_course_category_common_status')"
                      required />
        <select id="status" name="status" class="lf-form-control" required>
            <option value="active" @selected($selectedStatus === 'active')>
                {{ __('lf.LF_course_category_common_active') }}
            </option>
            <option value="inactive" @selected($selectedStatus === 'inactive')>
                {{ __('lf.LF_course_category_common_inactive') }}
            </option>
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="sort_order"
                      :value="__('lf.LF_course_category_common_sort_order')"
                      required />
        <input id="sort_order" type="number" name="sort_order" class="lf-form-control"
               value="{{ old('sort_order', $formCategory?->sort_order ?? 0) }}" required>
    </div>

    <div class="lf-form-group">
        <input type="hidden" name="is_featured" value="0">
        <div class="admin-radio-group">
            <input id="is_featured" type="checkbox" name="is_featured" value="1"
                   @checked($isFeatured)>
            <label for="is_featured">{{ __('lf.LF_course_category_common_featured') }}</label>
        </div>
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-category-media-title">
    <h2 id="course-category-media-title" class="admin-form-section-title">
        {{ __('lf.LF_course_category_group_media') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="thumbnail_image_file"
                      :value="__('lf.LF_course_category_common_thumbnail_upload')" />
        @if ($thumbnailMedia ?? null)
            <div class="lf-form-help admin-media-preview">
                <img src="{{ $thumbnailMedia->signed_url }}"
                     alt="{{ $thumbnailMedia->display_name }}"
                     style="max-width: 120px; height: auto; display: block;">
            </div>
            <input type="hidden" name="remove_thumbnail_image_media" value="0">
            <div class="admin-media-action-row">
                <a href="{{ $thumbnailMedia->signed_url }}" target="_blank" rel="noopener">
                    {{ __('lf.LF_course_category_common_view_image') }}
                </a>
                <span class="admin-media-action-divider" aria-hidden="true">|</span>
                <div class="admin-radio-group">
                    <input id="remove_thumbnail_image_media"
                           type="checkbox"
                           name="remove_thumbnail_image_media"
                           value="1">
                    <label for="remove_thumbnail_image_media">
                        {{ __('lf.LF_course_category_common_remove_image') }}
                    </label>
                </div>
            </div>
        @elseif ($formCategory?->thumbnail_image)
            <div class="lf-form-help admin-media-preview">
                <img src="{{ $formCategory->thumbnail_image }}"
                     alt="{{ $formCategory->name }}"
                     style="max-width: 120px; height: auto; display: block;">
            </div>
            <div class="admin-media-action-row">
                <a href="{{ $formCategory->thumbnail_image }}" target="_blank" rel="noopener">
                    {{ __('lf.LF_course_category_common_view_image') }}
                </a>
            </div>
        @endif
        <input id="thumbnail_image_file"
               type="file"
               name="thumbnail_image_file"
               class="lf-form-control"
               accept="image/*">
    </div>

    <div class="lf-form-group">
        <x-form-label for="banner_image_file"
                      :value="__('lf.LF_course_category_common_banner_upload')" />
        @if ($bannerMedia ?? null)
            <div class="lf-form-help admin-media-preview">
                <img src="{{ $bannerMedia->signed_url }}"
                     alt="{{ $bannerMedia->display_name }}"
                     style="max-width: 240px; height: auto; display: block;">
            </div>
            <input type="hidden" name="remove_banner_image_media" value="0">
            <div class="admin-media-action-row">
                <a href="{{ $bannerMedia->signed_url }}" target="_blank" rel="noopener">
                    {{ __('lf.LF_course_category_common_view_image') }}
                </a>
                <span class="admin-media-action-divider" aria-hidden="true">|</span>
                <div class="admin-radio-group">
                    <input id="remove_banner_image_media"
                           type="checkbox"
                           name="remove_banner_image_media"
                           value="1">
                    <label for="remove_banner_image_media">
                        {{ __('lf.LF_course_category_common_remove_image') }}
                    </label>
                </div>
            </div>
        @elseif ($formCategory?->banner_image)
            <div class="lf-form-help admin-media-preview">
                <img src="{{ $formCategory->banner_image }}"
                     alt="{{ $formCategory->name }}"
                     style="max-width: 240px; height: auto; display: block;">
            </div>
            <div class="admin-media-action-row">
                <a href="{{ $formCategory->banner_image }}" target="_blank" rel="noopener">
                    {{ __('lf.LF_course_category_common_view_image') }}
                </a>
            </div>
        @endif
        <input id="banner_image_file"
               type="file"
               name="banner_image_file"
               class="lf-form-control"
               accept="image/*">
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-category-description-title">
    <h2 id="course-category-description-title" class="admin-form-section-title">
        {{ __('lf.LF_course_category_common_description') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="description"
                      :value="__('lf.LF_course_category_common_description')" />
        <textarea id="description" name="description" class="lf-form-control"
                  rows="4">{{ old('description', $formCategory?->description) }}</textarea>
    </div>
</section>
