@props(['name'])

<svg {{ $attributes->class('admin-action-menu__item-icon') }} viewBox="0 0 24 24" aria-hidden="true">
    @switch($name)
        @case('manage')
            <path d="M4 6h16M4 12h16M4 18h16" />
            <circle cx="9" cy="6" r="2" fill="var(--admin-surface)" />
            <circle cx="15" cy="12" r="2" fill="var(--admin-surface)" />
            <circle cx="8" cy="18" r="2" fill="var(--admin-surface)" />
            @break
        @case('view')
            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
            <circle cx="12" cy="12" r="2.5" />
            @break
        @case('edit')
            <path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
            <path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z" />
            @break
        @case('duplicate')
            <rect x="8" y="8" width="11" height="11" rx="2" />
            <path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" />
            @break
        @case('delete')
            <path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5" />
            @break
        @case('remove')
            <path d="M8.5 15.5 6 18a4.2 4.2 0 0 1-6-6l3.5-3.5a4.2 4.2 0 0 1 5.9 0M15.5 8.5 18 6a4.2 4.2 0 0 1 6 6l-3.5 3.5a4.2 4.2 0 0 1-5.9 0M8 16 16 8M4 4l16 16" />
            @break
    @endswitch
</svg>
