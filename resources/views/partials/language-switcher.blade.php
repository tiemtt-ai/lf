@php
    $targetLocale = app()->getLocale() === 'vi' ? 'en' : 'vi';
    $currentLocale = strtoupper(app()->getLocale());
@endphp

<form method="POST" action="{{ route('language.update', ['locale' => $targetLocale]) }}">
    @csrf
    <button type="submit"
            {{ $attributes->class(['language-switcher']) }}
            aria-label="{{ __('lf.LF_common_language_switch') }}: {{ __('lf.LF_common_language_'.$targetLocale) }}">
        <img class="language-switcher-icon" src="{{ asset('assets/admin/language.png') }}" alt="">
        <span class="language-switcher-code">{{ $currentLocale }}</span>
        <span class="auth-chevron" aria-hidden="true"></span>
    </button>
</form>

<style>
    .language-switcher {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-top: 5px;
    }

    .language-switcher-icon {
        width: auto;
        height: 16px;
        margin-right: 5px;
    }

    .language-switcher-code {
        line-height: 1;
    }
</style>
