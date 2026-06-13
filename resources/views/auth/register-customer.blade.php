@extends('layouts.public')

@section('title', 'Register Tenant - LearnForge')

@section('content')
    <section class="public-register">
        <div class="public-container">
            <div class="public-register-card">
                <h1>Register a LearnForge tenant</h1>
                <p class="public-register-intro">
                    Create your organization and its first customer administrator account.
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
                            <label class="public-form-label" for="customer_name">Organization name</label>
                            <input id="customer_name" type="text" name="customer_name" class="public-form-control"
                                   value="{{ old('customer_name') }}" required>
                        </div>

                        <div class="public-form-group is-full">
                            <label class="public-form-label" for="slug">Slug / subdomain</label>
                            <input id="slug" type="text" name="slug" class="public-form-control"
                                   value="{{ old('slug') }}" placeholder="your-organization" required>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="name">Administrator name</label>
                            <input id="name" type="text" name="name" class="public-form-control"
                                   value="{{ old('name') }}" required>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="email">Administrator email</label>
                            <input id="email" type="email" name="email" class="public-form-control"
                                   value="{{ old('email') }}" required>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="password">Password</label>
                            <input id="password" type="password" name="password" class="public-form-control" required>
                        </div>

                        <div class="public-form-group">
                            <label class="public-form-label" for="password_confirmation">Confirm password</label>
                            <input id="password_confirmation" type="password" name="password_confirmation"
                                   class="public-form-control" required>
                        </div>
                    </div>

                    <button type="submit" class="public-button">Create tenant</button>
                </form>
            </div>
        </div>
    </section>
@endsection
