@php
    $formCategory = $category ?? null;
    $selectedParentId = old('parent_id', $formCategory?->parent_id);
    $selectedStatus = old('status', $formCategory?->status ?? 'active');
    $isFeatured = (bool) old('is_featured', $formCategory?->is_featured ?? false);
@endphp

<div class="lf-form-group">
    <label class="lf-form-label" for="parent_id">
        {{ __('lf.LF_course_category_common_parent') }}
    </label>
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
    <label class="lf-form-label" for="name">
        {{ __('lf.LF_course_category_common_name') }}
    </label>
    <input id="name" type="text" name="name" class="lf-form-control"
           value="{{ old('name', $formCategory?->name) }}" required maxlength="255">
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="slug">
        {{ __('lf.LF_course_category_common_slug') }}
    </label>
    <input id="slug" type="text" name="slug" class="lf-form-control"
           value="{{ old('slug', $formCategory?->slug) }}" required maxlength="255">
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="description">
        {{ __('lf.LF_course_category_common_description') }}
    </label>
    <textarea id="description" name="description" class="lf-form-control"
              rows="4">{{ old('description', $formCategory?->description) }}</textarea>
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="thumbnail_image">
        {{ __('lf.LF_course_category_common_thumbnail_image') }}
    </label>
    <input id="thumbnail_image" type="text" name="thumbnail_image" class="lf-form-control"
           value="{{ old('thumbnail_image', $formCategory?->thumbnail_image) }}" maxlength="500">
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="banner_image">
        {{ __('lf.LF_course_category_common_banner_image') }}
    </label>
    <input id="banner_image" type="text" name="banner_image" class="lf-form-control"
           value="{{ old('banner_image', $formCategory?->banner_image) }}" maxlength="500">
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="sort_order">
        {{ __('lf.LF_course_category_common_sort_order') }}
    </label>
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

<div class="lf-form-group">
    <label class="lf-form-label" for="meta_title">
        {{ __('lf.LF_course_category_common_meta_title') }}
    </label>
    <input id="meta_title" type="text" name="meta_title" class="lf-form-control"
           value="{{ old('meta_title', $formCategory?->meta_title) }}" maxlength="255">
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="meta_description">
        {{ __('lf.LF_course_category_common_meta_description') }}
    </label>
    <textarea id="meta_description" name="meta_description" class="lf-form-control"
              rows="3" maxlength="500">{{ old('meta_description', $formCategory?->meta_description) }}</textarea>
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="meta_keywords">
        {{ __('lf.LF_course_category_common_meta_keywords') }}
    </label>
    <input id="meta_keywords" type="text" name="meta_keywords" class="lf-form-control"
           value="{{ old('meta_keywords', $formCategory?->meta_keywords) }}" maxlength="500">
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="status">
        {{ __('lf.LF_course_category_common_status') }}
    </label>
    <select id="status" name="status" class="lf-form-control" required>
        <option value="active" @selected($selectedStatus === 'active')>
            {{ __('lf.LF_course_category_common_active') }}
        </option>
        <option value="inactive" @selected($selectedStatus === 'inactive')>
            {{ __('lf.LF_course_category_common_inactive') }}
        </option>
    </select>
</div>
