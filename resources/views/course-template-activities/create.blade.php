@extends('layouts.backend')

@section('title', __('lf.LF_course_template_activity_common_create'))
@section('page_title', __('lf.LF_course_template_activity_common_create'))

@section('content')
    @php
        $activityRouteParameters = $section
            ? [$template->id, $section->id, $lesson->id]
            : [$template->id, $lesson->id];
    @endphp

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="course-template-tab-panel course-template-activity-form-page">
    <div class="admin-card admin-form-card course-template-activity-form-card">
        <p class="course-template-activity-context">
            {{ __('lf.LF_course_template_activity_common_location') }}:
            <strong>
                {{ $template->title }} →
                @if ($section)
                    {{ $section->title }} →
                @endif
                {{ $lesson->title }}
            </strong>
        </p>

        <form method="POST"
              enctype="multipart/form-data"
              action="{{ route(
                  $routePrefix.'.store',
                  $activityRouteParameters
              ) }}">
            @csrf

            @include('course-template-activities.partials.form')

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_course_template_activity_common_create') }}
                </button>
                <a href="{{ route($templateRoutePrefix.'.edit', $template->id) }}?tab=structure#course-template-lesson-{{ $lesson->id }}-activities">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
            </div>
        </form>
    </div>
    </section>
@endsection
