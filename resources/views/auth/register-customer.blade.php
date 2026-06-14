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

                <form method="POST" action="{{ route('customer.register.store') }}">
                    @csrf

                    <div class="public-form-grid">
                        <div class="public-form-group is-full">
                            <label class="public-form-label" for="customer_name">{{ __('lf.LF_auth_register_organization') }}</label>
                            <input id="customer_name" type="text" name="customer_name" class="public-form-control"
                                   value="{{ old('customer_name') }}" required>
                        </div>

                        <div class="public-form-group is-full">
                            <label class="public-form-label" for="slug">{{ __('lf.LF_auth_register_slug') }}</label>
                            <input id="slug" type="text" name="slug" class="public-form-control"
                                   value="{{ old('slug') }}" placeholder="{{ __('lf.LF_auth_register_slug_placeholder') }}" required>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="name">{{ __('lf.LF_auth_register_admin_name') }}</label>
                            <input id="name" type="text" name="name" class="public-form-control"
                                   value="{{ old('name') }}" required>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="email">{{ __('lf.LF_auth_register_admin_email') }}</label>
                            <input id="email" type="email" name="email" class="public-form-control"
                                   value="{{ old('email') }}" required>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="password">{{ __('lf.LF_common_label_password') }}</label>
                            <input id="password" type="password" name="password" class="public-form-control" required>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="password_confirmation">{{ __('lf.LF_common_label_confirm_password') }}</label>
                            <input id="password_confirmation" type="password" name="password_confirmation"
                                   class="public-form-control" required>
                        </div>
                    </div>

                    <button type="submit" class="public-button">{{ __('lf.LF_auth_register_create') }}</button>
                </form>
            </div>
        </div>
    </section>
@endsection
