@extends('layouts.public')

@section('title', __('lf.LF_home_title_public_features_page'))

@section('content')
    <header class="public-page-heading">
        <div class="public-container">
            <span class="public-eyebrow">{{ __('lf.LF_common_title_public_features') }}</span>
            <h1>{{ __('lf.LF_home_message_public_features_title') }}</h1>
            <p>{{ __('lf.LF_home_message_public_features_description') }}</p>
        </div>
    </header>

    <section class="public-section">
        <div class="public-container">
            <div class="public-card-grid">
                @foreach ([
                    [__('lf.LF_home_card_public_course_management_title'), __('lf.LF_home_card_public_course_management_description')],
                    [__('lf.LF_home_card_public_assessment_engine_title'), __('lf.LF_home_card_public_assessment_engine_description')],
                    [__('lf.LF_home_card_public_ai_learning_title'), __('lf.LF_home_card_public_ai_learning_description')],
                    [__('lf.LF_home_card_public_media_learning_title'), __('lf.LF_home_card_public_media_learning_description')],
                    [__('lf.LF_home_card_public_role_portals_title'), __('lf.LF_home_card_public_role_portals_description')],
                    [__('lf.LF_home_card_public_learning_analytics_title'), __('lf.LF_home_card_public_learning_analytics_description')],
                    [__('lf.LF_home_card_public_live_classes_title'), __('lf.LF_home_card_public_live_classes_description')],
                    [__('lf.LF_home_card_public_multi_tenant_title'), __('lf.LF_home_card_public_multi_tenant_description')],
                    [__('lf.LF_home_card_public_reports_title'), __('lf.LF_home_card_public_reports_description')],
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
