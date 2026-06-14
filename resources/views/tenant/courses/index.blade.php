@extends('layouts.tenant')

@section('title', 'Courses | '.($tenant?->name ?? 'LearnForge'))

@section('content')
    <header class="student-page-heading tenant-page-heading">
        <p class="student-eyebrow">Course Catalog</p>
        <h1>Courses</h1>
        <p>Compare course details, teachers, curriculum and pricing before you register.</p>
    </header>

    <div class="tenant-course-grid">
        @foreach ($courses as $course)
            @include('tenant.partials.course-card', compact('course'))
        @endforeach
    </div>
@endsection
