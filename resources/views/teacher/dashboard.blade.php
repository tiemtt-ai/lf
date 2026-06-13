@extends('layouts.backend')

@section('title', 'Teacher Dashboard')
@section('page_title', 'Dashboard')

@section('content')
    <p class="admin-dashboard-welcome">
        Welcome back, {{ auth()->user()->name }}.
    </p>

    <div class="teacher-dashboard-grid">
        @foreach ([
            ['label' => 'My Courses', 'value' => '--', 'copy' => 'Course workspace will appear here.'],
            ['label' => 'Students', 'value' => '--', 'copy' => 'Learner activity and support overview.'],
            ['label' => 'Pending Gradings', 'value' => '--', 'copy' => 'Assessments waiting for review.'],
            ['label' => 'Upcoming Live Classes', 'value' => '--', 'copy' => 'Your next scheduled teaching sessions.'],
            ['label' => 'Reports', 'value' => '--', 'copy' => 'Learning progress and class insights.'],
            ['label' => 'AI Assistant', 'value' => 'AI', 'copy' => 'Prepare lessons and support learners faster.'],
        ] as $card)
            <section class="admin-card teacher-dashboard-card">
                <p>{{ $card['label'] }}</p>
                <strong>{{ $card['value'] }}</strong>
                <span>{{ $card['copy'] }}</span>
            </section>
        @endforeach
    </div>
@endsection
