@extends('layouts.tenant')

@section('title', ($tenant?->name ?? 'Tenant').' | LearnForge')

@section('content')
    <section class="student-hero tenant-banner">
        <div class="student-hero-content">
            <p class="student-eyebrow">
                <span class="student-eyebrow-dot"></span>
                {{ $studentMode ? __('lf.LF_home_student_banner_welcome', ['name' => auth()->user()->name]) : __('lf.LF_home_public_banner_eyebrow') }}
            </p>
            <h1>{{ $studentMode ? __('lf.LF_home_student_banner_title') : __('lf.LF_home_public_banner_title') }}</h1>
            <p class="student-hero-copy">
                {{ __('lf.LF_home_public_banner_description', ['tenant' => $tenant->name]) }}
            </p>
            <div class="student-hero-actions">
                <a class="student-button" href="{{ route('tenant.courses.index') }}">{{ __('lf.LF_home_public_explore_courses') }}</a>
                <a class="student-button is-light" href="{{ route('public.services') }}">{{ __('lf.LF_home_public_view_services') }}</a>
            </div>
        </div>
    </section>

    @if ($studentMode)
        <section class="tenant-personalized-section" aria-labelledby="personalized-heading">
            <div class="student-section-header">
                <div>
                    <p class="student-eyebrow">{{ __('lf.LF_home_student_personalized') }}</p>
                    <h2 class="student-section-title" id="personalized-heading">{{ __('lf.LF_home_student_continue_learning') }}</h2>
                </div>
                <a class="student-text-link" href="{{ route('student.courses.index') }}">{{ __('lf.LF_navigation_menu_student_my_courses') }}</a>
            </div>

            <div class="student-stat-grid">
                <article class="student-card student-stat-card">
                    <div><p class="student-stat-value">72%</p><p class="student-stat-label">{{ __('lf.LF_home_student_continue_learning') }}</p></div>
                </article>
                <article class="student-card student-stat-card">
                    <div><p class="student-stat-value">3</p><p class="student-stat-label">{{ __('lf.LF_navigation_menu_student_my_courses') }}</p></div>
                </article>
                <article class="student-card student-stat-card">
                    <div><p class="student-stat-value">2</p><p class="student-stat-label">{{ __('lf.LF_home_student_upcoming_activities') }}</p></div>
                </article>
                <article class="student-card student-stat-card">
                    <div><p class="student-stat-value">1</p><p class="student-stat-label">{{ __('lf.LF_home_student_pending_assessments') }}</p></div>
                </article>
            </div>

            <article class="student-card tenant-ai-recommendation">
                <div>
                    <p class="student-profile-card-label">{{ __('lf.LF_home_student_ai_recommendations') }}</p>
                    <h3>{{ __('lf.LF_home_student_ai_title') }}</h3>
                    <p>{{ __('lf.LF_home_student_ai_description') }}</p>
                </div>
                <a class="student-button is-outline" href="{{ route('student.ai-tutor') }}">{{ __('lf.LF_home_student_ask_ai_tutor') }}</a>
            </article>
        </section>
    @endif

    <section class="student-card student-section tenant-section">
        <div class="student-section-header">
            <div>
                <p class="student-profile-card-label">{{ __('lf.LF_home_public_featured_courses') }}</p>
                <h2 class="student-section-title">{{ __('lf.LF_home_public_courses_title') }}</h2>
            </div>
            <a class="student-text-link" href="{{ route('tenant.courses.index') }}">{{ __('lf.LF_home_public_view_all_courses') }}</a>
        </div>
        <div class="tenant-course-grid">
            @foreach ($courses as $course)
                @include('tenant.partials.course-card', compact('course'))
            @endforeach
        </div>
    </section>

    <section class="tenant-feature-grid">
        <article class="student-card student-section">
            <p class="student-profile-card-label">{{ __('lf.LF_home_public_featured_services') }}</p>
            <h2 class="student-section-title">{{ __('lf.LF_home_public_services_title') }}</h2>
            <p class="student-section-copy">{{ __('lf.LF_home_public_services_description') }}</p>
            <a class="student-text-link" href="{{ route('public.services') }}">{{ __('lf.LF_home_public_explore_services') }}</a>
        </article>
        <article class="student-card student-section">
            <p class="student-profile-card-label">{{ __('lf.LF_navigation_menu_public_teachers') }}</p>
            <h2 class="student-section-title">{{ __('lf.LF_home_public_teachers_title') }}</h2>
            <p class="student-section-copy">{{ __('lf.LF_home_public_teachers_description') }}</p>
            <a class="student-text-link" href="{{ route('tenant.teachers') }}">{{ __('lf.LF_home_public_meet_teachers') }}</a>
        </article>
        <article class="student-card student-section">
            <p class="student-profile-card-label">{{ __('lf.LF_home_public_news') }}</p>
            <h2 class="student-section-title">{{ __('lf.LF_home_public_news_title') }}</h2>
            <p class="student-section-copy">{{ __('lf.LF_home_public_news_description') }}</p>
        </article>
    </section>

    <section class="student-card tenant-contact-cta">
        <div>
            <p class="student-profile-card-label">{{ __('lf.LF_home_public_contact_cta') }}</p>
            <h2>{{ __('lf.LF_home_public_contact_title') }}</h2>
            <p>{{ __('lf.LF_home_public_contact_description') }}</p>
        </div>
        <a class="student-button" href="{{ route('tenant.contact') }}">{{ __('lf.LF_home_public_contact_us') }}</a>
    </section>
@endsection
