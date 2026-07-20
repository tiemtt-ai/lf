@extends('layouts.backend')

@section('title', __('lf.LF_course_template_teacher_common_edit_title'))
@section('page_title', __('lf.LF_course_template_teacher_common_edit_title'))

@section('content')
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

    <section class="course-template-tab-panel course-template-teacher-form-page">
    <div class="admin-card admin-form-card course-template-teacher-form-card">
        <p class="course-template-teacher-context">
            {{ __('lf.LF_course_template_teacher_common_template') }}:
            <strong>{{ $template->title }}</strong>
        </p>

        <form method="POST"
              action="{{ route(
                  $routePrefix.'.update',
                  [$template->id, $assignment->id]
              ) }}">
            @csrf
            @method('PUT')

            @include('course-template-teachers.partials.form')

            <footer class="admin-form-actions admin-form-actions--footer">
                <a href="{{ route(
                    $templateRoutePrefix.'.edit',
                    $template->id
                ) }}?tab=teachers#course-template-teachers" class="btn btn-secondary">
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
