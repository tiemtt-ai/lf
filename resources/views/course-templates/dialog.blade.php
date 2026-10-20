@php
    $isEdit = $template !== null;
    $archived = $isEdit && $template->status === \App\Support\CourseTemplateStatus::ARCHIVED;
@endphp
<form class="ui-modal__form" method="POST" enctype="multipart/form-data" novalidate
      action="{{ $isEdit ? route($routePrefix.'.update', array_filter(['id' => $template->id, 'context' => $context ?? null])) : route($routePrefix.'.store') }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <header class="ui-modal__header">
        <div>
            <h3 id="template-dialog-title">
                {{ $isEdit ? __('lf.LF_course_template_action_edit_info') : __('lf.LF_course_template_common_create') }}
            </h3>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        @include('course-templates.partials.dialog-fields')
    </div>
    <x-ui-upload-progress />
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>
            {{ __('lf.LF_common_button_cancel') }}
        </button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit @disabled($archived)>
            {{ $isEdit ? __('lf.LF_common_button_save_changes') : __('lf.LF_course_template_action_create') }}
        </button>
    </footer>
</form>
