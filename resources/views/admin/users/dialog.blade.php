{{-- Add / edit user popup for the users list. $user is null when adding; $role is the preset role (the list tab). --}}
@php
    $isEdit = $user !== null;
    $selectedRole = $isEdit ? $user->role : $role;
    $selectAttributes = 'data-ui-kit-select data-ui-empty-label="'.e(__('lf.LF_course_category_index_no_options')).'"';
@endphp
<x-ui-form-dialog :action="$isEdit ? route('admin.users.update', $user->id) : route('admin.users.store')"
                  :method="$isEdit ? 'PUT' : 'POST'"
                  :title="$isEdit ? __('lf.LF_admin_user_edit_title') : __('lf.LF_admin_user_create_'.$role)"
                  title-id="user-dialog-title">
    <div class="ui-modal__grid">
        <div class="ui-field">
            <label class="ui-field__label" for="user-form-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_name') }}</label>
            <input id="user-form-name" type="text" name="name" value="{{ $user?->name }}" maxlength="255" required placeholder="{{ __('lf.LF_admin_placeholder_user_name') }}">
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="user-form-email"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_email') }}</label>
            <input id="user-form-email" type="email" name="email" value="{{ $user?->email }}" maxlength="255" required placeholder="{{ __('lf.LF_admin_placeholder_user_email') }}">
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="user-form-phone">{{ __('lf.LF_common_label_phone') }}</label>
            <input id="user-form-phone" type="text" name="phone" value="{{ $user?->phone }}" maxlength="30" placeholder="{{ __('lf.LF_admin_placeholder_user_phone') }}">
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="user-form-birth">{{ __('lf.LF_common_label_date_of_birth') }}</label>
            <x-ui-date-input id="user-form-birth" name="date_of_birth" :value="$user?->date_of_birth ? \Illuminate\Support\Carbon::parse($user->date_of_birth)->format('Y-m-d') : ''" :max="now()->format('Y-m-d')" />
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="user-form-gender">{{ __('lf.LF_common_label_gender') }}</label>
            <select id="user-form-gender" name="gender" {!! $selectAttributes !!} data-ui-clear-label="{{ __('lf.LF_common_gender_common_select') }}">
                <option value="">{{ __('lf.LF_common_gender_common_select') }}</option>
                @foreach (['male' => 'LF_common_gender_common_male', 'female' => 'LF_common_gender_common_female', 'other' => 'LF_common_gender_common_other'] as $value => $key)
                    <option value="{{ $value }}" @selected($user?->gender === $value)>{{ __('lf.'.$key) }}</option>
                @endforeach
            </select>
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="user-form-role"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_role') }}</label>
            <select id="user-form-role" name="role" required {!! $selectAttributes !!}>
                @foreach (['customer_admin' => 'LF_common_role_admin_customer_admin', 'teacher' => 'LF_common_role_teacher_teacher', 'student' => 'LF_common_role_student_student'] as $value => $key)
                    <option value="{{ $value }}" @selected($selectedRole === $value)>{{ __('lf.'.$key) }}</option>
                @endforeach
            </select>
        </div>
        @unless ($isEdit)
            <div class="ui-field">
                <label class="ui-field__label" for="user-form-password"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_password') }}</label>
                <input id="user-form-password" type="password" name="password" autocomplete="new-password" required placeholder="{{ __('lf.LF_admin_placeholder_user_password') }}">
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="user-form-password-confirmation"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_confirm_password') }}</label>
                <input id="user-form-password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" required placeholder="{{ __('lf.LF_admin_placeholder_user_password_confirmation') }}">
            </div>
        @endunless
    </div>
</x-ui-form-dialog>
