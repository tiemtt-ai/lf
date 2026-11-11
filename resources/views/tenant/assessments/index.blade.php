@extends('layouts.tenant')

@section('title', __('lf.LF_navigation_menu_public_assessments').' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))
@section('tenant_page_class', 'has-page-hero')

{{-- Quizzes open for registration in the standard B2B look: banner with search, category chips, test cards. --}}
@php
    $user = auth()->user();
    $isStudent = $user?->role === 'student';
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $filtered = $filters['keyword'] !== '' || $filters['category_id'];
    $chip = fn (?int $categoryId) => route('tenant.assessments', array_filter(['keyword' => $filters['keyword'], 'category_id' => $categoryId]));
@endphp

@section('page_hero')
    <div class="sb2b-page-title sb2b-page-title--exams">
        <div>
            <span>{{ __('lf.LF_catalog_eyebrow') }}</span>
            <h1>{{ __('lf.LF_navigation_menu_public_assessments') }}</h1>
            <p>{{ __('lf.LF_assessment_learner_catalog_help') }}</p>
            <form class="sb2b-catalog-search" method="GET" action="{{ route('tenant.assessments') }}" role="search" autocomplete="off">
                @if ($filters['category_id'])<input type="hidden" name="category_id" value="{{ $filters['category_id'] }}">@endif
                <label class="sr-only" for="exam-keyword">{{ __('lf.LF_assessment_learner_search') }}</label>
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m16 16 5 5" stroke="currentColor" stroke-width="1.8"/></svg>
                <input id="exam-keyword" type="search" name="keyword" maxlength="255" value="{{ $filters['keyword'] }}" placeholder="{{ __('lf.LF_assessment_learner_search') }}">
                <button type="submit">{{ __('lf.LF_catalog_search_button') }}</button>
            </form>
        </div>
        <b aria-hidden="true">✓</b>
    </div>
@endsection

@section('content')
    <section class="sb2b-flat-catalog student-products--exam" data-catalog>
        <nav class="sb2b-category-chips" aria-label="{{ __('lf.LF_assessment_learner_category') }}">
            <a @class(['is-active' => ! $filters['category_id']]) href="{{ $chip(null) }}">{{ __('lf.LF_catalog_all') }}</a>
            @foreach ($categories as $category)
                <a @class(['is-active' => $filters['category_id'] === (int) $category->id]) href="{{ $chip((int) $category->id) }}">{{ $category->name }}</a>
            @endforeach
            @if ($isStudent)
                <a class="sb2b-category-chips__mine" href="{{ route('student.assessments.mine') }}">{{ __('lf.LF_assessment_learner_tab_mine') }} <span aria-hidden="true">→</span></a>
            @endif
        </nav>

        @if (session('success'))
            <div class="student-alert is-success" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="student-alert is-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="sb2b-flat-catalog__heading">
            <span>{!! __($filtered ? 'lf.LF_assessment_catalog_found' : 'lf.LF_assessment_catalog_total', ['count' => '<strong class="sb2b-exam-result-count">'.$quizzes->total().'</strong>']) !!}</span>
        </div>

        @if ($quizzes->isEmpty())
            <div class="sb2b-flat-catalog__empty" role="status">
                <strong>{{ $filtered ? __('lf.LF_assessment_learner_filter_empty') : __('lf.LF_assessment_learner_catalog_empty') }}</strong>
                <p>{{ __('lf.LF_assessment_learner_catalog_empty_help') }}</p>
            </div>
        @else
            <div class="student-products__grid">
                @foreach ($quizzes as $quiz)
                    @php $isRegistered = $isStudent && $registered->has($quiz->id); @endphp
                    <article class="student-products__card lf-catalog-exam" aria-labelledby="exam-{{ $quiz->id }}" data-catalog-exam="{{ $quiz->id }}">
                        <div class="student-products__image" aria-hidden="true">
                            <div class="student-exam-placeholder">{!! \App\Support\LearnerHubIcon::svg('exams', 48) !!}</div>
                        </div>
                        <div class="student-products__body">
                            <div class="student-products__badge">
                                {{ __('lf.LF_assessment_quiz_type_'.$quiz->assessment_type) }}@if ($quiz->category_name) · {{ $quiz->category_name }}@endif
                            </div>
                            @if ($isRegistered)
                                <span class="lf-catalog-exam__registered">✓ {{ __('lf.LF_assessment_learner_registered_badge') }}</span>
                            @endif
                            <h2 id="exam-{{ $quiz->id }}">{{ $quiz->title }}</h2>
                            <p class="student-exam-facts">
                                <span>{{ __('lf.LF_my_learning_exam_duration_fact') }} <strong>{{ $quiz->duration_minutes ? __('lf.LF_assessment_learner_fact_minutes', ['minutes' => $quiz->duration_minutes]) : __('lf.LF_assessment_learner_fact_untimed') }}</strong></span>
                                <span>{{ __('lf.LF_assessment_learner_fact_questions', ['count' => $quiz->question_count]) }} · {{ __('lf.LF_assessment_learner_fact_points', ['points' => $trim($quiz->total_points)]) }} · {{ __('lf.LF_assessment_learner_fact_attempts', ['count' => $quiz->max_attempts]) }}</span>
                            </p>
                            @if (filled($quiz->description))
                                <p class="lf-catalog-exam__description">{{ $quiz->description }}</p>
                            @endif

                            <div class="student-exam-actions">
                                @guest
                                    <a class="student-exam-actions__start" href="{{ route('login', ['experience' => 'student']) }}">{{ __('lf.LF_assessment_learner_login_to_register') }}</a>
                                @else
                                    @if ($isRegistered)
                                        <a class="student-exam-actions__results" href="{{ route('student.assessments.mine') }}"><span>{{ __('lf.LF_assessment_learner_go_mine') }}</span><span aria-hidden="true">→</span></a>
                                    @elseif ($isStudent)
                                        <form method="POST" action="{{ route('student.assessments.register', $quiz->id) }}">
                                            @csrf
                                            <button class="student-exam-actions__start" type="submit">{{ __('lf.LF_assessment_learner_register') }}</button>
                                        </form>
                                    @else
                                        <p class="student-section-copy">{{ __('lf.LF_assessment_learner_students_only') }}</p>
                                    @endif
                                @endguest
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
            @include('tenant.partials.catalog-pages', ['pages' => $quizzes])
        @endif
    </section>
@endsection
