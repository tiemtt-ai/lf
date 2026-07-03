@extends('layouts.backend')

@section('title', __('lf.LF_course_product_common_create'))
@section('page_title', __('lf.LF_course_product_common_create'))

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

            @include('course-products.partials.form')

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_course_product_common_create') }}
                </button>
                <a href="{{ route($routePrefix.'.index') }}">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
            </div>
        </form>
    </div>
@endsection
