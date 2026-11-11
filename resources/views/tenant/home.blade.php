@extends('layouts.tenant')

@section('title', ($tenant?->name ?? 'Tenant').' | '.__('lf.LF_common_brand_student_name'))
@section('tenant_page_class', 'has-page-hero lf-home-page')

{{-- Tenant home in the standard B2B look (mk_v2 b2b/home): banner, suggested courses, suggested classes, AI Speak,
     benefits. Temporary by Owner's request (2026-11-11); every block is full width, so it lives in page_hero. --}}
@section('page_hero')
@php
    $home = fn (string $file) => asset('assets/student/home/'.$file);
    $more = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>';
    $benefits = ['brand.svg', 'roadmap.svg', 'message.svg', 'play-b2b.svg', 'cskh.svg', 'bag_home.svg'];
@endphp
    <section class="sb2b-default-banner" aria-label="{{ __('lf.LF_home_b2b_banner_title') }}">
        <div class="sb2b-default-banner__inner">
            <div class="sb2b-default-banner__copy">
                <span>{{ __('lf.LF_catalog_eyebrow') }}</span>
                <h1>{{ __('lf.LF_home_b2b_banner_title') }}<br><em>{{ __('lf.LF_home_b2b_banner_highlight') }}</em></h1>
                <p>{{ __('lf.LF_home_b2b_banner_description') }}</p>
            </div>
            <svg class="sb2b-default-banner__art" viewBox="0 0 400 300" fill="none" aria-hidden="true">
                <circle cx="210" cy="150" r="130" fill="#ffffff" fill-opacity=".08"/>
                <circle cx="330" cy="55" r="18" fill="#b8eb76"/>
                <rect x="60" y="62" width="275" height="176" rx="18" fill="#edf3ff" transform="rotate(-6 60 62)"/>
                <rect x="83" y="86" width="229" height="125" rx="10" fill="#4157c8" transform="rotate(-6 83 86)"/>
                <path d="m179 116 43 24-43 24v-48Z" fill="#b8eb76"/>
                <path d="M37 245h314" stroke="#b8eb76" stroke-width="12" stroke-linecap="round"/>
                <path d="M250 214c-23-11-43-9-61-1v53c19-9 39-9 61 1 22-10 42-10 61-1v-53c-18-8-38-10-61 1Z" fill="white"/>
                <path d="M250 214v53m-48-39 32-2m-32 16 32-2m32-14 32 2m-32 12 32 2" stroke="#4157c8" stroke-width="4" stroke-linecap="round"/>
                <path d="m65 30 5 12 12 5-12 5-5 12-5-12-12-5 12-5Z" fill="#b8eb76"/>
            </svg>
        </div>
    </section>

    <section class="sb2b-home-popular">
        <div class="lf-home__inner">
            @foreach ([
                ['courses', $courses, 'self_paced', 'LF_home_b2b_courses_title', 'LF_home_b2b_courses_description', 'LF_course_catalog_empty'],
                ['classes', $classes, 'class', 'LF_home_b2b_classes_title', 'LF_home_b2b_classes_description', 'LF_home_b2b_classes_empty'],
            ] as [$key, $items, $type, $titleKey, $descriptionKey, $emptyKey])
                <div class="lf-home__block" data-home-block="{{ $key }}">
                    <div class="sb2b-home-popular__intro">
                        <h2>{{ __('lf.'.$titleKey) }}</h2>
                        <p>{{ __('lf.'.$descriptionKey) }}</p>
                    </div>
                    @if ($items->isEmpty())
                        <div class="sb2b-flat-catalog__empty" role="status">{{ __('lf.'.$emptyKey) }}</div>
                    @else
                        <div class="student-products__grid student-products__grid--courses">
                            @foreach ($items as $course)
                                @include('tenant.partials.course-card', compact('course'))
                            @endforeach
                        </div>
                        <div class="sb2b-home-popular__more">
                            <a class="sb2b-home-popular__more-link" href="{{ route('tenant.courses.index', ['type' => $type]) }}">{{ __('lf.LF_home_b2b_more') }} {!! $more !!}</a>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="sb2b-home-ai-feature">
        <div class="sb2b-home-ai-feature__panel">
            <img class="recommend-img" src="{{ $home('ai-speak-phone.png') }}" alt="" loading="lazy">
            <div class="discovery">
                <h2 class="text-discovery">{!! __('lf.LF_home_b2b_ai_title', ['highlight' => '<span class="text-blue">'.e(__('lf.LF_home_b2b_ai_highlight')).'</span>']) !!}</h2>
                <div class="sb2b-home-ai-feature__details">
                    <p>{{ __('lf.LF_home_b2b_ai_description') }}</p>
                    <ul>
                        <li>{{ __('lf.LF_home_b2b_ai_item_1') }}</li>
                        <li>{{ __('lf.LF_home_b2b_ai_item_2') }}</li>
                        <li>{{ __('lf.LF_home_b2b_ai_item_3') }}</li>
                    </ul>
                </div>
                <button type="button" class="btn-discovery" data-coming-soon aria-disabled="true">{{ __('lf.LF_home_b2b_ai_explore') }}</button>
            </div>
        </div>
    </section>

    <section class="sb2b-home-benefits" aria-labelledby="sb2b-home-benefits-title">
        <div class="sb2b-home-benefits__heading">
            <h2 id="sb2b-home-benefits-title">{{ __('lf.LF_home_b2b_benefits_title', ['tenant' => $tenant->name]) }}</h2>
            <p>{{ __('lf.LF_home_b2b_benefits_description') }}</p>
        </div>
        <div class="sb2b-home-benefits__grid">
            @foreach ($benefits as $index => $iconFile)
                <article class="sb2b-home-benefits__card">
                    <span class="sb2b-home-benefits__icon"><img src="{{ $home($iconFile) }}" alt=""></span>
                    <h3>{{ __('lf.LF_home_b2b_benefit_'.($index + 1).'_title') }}</h3>
                    <p>{{ __('lf.LF_home_b2b_benefit_'.($index + 1).'_description') }}</p>
                </article>
            @endforeach
        </div>
    </section>
@endsection

@section('content')
@endsection
