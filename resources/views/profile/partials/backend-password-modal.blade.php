@props([
    'action',
    'name' => 'change-password',
])

<x-modal :name="$name" :show="$errors->updatePassword->any()" max-width="lg" focusable>
    <div class="lf-modal-card admin-password-modal">
        <header class="admin-password-modal-header">
            <div class="admin-password-modal-heading">
                <span class="admin-password-modal-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="5" y="10" width="14" height="10" rx="2"></rect>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"></path>
                    </svg>
                </span>
                <div>
                    <h2 id="{{ $name }}-title">{{ __('lf.LF_profile_title_common_change_password') }}</h2>
                    <p>{{ __('lf.LF_profile_help_admin_security') }}</p>
                </div>
            </div>
            <button type="button" class="admin-password-modal-close"
                    aria-label="{{ __('lf.LF_common_button_close') }}"
                    x-on:click="$dispatch('close-modal', '{{ $name }}')">
                <span aria-hidden="true">×</span>
            </button>
        </header>

        @if ($errors->updatePassword->any())
            <div class="lf-alert-danger admin-password-modal-errors" role="alert">
                <ul>
                    @foreach ($errors->updatePassword->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $action }}" aria-labelledby="{{ $name }}-title">
            @csrf
            @method('PATCH')

            @foreach ([
                ['suffix' => 'current-password', 'name' => 'current_password', 'label' => __('lf.LF_common_label_current_password'), 'autocomplete' => 'current-password', 'placeholder' => __('lf.LF_profile_placeholder_current_password')],
                ['suffix' => 'password', 'name' => 'password', 'label' => __('lf.LF_common_label_new_password'), 'autocomplete' => 'new-password', 'placeholder' => __('lf.LF_profile_placeholder_new_password')],
                ['suffix' => 'password-confirmation', 'name' => 'password_confirmation', 'label' => __('lf.LF_common_label_confirm_new_password'), 'autocomplete' => 'new-password', 'placeholder' => __('lf.LF_profile_placeholder_confirm_new_password')],
            ] as $field)
                <div class="lf-form-group admin-password-field" x-data="{ visible: false }">
                    <label for="{{ $name }}-{{ $field['suffix'] }}" class="lf-form-label">
                        {{ $field['label'] }}
                        <span class="lf-required-indicator" aria-hidden="true">*</span>
                    </label>
                    <div class="admin-password-control">
                        <input id="{{ $name }}-{{ $field['suffix'] }}" type="password"
                               x-bind:type="visible ? 'text' : 'password'"
                               name="{{ $field['name'] }}" class="lf-form-control"
                               autocomplete="{{ $field['autocomplete'] }}"
                               placeholder="{{ $field['placeholder'] }}"
                               @if ($field['name'] === 'password') aria-describedby="{{ $name }}-password-help" @endif
                               aria-required="true" @if ($loop->first) autofocus @endif required>
                        <button type="button" x-on:click="visible = ! visible"
                                x-bind:aria-label="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))"
                                x-bind:title="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
                            <svg x-show="! visible" class="admin-password-eye" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path>
                                <circle cx="12" cy="12" r="2.75"></circle>
                            </svg>
                            <svg x-show="visible" x-cloak class="admin-password-eye" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="m3 3 18 18M10.6 6.15A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-2.1 2.75M6.2 6.2C3.8 7.85 2.5 12 2.5 12s3.5 6 9.5 6a9.8 9.8 0 0 0 3.15-.52M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
                            </svg>
                        </button>
                    </div>
                    @if ($field['name'] === 'password')
                        <p id="{{ $name }}-password-help" class="lf-form-help">{{ __('lf.LF_profile_help_new_password') }}</p>
                    @endif
                    @foreach ($errors->updatePassword->get($field['name']) as $error)
                        <p class="lf-form-error">{{ $error }}</p>
                    @endforeach
                </div>
            @endforeach

            <div class="lf-modal-actions admin-password-modal-actions">
                <button type="button" class="btn btn-secondary"
                        x-on:click="$dispatch('close-modal', '{{ $name }}')">
                    {{ __('lf.LF_common_button_cancel') }}
                </button>
                <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_common_change_password') }}</button>
            </div>
        </form>
    </div>
</x-modal>
