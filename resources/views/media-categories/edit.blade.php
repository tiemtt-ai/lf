@extends('layouts.backend')

@section('title', __('lf.LF_media_category_common_edit'))
@section('page_title', __('lf.LF_media_category_common_edit'))

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
    {{-- The save result shows as the UI Kit toast, top right under the top bar. --}}
    <div class="lf-admin-ui ui-page-toasts"><x-ui-list-toasts /></div>

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
        <form class="admin-form-standard" method="POST" action="{{ route('admin.media-categories.update', $category->id) }}">
            @csrf
            @method('PUT')

            @include('media-categories.partials.form')

            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <a href="{{ route('admin.media-categories.index') }}" class="btn btn-secondary">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_save_changes') }}</button>
                </div>
            </footer>
        </form>
    </div>
@endsection
