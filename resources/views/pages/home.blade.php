@extends('layouts.public')

@section('title', 'LF')

@section('content')
    <div class="container py-5 text-center">

        <h1 class="mb-3">
            Master Korean | API Test
        </h1>

        <p class="lead">
            API Test Management Platform
        </p>

        <p>
            Hỗ trợ IT Team trong qua trình test các API liên quan các hệ thống MK V2, Admin site, Question Bank, MK Jobs, MK Live, MK B2B, Mobile App, Chatbot.
        </p>

        <a href="{{ route('customer.register') }}" class="btn btn-primary">
            Register Tenant
        </a>

    </div>
@endsection