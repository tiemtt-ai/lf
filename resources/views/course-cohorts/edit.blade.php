@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_edit'))
@section('page_title', __('lf.LF_course_cohort_common_edit'))

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="route($routePrefix.'.show', $cohort->id)" :label="__('lf.LF_course_cohort_common_back_to_detail')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
<div class="lf-admin-ui lf-admin-ui--list ui-cohort">
    <x-ui-list-toasts />

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @include('course-cohorts.partials.identity')

    @include('course-cohorts.partials.page-tabs', [
        'navClass' => 'course-cohort-edit-tabs',
        'tabLink' => fn (array $tab): string => $tab['key'] === 'overview' ? route($routePrefix.'.edit', $cohort->id) : $tab['route'],
    ])

    <form method="POST" action="{{ route($routePrefix.'.update', $cohort->id) }}" class="mk-section course-cohort-edit-overview"
          x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf
        @method('PUT')

        @include('course-cohorts.partials.form', [
            'cohort' => $cohort,
            'submitLabel' => __('lf.LF_common_button_save_changes'),
        ])
    </form>
</div>
@endsection
