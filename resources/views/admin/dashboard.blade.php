@extends('layouts.backend')

@section('title', 'Admin Dashboard')
@section('page_title', 'Admin Dashboard')

@section('content')

    <div class="lf-container">

        <h2>Welcome</h2>

        <p>
            Xin chào tiemtt!
        </p>

        <div class="lf-profile-card">

            <div class="lf-profile-row">
                <div class="lf-profile-label">
                    Name
                </div>

                <div class="lf-profile-value">
                    {{ auth()->user()->name }}
                </div>
            </div>

            <div class="lf-profile-row">
                <div class="lf-profile-label">
                    Email
                </div>

                <div class="lf-profile-value">
                    {{ auth()->user()->email }}
                </div>
            </div>

            <div class="lf-profile-row">
                <div class="lf-profile-label">
                    Role
                </div>

                <div class="lf-profile-value">
                    {{ auth()->user()->role }}
                </div>
            </div>

            <div class="lf-profile-row">
                <div class="lf-profile-label">
                    Customer ID
                </div>

                <div class="lf-profile-value">
                    {{ auth()->user()->customer_id }}
                </div>
            </div>

        </div>

    </div>

@endsection
