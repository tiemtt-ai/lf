{{-- Empty UI Kit form dialog: admin-ui-kit-dialog-form.js loads a form fragment into it. Put it inside a [data-ui-dialog-form-scope] element. --}}
@props(['titleId' => 'ui-form-dialog-title', 'columns' => 2])

<dialog class="ui-modal" data-columns="{{ $columns }}" aria-labelledby="{{ $titleId }}" data-ui-dialog-form
        data-msg-load-error="{{ __('lf.LF_common_dialog_load_error') }}"
        data-msg-discard-title="{{ __('lf.LF_common_dialog_discard_title') }}"
        data-msg-discard-text="{{ __('lf.LF_common_dialog_discard_text') }}"
        data-msg-discard="{{ __('lf.LF_common_dialog_discard') }}"
        data-msg-save-error="{{ __('lf.LF_common_dialog_save_error') }}"
        data-msg-session-expired="{{ __('lf.LF_common_dialog_session_expired') }}"
        data-msg-required-input="{{ __('lf.LF_common_dialog_required_input') }}"
        data-msg-required-select="{{ __('lf.LF_common_dialog_required_select') }}"></dialog>
