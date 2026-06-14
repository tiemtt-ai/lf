<article class="student-course-card tenant-course-card">
    <div class="student-course-cover">
        <span>{{ mb_substr($course['title'], 0, 1) }}</span>
    </div>
    <div>
        <div class="student-course-heading">
            <h3 class="student-course-title">{{ $course['title'] }}</h3>
            <span class="student-badge">{{ $course['price'] }}</span>
        </div>
        <p class="student-course-meta">Teacher: {{ $course['teacher'] }}</p>
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
           href="{{ route('tenant.courses.show', $course['slug']) }}">View Detail</a>
        @guest
            <a class="student-button is-small" href="{{ route('login') }}">Login to Register / Login to Purchase</a>
        @else
            @if (auth()->user()->role === 'student' && $course['enrolled'])
                <a class="student-button is-small" href="#">Continue Learning</a>
                <a class="student-text-link" href="#">View Progress</a>
                <a class="student-text-link" href="{{ route('tenant.assessments') }}">Take Assessments</a>
            @elseif (auth()->user()->role === 'student')
                <a class="student-button is-small" href="#">Register</a>
                <a class="student-text-link" href="#">Purchase</a>
                <button class="student-text-link tenant-link-button" type="button">
                    {{ $course['favorite'] ? 'Remove Favorite' : 'Add To Favorites' }}
                </button>
            @endif
        @endguest
    </div>
</article>
