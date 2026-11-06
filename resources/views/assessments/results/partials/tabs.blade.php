{{-- Tabs of Kết quả & Chấm bài: all attempts, the "Chờ chấm" view and Thống kê. $activeTab: all | pending | statistics. --}}
<nav class="ui-result-tabs" aria-label="{{ __('lf.LF_navigation_menu_assessment_results') }}">
    <a href="{{ route($routePrefix.'.index') }}" @class(['ui-result-tab', 'is-active' => $activeTab === 'all']) @if ($activeTab === 'all') aria-current="page" @endif>
        {{ __('lf.LF_assessment_result_tab_all') }}
    </a>
    <a href="{{ route($routePrefix.'.index', ['tab' => 'pending']) }}" @class(['ui-result-tab', 'is-active' => $activeTab === 'pending']) @if ($activeTab === 'pending') aria-current="page" @endif>
        {{ __('lf.LF_assessment_result_tab_pending') }}
        <span class="ui-result-tab__count">{{ $pendingCount }}</span>
    </a>
    <a href="{{ route($routePrefix.'.statistics') }}" @class(['ui-result-tab', 'is-active' => $activeTab === 'statistics']) @if ($activeTab === 'statistics') aria-current="page" @endif>
        {{ __('lf.LF_assessment_statistics_tab') }}
    </a>
</nav>
