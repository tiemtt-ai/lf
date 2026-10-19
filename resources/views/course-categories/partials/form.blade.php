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

<div class="ui-form-flow"
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
    <section class="ui-section" aria-labelledby="course-category-general">
        <header class="ui-form-section-header">
            <h3 id="course-category-general" class="ui-form-section-title">{{ __('lf.LF_course_category_group_general') }}</h3>
        </header>
        <div class="ui-grid">
            <div class="ui-field">
                <label class="ui-field__label" for="parent_id">{{ __('lf.LF_course_category_common_parent') }}</label>
                <select id="parent_id" aria-invalid="{{ $errors->has('parent_id') ? 'true' : 'false' }}" @error('parent_id') aria-describedby="parent_id-error" @enderror name="parent_id" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_category_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_category_common_clear_selection') }}"
                        x-model="selectedParentId">
                    <option value="" @selected($selectedParentId === null || $selectedParentId === '')>{{ __('lf.LF_course_category_select_parent') }}</option>
                    @foreach ($parentCategories as $parentCategory)
                        <option value="{{ $parentCategory->id }}"
                                @selected((string) $selectedParentId === (string) $parentCategory->id)>
                            {{ $parentCategory->name }}
                        </option>
                    @endforeach
                </select>
                @error('parent_id')<span class="ui-field__error" id="parent_id-error" role="alert">{{ $message }}</span>@enderror
            </div>

            <div class="ui-field">
                <label class="ui-field__label" for="name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_category_common_name') }}</label>
                <input id="name" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" @error('name') aria-describedby="name-error" @enderror type="text" name="name"
                       value="{{ old('name', $formCategory?->name) }}"
                       placeholder="{{ __('lf.LF_course_category_placeholder_name') }}"
                       required
                       maxlength="255"
                       @input="syncSlug($event.target.value)">
                @error('name')<span class="ui-field__error" id="name-error" role="alert">{{ $message }}</span>@enderror
            </div>

            <div class="ui-field ui-field--full">
                <div class="ui-form-label-row">
                    <label class="ui-field__label" for="slug">{{ __('lf.LF_course_category_common_slug') }}</label>
                    <span class="ui-caption">{{ __('lf.LF_course_category_common_automatic') }}</span>
                </div>
                <input id="slug" aria-invalid="{{ $errors->has('slug') ? 'true' : 'false' }}" @error('slug') aria-describedby="slug-error" @enderror type="text" name="slug"
                       value="{{ $generatedSlug }}"
                       placeholder="{{ __('lf.LF_course_category_placeholder_slug') }}"
                       maxlength="255"
                       readonly
                       x-model="generatedSlug">
                @error('slug')<span class="ui-field__error" id="slug-error" role="alert">{{ $message }}</span>@enderror
            </div>

            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="description">{{ __('lf.LF_course_category_common_description') }}</label>
                <textarea id="description" aria-invalid="{{ $errors->has('description') ? 'true' : 'false' }}" @error('description') aria-describedby="description-error" @enderror name="description"
                          rows="3" placeholder="{{ __('lf.LF_course_category_placeholder_description') }}">{{ old('description', $formCategory?->description) }}</textarea>
                @error('description')<span class="ui-field__error" id="description-error" role="alert">{{ $message }}</span>@enderror
            </div>

        </div>
    </section>

    <section class="ui-section" aria-labelledby="course-category-media">
        <header class="ui-form-section-header">
            <h3 id="course-category-media" class="ui-form-section-title">{{ __('lf.LF_course_category_group_media') }}</h3>
        </header>
        <div class="ui-grid">
            <div class="ui-field">
                <p class="ui-field__label" id="thumbnail-image-label">{{ __('lf.LF_course_category_common_thumbnail_image') }}</p>
                <div class="ui-form-media">
                @if ($thumbnailMedia ?? null)
                    <input type="hidden" name="remove_thumbnail_image_media" value="0">
                    <x-authoring-media-row ui-kit
                        :presentation="$thumbnailPresentation"
                        :alt="$thumbnailMedia->display_name"
                        :display-name="$thumbnailMedia->display_name"
                        remove-name="remove_thumbnail_image_media"
                        :remove-label="__('lf.LF_course_category_common_remove_image')">
                        <button type="button" class="ui-button" x-on:click.stop="openCategoryImagePreview(@js($thumbnailMedia->display_name), @js($thumbnailMedia->signed_url))">
                            <x-backend-icon name="eye" class="ui-icon" />
                            <span class="sr-only">{{ __('lf.LF_course_category_common_view_image') }}</span>
                        </button>
                    </x-authoring-media-row>
                @elseif ($formCategory?->thumbnail_image)
                    <x-authoring-media-row ui-kit
                        :presentation="['state' => 'image', 'kind' => 'image', 'url' => $formCategory->thumbnail_image]"
                        :alt="$formCategory->name"
                        :display-name="$formCategory->name">
                        <button type="button" class="ui-button" x-on:click.stop="openCategoryImagePreview(@js($formCategory->name), @js($formCategory->thumbnail_image))">
                            <x-backend-icon name="eye" class="ui-icon" />
                            <span class="sr-only">{{ __('lf.LF_course_category_common_view_image') }}</span>
                        </button>
                    </x-authoring-media-row>
                @endif
                <x-authoring-media-upload ui-kit aria-labelledby="thumbnail-image-label" id="thumbnail_image_file" name="thumbnail_image_file" :label="(($thumbnailMedia ?? null) || $formCategory?->thumbnail_image) ? __('lf.LF_media_replace_image') : __('lf.LF_media_upload_image')" accept="image/*" />
                </div>
                <x-upload-hint ui-kit :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
            </div>
            <div class="ui-field">
                <p class="ui-field__label" id="banner-image-label">{{ __('lf.LF_course_category_common_banner_image') }}</p>
                <div class="ui-form-media">
                @if ($bannerMedia ?? null)
                    <input type="hidden" name="remove_banner_image_media" value="0">
                    <x-authoring-media-row ui-kit
                        :presentation="$bannerPresentation"
                        :alt="$bannerMedia->display_name"
                        :display-name="$bannerMedia->display_name"
                        remove-name="remove_banner_image_media"
                        :remove-label="__('lf.LF_course_category_common_remove_image')">
                        <button type="button" class="ui-button" x-on:click.stop="openCategoryImagePreview(@js($bannerMedia->display_name), @js($bannerMedia->signed_url))">
                            <x-backend-icon name="eye" class="ui-icon" />
                            <span class="sr-only">{{ __('lf.LF_course_category_common_view_image') }}</span>
                        </button>
                    </x-authoring-media-row>
                @elseif ($formCategory?->banner_image)
                    <x-authoring-media-row ui-kit
                        :presentation="['state' => 'image', 'kind' => 'image', 'url' => $formCategory->banner_image]"
                        :alt="$formCategory->name"
                        :display-name="$formCategory->name">
                        <button type="button" class="ui-button" x-on:click.stop="openCategoryImagePreview(@js($formCategory->name), @js($formCategory->banner_image))">
                            <x-backend-icon name="eye" class="ui-icon" />
                            <span class="sr-only">{{ __('lf.LF_course_category_common_view_image') }}</span>
                        </button>
                    </x-authoring-media-row>
                @endif
                <x-authoring-media-upload ui-kit aria-labelledby="banner-image-label" id="banner_image_file" name="banner_image_file" :label="(($bannerMedia ?? null) || $formCategory?->banner_image) ? __('lf.LF_media_replace_image') : __('lf.LF_media_upload_image')" accept="image/*" />
                </div>
                <x-upload-hint ui-kit :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
            </div>

        </div>
    </section>

    <section class="ui-section" aria-labelledby="course-category-display">
        <header class="ui-form-section-header">
            <h3 id="course-category-display" class="ui-form-section-title">{{ __('lf.LF_course_category_group_display') }}</h3>
        </header>
        <div class="ui-grid">
            <div class="ui-field">
                <input type="hidden" name="is_featured" value="0">
                <label class="ui-check ui-switch">
                    <input id="is_featured" type="checkbox" role="switch" name="is_featured" value="1"
                           @checked($isFeatured)>
                    <span>{{ __('lf.LF_course_category_common_featured') }}</span>
                </label>
            </div>

            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="status"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_category_common_status') }}</label>
                <select id="status" aria-invalid="{{ $errors->has('status') ? 'true' : 'false' }}" @error('status') aria-describedby="status-error" @enderror name="status" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_category_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_category_common_clear_selection') }}" required>
                    <option value="active" @selected($selectedStatus === 'active')>
                        {{ __('lf.LF_course_category_common_active') }}
                    </option>
                    <option value="inactive" @selected($selectedStatus === 'inactive')>
                        {{ __('lf.LF_course_category_common_inactive') }}
                    </option>
                </select>
                @error('status')<span class="ui-field__error" id="status-error" role="alert">{{ $message }}</span>@enderror
            </div>
        </div>
    </section>

<x-media-preview-modal ui-kit id="course-category-preview-title" show="previewOpen" close="closeCategoryImagePreview()" title="preview.name" compact="preview.mediaType === 'audio' || preview.type === 'audio'">

            <img x-show="previewOpen"
                 x-bind:src="preview.url"
                 x-bind:alt="preview.name"
                 >

</x-media-preview-modal>
</div>
