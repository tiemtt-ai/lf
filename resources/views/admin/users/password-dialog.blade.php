{{-- Reset-password popup for one user; for your own account the current password is also asked and a change signs you out. --}}
@php
    $own = (int) $user->id === (int) auth()->id();
@endphp
<x-ui-form-dialog :action="route('admin.users.update', $user->id)" method="PUT"
                  :title="$own ? __('lf.LF_profile_button_admin_change_my_password') : __('lf.LF_profile_button_admin_change_user_password')"
                  title-id="user-dialog-title">
    <input type="hidden" name="password_reset" value="1">
    <div class="ui-modal__grid">
        <p class="ui-caption ui-field--full">{{ $user->name }} · {{ $user->email }}</p>
        @if ($own)
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="user-pw-current"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_current_password') }}</label>
                <input id="user-pw-current" type="password" name="current_password" autocomplete="current-password" required placeholder="{{ __('lf.LF_profile_placeholder_current_password') }}">
            </div>
        @endif
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="user-pw-new"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_new_password') }}</label>
            <input id="user-pw-new" type="password" name="password" autocomplete="new-password" required aria-describedby="user-pw-new-help" placeholder="{{ __('lf.LF_profile_placeholder_new_password') }}">
            <span class="ui-caption" id="user-pw-new-help">{{ __('lf.LF_profile_help_new_password') }}</span>
        </div>
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="user-pw-confirm"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_confirm_new_password') }}</label>
            <input id="user-pw-confirm" type="password" name="password_confirmation" autocomplete="new-password" required placeholder="{{ __('lf.LF_profile_placeholder_confirm_new_password') }}">
        </div>
    </div>
</x-ui-form-dialog>
