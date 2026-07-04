@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_create'))
@section('page_title', __('lf.LF_course_cohort_common_create'))

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
        <form method="POST" action="{{ route($routePrefix.'.store') }}">
            @csrf

            @include('course-cohorts.partials.form', [
                'cohort' => null,
                'submitLabel' => __('lf.LF_course_cohort_common_create'),
            ])
        </form>
    </div>
@endsection
