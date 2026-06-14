@extends('layouts.tenant')

@section('title', ($tenant?->name ?? 'Tenant').' | LearnForge')

@section('content')
    <section class="student-hero tenant-banner">
        <div class="student-hero-content">
            <p class="student-eyebrow">
                <span class="student-eyebrow-dot"></span>
                {{ $studentMode ? 'Welcome back, '.auth()->user()->name : 'Learn. Grow. Achieve.' }}
            </p>
            <h1>{{ $studentMode ? 'Your learning, connected to everything we offer.' : 'Build skills that move you forward.' }}</h1>
            <p class="student-hero-copy">
                Explore courses, assessments and expert services from {{ $tenant->name }}.
            </p>
            <div class="student-hero-actions">
                <a class="student-button" href="{{ route('tenant.courses.index') }}">Explore Courses</a>
                <a class="student-button is-light" href="{{ route('public.services') }}">View Services</a>
            </div>
        </div>
    </section>

    @if ($studentMode)
        <section class="tenant-personalized-section" aria-labelledby="personalized-heading">
            <div class="student-section-header">
                <div>
                    <p class="student-eyebrow">Personalized for you</p>
                    <h2 class="student-section-title" id="personalized-heading">Continue Learning</h2>
                </div>
                <a class="student-text-link" href="{{ route('student.courses.index') }}">My Courses</a>
            </div>

            <div class="student-stat-grid">
                <article class="student-card student-stat-card">
                    <div><p class="student-stat-value">72%</p><p class="student-stat-label">Continue Learning</p></div>
                </article>
                <article class="student-card student-stat-card">
                    <div><p class="student-stat-value">3</p><p class="student-stat-label">My Courses</p></div>
                </article>
                <article class="student-card student-stat-card">
                    <div><p class="student-stat-value">2</p><p class="student-stat-label">Upcoming Activities</p></div>
                </article>
                <article class="student-card student-stat-card">
                    <div><p class="student-stat-value">1</p><p class="student-stat-label">Pending Assessments</p></div>
                </article>
            </div>

            <article class="student-card tenant-ai-recommendation">
                <div>
                    <p class="student-profile-card-label">AI Recommendations</p>
                    <h3>Review workplace vocabulary before your next lesson.</h3>
                    <p>Your recent activity suggests a short vocabulary review will help reinforce this week’s course.</p>
                </div>
                <a class="student-button is-outline" href="{{ route('student.ai-tutor') }}">Ask AI Tutor</a>
            </article>
        </section>
    @endif

    <section class="student-card student-section tenant-section">
        <div class="student-section-header">
            <div>
                <p class="student-profile-card-label">Featured Courses</p>
                <h2 class="student-section-title">Learn with a clear path</h2>
            </div>
            <a class="student-text-link" href="{{ route('tenant.courses.index') }}">View all courses</a>
        </div>
        <div class="tenant-course-grid">
            @foreach ($courses as $course)
                @include('tenant.partials.course-card', compact('course'))
            @endforeach
        </div>
    </section>

    <section class="tenant-feature-grid">
        <article class="student-card student-section">
            <p class="student-profile-card-label">Featured Services</p>
            <h2 class="student-section-title">Coaching and workshops</h2>
            <p class="student-section-copy">Register for mentoring, live workshops and learning support services.</p>
            <a class="student-text-link" href="{{ route('public.services') }}">Explore services</a>
        </article>
        <article class="student-card student-section">
            <p class="student-profile-card-label">Teachers</p>
            <h2 class="student-section-title">Learn from experienced educators</h2>
            <p class="student-section-copy">Meet teachers with practical expertise and learner-focused methods.</p>
            <a class="student-text-link" href="{{ route('tenant.teachers') }}">Meet our teachers</a>
        </article>
        <article class="student-card student-section">
            <p class="student-profile-card-label">News</p>
            <h2 class="student-section-title">New learning events this month</h2>
            <p class="student-section-copy">Join live practice sessions and discover newly published courses.</p>
        </article>
    </section>

    <section class="student-card tenant-contact-cta">
        <div>
            <p class="student-profile-card-label">Contact / CTA</p>
            <h2>Not sure where to start?</h2>
            <p>Talk with our team about the right course or service for your goals.</p>
        </div>
        <a class="student-button" href="{{ route('tenant.contact') }}">Contact Us</a>
    </section>
@endsection
