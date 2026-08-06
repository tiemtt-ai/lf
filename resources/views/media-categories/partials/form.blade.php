@php
    $formCategory = $category ?? null;
    $selectedParentId = old('parent_id', $formCategory?->parent_id);
    $selectedStatus = old('status', $formCategory?->status ?? 'active');
@endphp

<div class="admin-form-flow">
<section class="admin-form-standard-section" aria-labelledby="media-category-general">
    <header class="admin-form-section-header">
        <h2 id="media-category-general" class="admin-form-section-title">{{ __('lf.LF_media_category_group_general') }}</h2>
    </header>
    <div class="admin-form-field-grid">
<div class="lf-form-group admin-form-field">
    <x-form-label for="parent_id" :value="__('lf.LF_media_category_common_parent')" />
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

<div class="lf-form-group admin-form-field">
    <x-form-label for="name" :value="__('lf.LF_media_category_common_name')" required />
    <input id="name" type="text" name="name" class="lf-form-control"
           value="{{ old('name', $formCategory?->name) }}" required maxlength="255">
</div>

<div class="lf-form-group admin-form-field--full">
    <x-form-label for="slug" :value="__('lf.LF_media_category_common_slug')" required />
    <input id="slug" type="text" name="slug" class="lf-form-control"
           value="{{ old('slug', $formCategory?->slug) }}" required maxlength="255">
</div>

<div class="lf-form-group admin-form-field--full">
    <x-form-label for="description" :value="__('lf.LF_media_category_common_description')" />
    <textarea id="description" name="description" class="lf-form-control"
              rows="4">{{ old('description', $formCategory?->description) }}</textarea>
</div>
    </div>
</section>

<section class="admin-form-standard-section" aria-labelledby="media-category-display">
    <header class="admin-form-section-header">
        <h2 id="media-category-display" class="admin-form-section-title">{{ __('lf.LF_media_category_group_display') }}</h2>
    </header>
    <div class="admin-form-field-grid">
<div class="lf-form-group admin-form-field">
    <x-form-label for="icon" :value="__('lf.LF_media_category_common_icon')" />
    <input id="icon" type="text" name="icon" class="lf-form-control"
           value="{{ old('icon', $formCategory?->icon) }}" maxlength="100">
</div>

<div class="lf-form-group admin-form-field">
    <x-form-label for="color" :value="__('lf.LF_media_category_common_color')" />
    <input id="color" type="text" name="color" class="lf-form-control"
           value="{{ old('color', $formCategory?->color) }}" maxlength="32">
</div>

<div class="lf-form-group admin-form-field">
    <x-form-label for="status" :value="__('lf.LF_media_category_common_status')" required />
    <select id="status" name="status" class="lf-form-control" required>
        <option value="active" @selected($selectedStatus === 'active')>
            {{ __('lf.LF_media_category_common_active') }}
        </option>
        <option value="archived" @selected($selectedStatus === 'archived')>
            {{ __('lf.LF_media_category_common_archived') }}
        </option>
    </select>
</div>

<div class="lf-form-group admin-form-field--full">
    <x-form-label for="metadata" :value="__('lf.LF_media_category_common_metadata')" />
    <textarea id="metadata" name="metadata" class="lf-form-control"
              rows="4">{{ old('metadata', $formCategory?->metadata) }}</textarea>
</div>
    </div>
</section>
</div>
