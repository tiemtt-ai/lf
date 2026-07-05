@extends('layouts.backend')

@section('title', __('lf.LF_media_category_common_create'))
@section('page_title', __('lf.LF_media_category_common_create'))

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
        <form method="POST" action="{{ route('admin.media-categories.store') }}">
            @csrf

            @include('media-categories.partials.form')

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_media_category_common_create') }}
                </button>
                <a href="{{ route('admin.media-categories.index') }}">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
            </div>
        </form>
    </div>
@endsection
