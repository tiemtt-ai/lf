@extends('layouts.backend')

@section('title', 'Admin Dashboard')
@section('page_title', 'Dashboard')

@section('content')
    <p class="admin-dashboard-welcome">
        Xin chào {{ auth()->user()->name }}!
    </p>

    <div class="admin-card">
        <dl class="admin-profile-summary">
            <div>
                <dt>Name</dt>
                <dd>{{ auth()->user()->name }}</dd>
            </div>
            <div>
                <dt>Email</dt>
                <dd>{{ auth()->user()->email }}</dd>
            </div>
            <div>
                <dt>Role</dt>
                <dd>{{ auth()->user()->role }}</dd>
            </div>
            <div>
                <dt>Customer ID</dt>
                <dd>{{ auth()->user()->customer_id }}</dd>
            </div>
        </dl>
    </div>
@endsection
