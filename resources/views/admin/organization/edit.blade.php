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

    <div class="admin-card admin-form-card admin-form-surface">
        <form id="organization-update-form"
              class="admin-form-standard"
              method="POST"
              action="{{ route('admin.organization.update') }}">
            @csrf
            @method('PATCH')

            <div class="admin-form-flow">
                <section class="admin-form-standard-section"
                         aria-labelledby="organization-contact-title">
                    <header class="admin-form-section-header">
                        <h2 id="organization-contact-title" class="admin-form-section-title">{{ __('lf.LF_admin_group_organization_contact') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_admin_help_organization_contact') }}</p>
                    </header>

                    <div class="admin-form-field-grid admin-form-field-grid--three">
                        <div class="lf-form-group admin-form-field">
                            <label class="lf-form-label" for="organization_name">{{ __('lf.LF_admin_label_tenant_name') }}</label>
                            <input id="organization_name"
                                   type="text"
                                   name="name"
                                   class="lf-form-control"
                                   value="{{ old('name', $tenant->name) }}"
                                   placeholder="{{ __('lf.LF_admin_placeholder_organization_name') }}"
                                   required
                                   @if($errors->has('name')) aria-invalid="true" aria-describedby="organization_name_error" @endif>
                            @error('name')
                                <p id="organization_name_error" class="lf-form-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <label class="lf-form-label" for="organization_email">{{ __('lf.LF_admin_label_tenant_email') }}</label>
                            <input id="organization_email"
                                   type="email"
                                   name="email"
                                   class="lf-form-control"
                                   value="{{ old('email', $tenant->email) }}"
                                   placeholder="{{ __('lf.LF_admin_placeholder_organization_email') }}"
                                   @if($errors->has('email')) aria-invalid="true" aria-describedby="organization_email_error" @endif>
                            @error('email')
                                <p id="organization_email_error" class="lf-form-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <label class="lf-form-label" for="organization_phone">{{ __('lf.LF_admin_label_tenant_phone') }}</label>
                            <input id="organization_phone"
                                   type="text"
                                   name="phone"
                                   class="lf-form-control"
                                   value="{{ old('phone', $tenant->phone) }}"
                                   placeholder="{{ __('lf.LF_admin_placeholder_organization_phone') }}"
                                   @if($errors->has('phone')) aria-invalid="true" aria-describedby="organization_phone_error" @endif>
                            @error('phone')
                                <p id="organization_phone_error" class="lf-form-error">{{ $message }}</p>
                            @enderror
                        </div>

                    </div>
                </section>

                <section class="admin-form-standard-section"
                         aria-labelledby="organization-system-title">
                    <header class="admin-form-section-header">
                        <h2 id="organization-system-title" class="admin-form-section-title">{{ __('lf.LF_admin_group_organization_system') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_admin_help_organization_system') }}</p>
                    </header>

                    <dl class="admin-profile-summary admin-profile-summary--three admin-readonly-summary admin-readonly-summary--standalone">
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
                                <dd>
                                    @if ($tenant->status === 'active')
                                        <span class="badge badge-success">{{ __('lf.LF_common_status_common_active') }}</span>
                                    @else
                                        <span class="badge badge-danger">{{ $tenant->status ?? $emptyValue }}</span>
                                    @endif
                                </dd>
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
                </section>
            </div>

            <footer class="admin-form-footer">
                <div class="admin-form-footer-danger"></div>
                <div class="admin-form-footer-primary">
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_save_changes') }}</button>
                </div>
            </footer>
        </form>
    </div>
@endsection
