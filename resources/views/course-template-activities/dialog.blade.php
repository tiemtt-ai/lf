@php
    $isEdit = isset($activity) && $activity !== null;
    $activityRouteParameters = $section ? [$template->id, $section->id, $lesson->id] : [$template->id, $lesson->id];
@endphp
<form class="ui-modal__form" method="POST" enctype="multipart/form-data" novalidate data-modal-columns="2"
      action="{{ $isEdit
          ? route($routePrefix.'.update', array_merge($activityRouteParameters, [$activity->id]))
          : route($routePrefix.'.store', $activityRouteParameters) }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <header class="ui-modal__header">
        <div>
            <h3 id="structure-dialog-title">
                {{ $isEdit ? __('lf.LF_course_template_activity_dialog_edit_title') : __('lf.LF_course_template_activity_common_create') }}
            </h3>
            <p class="ui-caption">
                {{ __('lf.LF_course_template_lesson_dialog_location') }}:
                {{ $template->title }} → @if ($section){{ $section->title }} → @endif{{ $lesson->title }}
            
                <span class="ui-context-chip" x-data="{ label: '' }" x-show="label" x-cloak
                      x-on:activity-phase-label.window="label = $event.detail" x-text="label"></span>
            </p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        @include('course-template-activities.partials.dialog-fields')
    </div>
    <x-ui-upload-progress />
    <x-media-library-picker />
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>
            {{ __('lf.LF_common_button_cancel') }}
        </button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ $isEdit ? __('lf.LF_common_button_save_changes') : __('lf.LF_course_template_activity_common_create') }}
        </button>
    </footer>
</form>
