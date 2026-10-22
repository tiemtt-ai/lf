@php
    $emptyValue = '-';
    $roleLabel = match ($user->role) {
        'customer_admin' => __('lf.LF_common_role_admin_customer_admin'),
        'teacher' => __('lf.LF_common_role_teacher_teacher'),
        'student' => __('lf.LF_common_role_student_student'),
        default => $user->role ?? $emptyValue,
    };
@endphp
<section class="ui-dash-card" aria-labelledby="dashboard-user-title">
    <header class="ui-dash-card__head">
        <h2 id="dashboard-user-title">{{ __('lf.LF_admin_title_current_user') }}</h2>
        <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open data-url="{{ $editUrl }}" aria-haspopup="dialog" aria-label="{{ __('lf.LF_common_button_edit') }}" data-tooltip="{{ __('lf.LF_common_button_edit') }}"><x-backend-icon name="square-pen" class="ui-icon" /></button>
    </header>
    <dl class="ui-dash-summary">
        <div><dt>{{ __('lf.LF_admin_label_current_user_name') }}</dt><dd>{{ $user->name ?? $emptyValue }}</dd></div>
        <div><dt>{{ __('lf.LF_admin_label_current_user_email') }}</dt><dd>{{ $user->email ?? $emptyValue }}</dd></div>
        <div><dt>{{ __('lf.LF_admin_label_current_user_phone') }}</dt><dd>{{ $user->phone ?? $emptyValue }}</dd></div>
        <div><dt>{{ __('lf.LF_common_label_role') }}</dt><dd>{{ $roleLabel }}</dd></div>
    </dl>
</section>
