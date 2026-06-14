@php
    $targetLocale = app()->getLocale() === 'vi' ? 'en' : 'vi';
@endphp

<form method="POST" action="{{ route('language.update', ['locale' => $targetLocale]) }}">
    @csrf
    <button type="submit"
            {{ $attributes->class(['language-switcher']) }}
            aria-label="{{ __('lf.LF_common_language_switch') }}: {{ __('lf.LF_common_language_'.$targetLocale) }}">
        {{ strtoupper($targetLocale) }}
    </button>
</form>
