{{-- Profile popup shared by the admin and the teacher dashboard. $emailEditable: teachers change their email, admins do not. --}}
@php
    $birth = $user->date_of_birth ? \Illuminate\Support\Carbon::parse($user->date_of_birth)->format('Y-m-d') : '';
@endphp
<x-ui-form-dialog :action="$action" :title="__('lf.LF_admin_dialog_my_account_title')" title-id="dashboard-dialog-title">
    <input type="hidden" name="return" value="{{ request()->query('return') === 'page' ? 'page' : 'dashboard' }}">
    <div class="ui-modal__grid">
        <div class="ui-field">
            <label class="ui-field__label" for="profile-form-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_name') }}</label>
            <input id="profile-form-name" type="text" name="name" value="{{ $user->name }}" maxlength="255" required placeholder="{{ __('lf.LF_common_label_name') }}">
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="profile-form-email">@if ($emailEditable)<span class="ui-field__required" aria-hidden="true">*</span>@endif{{ __('lf.LF_common_label_email') }}</label>
            @if ($emailEditable)
                <input id="profile-form-email" type="email" name="email" value="{{ $user->email }}" maxlength="255" required>
            @else
                <input id="profile-form-email" type="email" value="{{ $user->email }}" readonly disabled aria-describedby="profile-form-email-help">
                <span class="ui-caption" id="profile-form-email-help">{{ __('lf.LF_admin_dialog_email_readonly_help') }}</span>
            @endif
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="profile-form-phone">{{ __('lf.LF_profile_label_admin_phone') }}</label>
            <input id="profile-form-phone" type="text" name="phone" value="{{ $user->phone }}" maxlength="30" placeholder="0987 654 321">
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="profile-form-birth">{{ __('lf.LF_profile_label_admin_birth_date') }}</label>
            <x-ui-date-input id="profile-form-birth" name="date_of_birth" :value="$birth" :max="now()->format('Y-m-d')" />
        </div>
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="profile-form-gender">{{ __('lf.LF_profile_label_admin_gender') }}</label>
            <select id="profile-form-gender" name="gender" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_category_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_common_gender_common_select') }}">
                <option value="">{{ __('lf.LF_common_gender_common_select') }}</option>
                @foreach (['male' => 'LF_common_gender_common_male', 'female' => 'LF_common_gender_common_female', 'other' => 'LF_common_gender_common_other'] as $value => $key)
                    <option value="{{ $value }}" @selected($user->gender === $value)>{{ __('lf.'.$key) }}</option>
                @endforeach
            </select>
        </div>
    </div>
</x-ui-form-dialog>
