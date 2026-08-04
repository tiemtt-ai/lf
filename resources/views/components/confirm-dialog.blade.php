<dialog class="lf-confirm-dialog"
        data-lf-confirm-dialog
        data-default-title="{{ __('lf.LF_common_title_confirm_action') }}"
        aria-labelledby="lf-confirm-dialog-title"
        aria-describedby="lf-confirm-dialog-message">
    <div class="lf-confirm-dialog__panel">
        <div class="lf-confirm-dialog__heading">
            <span class="lf-confirm-dialog__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M12 9v4m0 4h.01M10.3 4.3 2.8 17.2A2 2 0 0 0 4.5 20h15a2 2 0 0 0 1.7-2.8L13.7 4.3a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <div>
                <h2 id="lf-confirm-dialog-title" data-lf-confirm-title></h2>
                <p id="lf-confirm-dialog-message" data-lf-confirm-message></p>
            </div>
        </div>
        <div class="lf-confirm-dialog__actions">
            <button type="button" class="btn btn-secondary" data-lf-confirm-cancel>
                {{ __('lf.LF_common_button_cancel') }}
            </button>
            <button type="button"
                    class="btn btn-primary"
                    data-lf-confirm-accept
                    data-default-label="{{ __('lf.LF_common_button_confirm') }}"></button>
        </div>
    </div>
</dialog>
