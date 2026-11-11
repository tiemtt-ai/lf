{{-- A catalogue card in the standard B2B look (sb2b-course-card): a visible Course Product read from its published
     Version (TenantCourseCatalogService). The footer follows the learner's own Enrollment: enter, waiting, expired,
     or register. --}}
@php
    $money = fn (string $amount) => \App\Services\TenantCourseCatalogService::money($amount, $course->currency);
    $detailUrl = route('tenant.courses.show', $course->slug);
    $enrollment = $course->enrollment;
    $state = $enrollment?->state;
    $isClass = $course->course_type !== \App\Support\CourseType::SELF_PACED;
    [$statusClass, $statusLabel] = match ($state) {
        'learning', 'completed' => ['sb2b-course-enrolled', __('lf.LF_course_card_student_enrolled')],
        'expired' => ['sb2b-course-expired', __('lf.LF_course_card_status_expired')],
        'pending', 'suspended' => ['sb2b-course-expired', __('lf.LF_my_learning_status_'.$state)],
        default => [null, null],
    };
@endphp
<article class="card hover-shadow sb2b-course-card student-course-card lf-catalog-card" data-catalog-course="{{ $course->id }}">
    <a href="{{ $detailUrl }}" class="area-img text-center" tabindex="-1" aria-hidden="true">
        <img class="w-100" src="{{ asset('assets/student/default_course.png') }}" alt="" loading="lazy">
    </a>
    <div class="card-body">
        <div>
            <div class="sb2b-course-badges">
                @if ($course->level || $course->category)
                    <span class="sb2b-course-category">{{ $course->level ?: $course->category }}</span>
                @endif
                @if ($statusLabel)
                    <span class="{{ $statusClass }}">{{ $statusLabel }}</span>
                @endif
                @auth
                    @if ($studentMode)
                        @include('tenant.partials.favorite-button', ['productId' => $course->id, 'favorite' => $course->favorite])
                    @endif
                @endauth
            </div>
            <a href="{{ $detailUrl }}"><h5 class="card-title line-2">{{ $course->title }}</h5></a>
            @if ($course->teachers !== [])
                <div class="sb2b-live-card__teacher">
                    <span class="sb2b-live-card__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($course->teachers[0]), 0, 1)) }}</span>
                    <span>{{ implode(', ', $course->teachers) }}</span>
                </div>
            @endif
            <div class="sb2b-course-facts">
                <div class="sb2b-course-fact">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="m10 8 5 4-5 4z"/></svg>
                    <span>{{ __('lf.LF_my_learning_lessons') }}</span><strong>{{ $course->lesson_total }}</strong>
                </div>
                <div class="sb2b-course-fact">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    @if (! $isClass && $course->access_duration_days)
                        <span>{{ __('lf.LF_my_learning_study_days') }}</span><strong>{{ $course->access_duration_days }}</strong>
                    @else
                        <span>{{ __('lf.LF_course_detail_type') }}</span><strong>{{ __('lf.LF_course_type_'.$course->course_type) }}</strong>
                    @endif
                </div>
            </div>
        </div>
        <div class="card--state">
            <hr>
            <div class="student-course-card__actions">
                @if ($enrollment)
                    <p class="student-course-card__progress-text">{{ __('lf.LF_my_learning_progress') }}: <strong>{{ $enrollment->progress }}%</strong></p>
                @else
                    <p class="student-course-card__progress-text lf-catalog-card__price">
                        @if ($course->is_free)
                            <strong>{{ __('lf.LF_course_card_public_free') }}</strong>
                        @else
                            @if ($course->discounted)<s>{{ $money($course->price) }}</s>@endif
                            <strong>{{ $money($course->selling_price) }}</strong>
                        @endif
                    </p>
                @endif

                @guest
                    <a class="sb2b-course-view sb2b-course-register" href="{{ route('login', ['experience' => 'student']) }}">{{ __('lf.LF_course_card_guest_login_register_purchase') }}</a>
                @else
                    @if ($state === 'expired')
                        <button type="button" class="sb2b-course-view sb2b-course-reregister" data-coming-soon aria-disabled="true">{{ __('lf.LF_my_learning_reregister') }}</button>
                    @elseif (in_array($state, ['pending', 'suspended'], true))
                        <span class="sb2b-course-view sb2b-course-pending">{{ $statusLabel }}</span>
                    @elseif ($enrollment)
                        <a class="sb2b-course-view sb2b-learning-entry" href="{{ $course->learn_url }}">{{ __('lf.LF_course_card_student_enter') }} <span aria-hidden="true">→</span></a>
                    @elseif ($studentMode && $course->registration_open)
                        <button type="button" class="sb2b-course-view sb2b-course-register" data-coming-soon aria-disabled="true">{{ __('lf.LF_course_card_student_register') }}</button>
                    @elseif ($studentMode)
                        <span class="sb2b-course-view sb2b-course-pending">{{ __('lf.LF_course_card_public_registration_closed') }}</span>
                    @else
                        <a class="sb2b-course-view sb2b-learning-entry" href="{{ $detailUrl }}">{{ __('lf.LF_course_card_public_view_detail') }} <span aria-hidden="true">→</span></a>
                    @endif
                @endguest
            </div>
        </div>
    </div>
</article>
