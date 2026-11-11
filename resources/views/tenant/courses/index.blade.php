@extends('layouts.tenant')

@section('title', __('lf.LF_course_title_public_courses').' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))

@section('content')
    <header class="student-page-heading tenant-page-heading">
        <p class="student-eyebrow">{{ __('lf.LF_course_title_public_catalog') }}</p>
        <h1>{{ __('lf.LF_course_title_public_courses') }}</h1>
        <p>{{ __('lf.LF_course_message_public_catalog_description') }}</p>
    </header>

    @if ($courses->isEmpty())
        <div class="student-card student-section" role="status"><p>{{ __('lf.LF_course_catalog_empty') }}</p></div>
    @else
        <div class="tenant-course-grid">
            @foreach ($courses as $course)
                @include('tenant.partials.course-card', compact('course'))
            @endforeach
        </div>
    @endif
@endsection
