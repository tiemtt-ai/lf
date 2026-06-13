@extends('layouts.public')

@section('title', 'LearnForge Services')

@section('content')
    <header class="public-page-heading">
        <div class="public-container">
            <span class="public-eyebrow">Services</span>
            <h1>Flexible learning solutions for different organizations.</h1>
            <p>Start with the capabilities you need and expand within the same platform architecture.</p>
        </div>
    </header>

    <section class="public-section">
        <div class="public-container">
            <div class="public-card-grid">
                @foreach ([
                    ['LMS Platform', 'A complete foundation for digital course delivery and user management.'],
                    ['Assessment Platform', 'Structured examination, quiz and learner evaluation workflows.'],
                    ['AI Learning', 'AI-assisted learning experiences backed by tracking and knowledge.'],
                    ['Learning Analytics', 'Operational reports and insights across learner activity.'],
                    ['Teacher Analytics', 'Tools for understanding class and student performance.'],
                    ['Multi-Tenant SaaS', 'A shared platform with customer-first data ownership and isolation.'],
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
