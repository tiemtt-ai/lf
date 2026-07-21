<div class="cohort-lifecycle-action" x-data="{ open: false, submitting: false }">
    <button type="button" class="{{ $triggerClass }}" x-on:click="open = true; $nextTick(() => $refs.dialog.showModal())">{{ $triggerLabel }}</button>
    <dialog x-ref="dialog" class="cohort-lifecycle-dialog" x-on:cancel.prevent="if (!submitting) { $refs.dialog.close(); open = false }">
        <div class="cohort-lifecycle-dialog-panel">
            <h2 class="cohort-lifecycle-dialog-title">{{ $title }}</h2>
            <p class="cohort-lifecycle-dialog-body">{{ $body }}</p>
            <form method="POST" action="{{ $action }}" class="cohort-lifecycle-dialog-actions" x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
                @csrf
                <button type="button" class="btn btn-secondary" x-on:click="$refs.dialog.close(); open = false" x-bind:disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
                <button type="submit" class="{{ $confirmClass }}" x-bind:disabled="submitting" x-bind:aria-busy="submitting"><span x-show="!submitting">{{ $confirmLabel }}</span><span x-cloak x-show="submitting">{{ __('lf.LF_course_enrollment_lifecycle_processing') }}</span></button>
            </form>
            <p class="sr-only" aria-live="polite" x-text="submitting ? @js(__('lf.LF_course_enrollment_lifecycle_processing')) : ''"></p>
        </div>
    </dialog>
</div>
