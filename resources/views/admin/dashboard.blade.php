@extends('layouts.backend')

@section('title', __('lf.LF_admin_title_admin_dashboard'))
@section('page_title', __('lf.LF_navigation_menu_admin_dashboard'))

@section('content')
    @php
        $admin = auth()->user();
        $emptyValue = '-';
    @endphp

    <section class="admin-dashboard-intro" aria-labelledby="admin-dashboard-welcome">
        <div>
            <h2 id="admin-dashboard-welcome">{{ __('lf.LF_admin_message_admin_welcome', ['name' => $admin->name]) }}</h2>
            <p>{{ __('lf.LF_admin_message_dashboard_guidance') }}</p>
        </div>
    </section>

    <section class="admin-dashboard-quick-section" aria-labelledby="admin-dashboard-quick-actions">
        <h2 id="admin-dashboard-quick-actions" class="admin-dashboard-subsection-title">{{ __('lf.LF_admin_title_quick_actions') }}</h2>
        <div class="admin-dashboard-quick-grid">
            <a class="admin-dashboard-quick-action" href="{{ route('admin.organization.edit') }}">
                <span class="admin-dashboard-quick-action-icon"><x-backend-icon name="cog" /></span>
                <span class="admin-dashboard-quick-action-copy"><strong>{{ __('lf.LF_admin_action_manage_organization') }}</strong><span>{{ __('lf.LF_admin_action_manage_organization_help') }}</span></span>
                <span class="admin-dashboard-quick-action-arrow" aria-hidden="true">→</span>
            </a>
            <a class="admin-dashboard-quick-action" href="{{ route('admin.users.index') }}">
                <span class="admin-dashboard-quick-action-icon"><x-backend-icon name="users" /></span>
                <span class="admin-dashboard-quick-action-copy"><strong>{{ __('lf.LF_admin_action_manage_users') }}</strong><span>{{ __('lf.LF_admin_action_manage_users_help') }}</span></span>
                <span class="admin-dashboard-quick-action-arrow" aria-hidden="true">→</span>
            </a>
            <a class="admin-dashboard-quick-action" href="{{ route('admin.my-account.edit') }}">
                <span class="admin-dashboard-quick-action-icon"><x-backend-icon name="user-cog" /></span>
                <span class="admin-dashboard-quick-action-copy"><strong>{{ __('lf.LF_admin_action_manage_account') }}</strong><span>{{ __('lf.LF_admin_action_manage_account_help') }}</span></span>
                <span class="admin-dashboard-quick-action-arrow" aria-hidden="true">→</span>
            </a>
        </div>
    </section>

    <div class="admin-dashboard-info-grid">
        <section class="admin-card admin-dashboard-info-card">
            <header class="admin-dashboard-card-header">
                <h2 class="admin-dashboard-section-title">{{ __('lf.LF_admin_title_tenant_information') }}</h2>
                <a class="admin-text-action" href="{{ route('admin.organization.edit') }}">{{ __('lf.LF_common_button_edit') }}</a>
            </header>

            <dl class="admin-profile-summary">
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_name') }}</dt>
                    <dd>{{ $tenant?->name ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_slug') }}</dt>
                    <dd>{{ $tenant?->subdomain ?? $tenant?->slug ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_email') }}</dt>
                    <dd>{{ $tenant?->email ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_phone') }}</dt>
                    <dd>{{ $tenant?->phone ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_status') }}</dt>
                    <dd>
                        @if ($tenant?->status === 'active')
                            <span class="badge badge-success">{{ __('lf.LF_common_status_common_active') }}</span>
                        @else
                            <span class="badge badge-danger">{{ $tenant?->status ?? $emptyValue }}</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        <section class="admin-card admin-dashboard-info-card">
            <header class="admin-dashboard-card-header">
                <h2 class="admin-dashboard-section-title">{{ __('lf.LF_admin_title_current_user') }}</h2>
                <a class="admin-text-action" href="{{ route('admin.my-account.edit') }}">{{ __('lf.LF_common_button_edit') }}</a>
            </header>

            <dl class="admin-profile-summary">
                <div>
                    <dt>{{ __('lf.LF_admin_label_current_user_name') }}</dt>
                    <dd>{{ $admin->name ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_current_user_email') }}</dt>
                    <dd>{{ $admin->email ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_current_user_phone') }}</dt>
                    <dd>{{ $admin->phone ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_common_label_role') }}</dt>
                    <dd>
                        @php
                            $roleLabel = match ($admin->role) {
                                'customer_admin' => __('lf.LF_common_role_admin_customer_admin'),
                                'teacher' => __('lf.LF_common_role_teacher_teacher'),
                                'student' => __('lf.LF_common_role_student_student'),
                                default => $admin->role ?? $emptyValue,
                            };
                        @endphp
                        <span class="badge badge-primary">{{ $roleLabel }}</span>
                    </dd>
                </div>
            </dl>
        </section>
    </div>
@endsection
