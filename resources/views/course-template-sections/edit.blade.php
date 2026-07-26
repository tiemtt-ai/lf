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

    <section class="course-template-tab-panel course-template-section-form-page">
    <div class="admin-card admin-form-card course-template-section-form-card">
        <div class="course-template-section-context" role="note">
            <span>{{ __('lf.LF_course_template_section_common_location') }}</span>
            <strong>{{ $template->title }} → {{ $section->title }}</strong>
        </div>

        <form class="admin-form-standard" method="POST"
              action="{{ route(
                  $routePrefix.'.update',
                  [$template->id, $section->id]
              ) }}">
            @csrf
            @method('PUT')

            @include('course-template-sections.partials.form')

            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <a href="{{ route($templateRoutePrefix.'.edit', $template->id) }}?tab=structure#course-template-sections" class="btn btn-secondary">
                        {{ __('lf.LF_common_button_cancel') }}
                    </a>
                    <button type="submit" class="btn btn-primary">
                        {{ __('lf.LF_common_button_save_changes') }}
                    </button>
                </div>
            </footer>
        </form>
    </div>
    </section>
@endsection
