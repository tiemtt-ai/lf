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
    $selectedDisplayOrder = old(
        'display_order',
        $formSection?->display_order ?? $suggestedDisplayOrder ?? null
    );
@endphp

<div class="admin-form-flow course-template-section-form"
     x-data="{
         selectedParentId: @js((string) ($selectedParentId ?? '')),
         selectedDisplayOrder: @js((int) $selectedDisplayOrder),
         nextDisplayOrders: @js($nextDisplayOrders ?? []),
         syncDisplayOrder(parentId) {
             if (@js($formSection === null)) {
                 this.selectedDisplayOrder = Number(
                     this.nextDisplayOrders[parentId || 'root'] ?? 1
                 );
             }
         },
     }">
    <section class="admin-form-standard-section" aria-labelledby="section-information">
        <header class="admin-form-section-header">
            <h2 id="section-information" class="admin-form-section-title">{{ __('lf.LF_course_template_section_group_basic') }}</h2>
            <p class="admin-form-section-help">{{ __('lf.LF_course_template_section_group_basic_help') }}</p>
        </header>

        <div class="admin-form-field-grid">
            <div class="lf-form-group admin-form-field--full">
                <x-form-label for="title"
                              :value="__('lf.LF_course_template_section_common_name')"
                              :required="$isRequired('title')" />
                <input id="title" type="text" name="title" class="lf-form-control"
                       value="{{ old('title', $formSection?->title) }}"
                       placeholder="{{ __('lf.LF_course_template_section_placeholder_name') }}"
                       required maxlength="255"
                       @error('title') aria-invalid="true" aria-describedby="title_error" @enderror>
                @error('title')<p id="title_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>

            <div class="lf-form-group admin-form-field--full">
                <x-form-label for="description"
                              :value="__('lf.LF_course_template_section_common_description')"
                              :required="$isRequired('description')" />
                <textarea id="description" name="description" class="lf-form-control"
                          rows="4" placeholder="{{ __('lf.LF_course_template_section_placeholder_description') }}"
                          @error('description') aria-invalid="true" aria-describedby="description_error" @enderror>{{ old('description', $formSection?->description) }}</textarea>
                @error('description')<p id="description_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>

    <section class="admin-form-standard-section" aria-labelledby="section-organization">
        <header class="admin-form-section-header">
            <h2 id="section-organization" class="admin-form-section-title">{{ __('lf.LF_course_template_section_group_organization') }}</h2>
            <p class="admin-form-section-help">{{ __('lf.LF_course_template_section_group_organization_help') }}</p>
        </header>

        <div class="admin-form-field-grid admin-form-field-grid--three">
            <div class="lf-form-group">
                <x-form-label for="parent_section_id"
                              :value="__('lf.LF_course_template_section_common_parent')"
                              :required="$isRequired('parent_section_id')" />
                <select id="parent_section_id" name="parent_section_id" class="lf-form-control"
                        x-model="selectedParentId"
                        x-on:change="syncDisplayOrder($event.target.value)"
                        @error('parent_section_id') aria-invalid="true" aria-describedby="parent_section_id_error" @enderror>
                    <option value="">{{ __('lf.LF_course_template_section_common_root') }}</option>
                    @foreach ($parentSections as $parentSection)
                        <option value="{{ $parentSection->id }}"
                                @selected((string) $selectedParentId === (string) $parentSection->id)>
                            {{ str_repeat('— ', $parentSection->depth) }}{{ $parentSection->title }}
                        </option>
                    @endforeach
                </select>
                @error('parent_section_id')<p id="parent_section_id_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>

            <div class="lf-form-group">
                <x-form-label for="allows_lessons"
                              :value="__('lf.LF_course_template_section_common_allows_lessons')"
                              :required="$isRequired('allows_lessons')" />
                <select id="allows_lessons" name="allows_lessons"
                        class="lf-form-control" required
                        @error('allows_lessons') aria-invalid="true" aria-describedby="allows_lessons_error" @enderror>
                    <option value="1" @selected($selectedAllowsLessons === '1')>
                        {{ __('lf.LF_course_template_section_common_yes') }}
                    </option>
                    <option value="0" @selected($selectedAllowsLessons === '0')>
                        {{ __('lf.LF_course_template_section_common_no') }}
                    </option>
                </select>
                @error('allows_lessons')<p id="allows_lessons_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>

            <div class="lf-form-group">
                <x-form-label for="display_order"
                              :value="__('lf.LF_course_template_section_common_sort_order')"
                              :required="$isRequired('display_order')" />
                <input id="display_order" type="number" min="0" name="display_order"
                       @class(['lf-form-control', 'admin-form-readonly' => ! $formSection])
                       x-model.number="selectedDisplayOrder"
                       value="{{ $selectedDisplayOrder }}"
                       placeholder="{{ __('lf.LF_course_template_section_placeholder_sort_order') }}"
                       @if (! $formSection) readonly @endif
                       @required($isRequired('display_order'))
                       @error('display_order') aria-invalid="true" aria-describedby="display_order_error" @enderror>
                @error('display_order')<p id="display_order_error" class="lf-form-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>
</div>
