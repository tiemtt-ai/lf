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

<div class="backend-form-shell"
     x-data="{
         generatedSlug: @js((string) $generatedSlug),
         selectedParentId: @js($selectedParentId),
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
         previewOpen: false,
         preview: {
             name: '',
             url: '',
         },
         openCategoryImagePreview(name, url) {
             this.preview = { name, url };
             this.previewOpen = true;
         },
         closeCategoryImagePreview() {
             this.previewOpen = false;
             this.preview = { name: '', url: '' };
         },
     }"
     x-on:keydown.escape.window="closeCategoryImagePreview()">
    <div class="backend-form-columns">
        <div class="backend-form-column">
            <div class="lf-form-group">
                <x-form-label for="parent_id"
                              :value="__('lf.LF_course_category_common_parent')" />
                <select id="parent_id" name="parent_id" class="lf-form-control"
                        x-model="selectedParentId"
                        :class="{ 'lf-select-placeholder': selectedParentId === null || selectedParentId === '' }">
                    <option value="" @selected($selectedParentId === null || $selectedParentId === '')>{{ __('lf.LF_course_category_select_parent') }}</option>
                    @foreach ($parentCategories as $parentCategory)
                        <option value="{{ $parentCategory->id }}"
                                @selected((string) $selectedParentId === (string) $parentCategory->id)>
                            {{ $parentCategory->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="lf-form-group">
                <x-form-label for="name"
                              :value="__('lf.LF_course_category_common_name')"
                              required />
                <input id="name" type="text" name="name" class="lf-form-control"
                       value="{{ old('name', $formCategory?->name) }}"
                       placeholder="{{ __('lf.LF_course_category_placeholder_name') }}"
                       required
                       maxlength="255"
                       @input="syncSlug($event.target.value)">
            </div>

            <div class="lf-form-group">
                <x-form-label for="slug"
                              :value="__('lf.LF_course_category_common_slug')" />
                <input id="slug" type="text" name="slug" class="lf-form-control"
                       value="{{ $generatedSlug }}"
                       placeholder="{{ __('lf.LF_course_category_placeholder_slug') }}"
                       maxlength="255"
                       readonly
                       x-model="generatedSlug">
            </div>

            <div class="lf-form-group">
                <x-form-label for="thumbnail_image_file"
                              :value="__('lf.LF_course_category_common_thumbnail_upload')" />
                @if ($thumbnailMedia ?? null)
                    <input type="hidden" name="remove_thumbnail_image_media" value="0">
                    <div class="lf-form-help admin-attached-media-card">
                        <img class="admin-attached-media-thumbnail"
                             src="{{ $thumbnailMedia->signed_url }}"
                             alt="{{ $thumbnailMedia->display_name }}"
                             loading="lazy">
                        <div class="admin-attached-media-actions">
                            <button type="button"
                                    class="admin-media-preview-link admin-text-action"
                                    data-preview-name="{{ $thumbnailMedia->display_name }}"
                                    data-preview-url="{{ $thumbnailMedia->signed_url }}"
                                    x-on:click="openCategoryImagePreview($el.dataset.previewName, $el.dataset.previewUrl)">
                                {{ __('lf.LF_course_category_common_view_image') }}
                            </button>
                            <label class="admin-attached-media-remove" for="remove_thumbnail_image_media">
                                <input id="remove_thumbnail_image_media"
                                       type="checkbox"
                                       name="remove_thumbnail_image_media"
                                       value="1">
                                <span>{{ __('lf.LF_course_category_common_remove_image') }}</span>
                            </label>
                        </div>
                    </div>
                @elseif ($formCategory?->thumbnail_image)
                    <div class="lf-form-help admin-attached-media-card">
                        <img class="admin-attached-media-thumbnail"
                             src="{{ $formCategory->thumbnail_image }}"
                             alt="{{ $formCategory->name }}"
                             loading="lazy">
                        <div class="admin-attached-media-actions">
                            <button type="button"
                                    class="admin-media-preview-link admin-text-action"
                                    data-preview-name="{{ $formCategory->name }}"
                                    data-preview-url="{{ $formCategory->thumbnail_image }}"
                                    x-on:click="openCategoryImagePreview($el.dataset.previewName, $el.dataset.previewUrl)">
                                {{ __('lf.LF_course_category_common_view_image') }}
                            </button>
                        </div>
                    </div>
                @endif
                <input id="thumbnail_image_file"
                       type="file"
                       name="thumbnail_image_file"
                       class="lf-form-control"
                       accept="image/*">
                <x-upload-hint :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
            </div>
        </div>

        <div class="backend-form-column">
            <div class="lf-form-group">
                <x-form-label for="banner_image_file"
                              :value="__('lf.LF_course_category_common_banner_upload')" />
                @if ($bannerMedia ?? null)
                    <input type="hidden" name="remove_banner_image_media" value="0">
                    <div class="lf-form-help admin-attached-media-card">
                        <img class="admin-attached-media-thumbnail is-wide"
                             src="{{ $bannerMedia->signed_url }}"
                             alt="{{ $bannerMedia->display_name }}"
                             loading="lazy">
                        <div class="admin-attached-media-actions">
                            <button type="button"
                                    class="admin-media-preview-link admin-text-action"
                                    data-preview-name="{{ $bannerMedia->display_name }}"
                                    data-preview-url="{{ $bannerMedia->signed_url }}"
                                    x-on:click="openCategoryImagePreview($el.dataset.previewName, $el.dataset.previewUrl)">
                                {{ __('lf.LF_course_category_common_view_image') }}
                            </button>
                            <label class="admin-attached-media-remove" for="remove_banner_image_media">
                                <input id="remove_banner_image_media"
                                       type="checkbox"
                                       name="remove_banner_image_media"
                                       value="1">
                                <span>{{ __('lf.LF_course_category_common_remove_image') }}</span>
                            </label>
                        </div>
                    </div>
                @elseif ($formCategory?->banner_image)
                    <div class="lf-form-help admin-attached-media-card">
                        <img class="admin-attached-media-thumbnail is-wide"
                             src="{{ $formCategory->banner_image }}"
                             alt="{{ $formCategory->name }}"
                             loading="lazy">
                        <div class="admin-attached-media-actions">
                            <button type="button"
                                    class="admin-media-preview-link admin-text-action"
                                    data-preview-name="{{ $formCategory->name }}"
                                    data-preview-url="{{ $formCategory->banner_image }}"
                                    x-on:click="openCategoryImagePreview($el.dataset.previewName, $el.dataset.previewUrl)">
                                {{ __('lf.LF_course_category_common_view_image') }}
                            </button>
                        </div>
                    </div>
                @endif
                <input id="banner_image_file"
                       type="file"
                       name="banner_image_file"
                       class="lf-form-control"
                       accept="image/*">
                <x-upload-hint :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
            </div>

            <div class="lf-form-group">
                <input type="hidden" name="is_featured" value="0">
                <div class="admin-radio-group">
                    <input id="is_featured" type="checkbox" name="is_featured" value="1"
                           @checked($isFeatured)>
                    <label for="is_featured">{{ __('lf.LF_course_category_common_featured') }}</label>
                </div>
            </div>

            <div class="lf-form-group">
                <x-form-label for="sort_order"
                              :value="__('lf.LF_course_category_common_sort_order')"
                              required />
                <input id="sort_order" type="number" name="sort_order" class="lf-form-control"
                       value="{{ old('sort_order', $formCategory?->sort_order ?? 0) }}"
                       placeholder="{{ __('lf.LF_course_category_placeholder_sort_order') }}" required>
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
        </div>
    </div>

    <div class="lf-form-group">
        <x-form-label for="description"
                      :value="__('lf.LF_course_category_common_description')" />
        <textarea id="description" name="description" class="lf-form-control"
                  rows="4" placeholder="{{ __('lf.LF_course_category_placeholder_description') }}">{{ old('description', $formCategory?->description) }}</textarea>
    </div>

<div class="media-library-modal"
     x-cloak
     x-show="previewOpen"
     x-transition.opacity
     role="dialog"
     aria-modal="true"
     aria-labelledby="course-category-preview-title">
    <button type="button"
            class="media-library-modal-backdrop"
            aria-label="{{ __('lf.LF_common_button_cancel') }}"
            x-on:click="closeCategoryImagePreview()"></button>

    <div class="media-library-modal-panel">
        <div class="media-library-modal-header">
            <h2 id="course-category-preview-title"
                x-text="preview.name"></h2>
            <button type="button"
                    class="admin-link-button admin-text-action"
                    x-on:click="closeCategoryImagePreview()">
                {{ __('lf.LF_common_button_cancel') }}
            </button>
        </div>

        <div class="media-library-modal-body">
            <img x-show="previewOpen"
                 x-bind:src="preview.url"
                 x-bind:alt="preview.name"
                 class="media-library-modal-image">
        </div>
    </div>
</div>
</div>
