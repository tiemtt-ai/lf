@extends('layouts.tenant')

@section('title', __('lf.LF_navigation_menu_public_assessments').' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))

@section('content')
@php
    $user = auth()->user();
    $isStudent = $user?->role === 'student';
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $filtered = $filters['keyword'] !== '' || $filters['category_id'];
@endphp
    <header class="student-page-heading tenant-page-heading">
        <p class="student-eyebrow">{{ __('lf.LF_assessment_learner_eyebrow') }}</p>
        <h1>{{ __('lf.LF_navigation_menu_public_assessments') }}</h1>
        <p>{{ __('lf.LF_assessment_learner_catalog_help') }}</p>
    </header>

    @include('tenant.assessments.partials.tabs')

    @if (session('success'))
        <div class="student-alert is-success" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="student-alert is-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <form class="lf-exam-filters" method="GET" action="{{ route('tenant.assessments') }}" role="search">
        <label class="sr-only" for="exam-keyword">{{ __('lf.LF_assessment_learner_search') }}</label>
        <input id="exam-keyword" class="student-form-control" type="search" name="keyword" maxlength="255"
               value="{{ $filters['keyword'] }}" placeholder="{{ __('lf.LF_assessment_learner_search') }}">
        @if ($categories->isNotEmpty())
            <label class="sr-only" for="exam-category">{{ __('lf.LF_assessment_learner_category') }}</label>
            <select id="exam-category" class="student-form-control" name="category_id">
                <option value="">{{ __('lf.LF_assessment_learner_all_categories') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($filters['category_id'] === (int) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        @endif
        <button class="student-button" type="submit">{{ __('lf.LF_assessment_learner_search_button') }}</button>
        @if ($filtered)
            <a class="student-text-link" href="{{ route('tenant.assessments') }}">{{ __('lf.LF_assessment_learner_clear') }}</a>
        @endif
    </form>

    @if ($quizzes->isEmpty())
        <section class="student-card lf-exam-empty" role="status">
            <h2 class="student-section-title">{{ $filtered ? __('lf.LF_assessment_learner_filter_empty') : __('lf.LF_assessment_learner_catalog_empty') }}</h2>
            <p class="student-section-copy">{{ __('lf.LF_assessment_learner_catalog_empty_help') }}</p>
        </section>
    @else
        <div class="tenant-course-grid">
            @foreach ($quizzes as $quiz)
                <article class="student-card tenant-course-card lf-exam-card" aria-labelledby="exam-{{ $quiz->id }}">
                    <div class="lf-exam-card__top">
                        <span @class(['student-badge', 'is-green' => $quiz->assessment_type === 'exercise'])>{{ __('lf.LF_assessment_quiz_type_'.$quiz->assessment_type) }}</span>
                        @if ($quiz->category_name)
                            <span class="lf-exam-card__category">{{ $quiz->category_name }}</span>
                        @endif
                    </div>
                    <h2 id="exam-{{ $quiz->id }}" class="lf-exam-card__title">{{ $quiz->title }}</h2>
                    <ul class="lf-exam-facts">
                        <li>{{ __('lf.LF_assessment_learner_fact_questions', ['count' => $quiz->question_count]) }}</li>
                        <li>{{ __('lf.LF_assessment_learner_fact_points', ['points' => $trim($quiz->total_points)]) }}</li>
                        <li>{{ $quiz->duration_minutes ? __('lf.LF_assessment_learner_fact_minutes', ['minutes' => $quiz->duration_minutes]) : __('lf.LF_assessment_learner_fact_untimed') }}</li>
                        <li>{{ __('lf.LF_assessment_learner_fact_attempts', ['count' => $quiz->max_attempts]) }}</li>
                    </ul>
                    @if (filled($quiz->description))
                        <p class="student-section-copy lf-exam-card__description">{{ $quiz->description }}</p>
                    @endif

                    <div class="tenant-course-actions">
                        @guest
                            <a class="student-button is-small" href="{{ route('login', ['experience' => 'student']) }}">{{ __('lf.LF_assessment_learner_login_to_register') }}</a>
                        @else
                            @if ($isStudent && $registered->has($quiz->id))
                                <span class="student-badge is-green">{{ __('lf.LF_assessment_learner_registered_badge') }}</span>
                                <a class="student-text-link" href="{{ route('student.assessments.mine') }}">{{ __('lf.LF_assessment_learner_go_mine') }}</a>
                            @elseif ($isStudent)
                                <form method="POST" action="{{ route('student.assessments.register', $quiz->id) }}">
                                    @csrf
                                    <button class="student-button is-small" type="submit">{{ __('lf.LF_assessment_learner_register') }}</button>
                                </form>
                            @else
                                <p class="student-section-copy">{{ __('lf.LF_assessment_learner_students_only') }}</p>
                            @endif
                        @endguest
                    </div>
                </article>
            @endforeach
        </div>

        @if ($quizzes->hasPages())
            <nav class="lf-exam-pages" aria-label="{{ __('lf.LF_assessment_learner_pages') }}">
                @if ($quizzes->previousPageUrl())
                    <a class="student-button is-outline is-small" href="{{ $quizzes->previousPageUrl() }}" rel="prev">{{ __('lf.LF_assessment_learner_previous') }}</a>
                @endif
                <span>{{ __('lf.LF_assessment_learner_page_of', ['page' => $quizzes->currentPage(), 'pages' => $quizzes->lastPage()]) }}</span>
                @if ($quizzes->nextPageUrl())
                    <a class="student-button is-outline is-small" href="{{ $quizzes->nextPageUrl() }}" rel="next">{{ __('lf.LF_assessment_learner_next') }}</a>
                @endif
            </nav>
        @endif
    @endif
@endsection
