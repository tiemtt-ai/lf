@extends('layouts.backend')

@section('title', __('lf.LF_course_category_common_create'))
@section('page_title', __('lf.LF_course_category_common_create'))

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
        <form class="admin-form-standard" method="POST" action="{{ route($routePrefix.'.store') }}" enctype="multipart/form-data">
            @csrf

            @include('course-categories.partials.form')

            <footer class="admin-form-footer">
                <div class="admin-form-footer-danger"></div>
                <div class="admin-form-footer-primary">
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_course_category_common_create') }}</button>
                    <a href="{{ route($routePrefix.'.index') }}" class="admin-form-cancel">{{ __('lf.LF_common_button_cancel') }}</a>
                </div>
            </footer>
        </form>
    </div>
@endsection
