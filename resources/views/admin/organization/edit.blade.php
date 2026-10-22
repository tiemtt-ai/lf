@extends('layouts.backend')

@section('title', __('lf.LF_admin_title_organization'))
@section('page_title', __('lf.LF_admin_title_organization'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js'])
@endsection

@section('content')
    @php
        $emptyValue = '-';
    @endphp

    <div class="lf-admin-ui lf-admin-ui--dashboard" data-ui-dialog-form-scope>
        <x-ui-list-toasts />

        <div class="ui-dash-info">
            <section class="ui-dash-card" aria-labelledby="organization-contact-title">
                <header class="ui-dash-card__head">
                    <h2 id="organization-contact-title">{{ __('lf.LF_admin_group_organization_contact') }}</h2>
                    <button type="button" class="ux-row-edit cursor-interaction" data-ui-dialog-form-open data-url="{{ route('admin.organization.edit') }}?return=page" aria-haspopup="dialog"
                            aria-label="{{ __('lf.LF_common_button_edit') }}" data-tooltip="{{ __('lf.LF_common_button_edit') }}"><x-backend-icon name="square-pen" class="ui-icon" /></button>
                </header>
                <dl class="ui-dash-summary">
                    <div><dt>{{ __('lf.LF_admin_label_tenant_name') }}</dt><dd>{{ $tenant->name ?? $emptyValue }}</dd></div>
                    <div><dt>{{ __('lf.LF_admin_label_tenant_email') }}</dt><dd>{{ $tenant->email ?: $emptyValue }}</dd></div>
                    <div><dt>{{ __('lf.LF_admin_label_tenant_phone') }}</dt><dd>{{ $tenant->phone ?: $emptyValue }}</dd></div>
                </dl>
                <p class="ui-caption ui-dash-card__note">{{ __('lf.LF_admin_help_organization_contact') }}</p>
            </section>

            <section class="ui-dash-card" aria-labelledby="organization-system-title">
                <header class="ui-dash-card__head">
                    <h2 id="organization-system-title">{{ __('lf.LF_admin_group_organization_system') }}</h2>
                </header>
                <dl class="ui-dash-summary">
                    <div><dt>{{ __('lf.LF_admin_label_tenant_customer_id') }}</dt><dd><code>{{ $tenant->id }}</code></dd></div>
                    <div><dt>{{ __('lf.LF_admin_label_tenant_slug') }}</dt><dd><code>{{ $tenant->slug ?? $emptyValue }}</code></dd></div>
                    <div><dt>{{ __('lf.LF_admin_label_tenant_subdomain') }}</dt><dd><code>{{ $tenant->subdomain ?? $emptyValue }}</code></dd></div>
                    <div>
                        <dt>{{ __('lf.LF_admin_label_tenant_status') }}</dt>
                        <dd>
                            @if ($tenant->status === 'active')
                                <span class="ui-status ui-status--active">{{ __('lf.LF_common_status_common_active') }}</span>
                            @else
                                <span class="ui-status ui-status--inactive">{{ $tenant->status ?? $emptyValue }}</span>
                            @endif
                        </dd>
                    </div>
                    <div><dt>{{ __('lf.LF_admin_label_tenant_theme') }}</dt><dd>{{ $tenant->theme_key ?? __('lf.LF_admin_value_future_ready') }}</dd></div>
                    <div><dt>{{ __('lf.LF_admin_label_tenant_language') }}</dt><dd>{{ __('lf.LF_admin_value_future_ready') }}</dd></div>
                </dl>
                <p class="ui-caption ui-dash-card__note">{{ __('lf.LF_admin_help_organization_system') }}</p>
            </section>
        </div>

        <x-ui-form-dialog-host title-id="dashboard-dialog-title" />
    </div>
@endsection
