// Touch screens have no hover: a long press on a control shows its tooltip
// and releasing does not press the control (ADR-0002 § Interface).
const HOLD_MS = 500;
const SHOW_MS = 1500;

export function installLongPressTooltips(root) {
    let timer = 0;
    let hideTimer = 0;
    let shown = null;
    let suppress = null;

    const hide = () => {
        shown?.classList.remove('is-tooltip-open');
        shown = null;
    };

    root.addEventListener('pointerdown', (event) => {
        if (event.pointerType !== 'touch') {
            return;
        }
        const target = event.target.closest('[data-tooltip]');
        if (! target || ! root.contains(target)) {
            return;
        }
        clearTimeout(timer);
        timer = setTimeout(() => {
            hide();
            clearTimeout(hideTimer);
            shown = target;
            suppress = target;
            target.classList.add('is-tooltip-open');
        }, HOLD_MS);
    });

    const release = () => {
        clearTimeout(timer);
        if (shown) {
            clearTimeout(hideTimer);
            hideTimer = setTimeout(hide, SHOW_MS);
        }
    };
    root.addEventListener('pointerup', release);
    root.addEventListener('pointercancel', release);
    root.addEventListener('pointermove', (event) => {
        if (event.pointerType === 'touch' && ! shown && Math.abs(event.movementX) + Math.abs(event.movementY) > 8) {
            clearTimeout(timer);
        }
    });

    // The click that ends a long press must not run the control.
    root.addEventListener('click', (event) => {
        if (suppress && suppress.contains(event.target)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
        suppress = null;
    }, true);

    root.addEventListener('contextmenu', (event) => {
        if (event.target.closest('[data-tooltip]')) {
            event.preventDefault();
        }
    });
}
