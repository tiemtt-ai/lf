@extends('layouts.public')

@section('title', 'LearnForge Features')

@section('content')
    <header class="public-page-heading">
        <div class="public-container">
            <span class="public-eyebrow">Features</span>
            <h1>Core capabilities for modern learning operations.</h1>
            <p>Manage the full learning lifecycle while keeping tenant data isolated and ready for analytics and AI.</p>
        </div>
    </header>

    <section class="public-section">
        <div class="public-container">
            <div class="public-card-grid">
                @foreach ([
                    ['Course Management', 'Organize courses, lessons and learning materials.'],
                    ['Assessment Engine', 'Create and manage exams, quizzes and evaluations.'],
                    ['AI Learning Assistant', 'Support learners with AI-native guidance and insights.'],
                    ['Media Learning', 'Deliver video, document and media-based learning.'],
                    ['Role Portals', 'Focused workspaces for admins, teachers and students.'],
                    ['Learning Analytics', 'Track activity, progress and learning outcomes.'],
                    ['Live Classes', 'Coordinate synchronous learning experiences.'],
                    ['Multi-Tenant SaaS', 'Isolate customer data on shared infrastructure.'],
                    ['Reports', 'Turn learning data into operational information.'],
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
