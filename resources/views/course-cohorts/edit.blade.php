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

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.show', $cohort->id) }}">
            {{ __('lf.LF_course_cohort_common_back_to_detail') }}
        </a>
    </div>

    <div class="admin-card admin-form-card">
        <form method="POST" action="{{ route($routePrefix.'.update', $cohort->id) }}">
            @csrf
            @method('PUT')

            @include('course-cohorts.partials.form', [
                'cohort' => $cohort,
                'submitLabel' => __('lf.LF_common_button_save_changes'),
            ])
        </form>
    </div>
@endsection
