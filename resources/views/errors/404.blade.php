@extends('layouts.public')

@section('title', '404 - '.__('lf.LF_common_title_public_page_not_found'))

@section('content')
    <div class="container py-5 text-center">
        <h1 class="display-1 fw-bold">404</h1>

        <h3 class="mb-3">
            {{ __('lf.LF_common_title_public_page_not_found') }}
        </h3>

        <p class="text-muted mb-4">
            {{ __('lf.LF_common_message_public_page_not_found') }}
        </p>

        <a href="{{ config('app.url') }}" class="btn btn-primary">
            {{ __('lf.LF_common_button_public_back_home') }}
        </a>
    </div>
@endsection
