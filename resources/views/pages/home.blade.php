@extends('layouts.public')

@section('title', 'LF')

@section('content')
    <div class="container py-5 text-center">

        <h1 class="mb-3">
            LF
        </h1>

        <p class="lead">
            AI-Native Learning Management Platform
        </p>

        <p>
            LF helps schools, training centers, teachers and organizations
            create modern learning experiences powered by AI.
        </p>

        <a href="{{ route('customer.register') }}" class="btn btn-primary">
            Register Customer
        </a>

    </div>
@endsection