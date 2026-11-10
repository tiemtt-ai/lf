{{-- Test cards of "Bài thi của tôi" (student-exam-card of the standard B2B site); the full attempt history stays on
     "Đề thi của tôi" (student.assessments.mine). --}}
@php
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
@endphp
<div class="student-products__grid">
    @foreach ($items as $registration)
        @php
            $open = $registration->attempts->firstWhere('status', 'in_progress');
            $left = max(0, (int) $registration->max_attempts - (int) $registration->attempts_used);
            $canStart = $registration->status === 'active' && $registration->quiz_status !== 'archived' && $registration->title !== null && $left > 0;
            $last = $registration->attempts->where('status', '!=', 'in_progress')->last();
            $badge = match (true) {
                $registration->status !== 'active' => __('lf.LF_assessment_registration_status_'.$registration->status),
                $left === 0 && ! $open => __('lf.LF_my_learning_exam_used_up'),
                default => __('lf.LF_my_learning_exam_in_use'),
            };
        @endphp
        <article class="student-products__card" data-learn-exam="{{ $registration->id }}">
            <a href="{{ route('student.assessments.mine') }}" class="student-products__image" tabindex="-1" aria-hidden="true">
                <div class="student-exam-placeholder">{!! $icon('exams', 48) !!}</div>
            </a>
            <div class="student-products__body">
                <button type="button" class="student-products__like" data-coming-soon aria-disabled="true" aria-label="{{ __('lf.LF_my_learning_favorite') }}"></button>
                <div class="student-products__badge">{{ $badge }} · {{ __('lf.LF_assessment_quiz_type_'.($registration->assessment_type ?? 'exam')) }}</div>
                <h2><a href="{{ route('student.assessments.mine') }}">{{ $registration->title ?? '—' }}</a></h2>
                <p class="student-exam-facts">
                    <span>{{ __('lf.LF_my_learning_exam_duration_fact') }} <strong>{{ $registration->duration_minutes ? __('lf.LF_assessment_learner_fact_minutes', ['minutes' => $registration->duration_minutes]) : __('lf.LF_assessment_learner_fact_untimed') }}</strong></span>
                    <span>{{ __('lf.LF_my_learning_exam_attempts_fact', ['used' => (int) $registration->attempts_used, 'max' => (int) $registration->max_attempts]) }}</span>
                    @if ($last && $last->visible['score'] && $last->status === 'graded')
                        <span>{{ __('lf.LF_my_learning_exam_score') }}: <strong>{{ $trim($last->score) }} / {{ $trim($last->max_score) }}</strong></span>
                    @endif
                </p>
                <div class="student-exam-actions student-exam-actions--pair">
                    @if ($open)
                        <a class="student-exam-actions__start" href="{{ route('student.assessments.attempts.show', $open->id) }}">{{ __('lf.LF_my_learning_exam_continue') }} <span aria-hidden="true">→</span></a>
                    @elseif ($canStart)
                        <form method="POST" action="{{ route('student.assessments.start', $registration->id) }}">
                            @csrf
                            <button class="student-exam-actions__start" type="submit" data-learn-exam-start>{{ __('lf.LF_my_learning_exam_start') }} <span aria-hidden="true">→</span></button>
                        </form>
                    @else
                        <button type="button" class="student-exam-actions__start" disabled>{{ __('lf.LF_my_learning_exam_start') }} {!! $lock !!}</button>
                    @endif
                    @if ($last)
                        <a class="student-exam-actions__results" href="{{ route('student.assessments.attempts.result', $last->id) }}"><span>{{ __('lf.LF_my_learning_exam_result') }}</span><span aria-hidden="true">→</span></a>
                    @else
                        <button type="button" class="student-exam-actions__results" disabled>{{ __('lf.LF_my_learning_exam_result') }} {!! $lock !!}</button>
                    @endif
                </div>
            </div>
        </article>
    @endforeach
</div>
