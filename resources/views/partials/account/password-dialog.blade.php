{{-- Change-password popup shared by the admin and the teacher account pages; success signs the user out. --}}
<x-ui-form-dialog :action="$action" :title="__('lf.LF_profile_title_common_change_password')" title-id="account-dialog-title">
    <div class="ui-modal__grid">
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="password-form-current"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_current_password') }}</label>
            <x-ui-password-input id="password-form-current" name="current_password" autocomplete="current-password" required
                   placeholder="{{ __('lf.LF_profile_placeholder_current_password') }}" />
        </div>
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="password-form-new"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_new_password') }}</label>
            <x-ui-password-input id="password-form-new" name="password" autocomplete="new-password" required aria-describedby="password-form-new-help"
                   placeholder="{{ __('lf.LF_profile_placeholder_new_password') }}" />
            <span class="ui-caption" id="password-form-new-help">{{ __('lf.LF_profile_help_new_password') }}</span>
        </div>
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="password-form-confirm"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_confirm_new_password') }}</label>
            <x-ui-password-input id="password-form-confirm" name="password_confirmation" autocomplete="new-password" required
                   placeholder="{{ __('lf.LF_profile_placeholder_confirm_new_password') }}" />
        </div>
    </div>
</x-ui-form-dialog>
