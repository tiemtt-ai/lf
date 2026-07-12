@extends('layouts.backend')

@section('title', __('lf.LF_course_template_section_common_create'))
@section('page_title', __('lf.LF_course_template_section_common_create'))

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

    <section class="course-template-tab-panel course-template-section-form-page">
    <div class="admin-card admin-form-card course-template-section-form-card">
        <p class="course-template-section-context">
            {{ __('lf.LF_course_template_section_common_template') }}:
            <strong>{{ $template->title }}</strong>
        </p>

        <form method="POST"
              action="{{ route($routePrefix.'.store', $template->id) }}">
            @csrf

            @include('course-template-sections.partials.form')

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_course_template_section_common_create') }}
                </button>
                <a href="{{ route($templateRoutePrefix.'.edit', $template->id) }}?tab=structure#course-template-sections">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
            </div>
        </form>
    </div>
    </section>
@endsection
