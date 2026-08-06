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

<div class="admin-form-flow"
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
    <section class="admin-form-standard-section" aria-labelledby="course-category-general">
        <header class="admin-form-section-header">
            <h2 id="course-category-general" class="admin-form-section-title">{{ __('lf.LF_course_category_group_general') }}</h2>
        </header>
        <div class="admin-form-field-grid">
            <div class="lf-form-group admin-form-field">
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

            <div class="lf-form-group admin-form-field">
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

            <div class="lf-form-group admin-form-field--full">
                <div class="admin-form-label-row">
                    <x-form-label for="slug"
                                  :value="__('lf.LF_course_category_common_slug')" />
                    <span class="admin-form-label-metadata">{{ __('lf.LF_course_category_common_automatic') }}</span>
                </div>
                <input id="slug" type="text" name="slug" class="lf-form-control admin-form-readonly"
                       value="{{ $generatedSlug }}"
                       placeholder="{{ __('lf.LF_course_category_placeholder_slug') }}"
                       maxlength="255"
                       readonly
                       x-model="generatedSlug">
            </div>

            <div class="lf-form-group admin-form-field--full">
                <x-form-label for="description" :value="__('lf.LF_course_category_common_description')" />
                <textarea id="description" name="description" class="lf-form-control"
                          rows="3" placeholder="{{ __('lf.LF_course_category_placeholder_description') }}">{{ old('description', $formCategory?->description) }}</textarea>
            </div>

        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="course-category-media">
        <header class="admin-form-section-header">
            <h2 id="course-category-media" class="admin-form-section-title">{{ __('lf.LF_course_category_group_media') }}</h2>
        </header>
        <div class="admin-form-field-grid">
            <div class="lf-form-group admin-form-field">
                <p class="authoring-media-field-title">{{ __('lf.LF_course_category_common_thumbnail_image') }}</p>
                <div class="authoring-media-picker-row">
                @if ($thumbnailMedia ?? null)
                    <input type="hidden" name="remove_thumbnail_image_media" value="0">
                    <x-authoring-media-row
                        :presentation="$thumbnailPresentation"
                        :alt="$thumbnailMedia->display_name"
                        :display-name="$thumbnailMedia->display_name"
                        remove-name="remove_thumbnail_image_media"
                        :remove-label="__('lf.LF_course_category_common_remove_image')">
                        <button type="button" class="authoring-media-overlay-action" x-on:click.stop="openCategoryImagePreview(@js($thumbnailMedia->display_name), @js($thumbnailMedia->signed_url))">
                            <x-backend-icon name="eye" class="authoring-media-action-icon" />
                            <span class="sr-only">{{ __('lf.LF_course_category_common_view_image') }}</span>
                        </button>
                    </x-authoring-media-row>
                @elseif ($formCategory?->thumbnail_image)
                    <x-authoring-media-row
                        :presentation="['state' => 'image', 'kind' => 'image', 'url' => $formCategory->thumbnail_image]"
                        :alt="$formCategory->name"
                        :display-name="$formCategory->name">
                        <button type="button" class="authoring-media-overlay-action" x-on:click.stop="openCategoryImagePreview(@js($formCategory->name), @js($formCategory->thumbnail_image))">
                            <x-backend-icon name="eye" class="authoring-media-action-icon" />
                            <span class="sr-only">{{ __('lf.LF_course_category_common_view_image') }}</span>
                        </button>
                    </x-authoring-media-row>
                @endif
                <x-authoring-media-upload id="thumbnail_image_file" name="thumbnail_image_file" :label="(($thumbnailMedia ?? null) || $formCategory?->thumbnail_image) ? __('lf.LF_media_replace_image') : __('lf.LF_media_upload_image')" accept="image/*" />
                </div>
                <x-upload-hint :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
            </div>
            <div class="lf-form-group admin-form-field">
                <p class="authoring-media-field-title">{{ __('lf.LF_course_category_common_banner_image') }}</p>
                <div class="authoring-media-picker-row">
                @if ($bannerMedia ?? null)
                    <input type="hidden" name="remove_banner_image_media" value="0">
                    <x-authoring-media-row
                        :presentation="$bannerPresentation"
                        :alt="$bannerMedia->display_name"
                        :display-name="$bannerMedia->display_name"
                        remove-name="remove_banner_image_media"
                        :remove-label="__('lf.LF_course_category_common_remove_image')">
                        <button type="button" class="authoring-media-overlay-action" x-on:click.stop="openCategoryImagePreview(@js($bannerMedia->display_name), @js($bannerMedia->signed_url))">
                            <x-backend-icon name="eye" class="authoring-media-action-icon" />
                            <span class="sr-only">{{ __('lf.LF_course_category_common_view_image') }}</span>
                        </button>
                    </x-authoring-media-row>
                @elseif ($formCategory?->banner_image)
                    <x-authoring-media-row
                        :presentation="['state' => 'image', 'kind' => 'image', 'url' => $formCategory->banner_image]"
                        :alt="$formCategory->name"
                        :display-name="$formCategory->name">
                        <button type="button" class="authoring-media-overlay-action" x-on:click.stop="openCategoryImagePreview(@js($formCategory->name), @js($formCategory->banner_image))">
                            <x-backend-icon name="eye" class="authoring-media-action-icon" />
                            <span class="sr-only">{{ __('lf.LF_course_category_common_view_image') }}</span>
                        </button>
                    </x-authoring-media-row>
                @endif
                <x-authoring-media-upload id="banner_image_file" name="banner_image_file" :label="(($bannerMedia ?? null) || $formCategory?->banner_image) ? __('lf.LF_media_replace_image') : __('lf.LF_media_upload_image')" accept="image/*" />
                </div>
                <x-upload-hint :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
            </div>

        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="course-category-display">
        <header class="admin-form-section-header">
            <h2 id="course-category-display" class="admin-form-section-title">{{ __('lf.LF_course_category_group_display') }}</h2>
        </header>
        <div class="admin-form-field-grid">
            <div class="admin-form-option-group course-category-featured-option admin-form-field--full">
                <input type="hidden" name="is_featured" value="0">
                <label class="admin-form-option-panel admin-form-option-panel--compact">
                    <input id="is_featured" type="checkbox" name="is_featured" value="1"
                           @checked($isFeatured)>
                    <span><strong>{{ __('lf.LF_course_category_common_featured') }}</strong></span>
                </label>
            </div>

            <div class="lf-form-group admin-form-field--full">
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
    </section>

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
