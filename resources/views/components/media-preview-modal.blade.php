@props(['id', 'show', 'close', 'title', 'compact' => 'false'])

<div class="media-library-modal" x-cloak x-show="{{ $show }}" x-transition.opacity
     x-bind:class="{ 'is-compact-preview': {{ $compact }} }"
     x-on:keydown.escape.window="if ({{ $show }}) { {{ $close }} }"
     role="dialog" aria-modal="true" aria-labelledby="{{ $id }}">
    <button type="button" class="media-library-modal-backdrop"
            aria-label="{{ __('lf.LF_common_button_close') }}" x-on:click="{{ $close }}"></button>
    <div class="media-library-modal-panel">
        <div class="media-library-modal-header">
            <h2 id="{{ $id }}" x-text="{{ $title }}"></h2>
            <button type="button" class="btn btn-secondary" x-on:click="{{ $close }}">
                {{ __('lf.LF_common_button_close') }}
            </button>
        </div>
        <div class="media-library-modal-body">{{ $slot }}</div>
    </div>
</div>
