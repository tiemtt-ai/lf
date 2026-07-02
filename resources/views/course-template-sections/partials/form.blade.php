@php
    $formSection = $section ?? null;
    $selectedParentId = old('parent_section_id', $formSection?->parent_section_id);
    $selectedRequired = (string) old('is_required', $formSection?->is_required ?? 1);
    $selectedUnlockRule = old('unlock_rule', $formSection?->unlock_rule ?? 'immediate');
    $selectedStatus = old('status', $formSection?->status ?? 'active');
    $isRequired = static fn (string $field): bool => in_array($field, $requiredFields, true);
@endphp

<section class="admin-form-section" aria-labelledby="course-template-section-basic-title">
    <h2 id="course-template-section-basic-title" class="admin-form-section-title">
        {{ __('lf.LF_course_template_section_group_basic') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="parent_section_id"
                      :value="__('lf.LF_course_template_section_common_parent')"
                      :required="$isRequired('parent_section_id')" />
        <select id="parent_section_id" name="parent_section_id" class="lf-form-control">
            <option value="">{{ __('lf.LF_course_template_section_common_root') }}</option>
            @foreach ($parentSections as $parentSection)
                <option value="{{ $parentSection->id }}"
                        @selected((string) $selectedParentId === (string) $parentSection->id)>
                    {{ $parentSection->title }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="code"
                      :value="__('lf.LF_course_template_section_common_code')"
                      :required="$isRequired('code')" />
        <input id="code" type="text" name="code" class="lf-form-control"
               value="{{ old('code', $formSection?->code) }}" maxlength="100">
    </div>

    <div class="lf-form-group">
        <x-form-label for="title"
                      :value="__('lf.LF_course_template_section_common_name')"
                      :required="$isRequired('title')" />
        <input id="title" type="text" name="title" class="lf-form-control"
               value="{{ old('title', $formSection?->title) }}" required maxlength="255">
    </div>

    <div class="lf-form-group">
        <x-form-label for="short_title"
                      :value="__('lf.LF_course_template_section_common_short_title')"
                      :required="$isRequired('short_title')" />
        <input id="short_title" type="text" name="short_title" class="lf-form-control"
               value="{{ old('short_title', $formSection?->short_title) }}" maxlength="100">
    </div>

    <div class="lf-form-group">
        <x-form-label for="description"
                      :value="__('lf.LF_course_template_section_common_description')"
                      :required="$isRequired('description')" />
        <textarea id="description" name="description" class="lf-form-control"
                  rows="5">{{ old('description', $formSection?->description) }}</textarea>
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-template-section-display-title">
    <h2 id="course-template-section-display-title" class="admin-form-section-title">
        {{ __('lf.LF_course_template_section_group_display') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="thumbnail_file_id"
                      :value="__('lf.LF_course_template_section_common_thumbnail_file_id')"
                      :required="$isRequired('thumbnail_file_id')" />
        <input id="thumbnail_file_id" type="number" min="1"
               name="thumbnail_file_id" class="lf-form-control"
               value="{{ old('thumbnail_file_id', $formSection?->thumbnail_file_id) }}">
    </div>

    <div class="lf-form-group">
        <x-form-label for="sort_order"
                      :value="__('lf.LF_course_template_section_common_sort_order')"
                      :required="$isRequired('sort_order')" />
        <input id="sort_order" type="number" min="0" name="sort_order"
               class="lf-form-control"
               value="{{ old('sort_order', $formSection?->sort_order ?? 1) }}" required>
    </div>

    <div class="lf-form-group">
        <x-form-label for="is_required"
                      :value="__('lf.LF_course_template_section_common_required')"
                      :required="$isRequired('is_required')" />
        <select id="is_required" name="is_required" class="lf-form-control" required>
            <option value="1" @selected($selectedRequired === '1')>
                {{ __('lf.LF_course_template_section_common_yes') }}
            </option>
            <option value="0" @selected($selectedRequired === '0')>
                {{ __('lf.LF_course_template_section_common_no') }}
            </option>
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="unlock_rule"
                      :value="__('lf.LF_course_template_section_common_unlock_rule')"
                      :required="$isRequired('unlock_rule')" />
        <select id="unlock_rule" name="unlock_rule" class="lf-form-control" required>
            @foreach (['immediate', 'after_previous_section', 'manual'] as $unlockRule)
                <option value="{{ $unlockRule }}" @selected($selectedUnlockRule === $unlockRule)>
                    {{ __('lf.LF_course_template_section_common_unlock_'.$unlockRule) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="estimated_duration_minutes"
                      :value="__('lf.LF_course_template_section_common_estimated_duration_minutes')"
                      :required="$isRequired('estimated_duration_minutes')" />
        <input id="estimated_duration_minutes" type="number" min="0"
               name="estimated_duration_minutes" class="lf-form-control"
               value="{{ old('estimated_duration_minutes', $formSection?->estimated_duration_minutes) }}">
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-template-section-status-title">
    <h2 id="course-template-section-status-title" class="admin-form-section-title">
        {{ __('lf.LF_course_template_section_group_status') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="status"
                      :value="__('lf.LF_course_template_section_common_status')"
                      :required="$isRequired('status')" />
        <select id="status" name="status" class="lf-form-control" required>
            @foreach (['active', 'inactive', 'archived'] as $status)
                <option value="{{ $status }}" @selected($selectedStatus === $status)>
                    {{ __('lf.LF_course_template_section_common_'.$status) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="metadata"
                      :value="__('lf.LF_course_template_section_common_metadata')"
                      :required="$isRequired('metadata')" />
        <textarea id="metadata" name="metadata" class="lf-form-control"
                  rows="4"
                  placeholder='{"color":"#0EA5E9","icon":"book-open"}'>{{ old('metadata', $formSection?->metadata) }}</textarea>
    </div>
</section>
