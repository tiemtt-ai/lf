/**
 * Row actions for UI Kit lists. Three triggers, all asking through the shared LF confirmation dialog
 * (window.LFConfirm, danger tone) before anything is sent, all answered as XHR/JSON:
 *  - `[data-ui-row-delete]` (data-url, data-confirm-title/-message/-label): DELETE (method spoofing);
 *  - `[data-ui-bulk-delete]`: DELETE of every checked `[data-ui-bulk-item]` (their values go as `data-ui-bulk-name`,
 *    default `ids[]`; ":count" in the confirm message is replaced), with the select-all box
 *    `[data-ui-bulk-select-all]` and the bar `[data-ui-bulk-bar]` (hidden until something is checked) kept in step;
 *  - `[data-ui-confirm-post]`: a plain POST (e.g. a clean-up that is not tied to a row).
 * Success: a toast and `ui-dialog-form-saved` so the list refetches (or, when the server answers `redirect`, a reload
 * there). A refusal (409) shows the server's reason. One request at a time; a disabled button does nothing.
 */
import { showUiToast, claimOnce, releaseClaim, followRedirect } from './admin-ui-kit-controls.js';

const instances = new WeakMap();

export function initUiKitRowActions(scope, deps = {}) {
    if (instances.has(scope)) return instances.get(scope);
    if (!claimOnce(scope, 'RowActions')) return null;
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

    async function perform(trigger, { method, confirmMessage, fields = [] }) {
        if (busy || trigger.disabled || attr(trigger, 'aria-disabled') === 'true') return;
        busy = true;
        try {
            const confirmed = await confirmAction({
                title: attr(trigger, 'data-confirm-title'),
                message: confirmMessage ?? attr(trigger, 'data-confirm-message'),
                confirmLabel: attr(trigger, 'data-confirm-label'),
                tone: 'danger',
                trigger,
            });
            if (!confirmed) return;
            const body = new win.FormData();
            if (method !== 'POST') body.append('_method', method);
            body.append('_token', attr(scope, 'data-csrf'));
            for (const [name, value] of fields) body.append(name, value);
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
            if (response.ok && payload?.redirect) {
                // The list is rendered by the page itself (e.g. lessons in the structure tab): reload it; the server flashed the message.
                followRedirect(win, payload.redirect);
            } else if (response.ok) {
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

    const remove = trigger => perform(trigger, { method: 'DELETE' });
    const checkedItems = () => [...scope.querySelectorAll('[data-ui-bulk-item]')].filter(box => box.checked && !box.disabled);
    function bulkRemove(trigger) {
        const items = checkedItems();
        if (items.length === 0) return;
        return perform(trigger, {
            method: 'DELETE',
            confirmMessage: attr(trigger, 'data-confirm-message').replace(':count', String(items.length)),
            fields: items.map(box => [attr(trigger, 'data-ui-bulk-name') || 'ids[]', box.value]),
        });
    }
    // Select-all (indeterminate when some are checked) and the bar with the count; items that cannot be chosen are skipped.
    function syncBulk() {
        const items = [...scope.querySelectorAll('[data-ui-bulk-item]')].filter(box => !box.disabled);
        const chosen = items.filter(box => box.checked).length;
        const all = scope.querySelector('[data-ui-bulk-select-all]');
        if (all) {
            all.disabled = items.length === 0;
            all.checked = items.length > 0 && chosen === items.length;
            all.indeterminate = chosen > 0 && chosen < items.length;
        }
        const bar = scope.querySelector('[data-ui-bulk-bar]');
        if (bar) {
            bar.hidden = chosen === 0;
            const count = bar.querySelector('[data-ui-bulk-count]');
            if (count) count.textContent = attr(bar, 'data-text-selected').replace(':count', String(chosen));
        }
    }
    const onChange = event => {
        const target = event.target;
        if (target?.hasAttribute?.('data-ui-bulk-select-all')) {
            for (const box of scope.querySelectorAll('[data-ui-bulk-item]')) if (!box.disabled) box.checked = target.checked;
            syncBulk();
        } else if (target?.hasAttribute?.('data-ui-bulk-item')) {
            syncBulk();
        }
    };

    const onClick = event => {
        const trigger = event.target?.closest?.('[data-ui-row-delete], [data-ui-bulk-delete], [data-ui-confirm-post]');
        if (!trigger || !scope.contains(trigger)) return;
        if (trigger.hasAttribute('data-ui-bulk-delete')) bulkRemove(trigger);
        else if (trigger.hasAttribute('data-ui-confirm-post')) perform(trigger, { method: 'POST' });
        else remove(trigger);
    };
    scope.addEventListener('click', onClick);
    scope.addEventListener('change', onChange);
    const instance = { destroy() {
        scope.removeEventListener('click', onClick);
        scope.removeEventListener('change', onChange);
        releaseClaim(scope, 'RowActions');
        instances.delete(scope);
    } };
    instances.set(scope, instance);
    return instance;
}

if (typeof document !== 'undefined') {
    const initialize = () => document.querySelectorAll('.lf-admin-ui[data-ui-row-actions]').forEach(scope => initUiKitRowActions(scope));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}
