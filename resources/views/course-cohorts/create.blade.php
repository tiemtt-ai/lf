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

    <div class="admin-card admin-form-card admin-form-surface">
        <form class="admin-form-standard"
              method="POST"
              action="{{ route($routePrefix.'.store') }}"
              x-data="{ submitting: false }"
              x-on:submit="submitting = true">
            @csrf

            @include('course-cohorts.partials.create-form')
        </form>
    </div>
@endsection
