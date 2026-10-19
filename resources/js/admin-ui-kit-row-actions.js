/**
 * Row actions for UI Kit lists. `[data-ui-row-delete]` (data-url, data-confirm-title/-message/-label) asks through the
 * shared LF confirmation dialog (window.LFConfirm, danger tone), then sends DELETE (method spoofing) as XHR/JSON.
 * Success: a toast and `ui-dialog-form-saved` so the list refetches. A refusal (409) shows the server's reason.
 * One request at a time; a disabled button (the server said it cannot be deleted) does nothing.
 */
import { showUiToast } from './admin-ui-kit-controls.js';

const instances = new WeakMap();

export function initUiKitRowActions(scope, deps = {}) {
    if (instances.has(scope)) return instances.get(scope);
    const win = deps.window ?? window;
    const confirmAction = deps.confirm ?? (options => win.LFConfirm.open(options));
    const createEvent = deps.createEvent ?? ((type, detail) => new CustomEvent(type, { bubbles: true, detail }));
    const attr = (node, name) => node.getAttribute(name) || '';
    const part = name => scope.querySelector(`[data-ui-dialog-form-${name}]`);
    let busy = false;

    const report = (kind, text) => {
        const node = part(kind);
        if (node && text) showUiToast(node, text, win);
    };

    async function remove(trigger) {
        if (busy || trigger.disabled || attr(trigger, 'aria-disabled') === 'true') return;
        busy = true;
        try {
            const confirmed = await confirmAction({
                title: attr(trigger, 'data-confirm-title'),
                message: attr(trigger, 'data-confirm-message'),
                confirmLabel: attr(trigger, 'data-confirm-label'),
                tone: 'danger',
                trigger,
            });
            if (!confirmed) return;
            const body = new win.FormData();
            body.append('_method', 'DELETE');
            body.append('_token', attr(scope, 'data-csrf'));
            let response;
            try {
                response = await win.fetch(attr(trigger, 'data-url'), {
                    method: 'POST',
                    body,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
            } catch (error) {
                report('page-error', attr(scope, 'data-msg-delete-error'));
                return;
            }
            let payload = null;
            try { payload = await response.json(); } catch (error) { payload = null; }
            if (response.ok) {
                report('flash', String(payload?.message ?? ''));
                scope.dispatchEvent(createEvent('ui-dialog-form-saved', { message: String(payload?.message ?? '') }));
                scope.querySelector('[data-ui-dialog-form-open]')?.focus?.();
            } else if (response.status === 409 && payload?.message) {
                report('page-error', String(payload.message));
                scope.dispatchEvent(createEvent('ui-dialog-form-saved', { message: '' }));
            } else {
                report('page-error', attr(scope, response.status === 419 || response.status === 401 ? 'data-msg-session-expired' : 'data-msg-delete-error'));
            }
        } finally {
            busy = false;
        }
    }

    const onClick = event => {
        const trigger = event.target?.closest?.('[data-ui-row-delete]');
        if (trigger && scope.contains(trigger)) remove(trigger);
    };
    scope.addEventListener('click', onClick);
    const instance = { destroy() { scope.removeEventListener('click', onClick); instances.delete(scope); } };
    instances.set(scope, instance);
    return instance;
}

if (typeof document !== 'undefined') {
    const initialize = () => document.querySelectorAll('.lf-admin-ui[data-ui-row-actions]').forEach(scope => initUiKitRowActions(scope));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}
