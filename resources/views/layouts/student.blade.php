@extends('layouts.app')

@section('body_class', 'lf-student-page')

@section('app_shell')
    <div class="lf-student-portal">
        <header class="lf-student-header">
            <a class="lf-student-brand" href="{{ route('student.dashboard') }}">
                Front end
            </a>

            <nav class="lf-student-menu" aria-label="Student navigation">
                <a href="{{ route('student.dashboard') }}">Dashboard</a>
                <span class="lf-menu-disabled">My Courses</span>
                <span class="lf-menu-disabled">Assessments</span>
                <span class="lf-menu-disabled">Live Classes</span>
                <span class="lf-menu-disabled">AI Tutor</span>
                <a href="{{ route('student.profile.edit') }}">Profile</a>
            </nav>

            <div class="lf-portal-account">
                <span>{{ auth()->user()->name }}</span>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <button type="submit">Logout</button>
                </form>
            </div>
        </header>

        <main class="lf-student-main">
            <div class="lf-student-page-title">
                @yield('page_title', 'Learning Dashboard')
            </div>

            @yield('content')
        </main>
    </div>
@endsection
