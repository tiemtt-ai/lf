{{-- Tabs of Kết quả & Chấm bài: all attempts, the "Chờ chấm" view and Thống kê. $activeTab: all | pending | statistics.
     Same look as every page tab (Template tabs, count in ui-tab-count; Owner 2026-11-18). --}}
<nav class="course-template-tabs ui-result-tabs" aria-label="{{ __('lf.LF_navigation_menu_assessment_results') }}">
    <a href="{{ route($routePrefix.'.index') }}" @class(['course-template-tab', 'cursor-interaction', 'is-active' => $activeTab === 'all']) @if ($activeTab === 'all') aria-current="page" @endif>
        {{ __('lf.LF_assessment_result_tab_all') }}
    </a>
    <a href="{{ route($routePrefix.'.index', ['tab' => 'pending']) }}" @class(['course-template-tab', 'cursor-interaction', 'is-active' => $activeTab === 'pending']) @if ($activeTab === 'pending') aria-current="page" @endif>
        {{ __('lf.LF_assessment_result_tab_pending') }}
        <span class="ui-tab-count">{{ $pendingCount }}</span>
    </a>
    <a href="{{ route($routePrefix.'.statistics') }}" @class(['course-template-tab', 'cursor-interaction', 'is-active' => $activeTab === 'statistics']) @if ($activeTab === 'statistics') aria-current="page" @endif>
        {{ __('lf.LF_assessment_statistics_tab') }}
    </a>
</nav>
