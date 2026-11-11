{{-- Class cards of "Lớp học của tôi" (standard-live-courses of the standard B2B site). --}}
@php
    $day = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : '—';
@endphp
<div class="my-course-list">
    <div class="course-group">
        @foreach ($items as $class)
            @if ($class->awaiting_class)
                {{-- Enrolled in a class course, no class yet: nothing to open until the academy places the learner. --}}
                @php
                    $title = $class->product_title ?: $class->version_title;
                    $category = \App\Services\LearnerMyLearningService::levelLabel($class->level) ?: $class->category;
                @endphp
                <article class="my-course-card" data-learn-awaiting="{{ $class->enrollment_id }}">
                    <span class="my-course-thumb my-course-thumb--letter" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr(trim($title), 0, 1)) }}</span></span>
                    <div class="my-course-content">
                        <div class="my-course-tags">
                            @if ($category)<span>{{ $category }}</span>@endif
                            <span>{{ __('lf.LF_my_learning_class_awaiting') }}</span>
                        </div>
                        <h3>{{ $title }}</h3>
                        <p>{{ __('lf.LF_my_learning_class_awaiting_help') }}</p>
                        <p>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}: {{ $day($class->enrolled_at) }}</p>
                    </div>
                    <div class="my-course-actions">
                        <span class="btn-enter-class" aria-disabled="true">
                            <span class="btn-enter-icon" aria-hidden="true">⏳</span>
                            <span class="btn-enter-text">{{ __('lf.LF_my_learning_class_awaiting') }}</span>
                        </span>
                    </div>
                </article>
                @continue
            @endif
            @php
                $title = $class->product_title ?: $class->version_title;
                $url = route('student.courses.classes.show', $class->cohort_id);
                $category = \App\Services\LearnerMyLearningService::levelLabel($class->level) ?: $class->category;
                $notStarted = $class->group === 'current' && $class->session_held === 0;
            @endphp
            <article class="my-course-card" data-learn-class="{{ $class->cohort_id }}">
                <a class="my-course-thumb my-course-thumb--letter" href="{{ $url }}" tabindex="-1" aria-hidden="true">
                    <span>{{ mb_strtoupper(mb_substr(trim($class->name), 0, 1)) }}</span>
                </a>
                <div class="my-course-content">
                    <div class="my-course-tags">
                        @if ($category)<span>{{ $category }}</span>@endif
                        @foreach ($class->delivery_modes as $mode)
                            <span>{{ __('lf.LF_course_cohort_session_mode_'.$mode) }}</span>
                        @endforeach
                        @if ($class->live_session)
                            <span class="is-live">{{ __('lf.LF_my_learning_class_live_now') }}</span>
                        @endif
                    </div>
                    <h3><a href="{{ $url }}">{{ $class->name }}</a></h3>
                    <p>
                        {{ collect([$class->teachers !== [] ? implode(', ', $class->teachers) : null, $title])->filter()->implode(' · ') }}
                        @if ($class->next_session)
                            · {{ __('lf.LF_my_learning_class_next') }}: {{ $class->next_session->starts_at->translatedFormat('D d/m') }} {{ $class->next_session->starts_at->format('H:i') }} ~ {{ $class->next_session->ends_at->format('H:i') }}
                        @endif
                    </p>
                    <p>{{ __('lf.LF_my_learning_class_code') }}: {{ $class->code }} · {{ __('lf.LF_my_learning_class_period') }}: {{ $day($class->start_date) }} ~ {{ $day($class->end_date) }}</p>
                    <div class="my-progress-bar"><span style="width: {{ $class->schedule_rate }}%"></span></div>
                    <div class="my-progress-info">
                        @if ($class->group === 'completed')
                            <b>{{ __('lf.LF_my_learning_class_done', ['percent' => $class->schedule_rate]) }}</b>
                        @elseif ($notStarted)
                            <b>{{ __('lf.LF_my_learning_class_not_started') }}</b>
                        @else
                            <b>{{ __('lf.LF_my_learning_class_progress', ['percent' => $class->schedule_rate]) }}</b>
                        @endif
                        <span>{{ __('lf.LF_my_learning_class_sessions', ['held' => $class->session_held, 'total' => $class->session_total]) }} · {{ __('lf.LF_my_learning_class_attendance') }}: {{ $class->attendance_rate }}%</span>
                    </div>
                </div>
                <div class="my-course-actions">
                    @if ($class->group === 'current')
                        <a href="{{ $url }}" class="btn-enter-class btn-live-class">
                            <span class="btn-enter-icon" aria-hidden="true"></span>
                            <span class="btn-enter-text">{{ $notStarted ? __('lf.LF_my_learning_class_upcoming') : __('lf.LF_my_learning_enter_class') }}</span>
                        </a>
                        <button type="button" class="btn-ask-teacher" data-coming-soon aria-disabled="true">
                            <span class="btn-chat-icon" aria-hidden="true">💬</span><span>{{ __('lf.LF_my_learning_ask_teacher') }}</span>
                        </button>
                    @else
                        <a href="{{ $url }}" class="btn-enter-class">
                            <span class="btn-enter-icon" aria-hidden="true">📚</span>
                            <span class="btn-enter-text">{{ __('lf.LF_my_learning_class_review') }}</span>
                        </a>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
</div>
