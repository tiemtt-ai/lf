@php
    $formCategory = $category ?? null;
    $selectedParentId = old('parent_id', $formCategory?->parent_id);
    $selectedStatus = old('status', $formCategory?->status ?? 'active');
@endphp

<div class="lf-form-group">
    <label class="lf-form-label" for="parent_id">
        {{ __('lf.LF_media_category_common_parent') }}
    </label>
    <select id="parent_id" name="parent_id" class="lf-form-control">
        <option value="">{{ __('lf.LF_media_category_common_root') }}</option>
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
        {{ __('lf.LF_media_category_common_name') }}
    </label>
    <input id="name" type="text" name="name" class="lf-form-control"
           value="{{ old('name', $formCategory?->name) }}" required maxlength="255">
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="slug">
        {{ __('lf.LF_media_category_common_slug') }}
    </label>
    <input id="slug" type="text" name="slug" class="lf-form-control"
           value="{{ old('slug', $formCategory?->slug) }}" required maxlength="255">
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="description">
        {{ __('lf.LF_media_category_common_description') }}
    </label>
    <textarea id="description" name="description" class="lf-form-control"
              rows="4">{{ old('description', $formCategory?->description) }}</textarea>
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="icon">
        {{ __('lf.LF_media_category_common_icon') }}
    </label>
    <input id="icon" type="text" name="icon" class="lf-form-control"
           value="{{ old('icon', $formCategory?->icon) }}" maxlength="100">
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="color">
        {{ __('lf.LF_media_category_common_color') }}
    </label>
    <input id="color" type="text" name="color" class="lf-form-control"
           value="{{ old('color', $formCategory?->color) }}" maxlength="32">
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="sort_order">
        {{ __('lf.LF_media_category_common_sort_order') }}
    </label>
    <input id="sort_order" type="number" name="sort_order" class="lf-form-control"
           value="{{ old('sort_order', $formCategory?->sort_order ?? 1) }}" required min="0">
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="status">
        {{ __('lf.LF_media_category_common_status') }}
    </label>
    <select id="status" name="status" class="lf-form-control" required>
        <option value="active" @selected($selectedStatus === 'active')>
            {{ __('lf.LF_media_category_common_active') }}
        </option>
        <option value="archived" @selected($selectedStatus === 'archived')>
            {{ __('lf.LF_media_category_common_archived') }}
        </option>
    </select>
</div>

<div class="lf-form-group">
    <label class="lf-form-label" for="metadata">
        {{ __('lf.LF_media_category_common_metadata') }}
    </label>
    <textarea id="metadata" name="metadata" class="lf-form-control"
              rows="4">{{ old('metadata', $formCategory?->metadata) }}</textarea>
</div>
