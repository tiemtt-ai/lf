import test from 'node:test';
import assert from 'node:assert/strict';
import { initUiKitCleanup } from '../../resources/js/admin-ui-kit-cleanup.js';

// Invariants:
// 1. Opening only scans; nothing is purged before the admin presses the run button.
// 2. Nothing found -> "nothing to clean" and no run button; found -> the counts and the run button.
// 3. The run goes in batches with the scan's token, the bar follows the processed count, the dialog cannot be closed meanwhile.
// 4. Totals (purged, failed) are shown at the end; an expired scan or a failure shows an error with "scan again".

class El {
    constructor(attrs = {}) { this.attrs = new Map(Object.entries(attrs)); this.listeners = new Map(); this.hidden = false; this.disabled = false; this.textContent = ''; this.style = {}; }
    getAttribute(n) { return this.attrs.has(n) ? this.attrs.get(n) : null; }
    setAttribute(n, v) { this.attrs.set(n, String(v)); }
    hasAttribute(n) { return this.attrs.has(n); }
    addEventListener(t, f) { this.listeners.set(t, f); }
    removeEventListener(t) { this.listeners.delete(t); }
}

function setup({ initialState = 'dirty', status = { items: 0, files: 0 }, scan = { token: 'T'.repeat(32), items: 3, files: 7, unreadable: 0 }, runs = [] } = {}) {
    const attrs = { id: 'media-cleanup-dialog', 'data-scan-url': '/scan', 'data-run-url': '/run', 'data-csrf': 'CSRF', 'data-batch': '2',
        'data-text-found': 'Found :files files in :items Media', 'data-text-unreadable': ':count unreadable', 'data-text-progress': ':done/:total',
        'data-text-done': 'Purged :purged files', 'data-text-done-failed': ':failed not purged', 'data-text-error': 'ERR' };
    const dialog = new El(attrs);
    dialog.id = 'media-cleanup-dialog'; dialog.open = false;
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; };
    const panels = Object.fromEntries(['scan', 'result', 'empty', 'run', 'done', 'error'].map(s => [s, new El()]));
    const texts = Object.fromEntries(['found', 'unreadable', 'progress', 'done', 'error'].map(n => [n, new El()]));
    const bar = new El(); const run = new El(); const retry = new El(); const closeA = new El(); const closeB = new El();
    dialog.querySelector = selector => {
        const step = selector.match(/data-cleanup-step="([a-z]+)"/)?.[1]; if (step) return panels[step];
        const text = selector.match(/data-cleanup-text="([a-z]+)"/)?.[1]; if (text) return texts[text];
        if (selector.includes('cleanup-bar')) return bar;
        if (selector.includes('cleanup-run')) return run;
        if (selector.includes('cleanup-retry')) return retry;
        return null;
    };
    dialog.querySelectorAll = () => [closeA, closeB];
    const requests = []; const queue = [...runs];
    const win = {
        AbortController: class { constructor() { this.signal = { aborted: false }; } abort() { this.signal.aborted = true; } },
        FormData: class { constructor() { this.rows = []; } append(k, v) { this.rows.push([k, v]); } },
        document: { listeners: new Map(), addEventListener(t, f) { this.listeners.set(t, f); }, removeEventListener(t) { this.listeners.delete(t); } },
        fetch: async (url, options) => {
            requests.push({ url, options });
            if (url === '/scan') return { ok: true, json: async () => scan };
            if (url === '/status') return { ok: true, json: async () => status };
            const next = queue.shift();
            return { ok: next.status === undefined, status: next.status ?? 200, json: async () => next.body };
        },
    };
    const trigger = new El({ 'data-state': initialState, 'data-tooltip': 'DEF' });
    trigger.disabled = false;
    const label = new El({ 'data-state': initialState, 'data-text-clean': 'Storage is clean', 'data-text-dirty': 'Orphans in :count Media' }); label.hidden = true;
    win.document.querySelectorAll = () => [trigger];
    win.document.querySelector = selector => (selector.includes('cleanup-status') ? label : null);
    dialog.attrs.set('data-status-url', '/status');
    const instance = initUiKitCleanup(dialog, { window: win });
    const tick = () => new Promise(r => setImmediate(r));
    const visible = () => Object.entries(panels).filter(([, p]) => !p.hidden).map(([n]) => n);
    const openDialog = async () => { win.document.listeners.get('click')({ target: { closest: s => (s.includes('media-cleanup-dialog') ? {} : null) } }); await tick(); await tick(); };
    return { trigger, label, dialog, panels, texts, bar, run, retry, closeA, closeB, requests, win, instance, tick, visible, openDialog };
}

test('opening scans and deletes nothing; the result shows the counts and the run button', async () => {
    const t = setup();
    await t.openDialog();
    assert.equal(t.dialog.open, true);
    assert.deepEqual(t.requests.map(r => r.url), ['/scan']);
    assert.deepEqual(t.visible(), ['result']);
    assert.equal(t.texts.found.textContent, 'Found 7 files in 3 Media');
    assert.equal(t.run.hidden, false);
    t.instance.destroy();
});

