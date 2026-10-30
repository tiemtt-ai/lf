/**
 * Processing status of the activities on the template content tab (transcription, document analysis, video processing).
 * Every status is rendered as `[data-activity-status="<activity id>:<kind>"][data-status="<status>"]`.
 *
 *  - Starting a transcription (`form[data-activity-status-start="<key>"]` inside its dialog) is sent with fetch, so the page,
 *    its open lessons, tabs and scroll position stay as they are. The answer is the re-rendered page: the status is read
 *    from it. Started -> the dialog closes and the row shows the new status; not started -> the dialog stays open with
 *    `data-error` in `[data-activity-status-error]`.
 *  - While any status is in progress the page is read again every few seconds and only those statuses are swapped,
 *    until none is left (or after a time limit). The badge of a status in progress shows a spinner (CSS).
 *
 * Invariants (tests/js/course-template-activity-status.test.mjs):
 *  1. Starting never reloads the page; the dialog closes only when the new status shows the work has started.
 *  2. Only a status in progress, or the one just started, is replaced; never one whose dialog is open.
 *  3. A read that was overtaken by a newer one (a later poll or a start) changes nothing.
 *  4. Polling stops when no status is in progress.
 */
const IN_PROGRESS = new Set(['initializing', 'pending', 'processing']);
const NOT_STARTED = new Set(['absent', 'disabled']);
const POLL_MS = 5000;
const MAX_POLLS = 360;

export function initActivityStatus(root, deps = {}) {
    const win = deps.window ?? window;
    const doc = deps.document ?? win.document;
    const parse = deps.parse ?? (html => new win.DOMParser().parseFromString(html, 'text/html'));
    const pollMs = deps.pollMs ?? POLL_MS;
    const headers = { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' };
    let seq = 0;
    let timer = null;
    let polls = 0;

    const statuses = scope => [...scope.querySelectorAll('[data-activity-status]')];
    const keyOf = node => node.getAttribute('data-activity-status');
    const inProgress = node => IN_PROGRESS.has(node.getAttribute('data-status'));
    const find = (scope, key) => statuses(scope).find(node => keyOf(node) === key) ?? null;

    // The single swap path (invariant 2).
    const swap = (fresh, started = null) => {
        for (const node of statuses(root)) {
            const key = keyOf(node);
            if (key !== started && !inProgress(node)) continue;
            if (node.querySelector('dialog[open]')) continue;
            const next = find(fresh, key);
            if (!next || next.getAttribute('data-status') === node.getAttribute('data-status')) continue;
            node.replaceWith(doc.importNode(next, true));
        }
    };

    const pageUrl = () => {
        const url = new URL(win.location.href);
        url.hash = '';
        return url.toString();
    };

    const stop = () => {
        if (timer !== null) win.clearTimeout(timer);
        timer = null;
    };

    const schedule = () => {
        if (timer !== null || polls >= MAX_POLLS || !statuses(root).some(inProgress)) return;
        timer = win.setTimeout(poll, pollMs);
    };

    const poll = async () => {
        timer = null;
        if (doc.hidden) {
            schedule();
            return;
        }
        polls += 1;
        const mine = ++seq;
        try {
            const response = await win.fetch(pageUrl(), { headers, credentials: 'same-origin' });
            if (mine !== seq) return;
            if (response.ok) {
                const html = await response.text();
                if (mine !== seq) return;
                swap(parse(html));
            }
        } catch {
            // A failed read is retried at the next tick.
        }
        if (mine === seq) schedule();
    };

    const onSubmit = async event => {
        const form = event.target?.closest?.('form[data-activity-status-start]');
        if (!form) return;
        event.preventDefault();
        if (form.getAttribute('aria-busy') === 'true') return;
        const key = form.getAttribute('data-activity-status-start');
        const dialog = form.closest('dialog');
        const submit = form.querySelector('[type="submit"]');
        const error = form.querySelector('[data-activity-status-error]');
        form.setAttribute('aria-busy', 'true');
        if (submit) submit.disabled = true;
        if (error) error.textContent = '';
        // A poll still in flight was read before this start: discard it (invariant 3).
        const mine = ++seq;
        stop();

        let fresh = null;
        try {
            const response = await win.fetch(form.action, {
                method: 'POST', body: new win.FormData(form), headers, credentials: 'same-origin',
            });
            if (response.ok) fresh = parse(await response.text());
        } catch {
            fresh = null;
        }
        form.removeAttribute('aria-busy');

        const next = fresh ? find(fresh, key) : null;
        if (next && !NOT_STARTED.has(next.getAttribute('data-status'))) {
            dialog?.close();
            swap(fresh, key);
            polls = 0;
        } else {
            if (submit) submit.disabled = false;
            if (error) error.textContent = form.getAttribute('data-error') || '';
        }
        if (mine === seq) schedule();
    };

    doc.addEventListener('submit', onSubmit);
    schedule();

    return {
        poll,
        destroy() {
            stop();
            doc.removeEventListener('submit', onSubmit);
        },
    };
}

if (typeof document !== 'undefined') {
    const initialize = () => {
        if (document.querySelector('[data-activity-status]')) initActivityStatus(document);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}
