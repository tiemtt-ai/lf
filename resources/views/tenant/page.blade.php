@extends('layouts.tenant')

@section('title', $title.' | '.($tenant?->name ?? 'LearnForge'))

@section('content')
    <header class="student-page-heading tenant-page-heading">
        <p class="student-eyebrow">Tenant Website</p>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
    </header>

    <section class="student-card student-section">
        <h2 class="student-section-title">{{ $title }} content</h2>
        <p class="student-section-copy">This section is ready for tenant-owned content and customer-scoped data.</p>
    </section>
@endsection
