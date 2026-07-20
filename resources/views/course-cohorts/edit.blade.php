@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_edit'))
@section('page_title', __('lf.LF_course_cohort_common_edit'))

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

    <nav class="admin-form-actions" aria-label="{{ __('lf.LF_course_cohort_common_tabs') }}">
        <span class="btn btn-primary" aria-current="page">{{ __('lf.LF_course_cohort_tab_overview') }}</span>
        <a class="btn btn-secondary" href="{{ route('admin.course-cohort-students.index', ['cohort_id' => $cohort->id]) }}">
            {{ __('lf.LF_course_cohort_tab_students') }}
        </a>
    </nav>

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.show', $cohort->id) }}">
            {{ __('lf.LF_course_cohort_common_back_to_detail') }}
        </a>
    </div>

    <div class="admin-card admin-form-card admin-form-surface">
        <form method="POST" action="{{ route($routePrefix.'.update', $cohort->id) }}"
              class="admin-form-standard" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf
            @method('PUT')

            @include('course-cohorts.partials.form', [
                'cohort' => $cohort,
                'submitLabel' => __('lf.LF_common_button_save_changes'),
            ])
        </form>
    </div>
@endsection
