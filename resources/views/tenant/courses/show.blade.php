@extends('layouts.tenant')

@section('title', $course->title.' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))

@section('content')
@php
    $money = fn (string $amount) => \App\Services\TenantCourseCatalogService::money($amount, $course->currency);
    $isClass = $course->course_type !== \App\Support\CourseType::SELF_PACED;
    $access = match (true) {
        $isClass => __('lf.LF_course_detail_access_class'),
        $course->access_duration_days && $course->review_duration_days => __('lf.LF_course_detail_access_review_days', ['days' => $course->access_duration_days, 'review' => $course->review_duration_days]),
        (bool) $course->access_duration_days => __('lf.LF_course_detail_access_days', ['days' => $course->access_duration_days]),
        default => null,
    };
@endphp
    <article class="student-card student-section tenant-course-detail" data-catalog-detail="{{ $course->id }}">
        <p class="student-eyebrow">{{ __('lf.LF_course_title_public_detail') }}</p>
        <h1>{{ $course->title }}</h1>
        <p class="student-course-meta">
            {{ collect([$course->category, $course->level])->filter()->implode(' · ') }}
            @if ($course->category || $course->level) · @endif
            @if ($course->is_free)
                {{ __('lf.LF_course_card_public_free') }}
            @else
                @if ($course->discounted)<s>{{ $money($course->price) }}</s>@endif
                <strong>{{ $money($course->selling_price) }}</strong>
            @endif
        </p>
        @if ($course->summary)
            <p class="student-section-copy">{{ $course->summary }}</p>
        @endif
        @if ($course->description)
            <p class="student-section-copy tenant-course-description">{{ $course->description }}</p>
        @endif

        <div class="tenant-detail-grid">
            <div><strong>{{ __('lf.LF_course_detail_type') }}</strong><span>{{ __('lf.LF_course_type_'.$course->course_type) }}</span></div>
            <div><strong>{{ __('lf.LF_course_label_public_curriculum') }}</strong><span>{{ trans_choice('lf.LF_course_card_public_lessons', $course->lesson_total, ['count' => $course->lesson_total]) }}</span></div>
            @if ($course->teachers !== [])
                <div><strong>{{ __('lf.LF_course_detail_teachers') }}</strong><span>{{ implode(', ', $course->teachers) }}</span></div>
            @endif
            @if ($access)
                <div><strong>{{ __('lf.LF_course_detail_access') }}</strong><span>{{ $access }}</span></div>
            @endif
        </div>

        <div class="tenant-course-actions">
            @guest
                <a class="student-button" href="{{ route('login', ['experience' => 'student']) }}">{{ __('lf.LF_course_card_guest_login_register_purchase') }}</a>
            @else
                @if ($course->enrollment)
                    <a class="student-button" href="{{ $course->learn_url }}">{{ __('lf.LF_course_card_student_enter') }}</a>
                @elseif ($studentMode && $course->registration_open)
                    <button type="button" class="student-button" data-coming-soon aria-disabled="true">{{ __('lf.LF_course_card_student_register') }}</button>
                @endif
            @endguest
        </div>
        @if (! $course->enrollment)
            <p class="tenant-access-note">{{ $course->registration_open ? __('lf.LF_course_card_public_register_soon') : __('lf.LF_course_card_public_registration_closed') }}</p>
        @endif
    </article>

    <section class="student-card student-section tenant-course-outline" aria-labelledby="catalog-outline-title">
        <h2 id="catalog-outline-title" class="student-section-title">{{ __('lf.LF_course_detail_outline') }}</h2>
        @if ($course->outline->isEmpty())
            <p class="student-section-copy">{{ __('lf.LF_course_detail_outline_empty') }}</p>
        @else
            @foreach ($course->outline as $section)
                @if ($section->title || $course->outline->count() > 1)
                    <h3>{{ $section->title ?? __('lf.LF_course_detail_other_section') }}</h3>
                @endif
                <ol>
                    @foreach ($section->lessons as $lessonTitle)
                        <li>{{ $lessonTitle }}</li>
                    @endforeach
                </ol>
            @endforeach
        @endif
    </section>
@endsection
