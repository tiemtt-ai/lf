@php
    $formTemplate = $template ?? null;
    $selectedCategoryId = old('category_id', $formTemplate?->category_id);
    $selectedThumbnailType = old('thumbnail_type', $formTemplate?->thumbnail_type ?? 'image');
    $selectedDifficulty = old('difficulty_level', $formTemplate?->difficulty_level);
    $selectedStatus = old('status', $formTemplate?->status ?? 'draft');
    $isRequired = static fn (string $field): bool => in_array($field, $requiredFields, true);
@endphp

<div class="backend-form-columns">
    <div class="backend-form-column">
<section class="admin-form-section" aria-labelledby="course-template-basic-title">
    <h2 id="course-template-basic-title" class="admin-form-section-title">
        {{ __('lf.LF_course_template_group_basic') }}
    </h2>

<div class="lf-form-group">
    <x-form-label for="category_id"
                  :value="__('lf.LF_course_template_common_category')"
                  :required="$isRequired('category_id')" />
    <select id="category_id" name="category_id" class="lf-form-control">
        <option value="">{{ __('lf.LF_course_template_common_no_category') }}</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}"
                    @selected((string) $selectedCategoryId === (string) $category->id)>
                {{ $category->name }}
            </option>
        @endforeach
    </select>
</div>

<div class="lf-form-group">
    <x-form-label for="title"
                  :value="__('lf.LF_course_template_common_name')"
                  :required="$isRequired('title')" />
    <input id="title" type="text" name="title" class="lf-form-control"
           value="{{ old('title', $formTemplate?->title) }}" required maxlength="255">
</div>

<div class="lf-form-group">
    <x-form-label for="slug"
                  :value="__('lf.LF_course_template_common_slug')"
                  :required="$isRequired('slug')" />
    <input id="slug" type="text" name="slug" class="lf-form-control"
           value="{{ old('slug', $formTemplate?->slug) }}" required maxlength="255">
</div>

<div class="lf-form-group">
    <x-form-label for="short_description"
                  :value="__('lf.LF_course_template_common_short_description')"
                  :required="$isRequired('short_description')" />
    <textarea id="short_description" name="short_description" class="lf-form-control"
              rows="2" maxlength="500">{{ old('short_description', $formTemplate?->short_description) }}</textarea>
</div>

<div class="lf-form-group">
    <x-form-label for="description"
                  :value="__('lf.LF_course_template_common_description')"
                  :required="$isRequired('description')" />
    <textarea id="description" name="description" class="lf-form-control"
              rows="5">{{ old('description', $formTemplate?->description) }}</textarea>
</div>

<div class="lf-form-group">
    <x-form-label for="publisher_name"
                  :value="__('lf.LF_course_template_common_publisher_name')"
                  :required="$isRequired('publisher_name')" />
    <input id="publisher_name" type="text" name="publisher_name" class="lf-form-control"
           value="{{ old('publisher_name', $formTemplate?->publisher_name) }}" maxlength="255">
</div>
</section>

<section class="admin-form-section" aria-labelledby="course-template-metadata-title">
    <h2 id="course-template-metadata-title" class="admin-form-section-title">
        {{ __('lf.LF_course_template_group_learning_metadata') }}
    </h2>

<div class="lf-form-group">
    <x-form-label for="difficulty_level"
                  :value="__('lf.LF_course_template_common_difficulty_level')"
                  :required="$isRequired('difficulty_level')" />
    <select id="difficulty_level" name="difficulty_level" class="lf-form-control">
        <option value="">{{ __('lf.LF_course_template_common_no_difficulty') }}</option>
        @foreach (['beginner', 'intermediate', 'advanced'] as $difficulty)
            <option value="{{ $difficulty }}" @selected($selectedDifficulty === $difficulty)>
                {{ __('lf.LF_course_template_common_'.$difficulty) }}
            </option>
        @endforeach
    </select>
</div>

<div class="lf-form-group">
    <x-form-label for="language"
                  :value="__('lf.LF_course_template_common_language')"
                  :required="$isRequired('language')" />
    <input id="language" type="text" name="language" class="lf-form-control"
           value="{{ old('language', $formTemplate?->language) }}" maxlength="20">
</div>

<div class="lf-form-group">
    <x-form-label for="estimated_duration_minutes"
                  :value="__('lf.LF_course_template_common_estimated_duration_minutes')"
                  :required="$isRequired('estimated_duration_minutes')" />
    <input id="estimated_duration_minutes" type="number" min="0"
           name="estimated_duration_minutes" class="lf-form-control"
           value="{{ old('estimated_duration_minutes', $formTemplate?->estimated_duration_minutes ?? 0) }}"
           required>
