@props(['title', 'text'])

{{-- UI Kit list page title and description, above the list card. The layout's h1 stays hidden for screen readers. --}}
<header class="ui-page-head">
    <h2 class="ui-page-head__title">{{ $title }}</h2>
    <p class="ui-page-head__text">{{ $text }}</p>
</header>
