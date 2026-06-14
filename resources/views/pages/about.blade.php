@extends('layouts.public')

@section('title', __('lf.LF_home_title_public_about_page'))

@section('content')
    <header class="public-page-heading">
        <div class="public-container">
            <span class="public-eyebrow">{{ __('lf.LF_common_title_public_about') }}</span>
            <h1>{{ __('lf.LF_home_message_public_about_title') }}</h1>
            <p>{{ __('lf.LF_home_message_public_about_description') }}</p>
        </div>
    </header>

    <section class="public-section">
        <div class="public-container public-content">
            <p>{{ __('lf.LF_home_message_public_about_capabilities') }}</p>
            <p>{{ __('lf.LF_home_message_public_about_tenancy') }}</p>
            <p>{{ __('lf.LF_home_message_public_about_architecture') }}</p>
        </div>
    </section>
@endsection
