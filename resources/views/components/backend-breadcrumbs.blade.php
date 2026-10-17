@props(['breadcrumbs' => []])

<nav class="admin-breadcrumb" aria-label="{{ __('lf.LF_navigation_label_backend_breadcrumbs') }}">
    <span class="admin-system-name">MK eLearning</span>
    @foreach ($breadcrumbs as $breadcrumb)
        <span class="admin-breadcrumb-separator" aria-hidden="true"> / </span>
        @if (($breadcrumb['url'] ?? null) && ! ($breadcrumb['current'] ?? false))
            <a href="{{ $breadcrumb['url'] }}">{{ $breadcrumb['label'] }}</a>
        @else
            <strong @if ($breadcrumb['current'] ?? false) aria-current="page" @endif>{{ $breadcrumb['label'] }}</strong>
        @endif
    @endforeach
</nav>
