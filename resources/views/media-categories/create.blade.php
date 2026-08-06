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

    <div class="admin-card admin-form-card admin-form-surface">
        <form class="admin-form-standard" method="POST" action="{{ route('admin.media-categories.store') }}">
            @csrf

            @include('media-categories.partials.form')

            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <a href="{{ route('admin.media-categories.index') }}" class="btn btn-secondary">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_media_category_common_create') }}</button>
                </div>
            </footer>
        </form>
    </div>
@endsection
