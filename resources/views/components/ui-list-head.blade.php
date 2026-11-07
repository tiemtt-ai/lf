@props(['title', 'text', 'tip' => null])

{{-- UI Kit list page title and description, above the list card. The layout's h1 stays hidden for screen readers. --}}
<header class="ui-page-head">
    <h2 class="ui-page-head__title">
        {{ $title }}
        @if (filled($tip))
            <span class="ui-source-icon ui-page-head__tip" tabindex="0" role="img" data-tooltip-align="start" data-tooltip-placement="bottom"
                  data-tooltip="{{ $tip }}" aria-label="{{ $tip }}">
                <x-backend-icon name="info" class="ui-icon" />
            </span>
        @endif
    </h2>
    <p class="ui-page-head__text">{{ $text }}</p>
    {{ $slot }}
</header>
