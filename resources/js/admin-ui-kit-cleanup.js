/**
 * Storage clean-up dialog (media library). Three steps, nothing is deleted before the admin confirms:
 *  1. scan   — GET `data-scan-url` (a token and what was found; the dialog shows a waiting state);
 *  2. result — "found N files in M Media" with [Dọn ngay], or "nothing to clean";
 *  3. run    — POST `data-run-url` in batches of `data-batch` items (token, offset, limit) while a real progress bar
 *              fills; the dialog cannot be closed meanwhile; at the end the totals are shown.
 * Steps are `[data-cleanup-step=name]` panels, texts are `[data-cleanup-text=name]` (templates in `data-text-*` on the
 * dialog, `:placeholders` replaced), buttons are `[data-cleanup-run|close|retry]`. Opened by any element with
 * `data-ui-cleanup-open="<dialog id>"` (the heading button lives outside the list).
 *
 * The opening button follows what is known about the storage so nobody clicks for nothing: `data-state` is `clean` (the button
 * is disabled, like the disabled Delete button, with `data-tooltip-clean`), `dirty` or `unknown` (asked from `data-status-url`
 * after the page loads; a scan or a run updates it too).
 */
import { claimOnce, releaseClaim } from './admin-ui-kit-controls.js';

const instances = new WeakMap();
const STEPS = ['scan', 'result', 'empty', 'run', 'done', 'error'];
// Which footer buttons each step offers.
const BUTTONS = { scan: ['close'], result: ['close', 'run'], empty: ['close'], run: [], done: ['close'], error: ['close', 'retry'] };

