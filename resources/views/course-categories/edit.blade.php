@extends('layouts.backend')

@section('title', __('lf.LF_course_category_common_edit'))
@section('page_title', __('lf.LF_course_category_common_edit'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
<div class="lf-admin-ui">
    @if (session('success'))
        <div class="ui-caption" role="status">
            {{ session('success') }}
        </div>
    @endif

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
        <form class="ui-form" method="POST" action="{{ route($routePrefix.'.update', $category->id) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            @include('course-categories.partials.form')

            <footer class="ui-action-row">
                    <a href="{{ route($routePrefix.'.index') }}" class="ui-button">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button type="submit" class="ui-button ui-button--primary">{{ __('lf.LF_common_button_save_changes') }}</button>
            </footer>
        </form>
    </div>
</div>
@endsection
