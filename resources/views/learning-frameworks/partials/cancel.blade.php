<button type="button" class="btn btn-secondary"
        @click="$el.closest('form').reset(); $el.closest('details').open = false; $el.closest('details').querySelector('summary').focus()">{{ __('lf.LF_common_button_cancel') }}</button>