export function initUiKitCleanup(dialog, deps = {}) {
    if (instances.has(dialog)) return instances.get(dialog);
    if (!claimOnce(dialog, 'Cleanup')) return null;
    const win = deps.window ?? window;
    const doc = deps.document ?? win.document;
    const attr = name => dialog.getAttribute(name) || '';
    const template = (name, values = {}) => Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`:${key}`, String(value)), attr(`data-text-${name}`));
    const panels = Object.fromEntries(STEPS.map(step => [step, dialog.querySelector(`[data-cleanup-step="${step}"]`)]));
    const textNode = name => dialog.querySelector(`[data-cleanup-text="${name}"]`);
    const bar = dialog.querySelector('[data-cleanup-bar]');
    const buttons = { run: dialog.querySelector('[data-cleanup-run]'), retry: dialog.querySelector('[data-cleanup-retry]') };
    const closers = [...(dialog.querySelectorAll?.('[data-cleanup-close]') ?? [])];
    const batch = Number(attr('data-batch')) || 20;
    const triggers = () => [...(doc.querySelectorAll?.(`[data-ui-cleanup-open="${dialog.id}"]`) ?? [])];
    let step = 'scan';
    let controller = null;
    let scanned = null;
    let running = false;

    // What is known about the storage (items = Media with leftovers, null = unknown): when it is clean the opening button is disabled
    // like the disabled Delete button (nothing to clean); otherwise it stays as it is. No label.
    const applyStatus = items => {
        const state = items === null ? 'unknown' : items === 0 ? 'clean' : 'dirty';
        for (const button of triggers()) {
            button.setAttribute('data-state', state);
            button.disabled = state === 'clean';
            button.setAttribute('data-tooltip', button.getAttribute(state === 'clean' ? 'data-tooltip-clean' : 'data-tooltip-default') || '');
        }
    };
    async function loadStatus() {
        const url = attr('data-status-url');
        if (!url || !triggers().some(button => button.getAttribute('data-state') === 'unknown')) return;
        try {
            const response = await win.fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            const payload = await json(response);
            if (response.ok && typeof payload?.items === 'number') applyStatus(payload.items);
        } catch (error) {
            // The button simply stays as it is; the dialog still scans on demand.
        }
    }

    const setText = (name, text) => { const node = textNode(name); if (node) node.textContent = text; };
    const show = next => {
        step = next;
        for (const name of STEPS) if (panels[name]) panels[name].hidden = name !== next;
        const offered = BUTTONS[next];
        if (buttons.run) buttons.run.hidden = !offered.includes('run');
        if (buttons.retry) buttons.retry.hidden = !offered.includes('retry');
        closers.forEach(node => { node.hidden = !offered.includes('close') && !node.hasAttribute('data-cleanup-x'); node.disabled = next === 'run'; });
    };
    const progress = (done, total) => {
        const percent = total > 0 ? Math.floor((done / total) * 100) : 100;
        if (bar) { bar.style.width = `${percent}%`; bar.setAttribute('aria-valuenow', String(percent)); }
        setText('progress', template('progress', { done, total }));
    };
    const json = async response => { try { return await response.json(); } catch (error) { return null; } };
    const fail = message => { setText('error', message || attr('data-text-error')); show('error'); };

    async function scan() {
        scanned = null;
        show('scan');
        controller = new win.AbortController();
        try {
            const response = await win.fetch(attr('data-scan-url'), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await json(response);
            if (!response.ok || !payload?.token) { fail(payload?.message); return; }
            applyStatus(payload.items);
            if (payload.items === 0) { show('empty'); return; }
            scanned = payload;
            setText('found', template('found', { files: payload.files, items: payload.items }));
            const unreadable = textNode('unreadable');
            if (unreadable) { unreadable.hidden = !(payload.unreadable > 0); unreadable.textContent = template('unreadable', { count: payload.unreadable }); }
            show('result');
        } catch (error) {
            if (error?.name !== 'AbortError') fail();
        }
    }

    async function run() {
        if (running || !scanned) return;
        running = true;
        let offset = 0; let purged = 0; let failed = 0; const total = scanned.items;
        progress(0, total);
        show('run');
        try {
            for (;;) {
                const body = new win.FormData();
                body.append('_token', attr('data-csrf'));
                body.append('token', scanned.token);
                body.append('offset', String(offset));
                body.append('limit', String(batch));
                const response = await win.fetch(attr('data-run-url'), { method: 'POST', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                const payload = await json(response);
                if (!response.ok || !payload) { fail(payload?.message); return; }
                offset = payload.next; purged += payload.purged; failed += payload.failed;
                progress(Math.min(offset, total), total);
                if (payload.finished || payload.processed === 0) break;
            }
            applyStatus(failed);
            setText('done', template('done', { purged }) + (failed > 0 ? ` ${template('done-failed', { failed })}` : ''));
            show('done');
        } catch (error) {
            fail();
        } finally {
            running = false;
        }
    }

    function open() {
        if (dialog.open) return;
        dialog.showModal();
        scan();
    }
    function close() {
        if (running) return;
        controller?.abort();
        if (dialog.open) dialog.close();
    }

    const onClick = event => {
        const trigger = event.target?.closest?.(`[data-ui-cleanup-open="${dialog.id}"]`);
        if (trigger) open();
    };
    const onCancel = event => {
        if (running) event.preventDefault();
        else controller?.abort();
    };
    const onRun = () => run();
    const onRetry = () => scan();
    const onClose = () => close();
    doc.addEventListener('click', onClick);
    dialog.addEventListener('cancel', onCancel);
    buttons.run?.addEventListener('click', onRun);
    buttons.retry?.addEventListener('click', onRetry);
    closers.forEach(node => node.addEventListener('click', onClose));
    show('scan');
    loadStatus();

    const instance = { open, destroy() {
        doc.removeEventListener('click', onClick);
        dialog.removeEventListener('cancel', onCancel);
        buttons.run?.removeEventListener('click', onRun);
        buttons.retry?.removeEventListener('click', onRetry);
        closers.forEach(node => node.removeEventListener('click', onClose));
        controller?.abort();
        releaseClaim(dialog, 'Cleanup');
        instances.delete(dialog);
    } };
    instances.set(dialog, instance);
    return instance;
}

if (typeof document !== 'undefined') {
    const initialize = () => document.querySelectorAll('dialog[data-ui-cleanup]').forEach(dialog => initUiKitCleanup(dialog));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}
