{{-- Shell icons: Lucide v0.468.0 (ISC), from docs/ui-demo/admin-layout-developer/assets/lucide.min.js. --}}
@props([
    'name' => 'circle',
])

@php
    $paths = [
        'building-2' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"></path><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"></path><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"></path><path d="M10 6h4"></path><path d="M10 10h4"></path><path d="M10 14h4"></path><path d="M10 18h4"></path>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
        'book-marked' => '<path d="M10 2v8l3-3 3 3V2"></path><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"></path>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" x2="9" y1="12" y2="12"></line>',
        'x' => '<path d="M18 6 6 18"></path><path d="m6 6 12 12"></path>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"></path>',
        'globe' => '<circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path><path d="M2 12h20"></path>',
        'bell' => '<path d="M10.268 21a2 2 0 0 0 3.464 0"></path><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"></path>',
        'panel-left' => '<rect width="18" height="18" x="3" y="3" rx="2"></rect><path d="M9 3v18"></path>',
        'home' => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"></path><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'user-cog' => '<circle cx="9" cy="7" r="4"></circle><path d="M3 21v-2a4 4 0 0 1 4-4h3"></path><circle cx="17" cy="17" r="3"></circle><path d="M17 13v1"></path><path d="M17 20v1"></path><path d="m21 15-.87.5"></path><path d="m13.87 18.5-.87.5"></path><path d="m21 19-.87-.5"></path><path d="m13.87 15.5-.87-.5"></path>',
        'folder' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"></path>',
        'book-open' => '<path d="M12 7v14"></path><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"></path>',
        'shopping-bag' => '<path d="M6 8h12l-1 13H7Z"></path><path d="M9 8a3 3 0 0 1 6 0"></path>',
        'package' => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path>',
        'clipboard-check' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"></rect><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><path d="m9 14 2 2 4-4"></path>',
        'video' => '<path d="M4 6h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z"></path><path d="m16 10 6-3v10l-6-3Z"></path>',
        'file-video' => '<path d="M6 2h8l4 4v16H6Z"></path><path d="M14 2v5h5"></path><path d="m10 12 5 3-5 3Z"></path>',
        'square-play' => '<rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="m10 9 5 3-5 3Z"></path>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l2-2a5 5 0 0 0-7.07-7.07l-1.15 1.15"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-2 2a5 5 0 0 0 7.07 7.07l1.15-1.15"></path>',
        'external-link' => '<path d="M15 3h6v6"></path><path d="m10 14 11-11"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"></rect><path d="M15 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h3"></path>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path><path d="M12 14v3"></path>',
        'edit' => '<path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"></path>',
        'check' => '<path d="m5 12 4 4L19 6"></path>',
        'audio' => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"></path><path d="M4 14h3v7H6a2 2 0 0 1-2-2Z"></path><path d="M20 14h-3v7h1a2 2 0 0 0 2-2Z"></path>',
        'image' => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"></rect><circle cx="9" cy="9" r="2"></circle><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"></path>',
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
