@extends('layouts.public')

@section('title', __('lf.LF_home_title_public_pricing_page'))

@section('content')
    <header class="public-page-heading">
        <div class="public-container">
            <span class="public-eyebrow">{{ __('lf.LF_common_title_public_pricing') }}</span>
            <h1>{{ __('lf.LF_home_message_public_pricing_title') }}</h1>
            <p>{{ __('lf.LF_home_message_public_pricing_description') }}</p>
        </div>
    </header>

    <section class="public-section">
        <div class="public-container">
            <div class="public-pricing-grid">
                <article class="public-price-card">
                    <h2>{{ __('lf.LF_home_card_public_starter') }}</h2>
                    <span class="public-status">{{ __('lf.LF_common_message_common_coming_soon') }}</span>
                </article>
                <article class="public-price-card is-featured">
                    <h2>{{ __('lf.LF_home_card_public_professional') }}</h2>
                    <span class="public-status">{{ __('lf.LF_common_message_common_coming_soon') }}</span>
                </article>
                <article class="public-price-card">
                    <h2>{{ __('lf.LF_home_card_public_enterprise') }}</h2>
                    <span class="public-status">{{ __('lf.LF_common_message_common_coming_soon') }}</span>
                </article>
            </div>
        </div>
    </section>
@endsection
