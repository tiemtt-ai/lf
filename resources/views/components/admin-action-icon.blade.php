@props(['name'])

<svg {{ $attributes->class('admin-action-menu__item-icon') }} viewBox="0 0 24 24" aria-hidden="true">
    @switch($name)
        @case('view')
            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
            <circle cx="12" cy="12" r="2.5" />
            @break
        @case('edit')
            <path d="m4 16.5-.8 4.3 4.3-.8L19 8.5 15.5 5 4 16.5Z" />
            <path d="m13.8 6.7 3.5 3.5" />
            @break
        @case('delete')
            <path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5" />
            @break
        @case('remove')
            <path d="M8.5 15.5 6 18a4.2 4.2 0 0 1-6-6l3.5-3.5a4.2 4.2 0 0 1 5.9 0M15.5 8.5 18 6a4.2 4.2 0 0 1 6 6l-3.5 3.5a4.2 4.2 0 0 1-5.9 0M8 16 16 8M4 4l16 16" />
            @break
    @endswitch
</svg>
