<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', __('lf.LF_common_title_public_default'))</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="public-page">

@include('partials.topbar')

<main class="public-main">
    @yield('content')
</main>

@include('partials.footer')

</body>
</html>
