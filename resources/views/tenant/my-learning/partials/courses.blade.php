{{-- Self-paced course cards of "Khóa học của tôi" (student-course-card of the standard B2B site). --}}
@php
    $day = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : null;
@endphp
<div class="student-products__grid student-products__grid--courses">
    @foreach ($items as $course)
        @php
            $title = $course->product_title ?: $course->version_title;
            $url = route('student.courses.show', $course->id);
            $windowEnd = match ($course->phase) {
                'access' => $course->access_ends_at,
                'review' => $course->review_ends_at,
                default => null,
            };
            $daysLeft = $windowEnd ? max(0, (int) ceil(now()->diffInMinutes(\Illuminate\Support\Carbon::parse($windowEnd), false) / 1440)) : null;
            $periodEnd = $course->review_ends_at ?? $course->access_ends_at;
            $studyDays = $course->access_starts_at && $course->access_ends_at
                ? max(1, (int) ceil(\Illuminate\Support\Carbon::parse($course->access_starts_at)->diffInMinutes(\Illuminate\Support\Carbon::parse($course->access_ends_at)) / 1440))
                : null;
            $category = \App\Services\LearnerMyLearningService::levelLabel($course->level) ?: $course->category;
            [$statusClass, $statusLabel] = match (true) {
                $course->group === 'expired' => ['sb2b-course-expired', __('lf.LF_my_learning_status_ended')],
                in_array($course->status, ['pending', 'suspended'], true) => ['sb2b-course-expired', __('lf.LF_my_learning_status_'.$course->status)],
                $course->group === 'completed' => ['sb2b-course-enrolled', __('lf.LF_my_learning_status_completed')],
                $course->phase === 'not_started' => ['sb2b-course-enrolled', __('lf.LF_my_learning_status_not_started')],
                default => ['sb2b-course-enrolled', __('lf.LF_my_learning_status_in_use')],
            };
            $access = match (true) {
                $course->group === 'expired' => __('lf.LF_my_learning_access_ended'),
                $course->phase === 'open' => __('lf.LF_my_learning_no_time_limit'),
                $course->phase === 'not_started' => __('lf.LF_my_learning_starts_on', ['date' => $day($course->access_starts_at)]),
                $course->phase === 'review' && $daysLeft !== null => __('lf.LF_my_learning_access_reviewing', ['days' => $daysLeft]),
                $daysLeft !== null => __('lf.LF_my_learning_days_left', ['days' => $daysLeft]),
                default => null,
            };
            $teacher = $course->teachers[0] ?? null;
        @endphp
        <article class="card hover-shadow sb2b-course-card student-course-card" data-learn-course="{{ $course->id }}">
            <a href="{{ $url }}" class="area-img text-center" tabindex="-1" aria-hidden="true">
                <img class="w-100" src="{{ asset('assets/student/default_course.png') }}" alt="" loading="lazy">
            </a>
            <div class="card-body">
                <div>
                    <div class="sb2b-course-badges">
                        @if ($category)
                            <span class="sb2b-course-category">{{ $category }}</span>
                        @endif
                        <span class="{{ $statusClass }}">{{ $statusLabel }}</span>
                        <button type="button" class="student-products__like" data-coming-soon aria-disabled="true" aria-label="{{ __('lf.LF_my_learning_favorite') }}"></button>
                    </div>
                    <a @class(['student-course-card__primary-link' => $course->group === 'current']) href="{{ $url }}">
                        <h5 class="card-title line-2">{{ $title }}</h5>
                    </a>
                    @if ($teacher)
                        <div class="sb2b-live-card__teacher">
                            <span class="sb2b-live-card__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($teacher), 0, 1)) }}</span>
                            <span>{{ implode(', ', $course->teachers) }}</span>
                        </div>
                    @endif
                    <div class="sb2b-course-facts">
                        <div class="sb2b-course-fact">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="m10 8 5 4-5 4z"/></svg>
                            <span>{{ __('lf.LF_my_learning_lessons') }}</span><strong>{{ $course->lesson_total }}</strong>
                        </div>
                        @if ($studyDays)
                            <div class="sb2b-course-fact">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                <span>{{ __('lf.LF_my_learning_study_days') }}</span><strong>{{ $studyDays }}</strong>
                            </div>
                        @endif
                        <div @class(['student-course-card__access', 'is-expired' => $course->group === 'expired'])>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18"/></svg>
                            <div class="student-course-card__access-content">
                                <span class="student-course-card__access-label">{{ __('lf.LF_my_learning_access_period') }}</span>
                                @if ($course->phase !== 'open' && $course->access_starts_at)
                                    <p class="student-course-card__period">
                                        <time>{{ $day($course->access_starts_at) }}</time>
                                        @if ($periodEnd)<span aria-hidden="true">→</span><time>{{ $day($periodEnd) }}</time>@endif
                                    </p>
                                @endif
                                @if ($access)
                                    <span class="student-course-card__access-status">{{ $access }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card--state">
                    <hr>
                    <div class="student-course-card__actions">
                        <p class="student-course-card__progress-text">{{ __('lf.LF_my_learning_progress') }}: <strong>{{ $course->progress }}%</strong></p>
                        @if ($course->group === 'expired')
                            <button type="button" class="sb2b-course-view sb2b-course-reregister" data-coming-soon aria-disabled="true">
                                <span aria-hidden="true">↻</span> {{ __('lf.LF_my_learning_reregister') }}
                            </button>
                        @else
                            <a class="sb2b-course-view sb2b-learning-entry" href="{{ $url }}">
                                {{ $course->group === 'current' ? __('lf.LF_my_learning_enter_course') : __('lf.LF_my_learning_view_course') }}
                                <span aria-hidden="true">→</span>
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </article>
    @endforeach
</div>
