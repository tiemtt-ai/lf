@extends('layouts.backend')

@section('title', __('lf.LF_admin_title_admin_dashboard'))
@section('page_title', __('lf.LF_navigation_menu_admin_dashboard'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js'])
@endsection

@section('content')
    @php
        $admin = auth()->user();
        $emptyValue = '-';
    @endphp

    <div class="lf-admin-ui lf-admin-ui--dashboard" data-ui-dialog-form-scope>
        <x-ui-list-toasts />

        <p class="ui-dash-welcome" id="admin-dashboard-welcome"><strong>{{ __('lf.LF_admin_message_admin_welcome', ['name' => $admin->name]) }}</strong> {{ __('lf.LF_admin_message_dashboard_guidance') }}</p>

        <section aria-labelledby="admin-dashboard-quick-actions">
            <h2 id="admin-dashboard-quick-actions" class="ui-dash-title">{{ __('lf.LF_admin_title_quick_actions') }}</h2>
            <div class="ui-dash-shortcuts">
                <x-dashboard-shortcut :href="route('admin.organization.edit')" icon="building-2" :title="__('lf.LF_admin_action_manage_organization')" :help="__('lf.LF_admin_action_manage_organization_help')" />
                <x-dashboard-shortcut :href="route('admin.users.index')" icon="users" :title="__('lf.LF_admin_action_manage_users')" :help="__('lf.LF_admin_action_manage_users_help')" />
                <x-dashboard-shortcut :href="route('admin.my-account.edit')" icon="user-cog" :title="__('lf.LF_admin_action_manage_account')" :help="__('lf.LF_admin_action_manage_account_help')" />
            </div>
        </section>

        <div class="ui-dash-info">
            <section class="ui-dash-card" aria-labelledby="dashboard-tenant-title">
                <header class="ui-dash-card__head">
                    <h2 id="dashboard-tenant-title">{{ __('lf.LF_admin_title_tenant_information') }}
                        @if ($tenant?->status === 'active')
                            <span class="ui-status ui-status--active">{{ __('lf.LF_common_status_common_active') }}</span>
                        @else
                            <span class="ui-status ui-status--inactive">{{ $tenant?->status ?? $emptyValue }}</span>
                        @endif
                    </h2>
                    <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open data-url="{{ route('admin.organization.edit') }}" aria-haspopup="dialog" aria-label="{{ __('lf.LF_common_button_edit') }}" data-tooltip="{{ __('lf.LF_common_button_edit') }}"><x-backend-icon name="square-pen" class="ui-icon" /></button>
                </header>
                <dl class="ui-dash-summary">
                    <div><dt>{{ __('lf.LF_admin_label_tenant_name') }}</dt><dd>{{ $tenant?->name ?? $emptyValue }}</dd></div>
                    <div><dt>{{ __('lf.LF_admin_label_tenant_slug') }}</dt><dd>{{ $tenant?->subdomain ?? $tenant?->slug ?? $emptyValue }}</dd></div>
                    <div><dt>{{ __('lf.LF_admin_label_tenant_email') }}</dt><dd>{{ $tenant?->email ?? $emptyValue }}</dd></div>
                    <div><dt>{{ __('lf.LF_admin_label_tenant_phone') }}</dt><dd>{{ $tenant?->phone ?? $emptyValue }}</dd></div>
                </dl>
            </section>

            @include('partials.dashboard.user-card', ['user' => $admin, 'editUrl' => route('admin.my-account.edit')])
        </div>

        <x-ui-form-dialog-host title-id="dashboard-dialog-title" />
    </div>
@endsection
