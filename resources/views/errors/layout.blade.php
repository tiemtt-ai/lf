{{--
    Error page (404, 403) drawn in the area the visitor is in (App\Support\ErrorPageArea, Owner 2026-11-18):
    - backend: the admin/teacher shell with its menu, a UI Kit card and a way back to the dashboard;
    - tenant: the tenant's own website, a way back to its home page, no link to LearnForge;
    - root: the LearnForge public site. An unresolved tenant also lands here and must keep every link on the
      root domain (force_root_navigation, see ResolveTenant).
    Expects $code, $title, $message, $icon (backend-icon name) and $symbol (one character for the public card).
--}}
@php
    $errorArea = \App\Support\ErrorPageArea::current();
    $errorUser = auth()->user();
    $errorLayout = match ($errorArea) {
        \App\Support\ErrorPageArea::BACKEND => 'layouts.backend',
        \App\Support\ErrorPageArea::TENANT => 'layouts.tenant',
        default => 'layouts.public',
    };
    $tenantName = \App\Support\TenantContext::customer()?->name;
@endphp

@extends($errorLayout)

@section('title', $code.' - '.$title.($errorArea === \App\Support\ErrorPageArea::TENANT && $tenantName ? ' | '.$tenantName : ''))

@if ($errorArea === \App\Support\ErrorPageArea::ROOT)
    @section('force_root_navigation', '1')
@endif

@if ($errorArea === \App\Support\ErrorPageArea::BACKEND)
    @section('page_title', $title)
    @section('page_title_hidden', '1')

    @section('vite')
        @parent
        @vite(['resources/css/admin/admin-ui-kit.css'])
    @endsection
@endif

@section('content')
    @if ($errorArea === \App\Support\ErrorPageArea::BACKEND)
        <section class="lf-admin-ui lf-admin-ui--list ui-error-page" aria-labelledby="error-page-title">
            <div class="ui-section ui-error-card">
                <span class="ui-error-card__icon" aria-hidden="true"><x-backend-icon :name="$icon" class="ui-icon" /></span>
                <p class="ui-error-card__code">{{ $code }}</p>
                <h2 id="error-page-title">{{ $title }}</h2>
                <p class="ui-error-card__message">{{ $message }}</p>
                <div class="ui-error-card__actions">
                    <button type="button" class="ui-button cursor-interaction" onclick="history.back()">
                        <x-backend-icon name="arrow-left" class="ui-icon" />{{ __('lf.LF_common_button_back') }}
                    </button>
                    <a class="ui-button ui-button--primary cursor-interaction" href="{{ route(\App\Support\ErrorPageArea::dashboardRoute($errorUser)) }}">
                        <x-backend-icon name="home" class="ui-icon" />{{ __('lf.LF_common_button_common_back_to_dashboard') }}
                    </a>
                </div>
            </div>
        </section>
    @else
        <section class="public-error-page" aria-labelledby="public-error-title">
            <div class="public-error-card">
                <div class="public-error-visual" aria-hidden="true">
                    <span class="public-error-orbit"></span>
                    <span class="public-error-compass">{{ $symbol }}</span>
                </div>

                <p class="public-error-code">{{ $code }}</p>
                <h1 id="public-error-title">{{ $title }}</h1>
                <p class="public-error-message">{{ $message }}</p>
                @if ($errorArea === \App\Support\ErrorPageArea::TENANT)
                    <div class="public-error-actions">
                        <a class="student-button" href="{{ route('public.home') }}">{{ __('lf.LF_common_button_public_back_home') }}</a>
                    </div>
                @else
                    <span class="public-error-divider" aria-hidden="true"></span>
                @endif
            </div>
        </section>
    @endif
@endsection
