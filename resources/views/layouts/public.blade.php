<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', 'Master Korean | API Test')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

@include('partials.topbar')

<main class="lf-main py-4">
    @yield('content')
</main>

@include('partials.footer')

</body>
</html>