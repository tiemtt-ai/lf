<article class="student-course-card tenant-course-card">
    <div class="student-course-cover">
        <span>{{ mb_substr($course['title'], 0, 1) }}</span>
    </div>
    <div>
        <div class="student-course-heading">
            <h3 class="student-course-title">{{ $course['title'] }}</h3>
            <span class="student-badge">{{ $course['price'] }}</span>
        </div>
        <p class="student-course-meta">{{ __('lf.LF_course_card_public_teacher', ['teacher' => $course['teacher']]) }}</p>
        <p class="student-section-copy">{{ $course['summary'] }}</p>
        @if ($course['enrolled'])
            <div class="student-course-progress">
                <div class="student-progress-track">
                    <div class="student-progress-fill" style="width: {{ $course['progress'] }}%"></div>
                </div>
                <span>{{ $course['progress'] }}%</span>
            </div>
        @endif
    </div>
    <div class="tenant-course-actions">
        <a class="student-button is-outline is-small"
           href="{{ route('tenant.courses.show', $course['slug']) }}">{{ __('lf.LF_course_card_public_view_detail') }}</a>
        @guest
            <a class="student-button is-small" href="{{ route('login') }}">{{ __('lf.LF_course_card_guest_login_register_purchase') }}</a>
        @else
            @if (auth()->user()->role === 'student' && $course['enrolled'])
                <a class="student-button is-small" href="#">{{ __('lf.LF_course_card_student_continue_learning') }}</a>
                <a class="student-text-link" href="#">{{ __('lf.LF_course_card_student_view_progress') }}</a>
                <a class="student-text-link" href="{{ route('tenant.assessments') }}">{{ __('lf.LF_course_card_student_take_assessments') }}</a>
            @elseif (auth()->user()->role === 'student')
                <a class="student-button is-small" href="#">{{ __('lf.LF_course_card_student_register') }}</a>
                <a class="student-text-link" href="#">{{ __('lf.LF_course_card_student_purchase') }}</a>
                <button class="student-text-link tenant-link-button" type="button">
                    {{ $course['favorite'] ? __('lf.LF_course_card_student_remove_favorite') : __('lf.LF_course_card_student_add_to_favorites') }}
                </button>
            @endif
        @endguest
    </div>
</article>
