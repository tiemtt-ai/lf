@extends('layouts.tenant')

@section('title', $title.' | '.($tenant?->name ?? 'LearnForge'))

@section('content')
    <header class="student-page-heading tenant-page-heading">
        <p class="student-eyebrow">Personalized Student Experience</p>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
    </header>

    <div class="tenant-feature-grid">
        @foreach ($items as $item)
            <article class="student-card student-section">
                <h2 class="student-section-title">{{ $item }}</h2>
                <p class="student-section-copy">This area is protected by tenant, authentication, verification and student role middleware.</p>
            </article>
        @endforeach
    </div>
@endsection
