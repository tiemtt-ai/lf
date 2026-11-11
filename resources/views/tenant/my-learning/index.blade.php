@extends('layouts.tenant')

@section('title', __('lf.LF_my_learning_tab_'.$tab).' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))

{{-- "Khóa học của tôi" in the standard B2B look (docs/ui-demo/learner-my-learning-design). --}}
@section('content')
@php
    $variant = ['courses' => 'mk', 'classes' => 'mklive', 'exams' => 'exam'][$tab];
    $icons = [
        'courses' => '<path d="M4 5.5c2.7-1.2 5.3-1.2 8 0v14c-2.7-1.2-5.3-1.2-8 0zM20 5.5c-2.7-1.2-5.3-1.2-8 0v14c2.7-1.2 5.3-1.2 8 0z"/>',
        'classes' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 21h8M12 16v5m-2-14 5 3-5 3z"/>',
        'exams' => '<path d="M9 4H6a2 2 0 0 0-2 2v14h16V6a2 2 0 0 0-2-2h-3"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="m8 12 2 2 6-6M8 17h8"/>',
        'results' => '<path d="M4 3v17h17M8 16v-4m5 4V8m5 8V5"/>',
    ];
    $icon = fn (string $name, int $size = 23) => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$icons[$name].'</svg>';
    $lock = '<svg class="student-exam-lock" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg>';
    $clearUrl = route('student.courses.index', ['tab' => $tab, 'view' => $view]);
@endphp
    <section class="student-products student-products--{{ $variant }}">
        <nav class="student-products__categories" aria-label="{{ __('lf.LF_my_learning_categories') }}">
            @foreach (\App\Http\Controllers\LearnerMyLearningController::TABS as $hubTab)
                <a href="{{ route('student.courses.index', ['tab' => $hubTab]) }}" @class(['is-active' => $tab === $hubTab])
                   @if ($tab === $hubTab) aria-current="page" @endif data-learn-tab="{{ $hubTab }}">
                    {!! $icon($hubTab) !!} {{ __('lf.LF_my_learning_tab_'.$hubTab) }}
                </a>
            @endforeach
            <button type="button" data-coming-soon aria-disabled="true">
                {!! $icon('results') !!} {{ __('lf.LF_student_site_my_results') }}
            </button>
        </nav>

        @if ($liveSessions->isNotEmpty())
            @include('tenant.my-learning.partials.live-sessions')
        @endif

        <h1>{{ __('lf.LF_my_learning_tab_'.$tab) }}</h1>
        <p class="student-products__description">{{ __('lf.LF_my_learning_help_'.$tab) }}</p>

        <div class="student-products__tabbar">
            <nav class="student-products__tabs" aria-label="{{ __('lf.LF_my_learning_tab_'.$tab) }}">
                @foreach ($views as $subView)
                    <a href="{{ route('student.courses.index', ['tab' => $tab, 'view' => $subView]) }}" @class(['is-active' => $view === $subView])
                       @if ($view === $subView) aria-current="page" @endif data-learn-view="{{ $subView }}">
                        {{ __('lf.LF_my_learning_view_'.$tab.'_'.$subView) }}
                        <span class="student-products__tab-count">{{ $groups[$subView]->count() }}</span>
                    </a>
                @endforeach
                <button type="button" data-coming-soon aria-disabled="true">{{ __('lf.LF_my_learning_view_favorite') }} {!! $lock !!}</button>
            </nav>
            <div class="student-exam-filter" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
                <button type="button" @class(['student-exam-filter__toggle', 'is-active' => $filter['count'] > 0])
                        x-on:click="open = ! open; if (open) $nextTick(() => $refs.search.focus())" x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-controls="learn-filter-panel">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4 2v-8Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                    <span>{{ $filter['count'] ? __('lf.LF_my_learning_filter_count', ['count' => $filter['count']]) : __('lf.LF_my_learning_filter') }}</span>
                </button>
                <form id="learn-filter-panel" class="student-exam-filter__panel" method="GET" action="{{ route('student.courses.index') }}" x-show="open" x-cloak data-learn-filter>
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <input type="hidden" name="view" value="{{ $view }}">
                    <label for="learn-filter-q">{{ __('lf.LF_my_learning_filter_search_'.$tab) }}</label>
                    <input id="learn-filter-q" type="search" name="q" value="{{ $filter['q'] }}" placeholder="{{ __('lf.LF_my_learning_filter_placeholder') }}" maxlength="120" x-ref="search">
                    @if ($filter['categories'] !== [])
                        <label for="learn-filter-category">{{ __('lf.LF_my_learning_filter_category') }}</label>
                        <select id="learn-filter-category" name="category">
                            <option value="">{{ __('lf.LF_my_learning_filter_all_categories') }}</option>
                            @foreach ($filter['categories'] as $category)
                                <option value="{{ $category }}" @selected($filter['category'] === $category)>{{ $category }}</option>
                            @endforeach
                        </select>
                    @endif
                    <div class="student-exam-filter__actions">
                        <a href="{{ $clearUrl }}">{{ __('lf.LF_my_learning_filter_clear') }}</a>
                        <button type="submit">{{ __('lf.LF_my_learning_filter_apply') }}</button>
                    </div>
                </form>
            </div>
        </div>
        @if ($filter['count'])
            <p class="student-products__description" data-learn-found>{{ __('lf.LF_my_learning_found', ['count' => $items->count()]) }}</p>
        @endif

        @if ($items->isEmpty())
            <div @class(['my-course-list' => $tab === 'classes', 'student-products__grid' => $tab !== 'classes'])>
                <div class="student-products__empty" role="status">
                    {!! $icon($tab, 48) !!}
                    <p>{{ __('lf.LF_my_learning_empty_'.$tab) }}</p>
                    @if ($tab === 'courses')
                        <a class="sb2b-learn-browse" href="{{ route('tenant.courses.index') }}">{{ __('lf.LF_my_learning_empty_browse_courses') }} <span aria-hidden="true">→</span></a>
                    @elseif ($tab === 'exams')
                        <a class="sb2b-learn-browse" href="{{ route('tenant.assessments') }}">{{ __('lf.LF_my_learning_empty_browse_exams') }} <span aria-hidden="true">→</span></a>
                    @endif
                </div>
            </div>
        @else
            @include('tenant.my-learning.partials.'.$tab, ['items' => $items, 'lock' => $lock, 'icon' => $icon])
        @endif
    </section>
@endsection
