@php
    $formCategory = $category ?? null;
    $fieldPrefix = $fieldPrefix ?? 'course-category';
    $selectedParentId = old('parent_id', $formCategory?->parent_id);
    $selectedStatus = old('status', $formCategory?->status ?? 'active');
    $isFeatured = (bool) old('is_featured', $formCategory?->is_featured ?? false);
@endphp

<div class="course-category-form">
    <div class="course-category-form-row course-category-form-row-primary">
        <section class="admin-form-section course-category-form-general"
                 aria-labelledby="{{ $fieldPrefix }}-general-title">
            <h2 id="{{ $fieldPrefix }}-general-title" class="admin-form-section-title">
                {{ __('lf.LF_course_category_group_general') }}
            </h2>

            <div class="lf-form-group">
                <x-form-label for="{{ $fieldPrefix }}-name"
                              :value="__('lf.LF_course_category_common_name')"
                              required />
                <input id="{{ $fieldPrefix }}-name" type="text" name="name" class="lf-form-control"
                       value="{{ old('name', $formCategory?->name) }}" required maxlength="255">
            </div>

            <div class="lf-form-group">
                <x-form-label for="{{ $fieldPrefix }}-slug"
                              :value="__('lf.LF_course_category_common_slug')"
                              required />
                <input id="{{ $fieldPrefix }}-slug" type="text" name="slug" class="lf-form-control"
                       value="{{ old('slug', $formCategory?->slug) }}" required maxlength="255">
            </div>

            <div class="lf-form-group">
                <x-form-label for="{{ $fieldPrefix }}-parent-id"
                              :value="__('lf.LF_course_category_common_parent')" />
                <select id="{{ $fieldPrefix }}-parent-id" name="parent_id" class="lf-form-control">
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
                <x-form-label for="{{ $fieldPrefix }}-status"
                              :value="__('lf.LF_course_category_common_status')"
                              required />
                <select id="{{ $fieldPrefix }}-status" name="status" class="lf-form-control" required>
                    <option value="active" @selected($selectedStatus === 'active')>
                        {{ __('lf.LF_course_category_common_active') }}
                    </option>
                    <option value="inactive" @selected($selectedStatus === 'inactive')>
                        {{ __('lf.LF_course_category_common_inactive') }}
                    </option>
                </select>
            </div>

            <div class="lf-form-group">
                <x-form-label for="{{ $fieldPrefix }}-sort-order"
                              :value="__('lf.LF_course_category_common_sort_order')"
                              required />
                <input id="{{ $fieldPrefix }}-sort-order" type="number" name="sort_order" class="lf-form-control"
                       value="{{ old('sort_order', $formCategory?->sort_order ?? 0) }}" required>
            </div>

            <div class="lf-form-group">
                <input type="hidden" name="is_featured" value="0">
                <div class="admin-radio-group">
                    <input id="{{ $fieldPrefix }}-is-featured" type="checkbox" name="is_featured" value="1"
                           @checked($isFeatured)>
                    <label for="{{ $fieldPrefix }}-is-featured">{{ __('lf.LF_course_category_common_featured') }}</label>
                </div>
            </div>
        </section>

        <section class="admin-form-section course-category-form-media"
                 aria-labelledby="{{ $fieldPrefix }}-media-title">
            <h2 id="{{ $fieldPrefix }}-media-title" class="admin-form-section-title">
                {{ __('lf.LF_course_category_group_media') }}
            </h2>

            <div class="lf-form-group">
                <x-form-label for="{{ $fieldPrefix }}-thumbnail-image-file"
                              :value="__('lf.LF_course_category_common_thumbnail_upload')" />
                @if ($thumbnailMedia ?? null)
                    <div class="lf-form-help admin-media-preview">
                        <img class="course-category-form-thumbnail"
                             src="{{ $thumbnailMedia->signed_url }}"
                             alt="{{ $thumbnailMedia->display_name }}">
                    </div>
                    <input type="hidden" name="remove_thumbnail_image_media" value="0">
                    <div class="admin-media-action-row">
                        <a href="{{ $thumbnailMedia->signed_url }}" target="_blank" rel="noopener">
                            {{ __('lf.LF_course_category_common_view_image') }}
                        </a>
                        <span class="admin-media-action-divider" aria-hidden="true">|</span>
                        <div class="admin-radio-group">
                            <input id="{{ $fieldPrefix }}-remove-thumbnail-image-media"
                                   type="checkbox"
                                   name="remove_thumbnail_image_media"
                                   value="1">
                            <label for="{{ $fieldPrefix }}-remove-thumbnail-image-media">
                                {{ __('lf.LF_course_category_common_remove_image') }}
                            </label>
                        </div>
                    </div>
                @elseif ($formCategory?->thumbnail_image)
                    <div class="lf-form-help admin-media-preview">
                        <img class="course-category-form-thumbnail"
                             src="{{ $formCategory->thumbnail_image }}"
                             alt="{{ $formCategory->name }}">
                    </div>
                    <div class="admin-media-action-row">
                        <a href="{{ $formCategory->thumbnail_image }}" target="_blank" rel="noopener">
                            {{ __('lf.LF_course_category_common_view_image') }}
                        </a>
                    </div>
                @endif
                <input id="{{ $fieldPrefix }}-thumbnail-image-file"
                       type="file"
                       name="thumbnail_image_file"
                       class="lf-form-control"
                       accept="image/*">
            </div>

            <div class="lf-form-group">
                <x-form-label for="{{ $fieldPrefix }}-banner-image-file"
                              :value="__('lf.LF_course_category_common_banner_upload')" />
                @if ($bannerMedia ?? null)
                    <div class="lf-form-help admin-media-preview">
                        <img class="course-category-form-banner"
                             src="{{ $bannerMedia->signed_url }}"
                             alt="{{ $bannerMedia->display_name }}">
                    </div>
                    <input type="hidden" name="remove_banner_image_media" value="0">
                    <div class="admin-media-action-row">
                        <a href="{{ $bannerMedia->signed_url }}" target="_blank" rel="noopener">
                            {{ __('lf.LF_course_category_common_view_image') }}
                        </a>
                        <span class="admin-media-action-divider" aria-hidden="true">|</span>
                        <div class="admin-radio-group">
                            <input id="{{ $fieldPrefix }}-remove-banner-image-media"
                                   type="checkbox"
                                   name="remove_banner_image_media"
                                   value="1">
                            <label for="{{ $fieldPrefix }}-remove-banner-image-media">
                                {{ __('lf.LF_course_category_common_remove_image') }}
                            </label>
                        </div>
                    </div>
                @elseif ($formCategory?->banner_image)
                    <div class="lf-form-help admin-media-preview">
                        <img class="course-category-form-banner"
                             src="{{ $formCategory->banner_image }}"
                             alt="{{ $formCategory->name }}">
                    </div>
                    <div class="admin-media-action-row">
                        <a href="{{ $formCategory->banner_image }}" target="_blank" rel="noopener">
                            {{ __('lf.LF_course_category_common_view_image') }}
                        </a>
                    </div>
                @endif
                <input id="{{ $fieldPrefix }}-banner-image-file"
                       type="file"
                       name="banner_image_file"
                       class="lf-form-control"
                       accept="image/*">
            </div>

        </section>
    </div>

    <div class="course-category-form-row">
        <section class="admin-form-section course-category-form-description"
                 aria-labelledby="{{ $fieldPrefix }}-description-title">
            <h2 id="{{ $fieldPrefix }}-description-title" class="admin-form-section-title">
                {{ __('lf.LF_course_category_group_description') }}
            </h2>

            <div class="lf-form-group">
                <x-form-label for="{{ $fieldPrefix }}-description"
                              :value="__('lf.LF_course_category_common_description')" />
                <textarea id="{{ $fieldPrefix }}-description" name="description" class="lf-form-control"
                          rows="5">{{ old('description', $formCategory?->description) }}</textarea>
            </div>
        </section>
    </div>
</div>