test('nothing found shows the empty state without a run button; unreadable media are reported', async () => {
    const none = setup({ scan: { token: 'T'.repeat(32), items: 0, files: 0, unreadable: 0 } });
    await none.openDialog();
    assert.deepEqual(none.visible(), ['empty']);
    assert.equal(none.run.hidden, true);
    const some = setup({ scan: { token: 'T'.repeat(32), items: 1, files: 1, unreadable: 2 } });
    await some.openDialog();
    assert.equal(some.texts.unreadable.hidden, false);
    assert.equal(some.texts.unreadable.textContent, '2 unreadable');
});

test('the run sends batches with the token, fills the bar, locks closing, then shows the totals', async () => {
    const t = setup({ runs: [{ body: { processed: 2, purged: 4, failed: 0, total: 3, next: 2, finished: false } }, { body: { processed: 1, purged: 3, failed: 1, total: 3, next: 3, finished: true } }] });
    await t.openDialog();
    t.run.listeners.get('click')();
    assert.deepEqual(t.visible(), ['run']);
    assert.equal(t.closeA.disabled, true, 'closing is locked while running');
    t.dialog.listeners.get('cancel')({ preventDefault() { t.prevented = true; } });
    assert.equal(t.prevented, true, 'Escape is refused while running');
    for (let i = 0; i < 8; i++) await t.tick();
    const runs = t.requests.filter(r => r.url === '/run');
    assert.deepEqual(runs[0].options.body.rows, [['_token', 'CSRF'], ['token', 'T'.repeat(32)], ['offset', '0'], ['limit', '2']]);
    assert.deepEqual(runs[1].options.body.rows.find(r => r[0] === 'offset'), ['offset', '2']);
    assert.equal(t.bar.style.width, '100%');
    assert.deepEqual(t.visible(), ['done']);
    assert.equal(t.texts.done.textContent, 'Purged 7 files 1 not purged');
    assert.equal(t.closeA.disabled, false);
    t.instance.destroy();
});

test('an expired scan or a failed batch shows the error and offers to scan again', async () => {
    const t = setup({ runs: [{ status: 410, body: { message: 'EXPIRED' } }] });
    await t.openDialog();
    t.run.listeners.get('click')();
    for (let i = 0; i < 6; i++) await t.tick();
    assert.deepEqual(t.visible(), ['error']);
    assert.equal(t.texts.error.textContent, 'EXPIRED');
    assert.equal(t.retry.hidden, false);
    t.retry.listeners.get('click')();
    for (let i = 0; i < 4; i++) await t.tick();
    assert.equal(t.requests.filter(r => r.url === '/scan').length, 2);
    assert.deepEqual(t.visible(), ['result']);
});

test('destroy removes every listener', async () => {
    const t = setup();
    t.instance.destroy();
    assert.equal(t.win.document.listeners.size + t.dialog.listeners.size + t.run.listeners.size + t.retry.listeners.size + t.closeA.listeners.size, 0);
});

test('the status label says clean or how many Media have leftovers; the button is never touched', async () => {
    const clean = setup({ initialState: 'unknown', status: { items: 0, files: 0 } });
    await clean.tick(); await clean.tick();
    assert.equal(clean.requests[0].url, '/status');
    assert.equal(clean.label.hidden, false);
    assert.equal(clean.label.textContent, 'Storage is clean');
    assert.equal(clean.label.getAttribute('data-state'), 'clean');

    const dirty = setup({ initialState: 'unknown', status: { items: 4, files: 9 } });
    await dirty.tick(); await dirty.tick();
    assert.equal(dirty.label.textContent, 'Orphans in 4 Media');
    assert.equal(dirty.label.getAttribute('data-state'), 'dirty');

    const known = setup({ initialState: 'dirty' });
    await known.tick(); await known.tick();
    assert.equal(known.trigger.disabled, false);
    assert.equal(known.trigger.getAttribute('data-tooltip'), 'DEF');
    assert.equal(known.requests.length, 0, 'a state the server already knew is not asked again');
});

test('a scan and a finished run keep the label in step: nothing left means clean, failures remain as a count', async () => {
    const t = setup({ initialState: 'dirty', runs: [{ body: { processed: 3, purged: 6, failed: 0, total: 3, next: 3, finished: true } }] });
    await t.openDialog();
    t.run.listeners.get('click')();
    for (let i = 0; i < 6; i++) await t.tick();
    assert.equal(t.label.getAttribute('data-state'), 'clean');
    assert.equal(t.trigger.disabled, false);

    const partial = setup({ initialState: 'dirty', runs: [{ body: { processed: 3, purged: 4, failed: 2, total: 3, next: 3, finished: true } }] });
    await partial.openDialog();
    partial.run.listeners.get('click')();
    for (let i = 0; i < 6; i++) await partial.tick();
    assert.equal(partial.label.getAttribute('data-state'), 'dirty');
    assert.equal(partial.label.textContent, 'Orphans in 2 Media');
});
