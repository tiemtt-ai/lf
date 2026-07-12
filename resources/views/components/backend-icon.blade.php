@props([
    'name' => 'circle',
])

@php
    $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"></path><path d="M5 10v10h14V10"></path><path d="M9 20v-6h6v6"></path>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'user-cog' => '<circle cx="9" cy="7" r="4"></circle><path d="M3 21v-2a4 4 0 0 1 4-4h3"></path><circle cx="17" cy="17" r="3"></circle><path d="M17 13v1"></path><path d="M17 20v1"></path><path d="m21 15-.87.5"></path><path d="m13.87 18.5-.87.5"></path><path d="m21 19-.87-.5"></path><path d="m13.87 15.5-.87-.5"></path>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"></path>',
        'book-open' => '<path d="M12 7v14"></path><path d="M3 5a2 2 0 0 1 2-2h4a3 3 0 0 1 3 3v15a3 3 0 0 0-3-3H5a2 2 0 0 0-2 2Z"></path><path d="M21 5a2 2 0 0 0-2-2h-4a3 3 0 0 0-3 3v15a3 3 0 0 1 3-3h4a2 2 0 0 1 2 2Z"></path>',
        'shopping-bag' => '<path d="M6 8h12l-1 13H7Z"></path><path d="M9 8a3 3 0 0 1 6 0"></path>',
        'clipboard-check' => '<path d="M9 5h6"></path><path d="M9 3h6v4H9Z"></path><path d="M5 5h2"></path><path d="M17 5h2a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2"></path><path d="m9 14 2 2 4-4"></path>',
        'video' => '<path d="M4 6h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z"></path><path d="m16 10 6-3v10l-6-3Z"></path>',
        'image' => '<rect x="3" y="5" width="18" height="14" rx="2"></rect><circle cx="8" cy="10" r="2"></circle><path d="m21 15-5-5L5 19"></path>',
        'document' => '<path d="M6 2h8l4 4v16H6Z"></path><path d="M14 2v5h5"></path><path d="M9 12h6"></path><path d="M9 16h6"></path>',
        'chart-bar' => '<path d="M4 19V5"></path><path d="M4 19h16"></path><path d="M8 17v-6"></path><path d="M12 17V8"></path><path d="M16 17v-3"></path>',
        'sparkles' => '<path d="m12 3 1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5Z"></path><path d="m19 14 .75 2.25L22 17l-2.25.75L19 20l-.75-2.25L16 17l2.25-.75Z"></path><path d="m5 14 .75 2.25L8 17l-2.25.75L5 20l-.75-2.25L2 17l2.25-.75Z"></path>',
        'cog' => '<circle cx="12" cy="12" r="3"></circle><path d="M12 2v3"></path><path d="M12 19v3"></path><path d="m4.93 4.93 2.12 2.12"></path><path d="m16.95 16.95 2.12 2.12"></path><path d="M2 12h3"></path><path d="M19 12h3"></path><path d="m4.93 19.07 2.12-2.12"></path><path d="m16.95 7.05 2.12-2.12"></path>',
        'circle' => '<circle cx="12" cy="12" r="8"></circle>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'backend-sidebar-icon']) }}
     viewBox="0 0 24 24"
     fill="none"
     stroke="currentColor"
     stroke-width="1.8"
     stroke-linecap="round"
     stroke-linejoin="round"
     aria-hidden="true">
    {!! $paths[$name] ?? $paths['circle'] !!}
</svg>
