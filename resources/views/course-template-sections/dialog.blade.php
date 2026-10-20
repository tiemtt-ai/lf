@php
    $isEdit = isset($section) && $section !== null;
@endphp
<form class="ui-modal__form" method="POST" novalidate
      action="{{ $isEdit ? route($routePrefix.'.update', [$template->id, $section->id]) : route($routePrefix.'.store', $template->id) }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <header class="ui-modal__header">
        <div>
            <h3 id="structure-dialog-title">
                {{ $isEdit ? __('lf.LF_course_template_section_common_edit') : __('lf.LF_course_template_section_common_create') }}
            </h3>
            <p class="ui-caption">{{ __('lf.LF_course_template_lesson_dialog_location') }}: {{ $template->title }}</p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        @include('course-template-sections.partials.dialog-fields')
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>
            {{ __('lf.LF_common_button_cancel') }}
        </button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            {{ $isEdit ? __('lf.LF_common_button_save_changes') : __('lf.LF_course_template_section_common_create') }}
        </button>
    </footer>
</form>
