@extends('layouts.backend')

@section('title', __('lf.LF_course_template_activity_common_edit'))
@section('page_title', __('lf.LF_course_template_activity_common_edit'))

@section('content')
    @php
        $activityRouteParameters = $section
            ? [$template->id, $section->id, $lesson->id, $activity->id]
            : [$template->id, $lesson->id, $activity->id];
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

    <section class="course-template-tab-panel course-template-activity-form-page">
    <div class="admin-card admin-form-card admin-form-surface course-template-activity-form-card">
        <div class="course-template-activity-context" role="note">
            <span>{{ __('lf.LF_course_template_activity_common_location') }}</span>
            <strong>
                {{ $template->title }} →
                @if ($section)
                    {{ $section->title }} →
                @endif
                {{ $lesson->title }}
            </strong>
        </div>

        <form method="POST"
              class="admin-form-standard"
              enctype="multipart/form-data"
              action="{{ route(
                  $routePrefix.'.update',
                  $activityRouteParameters
              ) }}">
            @csrf
            @method('PUT')

            @include('course-template-activities.partials.form')

            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <a href="{{ route($templateRoutePrefix.'.edit', $template->id) }}?tab=structure#course-template-lesson-{{ $lesson->id }}-activities" class="btn btn-secondary">
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
