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
            x-on:click="open()">
        {{ $triggerLabel }}
    </button>

    <dialog x-ref="dialog"
            class="cohort-lifecycle-dialog"
            aria-labelledby="{{ $dialogId }}-title"
            aria-describedby="{{ $dialogId }}-body"
            x-on:cancel.prevent="close()"
            x-on:click="if ($event.target === $refs.dialog) close()">
        <div class="cohort-lifecycle-dialog-panel">
            <h2 id="{{ $dialogId }}-title" class="cohort-lifecycle-dialog-title">{{ $title }}</h2>
            <p id="{{ $dialogId }}-body" class="cohort-lifecycle-dialog-body">{{ $body }}</p>

            <form method="POST"
                  action="{{ $action }}"
                  class="cohort-lifecycle-dialog-actions"
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
