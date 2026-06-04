@extends('layouts.dashboard')

@section('title', 'Profile')

@section('content')

    <h1 style="font-size:32px;font-weight:700;margin-bottom:8px;">
        My Profile
    </h1>

    <p style="color:#6b7280;margin-bottom:32px;">
        Manage your account information
    </p>

    <div class="lf-profile-card" style="margin-bottom:32px;max-width:900px;">

        <div class="lf-profile-row">
            <div class="lf-profile-label">
                Name
            </div>

            <div class="lf-profile-value">
                {{ Auth::user()->name }}
            </div>
        </div>

        <div class="lf-profile-row">
            <div class="lf-profile-label">
                Email
            </div>

            <div class="lf-profile-value">
                {{ Auth::user()->email }}
            </div>
        </div>

        <div class="lf-profile-row">
            <div class="lf-profile-label">
                User ID
            </div>

            <div class="lf-profile-value">
                {{ Auth::user()->id }}
            </div>
        </div>

    </div>

    <div style="display:grid;gap:24px;max-width:900px;">

        @include('profile.partials.update-profile-information-form')

        @include('profile.partials.update-password-form')

        @include('profile.partials.delete-user-form')

    </div>

@endsection