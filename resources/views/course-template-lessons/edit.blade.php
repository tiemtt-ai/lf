@extends('layouts.backend')

@section('title', __('lf.LF_course_template_lesson_common_edit'))
@section('page_title', __('lf.LF_course_template_lesson_common_edit'))

@section('content')
    @php
        $lessonRouteParameters = $section
            ? [$template->id, $section->id, $lesson->id]
            : [$template->id, $lesson->id];
        $lessonAnchor = $section
            ? '?tab=structure#course-template-section-'.$section->id.'-lessons'
            : '?tab=structure#course-template-direct-lessons';
    @endphp

    @if (session('success'))
        <div class="admin-alert admin-alert-success admin-form-card">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="course-template-tab-panel course-template-lesson-form-page">
    <div class="admin-card admin-form-card course-template-lesson-form-card">
        <p class="course-template-lesson-context">
            {{ __('lf.LF_course_template_lesson_common_location') }}:
            <strong>
                {{ $template->title }} →
                {{ $section?->title
                    ?? __('lf.LF_course_template_lesson_common_direct_location') }}
            </strong>
        </p>

        <form method="POST"
              action="{{ route(
                  $routePrefix.'.update',
                  $lessonRouteParameters
              ) }}"
              enctype="multipart/form-data">
            @csrf
            @method('PUT')

            @include('course-template-lessons.partials.form')

            <footer class="admin-form-actions admin-form-actions--footer">
                <a href="{{ route($templateRoutePrefix.'.edit', $template->id) }}{{ $lessonAnchor }}" class="btn btn-secondary">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_save_changes') }}
                </button>
            </footer>
        </form>
    </div>
    </section>
@endsection
