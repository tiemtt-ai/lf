{{-- Account page body for the admin and the teacher: a read-only summary with edit and change-password popups. --}}
@php
    $emptyValue = '-';
    $genderLabel = match ($user->gender) {
        'male' => __('lf.LF_common_gender_common_male'),
        'female' => __('lf.LF_common_gender_common_female'),
        'other' => __('lf.LF_common_gender_common_other'),
        default => $emptyValue,
    };
    $birthLabel = $user->date_of_birth ? \Illuminate\Support\Carbon::parse($user->date_of_birth)->format('d/m/Y') : $emptyValue;
    $registeredLabel = $user->created_at ? \Illuminate\Support\Carbon::parse($user->created_at)->format('d/m/Y') : $emptyValue;
@endphp
<div class="lf-admin-ui lf-admin-ui--dashboard" data-ui-dialog-form-scope>
    <x-ui-list-toasts />

    <div class="ui-dash-info">
        <section class="ui-dash-card" aria-labelledby="account-info-title">
            <header class="ui-dash-card__head">
                <h2 id="account-info-title">{{ __('lf.LF_profile_group_admin_personal') }}</h2>
                <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open data-url="{{ $editUrl }}?return=page" aria-haspopup="dialog"
                        aria-label="{{ __('lf.LF_common_button_edit') }}" data-tooltip="{{ __('lf.LF_common_button_edit') }}"><x-backend-icon name="square-pen" class="ui-icon" /></button>
            </header>
            <dl class="ui-dash-summary">
                <div><dt>{{ __('lf.LF_common_label_name') }}</dt><dd>{{ $user->name ?? $emptyValue }}</dd></div>
                <div><dt>{{ __('lf.LF_common_label_email') }}</dt><dd>{{ $user->email ?? $emptyValue }}</dd></div>
                <div><dt>{{ __('lf.LF_profile_label_admin_phone') }}</dt><dd>{{ $user->phone ?: $emptyValue }}</dd></div>
                <div><dt>{{ __('lf.LF_profile_label_admin_birth_date') }}</dt><dd>{{ $birthLabel }}</dd></div>
                <div><dt>{{ __('lf.LF_profile_label_admin_gender') }}</dt><dd>{{ $genderLabel }}</dd></div>
                <div><dt>{{ rtrim(__('lf.LF_profile_label_admin_registration_date'), ': ') }}</dt><dd>{{ $registeredLabel }}</dd></div>
            </dl>
        </section>

        <section class="ui-dash-card" aria-labelledby="account-security-title">
            <header class="ui-dash-card__head">
                <h2 id="account-security-title">{{ __('lf.LF_profile_group_admin_security') }}</h2>
            </header>
            <div class="ui-dash-card__body">
                <p class="ui-caption">{{ __('lf.LF_profile_help_account_security') }}</p>
                <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-open data-url="{{ $passwordUrl }}" aria-haspopup="dialog">
                    <x-backend-icon name="lock" class="ui-icon" />{{ __('lf.LF_common_button_common_change_password') }}
                </button>
            </div>
        </section>
    </div>

    <x-ui-form-dialog-host title-id="dashboard-dialog-title" />
</div>