</div>

<div class="lf-form-group">
    <x-form-label for="max_lessons"
                  :value="__('lf.LF_course_template_common_max_lessons')"
                  :required="$isRequired('max_lessons')" />
    <input id="max_lessons" type="number" min="0" name="max_lessons" class="lf-form-control"
           value="{{ old('max_lessons', $formTemplate?->max_lessons) }}">
</div>
</section>
    </div>

    <div class="backend-form-column">

<section class="admin-form-section" aria-labelledby="course-template-media-title">
    <h2 id="course-template-media-title" class="admin-form-section-title">
        {{ __('lf.LF_course_template_group_media') }}
    </h2>

<div class="lf-form-group">
    <x-form-label for="thumbnail_type"
                  :value="__('lf.LF_course_template_common_thumbnail_type')"
                  :required="$isRequired('thumbnail_type')" />
    <select id="thumbnail_type" name="thumbnail_type" class="lf-form-control" required>
        <option value="image" @selected($selectedThumbnailType === 'image')>
            {{ __('lf.LF_course_template_common_thumbnail_image_type') }}
        </option>
        <option value="video" @selected($selectedThumbnailType === 'video')>
            {{ __('lf.LF_course_template_common_thumbnail_video_type') }}
        </option>
    </select>
</div>

<div class="lf-form-group">
    <x-form-label for="thumbnail_image"
                  :value="__('lf.LF_course_template_common_thumbnail_image')"
                  :required="$isRequired('thumbnail_image')" />
    <input id="thumbnail_image" type="text" name="thumbnail_image" class="lf-form-control"
           value="{{ old('thumbnail_image', $formTemplate?->thumbnail_image) }}" maxlength="500">
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
</div>

<div class="lf-form-group">
    <x-form-label for="thumbnail_video_source"
                  :value="__('lf.LF_course_template_common_thumbnail_video_source')"
                  :required="$isRequired('thumbnail_video_source')" />
    <select id="thumbnail_video_source" name="thumbnail_video_source" class="lf-form-control">
        <option value="">{{ __('lf.LF_course_template_common_no_video_source') }}</option>
        <option value="youtube"
                @selected(old('thumbnail_video_source', $formTemplate?->thumbnail_video_source) === 'youtube')>
            YouTube
        </option>
        <option value="aws"
                @selected(old('thumbnail_video_source', $formTemplate?->thumbnail_video_source) === 'aws')>
            AWS
        </option>
    </select>
</div>

<div class="lf-form-group">
    <x-form-label for="thumbnail_video_url"
                  :value="__('lf.LF_course_template_common_thumbnail_video_url')"
                  :required="$isRequired('thumbnail_video_url')" />
    <input id="thumbnail_video_url" type="url" name="thumbnail_video_url" class="lf-form-control"
           value="{{ old('thumbnail_video_url', $formTemplate?->thumbnail_video_url) }}" maxlength="1000">
</div>

<div class="lf-form-group">
    <x-form-label for="thumbnail_video_media_id"
                  :value="__('lf.LF_course_template_common_thumbnail_video_media_id')"
                  :required="$isRequired('thumbnail_video_media_id')" />
    <input id="thumbnail_video_media_id" type="number" min="1"
           name="thumbnail_video_media_id" class="lf-form-control"
           value="{{ old('thumbnail_video_media_id', $formTemplate?->thumbnail_video_media_id) }}">
</div>
</section>

<section class="admin-form-section" aria-labelledby="course-template-lifecycle-title">
    <h2 id="course-template-lifecycle-title" class="admin-form-section-title">
        {{ __('lf.LF_course_template_group_lifecycle') }}
    </h2>

<div class="lf-form-group">
    <x-form-label for="status"
                  :value="__('lf.LF_course_template_common_status')"
                  :required="$isRequired('status')" />
    <select id="status" name="status" class="lf-form-control" required>
        <option value="draft" @selected($selectedStatus === 'draft')>
            {{ __('lf.LF_course_template_common_draft') }}
        </option>
        <option value="active" @selected($selectedStatus === 'active')>
            {{ __('lf.LF_course_template_common_active') }}
        </option>
        <option value="archived" @selected($selectedStatus === 'archived')>
            {{ __('lf.LF_course_template_common_archived') }}
        </option>
    </select>
</div>
</section>
    </div>
</div>
