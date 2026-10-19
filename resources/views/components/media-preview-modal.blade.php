@props(['id', 'show', 'close', 'title', 'compact' => 'false', 'uiKit' => false])

@if ($uiKit)
    <dialog class="ui-modal" data-columns="2" aria-labelledby="{{ $id }}"
            x-effect="if ({{ $show }} && !$el.open) $el.showModal(); else if (!({{ $show }}) && $el.open) $el.close()"
            @close="{{ $close }}">
        <div class="ui-modal__form">
            <header class="ui-modal__header">
                <h3 id="{{ $id }}" x-text="{{ $title }}"></h3>
                <button type="button" class="ui-modal__close cursor-interaction" aria-label="{{ __('lf.LF_common_button_close') }}" @click="{{ $close }}">×</button>
            </header>
            <div class="ui-modal__body">{{ $slot }}</div>
        </div>
    </dialog>
@else
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

@endif
