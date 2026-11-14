{{-- Language flag drawn inline: an <img> is fetched after the first paint and blinks on every page load. --}}
@props([
    'locale',
])

@php
    $flagPath = public_path('assets/admin/flag-'.$locale.'.svg');
    $flagSvg = preg_match('/^[a-z]{2}$/', (string) $locale) && is_file($flagPath)
        ? trim((string) file_get_contents($flagPath))
        : '';
@endphp

@if ($flagSvg !== '')
    {!! preg_replace('/^<svg\b/', '<svg '.$attributes->merge(['width' => 24, 'height' => 16, 'aria-hidden' => 'true', 'focusable' => 'false'])->toHtml(), $flagSvg, 1) !!}
@endif
