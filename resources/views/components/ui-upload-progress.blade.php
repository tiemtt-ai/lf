{{--
    UI Kit dialog form: upload progress row. Place it between .ui-modal__body and .ui-modal__footer of a fragment.
    admin-ui-kit-dialog-form.js shows it only while a file is being uploaded (the save then goes by XHR): the bar,
    the text (":percent" is replaced), and a cancel button that aborts the upload and keeps the form.
--}}
<div class="ui-modal__progress" data-ui-dialog-form-progress hidden
     data-text-uploading="{{ __('lf.LF_ui_upload_uploading') }}"
     data-text-processing="{{ __('lf.LF_ui_upload_processing') }}"
     data-text-cancelled="{{ __('lf.LF_ui_upload_cancelled') }}">
    <div class="ui-progress">
        <span class="ui-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
              aria-label="{{ __('lf.LF_ui_upload_label') }}" data-ui-dialog-form-progress-bar></span>
    </div>
    <span class="ui-progress__text" data-ui-dialog-form-progress-text></span>
    <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-upload-cancel>
        {{ __('lf.LF_ui_upload_cancel') }}
    </button>
</div>
