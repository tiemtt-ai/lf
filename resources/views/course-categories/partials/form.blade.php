@php
    $formCategory = $category ?? null;
    $slugFollowsName = $formCategory === null
        || (string) $formCategory->slug === \Illuminate\Support\Str::slug((string) $formCategory->name);
    $generatedSlug = $slugFollowsName
        ? \Illuminate\Support\Str::slug((string) $formCategory?->name)
        : $formCategory?->slug;
    $selectedParentId = $formCategory?->parent_id;
    $selectedStatus = $formCategory?->status ?? 'active';
    $isFeatured = (bool) ($formCategory?->is_featured ?? false);

    $mediaSlot = fn ($media, $presentation, $legacyUrl) => $media
        ? ['presentation' => $presentation, 'name' => $media->display_name, 'url' => $media->signed_url]
        : ($legacyUrl
            ? ['presentation' => ['state' => 'image', 'kind' => 'image', 'url' => $legacyUrl], 'name' => $formCategory->name, 'url' => $legacyUrl]
            : null);
    $thumbnailSaved = $mediaSlot($thumbnailMedia ?? null, $thumbnailPresentation ?? null, $formCategory?->thumbnail_image);
    $bannerSaved = $mediaSlot($bannerMedia ?? null, $bannerPresentation ?? null, $formCategory?->banner_image);
@endphp

<div x-data="{
         generatedSlug: @js((string) $generatedSlug),
         selectedParentId: @js($selectedParentId),
         slugFollowsName: @js($slugFollowsName),
         slugify(value) {
             return value.toString()
                 .normalize('NFD')
                 .replace(/[̀-ͯ]/g, '')
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
         previewOpen: false,
         preview: { name: '', url: '' },
         openCategoryImagePreview(name, url) {
             this.preview = { name, url };
             this.previewOpen = true;
         },
         closeCategoryImagePreview() {
             this.previewOpen = false;
             this.preview = { name: '', url: '' };
         },
     }"
     x-on:ui-media-preview="openCategoryImagePreview($event.detail.name, $event.detail.url)">
    <div class="ui-modal__grid">
        <div class="ui-field">
            <label class="ui-field__label" for="category-form-parent_id">{{ __('lf.LF_course_category_common_parent') }}</label>
            <select id="category-form-parent_id" name="parent_id" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_category_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_category_common_clear_selection') }}"
                    x-model="selectedParentId">
                <option value="" @selected($selectedParentId === null || $selectedParentId === '')>{{ __('lf.LF_course_category_select_parent') }}</option>
                @foreach ($parentCategories as $parentCategory)
                    <option value="{{ $parentCategory->id }}"
                            @selected((string) $selectedParentId === (string) $parentCategory->id)>
                        {{ $parentCategory->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="category-form-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_category_common_name') }}</label>
            <input id="category-form-name" type="text" name="name"
                   value="{{ $formCategory?->name }}"
                   placeholder="{{ __('lf.LF_course_category_placeholder_name') }}"
                   required
                   maxlength="255"
                   @input="syncSlug($event.target.value)">
        </div>

        <div class="ui-field">
            <div class="ui-form-label-row">
                <label class="ui-field__label" for="category-form-slug">{{ __('lf.LF_course_category_common_slug') }}</label>
                <span class="ui-caption">{{ __('lf.LF_course_category_common_automatic') }}</span>
            </div>
            <input id="category-form-slug" type="text" name="slug"
                   value="{{ $generatedSlug }}"
                   placeholder="{{ __('lf.LF_course_category_placeholder_slug') }}"
                   maxlength="255"
                   readonly
                   x-model="generatedSlug">
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="category-form-status"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_category_common_status') }}</label>
            <select id="category-form-status" name="status" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_category_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_category_common_clear_selection') }}" required>
                <option value="active" @selected($selectedStatus === 'active')>
                    {{ __('lf.LF_course_category_common_active') }}
                </option>
                <option value="inactive" @selected($selectedStatus === 'inactive')>
                    {{ __('lf.LF_course_category_common_inactive') }}
                </option>
            </select>
        </div>
        <div class="ui-field-stack">
            <div class="ui-field">
                <label class="ui-field__label" for="category-form-description">{{ __('lf.LF_course_category_common_description') }}</label>
                <textarea id="category-form-description" name="description"
                          rows="3" placeholder="{{ __('lf.LF_course_category_placeholder_description') }}">{{ $formCategory?->description }}</textarea>
            </div>

            <div class="ui-field">
                <input type="hidden" name="is_featured" value="0">
                <label class="ui-check ui-switch">
                    <input id="category-form-is_featured" type="checkbox" role="switch" name="is_featured" value="1"
                           @checked($isFeatured)>
                    <span>{{ __('lf.LF_course_category_common_featured') }}</span>
                </label>
            </div>
        </div>

        <div class="ui-field-row">
            <div class="ui-field">
                <p class="ui-field__label" id="thumbnail-image-label">{{ __('lf.LF_course_category_common_thumbnail_image') }}</p>
                <x-ui-media-slot id="thumbnail_image_file" name="thumbnail_image_file" accept="image/*" kind="image"
                    labelledby="thumbnail-image-label"
                    :presentation="$thumbnailSaved['presentation'] ?? null"
                    :display-name="$thumbnailSaved['name'] ?? null"
                    :preview-url="$thumbnailSaved['url'] ?? null"
                    remove-name="remove_thumbnail_image_media"
                    :view-label="__('lf.LF_course_category_common_view_image')"
                    :remove-label="__('lf.LF_course_category_common_remove_image')">
                    <x-upload-hint ui-kit :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
                </x-ui-media-slot>
            </div>

            <div class="ui-field">
                <p class="ui-field__label" id="banner-image-label">{{ __('lf.LF_course_category_common_banner_image') }}</p>
                <x-ui-media-slot id="banner_image_file" name="banner_image_file" accept="image/*" kind="image"
                    labelledby="banner-image-label"
                    :presentation="$bannerSaved['presentation'] ?? null"
                    :display-name="$bannerSaved['name'] ?? null"
                    :preview-url="$bannerSaved['url'] ?? null"
                    remove-name="remove_banner_image_media"
                    :view-label="__('lf.LF_course_category_common_view_image')"
                    :remove-label="__('lf.LF_course_category_common_remove_image')">
                    <x-upload-hint ui-kit :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
                </x-ui-media-slot>
            </div>
        </div>
    </div>

    <x-media-preview-modal ui-kit id="course-category-preview-title" show="previewOpen" close="closeCategoryImagePreview()" title="preview.name" compact="false">
        <img x-show="previewOpen" x-bind:src="preview.url" x-bind:alt="preview.name">
    </x-media-preview-modal>
</div>
