@extends('layouts.backend')

@section('title', __('lf.LF_course_template_activity_common_create'))
@section('page_title', __('lf.LF_course_template_activity_common_create'))

@section('content')
    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-card admin-form-card">
        <p>
            {{ __('lf.LF_course_template_activity_common_location') }}:
            <strong>
                {{ $template->title }} → {{ $section->title }} → {{ $lesson->title }}
            </strong>
        </p>

        <form method="POST"
              action="{{ route(
                  $routePrefix.'.store',
                  [$template->id, $section->id, $lesson->id]
              ) }}">
            @csrf

            @include('course-template-activities.partials.form')

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_course_template_activity_common_create') }}
                </button>
                <a href="{{ route($templateRoutePrefix.'.edit', $template->id) }}#course-template-lesson-{{ $lesson->id }}-activities">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
            </div>
        </form>
    </div>
@endsection
