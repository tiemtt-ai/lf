@extends('layouts.tenant')

@section('title', __('lf.LF_my_learning_tab_'.$tab).' | '.($tenant?->name ?? __('lf.LF_common_brand_student_name')))

{{-- "Khóa học của tôi" in the standard B2B look (docs/ui-demo/learner-my-learning-design). --}}
@section('content')
@php
    $variant = ['courses' => 'mk', 'classes' => 'mklive', 'exams' => 'exam'][$tab];
    $icon = fn (string $name, int $size = 23) => \App\Support\LearnerHubIcon::svg($name, $size);
    $lock = '<svg class="student-exam-lock" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg>';
    $clearUrl = route('student.courses.index', ['tab' => $tab, 'view' => $view]);
@endphp
    <section class="student-products student-products--{{ $variant }}">
        @include('tenant.my-learning.partials.hub-nav', ['tab' => $tab])

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
                @unless (in_array('favorite', $views, true))
                    <button type="button" data-coming-soon aria-disabled="true">{{ __('lf.LF_my_learning_view_favorite') }} {!! $lock !!}</button>
                @endunless
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
                    <p>{{ __($view === 'favorite' ? 'lf.LF_my_learning_empty_favorite' : 'lf.LF_my_learning_empty_'.$tab) }}</p>
                    @if ($tab === 'courses')
                        <a class="sb2b-learn-browse" href="{{ route('tenant.courses.index') }}">{{ __('lf.LF_my_learning_empty_browse_courses') }} <span aria-hidden="true">→</span></a>
                    @elseif ($tab === 'exams')
                        <a class="sb2b-learn-browse" href="{{ route('tenant.assessments') }}">{{ __('lf.LF_my_learning_empty_browse_exams') }} <span aria-hidden="true">→</span></a>
                    @endif
                </div>
            </div>
        @else
            @include('tenant.my-learning.partials.'.($view === 'favorite' ? 'favorites' : $tab), ['items' => $items, 'lock' => $lock, 'icon' => $icon, 'favoriteIds' => $favoriteIds])
        @endif
    </section>
@endsection
