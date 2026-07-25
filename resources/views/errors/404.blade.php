@extends('layouts.public')

@section('title', '404 - '.__('lf.LF_common_title_public_page_not_found'))
@section('force_root_navigation', '1')

@section('content')
    <section class="public-error-page" aria-labelledby="public-error-title">
        <div class="public-error-card">
            <div class="public-error-visual" aria-hidden="true">
                <span class="public-error-orbit"></span>
                <span class="public-error-compass">?</span>
            </div>

            <p class="public-error-code">404</p>
            <h1 id="public-error-title">{{ __('lf.LF_common_title_public_page_not_found') }}</h1>
            <p class="public-error-message">{{ __('lf.LF_common_message_public_page_not_found') }}</p>
            <span class="public-error-divider" aria-hidden="true"></span>
        </div>
    </section>
@endsection
