@extends('layouts.public')

@section('title', 'LearnForge')

@section('content')
    <section class="public-hero">
        <div class="public-container">
            <div class="public-hero-content">
                <span class="public-eyebrow">AI-Native Learning Platform</span>
                <h1>Build and operate digital learning at scale.</h1>
                <p class="public-hero-lead">
                    LearnForge combines learning management, assessments, analytics and AI capabilities
                    in one multi-tenant platform.
                </p>
                <div class="public-hero-actions">
                    <a class="public-button" href="{{ route('customer.register') }}">Register Tenant</a>
                    <a class="public-button is-secondary" href="{{ route('public.features') }}">Explore Features</a>
                </div>
            </div>
        </div>
    </section>

    <section class="public-section">
        <div class="public-container">
            <div class="public-section-heading">
                <h2>One platform for every learning role</h2>
                <p>Dedicated back offices for operators and one tenant website for visitors and students.</p>
            </div>

            <div class="public-card-grid">
                <article class="public-card">
                    <span class="public-card-number">01</span>
                    <h3>Customer Admin</h3>
                    <p>Manage users, courses, assessments, reports and tenant settings.</p>
                </article>
                <article class="public-card">
                    <span class="public-card-number">02</span>
                    <h3>Teacher</h3>
                    <p>Deliver courses, manage learners and monitor learning performance.</p>
                </article>
                <article class="public-card">
                    <span class="public-card-number">03</span>
                    <h3>Student</h3>
                    <p>Access lessons, assessments, progress tracking and AI learning support.</p>
                </article>
            </div>
        </div>
    </section>
@endsection
