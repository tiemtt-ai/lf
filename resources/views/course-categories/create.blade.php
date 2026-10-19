@extends('layouts.backend')

@section('title', __('lf.LF_course_category_common_create'))
@section('page_title', __('lf.LF_course_category_common_create'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
<div class="lf-admin-ui">
    @if ($errors->any())
        <div class="ui-field__error" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ui-form-surface">
        <form class="ui-form" method="POST" action="{{ route($routePrefix.'.store') }}" enctype="multipart/form-data">
            @csrf

            @include('course-categories.partials.form')

            <footer class="ui-action-row">
                    <a href="{{ route($routePrefix.'.index') }}" class="ui-button">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button type="submit" class="ui-button ui-button--primary">{{ __('lf.LF_course_category_common_create') }}</button>
            </footer>
        </form>
    </div>
</div>
@endsection
