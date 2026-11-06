{{-- The one dialog a Kiểm tra page loads its forms into (passage form, question form). $dialogTitleId may list the title id of each form it can hold; only the one present labels it. --}}
<dialog class="ui-modal" data-columns="3" aria-labelledby="{{ $dialogTitleId }}" data-ui-dialog-form
        data-msg-load-error="{{ __('lf.LF_assessment_category_dialog_load_error') }}"
        data-msg-discard-title="{{ __('lf.LF_assessment_category_dialog_discard_title') }}"
        data-msg-discard-text="{{ __('lf.LF_assessment_category_dialog_discard_text') }}"
        data-msg-discard="{{ __('lf.LF_assessment_category_dialog_discard') }}"
        data-msg-save-error="{{ __('lf.LF_assessment_category_dialog_save_error') }}"
        data-msg-session-expired="{{ __('lf.LF_assessment_category_session_expired') }}"
        data-msg-required-input="{{ __('lf.LF_assessment_category_dialog_required_input') }}"
        data-msg-required-select="{{ __('lf.LF_assessment_category_dialog_required_select') }}"></dialog>
