@extends('layouts.backend')

@section('title', __('lf.LF_course_template_teacher_common_create'))
@section('page_title', __('lf.LF_course_template_teacher_common_create'))

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

    <section class="course-template-tab-panel course-template-teacher-form-page">
    <div class="admin-card admin-form-card course-template-teacher-form-card">
        <p class="course-template-teacher-context">
            {{ __('lf.LF_course_template_teacher_common_template') }}:
            <strong>{{ $template->title }}</strong>
        </p>

        <form method="POST"
              action="{{ route($routePrefix.'.store', $template->id) }}">
            @csrf

            @include('course-template-teachers.partials.form')

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_course_template_teacher_common_create') }}
                </button>
                <a href="{{ route(
                    $templateRoutePrefix.'.edit',
                    $template->id
                ) }}?tab=teachers#course-template-teachers">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
            </div>
        </form>
    </div>
    </section>
@endsection
