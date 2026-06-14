@extends('layouts.tenant')

@section('title', $title.' | '.($tenant?->name ?? 'LearnForge'))

@section('content')
    <header class="student-page-heading tenant-page-heading">
        <p class="student-eyebrow">{{ __('lf.LF_student_title_student_personalized_experience') }}</p>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
    </header>

    <div class="tenant-feature-grid">
        @foreach ($items as $item)
            <article class="student-card student-section">
                <h2 class="student-section-title">{{ $item }}</h2>
                <p class="student-section-copy">{{ __('lf.LF_student_message_student_protected_area') }}</p>
            </article>
        @endforeach
    </div>
@endsection
