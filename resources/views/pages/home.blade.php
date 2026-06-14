@extends('layouts.public')

@section('title', __('lf.LF_common_brand_name'))

@section('content')
    <section class="public-hero">
        <div class="public-container">
            <div class="public-hero-content">
                <span class="public-eyebrow">{{ __('lf.LF_home_public_eyebrow') }}</span>
                <h1>{{ __('lf.LF_home_public_title') }}</h1>
                <p class="public-hero-lead">
                    {{ __('lf.LF_home_public_description') }}
                </p>
                <div class="public-hero-actions">
                    <a class="public-button" href="{{ route('customer.register') }}">{{ __('lf.LF_home_public_register_tenant') }}</a>
                    <a class="public-button is-secondary" href="{{ route('public.features') }}">{{ __('lf.LF_home_public_explore_features') }}</a>
                </div>
            </div>
        </div>
    </section>

    <section class="public-section">
        <div class="public-container">
            <div class="public-section-heading">
                <h2>{{ __('lf.LF_home_public_roles_title') }}</h2>
                <p>{{ __('lf.LF_home_public_roles_description') }}</p>
            </div>

            <div class="public-card-grid">
                <article class="public-card">
                    <span class="public-card-number">01</span>
                    <h3>{{ __('lf.LF_common_role_admin_customer_admin') }}</h3>
                    <p>{{ __('lf.LF_home_public_admin_description') }}</p>
                </article>
                <article class="public-card">
                    <span class="public-card-number">02</span>
                    <h3>{{ __('lf.LF_common_role_teacher_teacher') }}</h3>
                    <p>{{ __('lf.LF_home_public_teacher_description') }}</p>
                </article>
                <article class="public-card">
                    <span class="public-card-number">03</span>
                    <h3>{{ __('lf.LF_common_role_student_student') }}</h3>
                    <p>{{ __('lf.LF_home_public_student_description') }}</p>
                </article>
            </div>
        </div>
    </section>
@endsection
