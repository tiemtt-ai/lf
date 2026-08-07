<div class="cohort-lifecycle-action"
     x-data="{
         submitting: false,
         open() { this.$refs.dialog.showModal() },
         close() {
             if (this.submitting) return
             this.$refs.dialog.close()
             this.$nextTick(() => this.$refs.trigger.focus())
         }
     }">
    <button type="button"
            x-ref="trigger"
            class="{{ $triggerClass }}"
            x-on:click="open()"
            @disabled($disabled ?? false)
            @if($disabled ?? false) aria-describedby="{{ $dialogId }}-requirements" @endif>
        {{ $triggerLabel }}
    </button>

    <dialog x-ref="dialog"
            @class(['cohort-lifecycle-dialog', 'lf-confirm-dialog', 'is-danger' => str_contains($confirmClass, 'danger'), 'is-positive' => ! str_contains($confirmClass, 'danger')])
            aria-labelledby="{{ $dialogId }}-title"
            aria-describedby="{{ $dialogId }}-body"
            x-on:cancel.prevent="close()"
            x-on:click="if ($event.target === $refs.dialog) close()">
        <div class="cohort-lifecycle-dialog-panel lf-confirm-dialog__panel">
            <div class="lf-confirm-dialog__heading">
                <span class="lf-confirm-dialog__icon" aria-hidden="true">
                    @if(str_contains($confirmClass, 'danger'))
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4m0 4h.01M10.3 4.3 2.8 17.2A2 2 0 0 0 4.5 20h15a2 2 0 0 0 1.7-2.8L13.7 4.3a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    @endif
                </span>
                <div>
                    <h2 id="{{ $dialogId }}-title" class="cohort-lifecycle-dialog-title">{{ $title }}</h2>
                    <p id="{{ $dialogId }}-body" class="cohort-lifecycle-dialog-body">{{ $body }}</p>
                </div>
            </div>

            <form method="POST"
                  action="{{ $action }}"
                  class="cohort-lifecycle-dialog-actions lf-confirm-dialog__actions"
                  x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
                @csrf
                <button type="button" class="btn btn-secondary" x-on:click="close()" x-bind:disabled="submitting">
                    {{ __('lf.LF_course_cohort_lifecycle_cancel') }}
                </button>
                <button type="submit" class="{{ $confirmClass }}" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <span x-show="!submitting">{{ $confirmLabel }}</span>
                    <span x-cloak x-show="submitting">{{ __('lf.LF_course_cohort_lifecycle_processing') }}</span>
                </button>
            </form>
            <p class="sr-only" aria-live="polite" x-text="submitting ? @js(__('lf.LF_course_cohort_lifecycle_processing')) : ''"></p>
        </div>
    </dialog>
</div>
