@extends('layouts.backend')

@section('title', __('lf.LF_admin_title_admin_dashboard'))
@section('page_title', __('lf.LF_navigation_menu_admin_dashboard'))

@section('content')
    @php
        $admin = auth()->user();
        $organizationType = $tenant?->organization_type;
        $emptyValue = '-';
        $organizationTypeLabel = match ($organizationType) {
            'training_center' => __('lf.LF_auth_register_organization_type_training_center'),
            'school' => __('lf.LF_auth_register_organization_type_school'),
            'corporate' => __('lf.LF_auth_register_organization_type_corporate'),
            'individual' => __('lf.LF_auth_register_organization_type_individual'),
            default => $emptyValue,
        };
    @endphp

    <p class="admin-dashboard-welcome">
        {{ __('lf.LF_admin_message_admin_welcome', ['name' => $admin->name]) }}
    </p>

    <div class="admin-dashboard-info-grid">
        <section class="admin-card">
            <h2 class="admin-dashboard-section-title">{{ __('lf.LF_admin_title_tenant_information') }}</h2>

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
                    <dt>{{ __('lf.LF_auth_register_organization_type') }}</dt>
                    <dd>{{ $organizationTypeLabel }}</dd>
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
                    <dd>{{ $tenant?->status ?? $emptyValue }}</dd>
                </div>
            </dl>
        </section>

        <section class="admin-card">
            <h2 class="admin-dashboard-section-title">{{ __('lf.LF_admin_title_admin_account') }}</h2>

            <dl class="admin-profile-summary">
                <div>
                    <dt>{{ __('lf.LF_admin_label_admin_name') }}</dt>
                    <dd>{{ $admin->name ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_admin_email') }}</dt>
                    <dd>{{ $admin->email ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_admin_phone') }}</dt>
                    <dd>{{ $admin->phone ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_common_label_role') }}</dt>
                    <dd>{{ $admin->role ?? $emptyValue }}</dd>
                </div>
            </dl>
        </section>
    </div>
@endsection
