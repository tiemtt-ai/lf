@props([
    'action',
    'name' => 'change-password',
    'variant' => null,
])

@php
    $isStudentVariant = $variant === 'student';
@endphp

<x-modal :name="$name" :show="$errors->updatePassword->any()" focusable>
    <div @class([
        'lf-modal-card',
        'student-password-modal' => $isStudentVariant,
    ])>
        @if ($isStudentVariant)
            <header class="student-password-header">
                <div>
                    <p class="student-password-eyebrow">{{ __('lf.LF_profile_section_student_security') }}</p>
                    <h2>{{ __('lf.LF_profile_title_common_change_password') }}</h2>
                    <p>{{ __('lf.LF_profile_message_student_password_security') }}</p>
                </div>

                <button type="button" class="student-password-close" aria-label="{{ __('lf.LF_common_button_close') }}"
                        x-on:click="$dispatch('close-modal', '{{ $name }}')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="m6 6 12 12M18 6 6 18"></path>
                    </svg>
                </button>
            </header>
        @else
            <h2>{{ __('lf.LF_profile_title_common_change_password') }}</h2>
        @endif

        @if ($errors->updatePassword->any())
            <div @class([
                'lf-alert-danger',
                'student-password-errors' => $isStudentVariant,
            ])>
                <ul>
                    @foreach ($errors->updatePassword->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $action }}"
              @class(['student-password-form' => $isStudentVariant])>
            @csrf
            @method('PATCH')

            @foreach ([
                ['id' => $name.'-current-password', 'name' => 'current_password', 'label' => 'lf.LF_common_label_current_password', 'autocomplete' => 'current-password'],
                ['id' => $name.'-password', 'name' => 'password', 'label' => 'lf.LF_common_label_new_password', 'autocomplete' => 'new-password'],
                ['id' => $name.'-password-confirmation', 'name' => 'password_confirmation', 'label' => 'lf.LF_common_label_confirm_new_password', 'autocomplete' => 'new-password'],
            ] as $field)
                <div @class([
                    'lf-form-group',
                    'student-password-field' => $isStudentVariant,
                ])>
                    <label class="lf-form-label" for="{{ $field['id'] }}">
                        {{ __($field['label']) }}
                    </label>
                    <div class="student-password-control" x-data="{ shown: false }">
                        <input id="{{ $field['id'] }}" type="password" x-bind:type="shown ? 'text' : 'password'"
                               name="{{ $field['name'] }}"
                               @class(['lf-form-control', 'student-password-input' => $isStudentVariant])
                               autocomplete="{{ $field['autocomplete'] }}" required>
                        <button type="button" class="student-password-toggle" x-on:click="shown = ! shown"
                                aria-controls="{{ $field['id'] }}" x-bind:aria-pressed="shown.toString()"
                                aria-label="{{ __('lf.LF_auth_login_show_password') }}"
                                x-bind:aria-label="shown ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
                            <span x-show="! shown"><x-backend-icon name="eye" /></span>
                            <span x-show="shown" x-cloak><x-backend-icon name="eye-off" /></span>
                        </button>
                    </div>
                </div>
            @endforeach

            <div @class([
                'lf-modal-actions',
                'student-password-actions' => $isStudentVariant,
            ])>
                <button type="button"
                        @class([
                            'lf-btn-secondary',
                            'student-password-button is-secondary' => $isStudentVariant,
                        ])
                        x-on:click="$dispatch('close-modal', '{{ $name }}')">
                    {{ __('lf.LF_common_button_cancel') }}
                </button>
                <button type="submit"
                        @class([
                            'lf-btn-primary',
                            'student-password-button is-primary' => $isStudentVariant,
                        ])>
                    {{ __('lf.LF_common_button_common_change_password') }}
                </button>
            </div>
        </form>
    </div>
</x-modal>
