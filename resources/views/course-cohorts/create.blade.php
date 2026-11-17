@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_create'))
@section('page_title', __('lf.LF_course_cohort_common_create'))

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="$cohortIndexUrl" :label="__('lf.LF_course_cohort_common_back_to_cohorts')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
<div class="lf-admin-ui lf-admin-ui--list ui-cohort">
    <x-ui-list-head :title="__('lf.LF_course_cohort_common_create')" :text="__($cohortAdmin ? 'lf.LF_course_cohort_create_description' : 'lf.LF_course_cohort_create_description_teacher')" />

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @include('course-cohorts.partials.page-tabs')

    <form class="mk-section course-cohort-create" method="POST" action="{{ route($routePrefix.'.store') }}"
          x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf

        @include('course-cohorts.partials.create-form')
    </form>
</div>
@endsection
