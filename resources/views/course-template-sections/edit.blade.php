@extends('layouts.backend')

@section('title', __('lf.LF_course_template_section_common_edit'))
@section('page_title', __('lf.LF_course_template_section_common_edit'))

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

    <div class="admin-card admin-form-card">
        <p>
            {{ __('lf.LF_course_template_section_common_template') }}:
            <strong>{{ $template->title }}</strong>
        </p>

        <form method="POST"
              action="{{ route(
                  $routePrefix.'.update',
                  [$template->id, $section->id]
              ) }}">
            @csrf
            @method('PUT')

            @include('course-template-sections.partials.form')

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_save_changes') }}
                </button>
                <a href="{{ route($templateRoutePrefix.'.edit', $template->id) }}?tab=structure#course-template-sections">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
            </div>
        </form>
    </div>
@endsection
