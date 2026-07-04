<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="https://www.masterkorean.vn/images/favicon.svg">
    <link rel="shortcut icon" href="https://www.masterkorean.vn/images/favicon.svg">
    <title>@yield('title', __('lf.LF_common_brand_name'))</title>

    @yield('vite')
</head>
<body class="@yield('body_class')">
@yield('app_shell')
</body>
</html>
