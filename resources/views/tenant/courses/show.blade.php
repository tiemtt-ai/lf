@extends('layouts.tenant')

@section('course_menu', $course->course_type !== \App\Support\CourseType::SELF_PACED ? 'classes' : 'courses')
@section('title', $course->title.' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))

{{-- Course detail in the standard B2B look: cover, four facts, the learner's state with its action, then the tabs
     "Danh sách bài học / Giới thiệu / Giáo viên". Lessons are titles only; learning happens in "Khóa học của tôi". --}}
@section('content')
@php
    $money = fn (string $amount) => \App\Services\TenantCourseCatalogService::money($amount, $course->currency);
    $isClass = $course->course_type !== \App\Support\CourseType::SELF_PACED;
    $enrollment = $course->enrollment;
    $state = $enrollment?->state;
    $clock = fn (int $seconds): string => sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    $number = 0;
@endphp
    <a class="lf-learn-back" href="{{ route('tenant.courses.index', ['type' => $isClass ? 'class' : 'self_paced']) }}"><span aria-hidden="true">←</span> {{ __($isClass ? 'lf.LF_navigation_menu_public_classes' : 'lf.LF_course_title_public_courses') }}</a>

    <article class="student-card lf-course-detail" data-catalog-detail="{{ $course->id }}">
        <div class="lf-course-detail__cover">
            <img src="{{ asset('assets/student/default_course.png') }}" alt="">
        </div>
        <div class="lf-course-detail__body">
            <div class="sb2b-course-badges">
                @if ($course->category)<span class="sb2b-course-category">{{ $course->category }}</span>@endif
                @if ($course->level)<span class="sb2b-course-category">{{ $course->level }}</span>@endif
            </div>
            <h1>{{ $course->title }}</h1>

            <div class="lf-learn-stats lf-course-detail__facts">
                <div class="lf-learn-stat">
                    <span>{{ __('lf.LF_course_detail_teachers') }}</span>
                    <strong>{{ $course->teachers !== [] ? implode(', ', $course->teachers) : '—' }}</strong>
                </div>
                <div class="lf-learn-stat">
                    <span>{{ __('lf.LF_course_detail_type') }}</span>
                    <strong>{{ __('lf.LF_course_type_'.$course->course_type) }}</strong>
                </div>
                <div class="lf-learn-stat">
                    <span>{{ __('lf.LF_course_detail_access') }}</span>
                    @if ($isClass)
                        <strong>{{ __('lf.LF_course_detail_access_class') }}</strong>
                    @elseif ($course->access_duration_days)
                        <strong>{{ __('lf.LF_course_detail_access_days', ['days' => $course->access_duration_days]) }}</strong>
                        @if ($course->review_duration_days)
                            <small>+ {{ __('lf.LF_course_detail_review_days', ['days' => $course->review_duration_days]) }}</small>
                        @endif
                    @else
                        <strong>—</strong>
                    @endif
                </div>
                <div class="lf-learn-stat">
                    <span>{{ __('lf.LF_course_detail_duration') }}</span>
                    <strong>{{ $course->duration_seconds > 0 ? $clock($course->duration_seconds) : '—' }}</strong>
                    <small>{{ trans_choice('lf.LF_course_card_public_lessons', $course->lesson_total, ['count' => $course->lesson_total]) }}</small>
                </div>
            </div>

            <div class="lf-course-detail__actions">
                @if (! $enrollment)
                    <p class="lf-course-detail__price">
                        @if ($course->is_free)
                            <strong>{{ __('lf.LF_course_card_public_free') }}</strong>
                        @else
                            @if ($course->discounted)<s>{{ $money($course->price) }}</s>@endif
                            <strong>{{ $money($course->selling_price) }}</strong>
                        @endif
                    </p>
                @endif
                @guest
                    <a class="student-button" href="{{ route('login', ['experience' => 'student']) }}">{{ __('lf.LF_course_card_guest_login_register_purchase') }}</a>
                @else
                    @if ($state === 'expired')
                        <span class="lf-learn-badge is-muted">{{ __('lf.LF_course_card_status_expired') }}</span>
                        <button type="button" class="student-button" data-coming-soon aria-disabled="true">{{ __('lf.LF_my_learning_reregister') }}</button>
                    @elseif (in_array($state, ['pending', 'suspended'], true))
                        <span class="lf-learn-badge is-orange">{{ __('lf.LF_my_learning_status_'.$state) }}</span>
                    @elseif ($enrollment)
                        <span class="lf-learn-badge is-green">✓ {{ __('lf.LF_course_card_student_enrolled') }}</span>
                        <a class="student-button" href="{{ $course->learn_url }}">{{ __('lf.LF_course_card_student_enter') }} <span aria-hidden="true">→</span></a>
                    @elseif ($studentMode && $course->registration_open)
                        <button type="button" class="student-button" data-coming-soon aria-disabled="true">{{ __('lf.LF_course_card_student_register') }}</button>
                    @endif
                    @if ($studentMode)
                        @include('tenant.partials.favorite-button', ['productId' => $course->id, 'favorite' => $course->favorite, 'labeled' => true])
                    @endif
                @endguest
            </div>
            @if (! $enrollment)
                <p class="tenant-access-note">{{ $course->registration_open ? __('lf.LF_course_card_public_register_soon') : __('lf.LF_course_card_public_registration_closed') }}</p>
            @endif
        </div>
    </article>

    <section class="student-card lf-course-tabs" x-data="{ tab: 'lessons' }">
        <div class="lf-course-tabs__nav" role="tablist" aria-label="{{ __('lf.LF_course_title_public_detail') }}">
            @foreach (['lessons' => __('lf.LF_course_detail_tab_lessons'), 'intro' => __('lf.LF_course_detail_tab_intro'), 'teachers' => __('lf.LF_course_detail_teachers')] as $key => $label)
                <button type="button" role="tab" id="course-tab-{{ $key }}" aria-controls="course-panel-{{ $key }}"
                        @class(['is-active' => $key === 'lessons']) x-bind:class="{ 'is-active': tab === '{{ $key }}' }"
                        x-bind:aria-selected="(tab === '{{ $key }}').toString()" aria-selected="{{ $key === 'lessons' ? 'true' : 'false' }}"
                        x-on:click="tab = '{{ $key }}'">{{ $label }}</button>
            @endforeach
        </div>

        <div id="course-panel-lessons" role="tabpanel" aria-labelledby="course-tab-lessons" x-show="tab === 'lessons'">
            <header class="lf-learn-outline__head">
                <h2>{{ __('lf.LF_course_detail_outline') }}</h2>
                <p>{{ __('lf.LF_course_detail_outline_help') }}</p>
            </header>
            @if ($course->outline->isEmpty())
                <p class="student-section-copy">{{ __('lf.LF_course_detail_outline_empty') }}</p>
            @endif
            @foreach ($course->outline as $section)
                <div class="lf-learn-section">
                    @if ($section->title || $course->outline->count() > 1)
                        <h3 class="lf-learn-section__title">{{ $section->title ?? __('lf.LF_course_detail_other_section') }}</h3>
                    @endif
                    <ol class="lf-learn-lessons">
                        @foreach ($section->lessons as $lesson)
                            <li class="lf-learn-lesson lf-course-lesson">
                                <span class="lf-learn-lesson__no"><small>{{ __('lf.LF_my_learning_course_lesson_no') }}</small>{{ sprintf('%02d', ++$number) }}</span>
                                <div class="lf-learn-lesson__body">
                                    <strong>{{ $lesson->title }}</strong>
                                    @if ($lesson->duration_seconds)
                                        <span class="lf-learn-lesson__meta">{{ $clock((int) $lesson->duration_seconds) }}</span>
                                    @endif
                                </div>
                                <span class="lf-learn-lesson__state">
                                    @if ($enrollment && $state === 'learning')
                                        <a class="lf-course-lesson__enter" href="{{ $course->learn_url }}">{{ __('lf.LF_course_card_student_enter') }}</a>
                                    @elseif (! $enrollment)
                                        <span class="lf-learn-badge is-muted">{{ __('lf.LF_course_detail_lesson_register_first') }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endforeach
        </div>

        <div id="course-panel-intro" role="tabpanel" aria-labelledby="course-tab-intro" x-show="tab === 'intro'" x-cloak>
            @if ($course->summary)
                <p class="student-section-copy">{{ $course->summary }}</p>
            @endif
            @if ($course->description)
                <p class="student-section-copy tenant-course-description">{{ $course->description }}</p>
            @endif
            @if (! $course->summary && ! $course->description)
                <p class="student-section-copy">{{ __('lf.LF_course_detail_intro_empty') }}</p>
            @endif
        </div>

        <div id="course-panel-teachers" role="tabpanel" aria-labelledby="course-tab-teachers" x-show="tab === 'teachers'" x-cloak>
            @forelse ($course->teachers as $teacher)
                <div class="sb2b-live-card__teacher lf-course-teacher">
                    <span class="sb2b-live-card__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($teacher), 0, 1)) }}</span>
                    <span>{{ $teacher }}</span>
                </div>
            @empty
                <p class="student-section-copy">{{ __('lf.LF_course_detail_teachers_empty') }}</p>
            @endforelse
        </div>
    </section>
@endsection
