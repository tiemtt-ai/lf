@php
    $formSection = $section ?? null;
    $selectedParentId = old(
        'parent_section_id',
        $formSection?->parent_section_id ?? request('parent_section_id')
    );
    $isRequired = static fn (string $field): bool => in_array($field, $requiredFields, true);
    $selectedAllowsLessons = (string) old(
        'allows_lessons',
        isset($formSection) ? (int) $formSection->allows_lessons : 1
    );
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
                    {{ str_repeat('— ', $parentSection->depth) }}{{ $parentSection->title }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="title"
                      :value="__('lf.LF_course_template_section_common_name')"
                      :required="$isRequired('title')" />
        <input id="title" type="text" name="title" class="lf-form-control"
               value="{{ old('title', $formSection?->title) }}" required maxlength="255">
    </div>

    <div class="lf-form-group">
        <x-form-label for="description"
                      :value="__('lf.LF_course_template_section_common_description')"
                      :required="$isRequired('description')" />
        <textarea id="description" name="description" class="lf-form-control"
                  rows="5">{{ old('description', $formSection?->description) }}</textarea>
    </div>

    <div class="lf-form-group">
        <x-form-label for="allows_lessons"
                      :value="__('lf.LF_course_template_section_common_allows_lessons')"
                      :required="$isRequired('allows_lessons')" />
        <select id="allows_lessons" name="allows_lessons"
                class="lf-form-control" required>
            <option value="1" @selected($selectedAllowsLessons === '1')>
                {{ __('lf.LF_course_template_section_common_yes') }}
            </option>
            <option value="0" @selected($selectedAllowsLessons === '0')>
                {{ __('lf.LF_course_template_section_common_no') }}
            </option>
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="display_order"
                      :value="__('lf.LF_course_template_section_common_sort_order')"
                      :required="$isRequired('display_order')" />
        <input id="display_order" type="number" min="0" name="display_order"
               class="lf-form-control"
               value="{{ old('display_order', $formSection?->display_order ?? 1) }}"
               required>
    </div>
</section>
