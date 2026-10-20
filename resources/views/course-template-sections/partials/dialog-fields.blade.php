@php
    $formSection = $section ?? null;
    $isRequired = static fn (string $field): bool => in_array($field, $requiredFields, true);
    $star = '<span class="ui-field__required" aria-hidden="true">*</span>';
    $selectedParentId = $formSection?->parent_section_id ?? $parentSectionId ?? null;
    $selectedAllowsLessons = isset($formSection) ? (string) (int) $formSection->allows_lessons : '1';
    $selectedDisplayOrder = $formSection?->display_order ?? $suggestedDisplayOrder ?? null;
    $selectAttributes = 'data-ui-kit-select data-ui-empty-label="'.e(__('lf.LF_course_template_lesson_dialog_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_common_button_clear')).'"';
@endphp

<div x-data="{
         displayOrder: @js($selectedDisplayOrder === null ? null : (int) $selectedDisplayOrder),
         nextDisplayOrders: @js($nextDisplayOrders ?? []),
         isNew: @js($formSection === null),
         syncDisplayOrder(parentId) {
             if (this.isNew) this.displayOrder = Number(this.nextDisplayOrders[parentId || 'root'] ?? 1);
         },
     }">
    <div class="ui-modal__grid">
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="section-form-title">@if ($isRequired('title')){!! $star !!}@endif{{ __('lf.LF_course_template_section_common_name') }}</label>
            <input id="section-form-title" type="text" name="title" value="{{ $formSection?->title }}" maxlength="255" required
                   placeholder="{{ __('lf.LF_course_template_section_placeholder_name') }}">
        </div>

        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="section-form-description">{{ __('lf.LF_course_template_section_common_description') }}</label>
            <textarea id="section-form-description" name="description" rows="3"
                      placeholder="{{ __('lf.LF_course_template_section_placeholder_description') }}">{{ $formSection?->description }}</textarea>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="section-form-parent_section_id">{{ __('lf.LF_course_template_section_common_parent') }}</label>
            <select id="section-form-parent_section_id" name="parent_section_id" {!! $selectAttributes !!}
                    x-on:change="syncDisplayOrder($event.target.value)">
                <option value="">{{ __('lf.LF_course_template_section_common_root') }}</option>
                @foreach ($parentSections as $parentSection)
                    <option value="{{ $parentSection->id }}" @selected((string) $selectedParentId === (string) $parentSection->id)>
                        {{ str_repeat('— ', $parentSection->depth) }}{{ $parentSection->title }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="section-form-allows_lessons">{!! $star !!}{{ __('lf.LF_course_template_section_common_allows_lessons') }}</label>
            <select id="section-form-allows_lessons" name="allows_lessons" required {!! $selectAttributes !!}>
                <option value="1" @selected($selectedAllowsLessons === '1')>{{ __('lf.LF_course_template_section_common_yes') }}</option>
                <option value="0" @selected($selectedAllowsLessons === '0')>{{ __('lf.LF_course_template_section_common_no') }}</option>
            </select>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="section-form-display_order">@if ($isRequired('display_order')){!! $star !!}@endif{{ __('lf.LF_course_template_section_common_sort_order') }}</label>
            <input id="section-form-display_order" type="number" min="0" name="display_order"
                   x-model.number="displayOrder" value="{{ $selectedDisplayOrder }}"
                   placeholder="{{ __('lf.LF_course_template_section_placeholder_sort_order') }}"
                   @if (! $formSection) readonly @endif @required($isRequired('display_order'))>
        </div>
    </div>
</div>
