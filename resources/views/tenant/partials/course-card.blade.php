{{-- A catalogue card: a visible Course Product read from its published Version (TenantCourseCatalogService). --}}
@php
    $money = fn (string $amount) => \App\Services\TenantCourseCatalogService::money($amount, $course->currency);
    $detailUrl = route('tenant.courses.show', $course->slug);
    $meta = collect([
        $course->teachers !== [] ? __('lf.LF_course_card_public_teacher', ['teacher' => implode(', ', $course->teachers)]) : null,
        __('lf.LF_course_type_'.$course->course_type),
        trans_choice('lf.LF_course_card_public_lessons', $course->lesson_total, ['count' => $course->lesson_total]),
    ])->filter()->implode(' · ');
@endphp
<article class="student-course-card tenant-course-card" data-catalog-course="{{ $course->id }}">
    <a class="student-course-cover" href="{{ $detailUrl }}" tabindex="-1" aria-hidden="true">
        <span>{{ mb_strtoupper(mb_substr(trim($course->title), 0, 1)) }}</span>
    </a>
    <div>
        <div class="student-course-heading">
            <h3 class="student-course-title"><a href="{{ $detailUrl }}">{{ $course->title }}</a></h3>
            <span class="student-badge">
                @if ($course->is_free)
                    {{ __('lf.LF_course_card_public_free') }}
                @else
                    @if ($course->discounted)<s>{{ $money($course->price) }}</s>@endif
                    {{ $money($course->selling_price) }}
                @endif
            </span>
        </div>
        <p class="student-course-meta">{{ $meta }}</p>
        @if ($course->summary)
            <p class="student-section-copy">{{ $course->summary }}</p>
        @endif
        @if ($course->enrollment)
            <p class="student-course-meta"><strong>{{ __('lf.LF_course_card_student_enrolled') }}</strong></p>
        @endif
    </div>
    <div class="tenant-course-actions">
        <a class="student-button is-outline is-small" href="{{ $detailUrl }}">{{ __('lf.LF_course_card_public_view_detail') }}</a>
        @guest
            <a class="student-button is-small" href="{{ route('login', ['experience' => 'student']) }}">{{ __('lf.LF_course_card_guest_login_register_purchase') }}</a>
        @else
            @if ($course->enrollment)
                <a class="student-button is-small" href="{{ $course->learn_url }}">{{ __('lf.LF_course_card_student_enter') }}</a>
            @elseif ($studentMode && $course->registration_open)
                <button type="button" class="student-button is-small" data-coming-soon aria-disabled="true">{{ __('lf.LF_course_card_student_register') }}</button>
            @endif
        @endguest
    </div>
</article>
