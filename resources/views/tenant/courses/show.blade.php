@extends('layouts.tenant')

@section('title', $course['title'].' | '.($tenant?->name ?? 'LF'))

@section('content')
    <article class="student-card student-section tenant-course-detail">
        <p class="student-eyebrow">{{ __('lf.LF_course_title_public_detail') }}</p>
        <h1>{{ $course['title'] }}</h1>
        <p class="student-course-meta">{{ __('lf.LF_course_card_public_teacher', ['teacher' => $course['teacher']]) }} · {{ $course['price'] }}</p>
        <p class="student-section-copy">{{ $course['summary'] }}</p>

        <div class="tenant-detail-grid">
            <div><strong>{{ __('lf.LF_course_label_public_curriculum') }}</strong><span>{{ __('lf.LF_course_message_public_curriculum') }}</span></div>
            <div><strong>{{ __('lf.LF_course_label_public_assessments') }}</strong><span>{{ __('lf.LF_course_message_public_assessments') }}</span></div>
            <div><strong>{{ __('lf.LF_course_label_public_access') }}</strong><span>{{ __('lf.LF_course_message_public_access') }}</span></div>
        </div>

        <div class="tenant-course-actions">
            @guest
                <a class="student-button" href="{{ route('login') }}">{{ __('lf.LF_course_card_guest_login_register_purchase') }}</a>
            @else
                @if (auth()->user()->role === 'student' && $course['enrolled'])
                    <a class="student-button" href="#">{{ __('lf.LF_course_button_student_start_continue') }}</a>
                    <a class="student-button is-outline" href="#">{{ __('lf.LF_course_card_student_view_progress') }}</a>
                    <a class="student-text-link" href="{{ route('tenant.assessments') }}">{{ __('lf.LF_course_card_student_take_assessments') }}</a>
                @elseif (auth()->user()->role === 'student')
                    <a class="student-button" href="#">{{ __('lf.LF_course_card_student_register') }}</a>
                    <a class="student-button is-outline" href="#">{{ __('lf.LF_course_card_student_purchase') }}</a>
                    <button class="student-text-link tenant-link-button" type="button">{{ __('lf.LF_course_card_student_add_to_favorites') }}</button>
                @endif
            @endguest
        </div>

        <p class="tenant-access-note">{{ __('lf.LF_course_message_student_favorite_enrollment') }}</p>
    </article>
@endsection
