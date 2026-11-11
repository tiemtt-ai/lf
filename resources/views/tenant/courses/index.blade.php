@extends('layouts.tenant')

@php $isClassList = $filters['type'] === 'class'; $pageTitle = __($isClassList ? 'lf.LF_navigation_menu_public_classes' : 'lf.LF_course_title_public_courses'); @endphp
@section('title', $pageTitle.' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))
@section('tenant_page_class', 'has-page-hero')

{{-- The catalogue in the standard B2B look: banner with search, category chips, card grid. --}}
@php
    $filtered = $filters['q'] !== '' || $filters['category'] || $filters['type'];
    $chip = fn (array $change) => route('tenant.courses.index', array_filter([...['q' => $filters['q'], 'category' => $filters['category'], 'type' => $filters['type']], ...$change]));
@endphp

@section('page_hero')
    <div class="sb2b-page-title sb2b-page-title--courses">
        <div>
            <span>{{ __('lf.LF_catalog_eyebrow') }}</span>
            <h1>{{ $pageTitle }}</h1>
            <p>{{ __($isClassList ? 'lf.LF_course_message_public_class_catalog_description' : 'lf.LF_course_message_public_catalog_description') }}</p>
            <form class="sb2b-catalog-search" action="{{ route('tenant.courses.index') }}" method="get" role="search" autocomplete="off">
                @if ($filters['category'])<input type="hidden" name="category" value="{{ $filters['category'] }}">@endif
                @if ($filters['type'])<input type="hidden" name="type" value="{{ $filters['type'] }}">@endif
                <label class="sr-only" for="catalog-search-name">{{ __('lf.LF_course_catalog_search') }}</label>
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m16 16 5 5" stroke="currentColor" stroke-width="1.8"/></svg>
                <input id="catalog-search-name" type="search" name="q" maxlength="120" value="{{ $filters['q'] }}" placeholder="{{ __('lf.LF_course_catalog_search') }}">
                <button type="submit">{{ __('lf.LF_catalog_search_button') }}</button>
            </form>
        </div>
        <b aria-hidden="true">{{ $isClassList ? '◉' : '▤' }}</b>
    </div>
@endsection

@section('content')
    <section class="sb2b-flat-catalog" data-catalog>
        <nav class="sb2b-category-chips" aria-label="{{ __('lf.LF_course_title_public_catalog') }}">
            <a @class(['is-active' => ! $filters['category']]) href="{{ $chip(['category' => null]) }}">{{ __('lf.LF_catalog_all') }}</a>
            @foreach ($categories as $category)
                <a @class(['is-active' => $filters['category'] === $category]) href="{{ $chip(['category' => $filters['category'] === $category ? null : $category]) }}">{{ $category }}</a>
            @endforeach
        </nav>

        <div class="sb2b-flat-catalog__heading">
            <span>{!! __(($filters['q'] !== '' || $filters['category'] ? 'lf.LF_course_catalog_found' : 'lf.LF_course_catalog_total').($isClassList ? '_classes' : ''), ['count' => '<strong class="sb2b-exam-result-count">'.$courses->total().'</strong>']) !!}</span>
        </div>

        @if ($courses->isEmpty())
            <div class="sb2b-flat-catalog__empty" role="status">{{ __($filters['q'] !== '' || $filters['category'] ? 'lf.LF_course_catalog_filter_empty' : 'lf.LF_course_catalog_empty') }}</div>
        @else
            <div class="student-products__grid student-products__grid--courses">
                @foreach ($courses as $course)
                    @include('tenant.partials.course-card', compact('course'))
                @endforeach
            </div>
            @include('tenant.partials.catalog-pages', ['pages' => $courses])
        @endif
    </section>
@endsection
