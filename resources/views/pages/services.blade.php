@extends('layouts.public')

@section('title', __('lf.LF_home_title_public_services_page'))

@section('content')
    <header class="public-page-heading">
        <div class="public-container">
            <span class="public-eyebrow">{{ __('lf.LF_service_title_public_services') }}</span>
            <h1>{{ __('lf.LF_home_message_public_services_page_title') }}</h1>
            <p>{{ __('lf.LF_home_message_public_services_page_description') }}</p>
        </div>
    </header>

    <section class="public-section">
        <div class="public-container">
            <div class="public-card-grid">
                @foreach ([
                    [__('lf.LF_home_card_public_lms_title'), __('lf.LF_home_card_public_lms_description')],
                    [__('lf.LF_home_card_public_assessment_platform_title'), __('lf.LF_home_card_public_assessment_platform_description')],
                    [__('lf.LF_home_card_public_ai_learning_service_title'), __('lf.LF_home_card_public_ai_learning_service_description')],
                    [__('lf.LF_home_card_public_learning_analytics_title'), __('lf.LF_home_card_public_learning_analytics_service_description')],
                    [__('lf.LF_home_card_public_teacher_analytics_title'), __('lf.LF_home_card_public_teacher_analytics_description')],
                    [__('lf.LF_home_card_public_multi_tenant_title'), __('lf.LF_home_card_public_multi_tenant_service_description')],
                ] as $index => [$title, $description])
                    <article class="public-card">
                        <span class="public-card-number">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <h2>{{ $title }}</h2>
                        <p>{{ $description }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endsection
