{{-- Icons of the class page board (MK Live "learning-action-icon"). --}}
<svg class="learning-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($icon)
        @case('ended')
            <path d="M20 10a8 8 0 1 0-10 10M12 7v5l3 2m1 5 2 2 4-4"/> @break
        @case('video')
            <path d="m9 5 11 7-11 7V5Z" fill="currentColor" stroke="none"/> @break
        @case('audio')
            <path d="M4 14v-3a8 8 0 0 1 16 0v3M4 13H2v7h4v-7H4Zm16 0h2v7h-4v-7h2Z"/> @break
        @case('quiz')
            <path d="M9 5H5v16h14v-5M9 3h6v4H9zM12 15l8-8 2 2-8 8-3 1zM8 11h2M8 16h1"/> @break
        @case('result')
            <path d="M14 3H5v18h14V8l-5-5Zm0 0v5h5M8 12h8M8 16h5"/><path d="m8 9 1 1 2-2"/> @break
        @case('meeting')
            <rect x="3" y="5" width="12" height="14" rx="2"/><path d="m15 10 6-4v12l-6-4"/> @break
        @case('link')
            <path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/> @break
        @default
            <path d="M14 3H5v18h14V8l-5-5Zm0 0v5h5M8 12h8M8 16h6"/>
    @endswitch
</svg>
