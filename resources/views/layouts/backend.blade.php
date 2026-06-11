@extends('layouts.app')

@section('body_class', 'lf-backend-page')

@section('app_shell')
    <div class="lf-dashboard">
        <aside class="lf-sidebar">
            <div class="lf-sidebar-brand">
                LearnForge Backend
            </div>

            <nav class="lf-sidebar-menu" aria-label="Backend navigation">
                @if (auth()->user()?->role === 'customer_admin')
                    <a href="{{ route('admin.dashboard') }}">Dashboard</a>
                    <a href="{{ route('admin.users.index') }}">Users</a>
                    <span class="lf-menu-disabled">Courses</span>
                    <span class="lf-menu-disabled">Assessments</span>
                    <a href="{{ route('admin.profile.edit') }}">Settings</a>
                @elseif (auth()->user()?->role === 'teacher')
                    <a href="{{ route('teacher.dashboard') }}">Dashboard</a>
                    <span class="lf-menu-disabled">My Courses</span>
                    <span class="lf-menu-disabled">Assessments</span>
                    <span class="lf-menu-disabled">Students</span>
                    <span class="lf-menu-disabled">Reports</span>
                    <a href="{{ route('teacher.profile.edit') }}">Profile</a>
                @endif
            </nav>
        </aside>

        <div class="lf-dashboard-content">
            <header class="lf-dashboard-header">
                <div>
                    @yield('page_title', 'Dashboard')
                </div>

                <div class="lf-portal-account">
                    <span>{{ auth()->user()->name }}</span>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <button type="submit">Logout</button>
                    </form>
                </div>
            </header>

            <main class="lf-dashboard-main">
                @yield('content')
            </main>
        </div>
    </div>
@endsection
