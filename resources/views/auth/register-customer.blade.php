@extends('layouts.public')

@section('title', __('lf.LF_auth_register_title'))

@section('content')
    <section class="public-register">
        <div class="public-container">
            <div class="public-register-card">
                <h1>{{ __('lf.LF_auth_register_title') }}</h1>
                <p class="public-register-intro">
                    {{ __('lf.LF_auth_register_description') }}
                </p>

                @if ($errors->any())
                    <div class="public-alert-danger">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST"
                      action="{{ route('customer.register.store') }}"
                      x-data="tenantRegistrationForm(@js(old('slug', \Illuminate\Support\Str::slug((string) old('customer_name', '')))))">
                    @csrf

                    <div class="public-form-grid">
                        <div class="public-form-group is-full">
                            <label class="public-form-label" for="customer_name">
                                {{ __('lf.LF_auth_register_organization') }}
                                <span class="lf-required-indicator" aria-hidden="true">*</span>
                            </label>
                            <input id="customer_name" type="text" name="customer_name" class="public-form-control"
                                   value="{{ old('customer_name') }}"
                                   placeholder="{{ __('lf.LF_auth_register_organization_placeholder') }}"
                                   @input="slug = slugify($event.target.value)" aria-required="true" required>
                        </div>

                        <div class="public-form-group is-full">
                            <label class="public-form-label" for="slug">
                                {{ __('lf.LF_auth_register_slug') }}
                                <span class="lf-required-indicator" aria-hidden="true">*</span>
                            </label>
                            <input id="slug" type="text" name="slug" class="public-form-control is-readonly"
                                   x-model="slug" value="{{ old('slug') }}"
                                   placeholder="{{ __('lf.LF_auth_register_slug_placeholder') }}"
                                   aria-describedby="slug_help" aria-required="true" readonly required>
                            <small id="slug_help" class="public-form-help">{{ __('lf.LF_auth_register_slug_help') }}</small>
                        </div>

                        <div class="public-form-group is-full">
                            <label class="public-form-label" for="organization_type">
                                {{ __('lf.LF_auth_register_organization_type') }}
                                <span class="lf-required-indicator" aria-hidden="true">*</span>
                            </label>
                            <select id="organization_type" name="organization_type" class="public-form-control" aria-required="true" required>
                                <option value="" @selected(old('organization_type') === null)>{{ __('lf.LF_auth_register_organization_type_placeholder') }}</option>
                                <option value="training_center" @selected(old('organization_type') === 'training_center')>{{ __('lf.LF_auth_register_organization_type_training_center') }}</option>
                                <option value="school" @selected(old('organization_type') === 'school')>{{ __('lf.LF_auth_register_organization_type_school') }}</option>
                                <option value="corporate" @selected(old('organization_type') === 'corporate')>{{ __('lf.LF_auth_register_organization_type_corporate') }}</option>
                                <option value="individual" @selected(old('organization_type') === 'individual')>{{ __('lf.LF_auth_register_organization_type_individual') }}</option>
                            </select>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="name">
                                {{ __('lf.LF_auth_register_admin_name') }}
                                <span class="lf-required-indicator" aria-hidden="true">*</span>
                            </label>
                            <input id="name" type="text" name="name" class="public-form-control"
                                   value="{{ old('name') }}"
                                   placeholder="{{ __('lf.LF_auth_register_admin_name_placeholder') }}" aria-required="true" required>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="email">
                                {{ __('lf.LF_auth_register_admin_email') }}
                                <span class="lf-required-indicator" aria-hidden="true">*</span>
                            </label>
                            <input id="email" type="email" name="email" class="public-form-control"
                                   value="{{ old('email') }}"
                                   placeholder="{{ __('lf.LF_auth_register_admin_email_placeholder') }}" aria-required="true" required>
                        </div>

                        <div class="public-form-group is-full">
                            <label class="public-form-label" for="phone">
                                {{ __('lf.LF_auth_register_admin_phone') }}
                                <span class="lf-required-indicator" aria-hidden="true">*</span>
                            </label>
                            <input id="phone" type="text" name="phone" class="public-form-control"
                                   value="{{ old('phone') }}"
                                   placeholder="{{ __('lf.LF_auth_register_admin_phone_placeholder') }}" aria-required="true" required>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="password">
                                {{ __('lf.LF_common_label_password') }}
                                <span class="lf-required-indicator" aria-hidden="true">*</span>
                            </label>
                            <input id="password" type="password" name="password" class="public-form-control"
                                   placeholder="{{ __('lf.LF_auth_register_password_placeholder') }}" aria-required="true" required>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="password_confirmation">
                                {{ __('lf.LF_common_label_confirm_password') }}
                                <span class="lf-required-indicator" aria-hidden="true">*</span>
                            </label>
                            <input id="password_confirmation" type="password" name="password_confirmation"
                                   class="public-form-control"
                                   placeholder="{{ __('lf.LF_auth_register_password_confirmation_placeholder') }}"
                                   aria-required="true" required>
                        </div>
                    </div>

                    <button type="submit" class="public-button">{{ __('lf.LF_auth_register_create') }}</button>
                </form>
            </div>
        </div>
    </section>
@endsection
