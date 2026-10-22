@props(['href', 'icon', 'title', 'help'])

<a class="ui-dash-shortcut" href="{{ $href }}">
    <span class="ui-dash-shortcut__icon" aria-hidden="true"><x-backend-icon :name="$icon" /></span>
    <span class="ui-dash-shortcut__copy"><strong>{{ $title }}</strong><small>{{ $help }}</small></span>
    <span class="ui-dash-shortcut__arrow" aria-hidden="true"><x-backend-icon name="arrow-up-right" /></span>
</a>
