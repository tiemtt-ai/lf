<div x-data="{ submitting: false }">
    @if ($blockedReason ?? null)
        <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $blockedReason }}">
            <button type="button" class="{{ $triggerClass }}" disabled>{{ $triggerLabel }}</button>
        </span>
    @else
    <button type="button" class="{{ $triggerClass }}" x-on:click="$refs.dialog.showModal()">{{ $triggerLabel }}</button>
    <dialog x-ref="dialog" class="ui-modal" data-columns="1" aria-labelledby="{{ $dialogId }}-title" x-on:cancel="if (submitting) $event.preventDefault()">
        <form method="POST" action="{{ $action }}" class="ui-modal__form" x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
            @csrf
            <header class="ui-modal__header">
                <div><h3 id="{{ $dialogId }}-title">{{ $title }}</h3></div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.dialog.close()" x-bind:disabled="submitting"
                        aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>
            <div class="ui-modal__body"><p class="ui-enroll-dialog-text">{{ $body }}</p></div>
            <footer class="ui-modal__footer">
                <span></span>
                <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.dialog.close()" x-bind:disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
                <button type="submit" class="{{ $confirmClass }}" x-bind:disabled="submitting" x-bind:aria-busy="submitting"><span x-show="!submitting">{{ $confirmLabel }}</span><span x-cloak x-show="submitting">{{ __('lf.LF_course_enrollment_lifecycle_processing') }}</span></button>
            </footer>
            <p class="ui-sr-only" aria-live="polite" x-text="submitting ? @js(__('lf.LF_course_enrollment_lifecycle_processing')) : ''"></p>
        </form>
    </dialog>
    @endif
</div>
