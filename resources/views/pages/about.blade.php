@extends('layouts.public')

@section('title', 'About LearnForge')

@section('content')
    <header class="public-page-heading">
        <div class="public-container">
            <span class="public-eyebrow">About</span>
            <h1>Learning infrastructure built for long-term growth.</h1>
            <p>LearnForge is an AI-native, multi-tenant learning platform designed for schools, training centers and organizations.</p>
        </div>
    </header>

    <section class="public-section">
        <div class="public-container public-content">
            <p>
                LearnForge brings course management, assessments, live learning, analytics and AI-powered
                experiences into a single maintainable platform.
            </p>
            <p>
                Every business record belongs to a customer. Tenant isolation and customer ownership remain
                central to how the platform is designed and operated.
            </p>
            <p>
                The product follows a simple, monolithic and Blade-first architecture so teams can ship useful
                learning capabilities without unnecessary complexity.
            </p>
        </div>
    </section>
@endsection
