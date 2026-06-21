@extends('layouts.backend')

@section('title', __('lf.LF_admin_title_organization'))
@section('page_title', __('lf.LF_admin_title_organization'))

@section('content')
    @php
        $emptyValue = '-';
    @endphp

    @if (session('organization_success'))
        <div class="admin-alert admin-alert-success">
            {{ session('organization_success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-card admin-form-card">
        <form method="POST" action="{{ route('admin.organization.update') }}">
            @csrf
            @method('PATCH')

            <div class="lf-form-group">
                <label class="lf-form-label" for="organization_name">{{ __('lf.LF_admin_label_tenant_name') }}</label>
                <input id="organization_name" type="text" name="name" class="lf-form-control"
                       value="{{ old('name', $tenant->name) }}" required>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="organization_email">{{ __('lf.LF_admin_label_tenant_email') }}</label>
                <input id="organization_email" type="email" name="email" class="lf-form-control"
                       value="{{ old('email', $tenant->email) }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="organization_phone">{{ __('lf.LF_admin_label_tenant_phone') }}</label>
                <input id="organization_phone" type="text" name="phone" class="lf-form-control"
                       value="{{ old('phone', $tenant->phone) }}">
            </div>

            <dl class="admin-profile-summary admin-readonly-summary">
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_customer_id') }}</dt>
                    <dd>{{ $tenant->id }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_slug') }}</dt>
                    <dd>{{ $tenant->slug ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_subdomain') }}</dt>
                    <dd>{{ $tenant->subdomain ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_status') }}</dt>
                    <dd>{{ $tenant->status ?? $emptyValue }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_logo') }}</dt>
                    <dd>{{ __('lf.LF_admin_value_future_ready') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_theme') }}</dt>
                    <dd>{{ $tenant->theme_key ?? __('lf.LF_admin_value_future_ready') }}</dd>
                </div>
                <div>
                    <dt>{{ __('lf.LF_admin_label_tenant_language') }}</dt>
                    <dd>{{ __('lf.LF_admin_value_future_ready') }}</dd>
                </div>
            </dl>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_save_changes') }}</button>
            </div>
        </form>
    </div>
@endsection
