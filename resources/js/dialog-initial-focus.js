/*
 * A dialog that opens gives focus to its first focusable element. In a popup that is usually the × of its header,
 * which then looks hovered as soon as the popup opens. Focus goes to the popup itself instead: Tab still reaches
 * the × next, with its ring, and a field or button a popup focuses on purpose keeps it.
 */
const CLOSE_BUTTONS = [
    '.ui-modal__close',
    '.admin-close',
    '.admin-password-modal-close',
    '.student-password-close',
    '.course-enrollment-lifecycle-modal__close',
].join(',');

export function installDialogInitialFocus(DialogClass, doc = globalThis.document) {
    if (!DialogClass) return;
    const showModal = DialogClass.prototype.showModal;

    DialogClass.prototype.showModal = function () {
        showModal.call(this);
        const active = doc.activeElement;
        if (active && this.contains(active) && active.matches(CLOSE_BUTTONS)) {
            this.tabIndex = -1;
            this.style.outline = 'none';
            this.focus({ preventScroll: true });
        }
    };
}
