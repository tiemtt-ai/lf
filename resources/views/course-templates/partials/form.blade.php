@php
    $formTemplate = $template ?? null;
    $slugSource = old('title', $formTemplate?->title);
    $slugFollowsTitle = $formTemplate === null
        || (string) $formTemplate->slug === \Illuminate\Support\Str::slug((string) $formTemplate->title);
    $generatedSlug = $slugFollowsTitle
        ? \Illuminate\Support\Str::slug((string) $slugSource)
        : old('slug', $formTemplate?->slug);
    $selectedCategoryId = old('category_id', $formTemplate?->category_id);
    $selectedCoverType = old('cover_type', $formTemplate?->cover_type ?? 'image');
    $selectedDifficulty = old('difficulty_level', $formTemplate?->difficulty_level);
    $selectedStatus = old('status', $formTemplate?->status ?? 'draft');
    $isRequired = static fn (string $field): bool => in_array($field, $requiredFields, true);
@endphp

<div class="backend-form-columns"
     x-data="{
         generatedSlug: @js((string) $generatedSlug),
         slugFollowsTitle: @js($slugFollowsTitle),
         selectedCoverType: @js($selectedCoverType),
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
             if (this.slugFollowsTitle) {
                 this.generatedSlug = this.slugify(value);
             }
         },
     }">
    <div class="backend-form-column">
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
                   value="{{ old('title', $formTemplate?->title) }}"
                   required
                   maxlength="255"
                   @input="syncSlug($event.target.value)">
        </div>

        <div class="lf-form-group">
            <x-form-label for="slug"
                          :value="__('lf.LF_course_template_common_slug')" />
            <input id="slug" type="text" name="slug" class="lf-form-control"
                   value="{{ $generatedSlug }}"
                   maxlength="255"
                   readonly
                   x-model="generatedSlug">
        </div>

        <div class="lf-form-group">
            <x-form-label for="publisher_name"
                          :value="__('lf.LF_course_template_common_publisher_name')"
                          :required="$isRequired('publisher_name')" />
            <input id="publisher_name" type="text" name="publisher_name" class="lf-form-control"
                   value="{{ old('publisher_name', $formTemplate?->publisher_name) }}" maxlength="255">
        </div>

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
    </div>

    <div class="backend-form-column">
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

        <div class="lf-form-group">
            <x-form-label for="cover_type"
                          :value="__('lf.LF_course_template_common_cover_type')"
                          :required="$isRequired('cover_type')" />
            <div id="cover_type" class="admin-radio-group">
                <label>
                    <input type="radio"
                           name="cover_type"
                           value="image"
                           required
                           x-model="selectedCoverType"
                           @checked($selectedCoverType === 'image')>
                    <span>{{ __('lf.LF_course_template_common_cover_image_type') }}</span>
                </label>
                <label>
                    <input type="radio"
                           name="cover_type"
                           value="video"
                           required
                           x-model="selectedCoverType"
                           @checked($selectedCoverType === 'video')>
                    <span>{{ __('lf.LF_course_template_common_cover_video_type') }}</span>
                </label>
            </div>
        </div>

        <div class="lf-form-group" x-show="selectedCoverType === 'image'">
            <input type="hidden"
                   name="cover_image_media_file_id"
                   value="{{ old('cover_image_media_file_id', $formTemplate?->cover_image_media_file_id) }}"
                   :disabled="selectedCoverType !== 'image'">
            <x-form-label for="cover_image_file"
                          :value="__('lf.LF_course_template_common_cover_image')"
                          :required="true" />
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
                   accept="image/*"
                   :disabled="selectedCoverType !== 'image'">
        </div>

        <div class="lf-form-group" x-show="selectedCoverType === 'video'">
            <input type="hidden"
                   name="intro_video_media_file_id"
                   value="{{ old('intro_video_media_file_id', $formTemplate?->intro_video_media_file_id) }}"
                   :disabled="selectedCoverType !== 'video'">
            <x-form-label for="intro_video_file"
                          :value="__('lf.LF_course_template_common_intro_video')"
                          :required="true" />
            @if ($introVideoMedia ?? null)
                <div class="lf-form-help">
                    <a href="{{ $introVideoMedia->signed_url }}" target="_blank" rel="noopener">
                        {{ $introVideoMedia->display_name }}
                    </a>
                </div>
            @endif
            <input id="intro_video_file"
                   type="file"
                   name="intro_video_file"
                   class="lf-form-control"
                   accept="video/*"
                   :disabled="selectedCoverType !== 'video'">
        </div>

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
    </div>
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
