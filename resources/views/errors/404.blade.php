@extends('layouts.public')

@section('title', '404 - Page Not Found')

@section('content')
    <div class="container py-5 text-center">
        <h1 class="display-1 fw-bold">404</h1>

        <h3 class="mb-3">
            Page Not Found
        </h3>

        <p class="text-muted mb-4">
            The page you are looking for does not exist.
        </p>

        <a href="{{ config('app.url') }}" class="btn btn-primary">
            Back to Home
        </a>
    </div>
@endsection