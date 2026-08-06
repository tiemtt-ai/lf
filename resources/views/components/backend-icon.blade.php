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
        'package' => '<path d="m12 3 9 4.5-9 4.5-9-4.5Z"></path><path d="m3 7.5 9 4.5 9-4.5V17l-9 4-9-4Z"></path><path d="M12 12v9"></path>',
        'clipboard-check' => '<path d="M9 5h6"></path><path d="M9 3h6v4H9Z"></path><path d="M5 5h2"></path><path d="M17 5h2a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2"></path><path d="m9 14 2 2 4-4"></path>',
        'video' => '<path d="M4 6h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z"></path><path d="m16 10 6-3v10l-6-3Z"></path>',
        'file-video' => '<path d="M6 2h8l4 4v16H6Z"></path><path d="M14 2v5h5"></path><path d="m10 12 5 3-5 3Z"></path>',
        'square-play' => '<rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="m10 9 5 3-5 3Z"></path>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l2-2a5 5 0 0 0-7.07-7.07l-1.15 1.15"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-2 2a5 5 0 0 0 7.07 7.07l1.15-1.15"></path>',
        'external-link' => '<path d="M15 3h6v6"></path><path d="m10 14 11-11"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"></rect><path d="M15 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h3"></path>',
        'edit' => '<path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"></path>',
        'check' => '<path d="m5 12 4 4L19 6"></path>',
        'audio' => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"></path><path d="M4 14h3v7H6a2 2 0 0 1-2-2Z"></path><path d="M20 14h-3v7h1a2 2 0 0 0 2-2Z"></path>',
        'image' => '<rect x="3" y="5" width="18" height="14" rx="2"></rect><circle cx="8" cy="10" r="2"></circle><path d="m21 15-5-5L5 19"></path>',
        'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="2.5"></circle>',
        'trash' => '<path d="M4 7h16"></path><path d="M9 3h6l1 4H8Z"></path><path d="m6 7 1 14h10l1-14"></path><path d="M10 11v6"></path><path d="M14 11v6"></path>',
        'plus' => '<path d="M12 5v14"></path><path d="M5 12h14"></path>',
        'document' => '<path d="M6 2h8l4 4v16H6Z"></path><path d="M14 2v5h5"></path><path d="M9 12h6"></path><path d="M9 16h6"></path>',
        'file-pdf' => '<path d="M6 2h8l4 4v16H6Z"></path><path d="M14 2v5h5"></path><path d="M9 12h6"></path><path d="M9 16h4"></path>',
        'file-text' => '<path d="M6 2h8l4 4v16H6Z"></path><path d="M14 2v5h5"></path><path d="M9 12h6"></path><path d="M9 16h6"></path>',
        'file-spreadsheet' => '<path d="M6 2h8l4 4v16H6Z"></path><path d="M14 2v5h5"></path><path d="M9 11h6v7H9Z"></path><path d="M12 11v7"></path><path d="M9 14.5h6"></path>',
        'file-presentation' => '<path d="M6 2h8l4 4v16H6Z"></path><path d="M14 2v5h5"></path><path d="M9 17v-6h6v6"></path><path d="m10 15 2-2 2 2"></path>',
        'chart-bar' => '<path d="M4 19V5"></path><path d="M4 19h16"></path><path d="M8 17v-6"></path><path d="M12 17V8"></path><path d="M16 17v-3"></path>',
        'hierarchy' => '<circle cx="4" cy="5" r="1"></circle><path d="M8 5h13"></path><path d="M6 8v11"></path><path d="M6 12h4"></path><circle cx="11.5" cy="12" r="1"></circle><path d="M15 12h6"></path><path d="M6 19h4"></path><circle cx="11.5" cy="19" r="1"></circle><path d="M15 19h4"></path>',
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
