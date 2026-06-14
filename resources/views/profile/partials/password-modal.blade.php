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

            <div @class([
                'lf-form-group',
                'student-password-field' => $isStudentVariant,
            ])>
                <label class="lf-form-label" for="{{ $name }}-current-password">
                    {{ __('lf.LF_common_label_current_password') }}
                </label>
                <input id="{{ $name }}-current-password" type="password" name="current_password"
                       @class(['lf-form-control', 'student-password-input' => $isStudentVariant])
                       autocomplete="current-password" required>
            </div>

            <div @class([
                'lf-form-group',
                'student-password-field' => $isStudentVariant,
            ])>
                <label class="lf-form-label" for="{{ $name }}-password">
                    {{ __('lf.LF_common_label_new_password') }}
                </label>
                <input id="{{ $name }}-password" type="password" name="password"
                       @class(['lf-form-control', 'student-password-input' => $isStudentVariant])
                       autocomplete="new-password" required>
            </div>

            <div @class([
                'lf-form-group',
                'student-password-field' => $isStudentVariant,
            ])>
                <label class="lf-form-label" for="{{ $name }}-password-confirmation">
                    {{ __('lf.LF_common_label_confirm_new_password') }}
                </label>
                <input id="{{ $name }}-password-confirmation" type="password" name="password_confirmation"
                       @class(['lf-form-control', 'student-password-input' => $isStudentVariant])
                       autocomplete="new-password" required>
            </div>

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
