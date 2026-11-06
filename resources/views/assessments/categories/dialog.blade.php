@php
    $isEdit = $category !== null;
    $selectedParentId = (string) ($category?->parent_id ?? '');
    $selectedStatus = $category?->status ?? 'active';
@endphp
<form class="ui-modal__form" method="POST" novalidate
      action="{{ $isEdit ? route($routePrefix.'.update', $category->id) : route($routePrefix.'.store') }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <header class="ui-modal__header">
        <div>
            <h3 id="assessment-category-dialog-title">
                {{ $isEdit ? __('lf.LF_assessment_category_edit') : __('lf.LF_assessment_category_create') }}
            </h3>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="assessment-category-form-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_category_name') }}</label>
            <input id="assessment-category-form-name" type="text" name="name" value="{{ $category?->name }}"
                   placeholder="{{ __('lf.LF_assessment_category_name_placeholder') }}" required maxlength="255">
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="assessment-category-form-parent">{{ __('lf.LF_assessment_category_parent') }}</label>
            <select id="assessment-category-form-parent" name="parent_id" data-ui-kit-select
                    data-ui-empty-label="{{ __('lf.LF_assessment_category_no_options') }}"
                    data-ui-clear-label="{{ __('lf.LF_assessment_category_clear_selection') }}">
                <option value="" @selected($selectedParentId === '')>{{ __('lf.LF_assessment_category_root') }}</option>
                @foreach ($parentOptions as $parent)
                    <option value="{{ $parent->id }}" @selected($selectedParentId === (string) $parent->id)>{{ $parent->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="assessment-category-form-status"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_assessment_category_status') }}</label>
            <select id="assessment-category-form-status" name="status" data-ui-kit-select required
                    data-ui-empty-label="{{ __('lf.LF_assessment_category_no_options') }}"
                    data-ui-clear-label="{{ __('lf.LF_assessment_category_clear_selection') }}">
                @foreach (\App\Services\Assessment\AssessmentCategoryService::STATUSES as $statusOption)
                    <option value="{{ $statusOption }}" @selected($selectedStatus === $statusOption)>{{ __('lf.LF_assessment_category_status_label_'.$statusOption) }}</option>
                @endforeach
            </select>
        </div>
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="assessment-category-form-description">{{ __('lf.LF_assessment_category_description') }}</label>
            <textarea id="assessment-category-form-description" name="description" rows="3" maxlength="2000"
                      placeholder="{{ __('lf.LF_assessment_category_description_placeholder') }}">{{ $category?->description }}</textarea>
        </div>
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>
            {{ __('lf.LF_common_button_cancel') }}
        </button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_common_button_confirm') }}
        </button>
    </footer>
</form>
