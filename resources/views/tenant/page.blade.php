@extends('layouts.tenant')

@section('title', $title.' | '.($tenant?->name ?? 'LearnForge'))

@section('content')
    <header class="student-page-heading tenant-page-heading">
        <p class="student-eyebrow">{{ __('lf.LF_common_title_public_tenant_website') }}</p>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
    </header>

    <section class="student-card student-section">
        <h2 class="student-section-title">{{ __('lf.LF_service_title_public_content', ['title' => $title]) }}</h2>
        <p class="student-section-copy">{{ __('lf.LF_service_message_public_placeholder') }}</p>
    </section>
@endsection
