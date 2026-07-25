<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', __('lf.LF_common_title_public_default'))</title>

    @vite(['resources/css/tenant-site.css', 'resources/js/app.js'])
    @stack('tenant_theme')
</head>
<body class="public-page">

@php
    $forceRootNavigation = trim($__env->yieldContent('force_root_navigation')) === '1';
@endphp

@include('partials.topbar', ['forceRootNavigation' => $forceRootNavigation])

<main class="public-main">
    @yield('content')
</main>

@include('partials.footer', ['forceRootNavigation' => $forceRootNavigation])

</body>
</html>
