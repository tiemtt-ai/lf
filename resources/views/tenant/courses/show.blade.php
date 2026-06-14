@extends('layouts.tenant')

@section('title', $course['title'].' | '.($tenant?->name ?? 'LearnForge'))

@section('content')
    <article class="student-card student-section tenant-course-detail">
        <p class="student-eyebrow">Course Detail</p>
        <h1>{{ $course['title'] }}</h1>
        <p class="student-course-meta">Teacher: {{ $course['teacher'] }} · {{ $course['price'] }}</p>
        <p class="student-section-copy">{{ $course['summary'] }}</p>

        <div class="tenant-detail-grid">
            <div><strong>Curriculum</strong><span>12 structured lessons</span></div>
            <div><strong>Assessments</strong><span>Practice quizzes and final assessment</span></div>
            <div><strong>Access</strong><span>Enrollment grants learning access</span></div>
        </div>

        <div class="tenant-course-actions">
            @guest
                <a class="student-button" href="{{ route('login') }}">Login to Register / Login to Purchase</a>
            @else
                @if (auth()->user()->role === 'student' && $course['enrolled'])
                    <a class="student-button" href="#">Start Learning / Continue Learning</a>
                    <a class="student-button is-outline" href="#">View Progress</a>
                    <a class="student-text-link" href="{{ route('tenant.assessments') }}">Take Assessments</a>
                @elseif (auth()->user()->role === 'student')
                    <a class="student-button" href="#">Register</a>
                    <a class="student-button is-outline" href="#">Purchase</a>
                    <button class="student-text-link tenant-link-button" type="button">Add To Favorites</button>
                @endif
            @endguest
        </div>

        <p class="tenant-access-note">Favorite is a saved preference. Enrollment remains the permission to learn.</p>
    </article>
@endsection
