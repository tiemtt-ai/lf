/** Opt-in native dialog adapter. Presentation lives in admin-ui-kit.css. */
const instances = new WeakMap();

export function initUiKitDialogs(scope) {
    if (instances.has(scope)) return instances.get(scope);
    const openers = new Map();
    const listeners = new Map();
    const dialogs = () => [...scope.querySelectorAll('dialog[data-ui-dialog]')];

    function register(dialog) {
        if (listeners.has(dialog)) return;
        const onClose = () => {
            if (dialog.open) return;
            const opener = openers.get(dialog);
            openers.delete(dialog);
            for (const trigger of scope.querySelectorAll('[data-ui-dialog-open]')) {
                if (trigger.dataset.uiDialogOpen === dialog.id) trigger.setAttribute('aria-expanded', 'false');
            }
            if (opener?.isConnected && scope.contains(opener)) opener.focus();
        };
        dialog.addEventListener('close', onClose);
        listeners.set(dialog, onClose);
    }

    function onClick(event) {
        const trigger = event.target.closest?.('[data-ui-dialog-open]');
        if (trigger && scope.contains(trigger)) {
            const dialog = dialogs().find(item => item.id === trigger.dataset.uiDialogOpen);
            if (!dialog || dialog.open) return;
            register(dialog);
            openers.set(dialog, trigger);
            dialog.showModal();
            trigger.setAttribute('aria-expanded', 'true');
            return;
        }
        const close = event.target.closest?.('[data-ui-dialog-close]');
        if (close && scope.contains(close)) {
            const dialog = close.closest('dialog[data-ui-dialog]');
            if (dialog && scope.contains(dialog)) dialog.close();
        }
    }

    dialogs().forEach(register);
    scope.addEventListener('click', onClick);
    const instance = {
        destroy() {
            scope.removeEventListener('click', onClick);
            for (const [dialog, listener] of listeners) {
                if (dialog.open) dialog.close();
                dialog.removeEventListener('close', listener);
            }
            listeners.clear();
            openers.clear();
            instances.delete(scope);
        },
    };
    instances.set(scope, instance);
    return instance;
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('.lf-admin-ui[data-ui-dialog-scope]').forEach(initUiKitDialogs);
}
