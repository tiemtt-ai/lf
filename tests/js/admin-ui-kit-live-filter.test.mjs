import test from 'node:test';
import assert from 'node:assert/strict';
import { initUiKitLiveFilter } from '../../resources/js/admin-ui-kit-controls.js';

// Invariants:
// 1. Only the latest request may change the page, the URL or the busy flag.
// 2. Typing waits for a pause and never runs mid-IME-composition (Vietnamese Telex/VNI).
// 3. Anything unexpected (HTTP error, redirect, missing region, network error) loads the same URL normally.
// 4. Regions are swapped as nodes; no HTML string sink is used.

class Region {
    constructor(name, label) {
        this.name = name;
        this.label = label;
        this.replacedWith = null;
    }
    getAttribute(attr) {
        return attr === 'data-ui-live-region' ? this.name : null;
    }
    replaceWith(node) {
        this.replacedWith = node;
        root.regions = root.regions.map(region => (region === this ? node : region));
    }
}

let root;

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

function setup({ keyword = '', status = '' } = {}) {
    const fields = { keyword, status };
    const listeners = new Map();
    const calls = { fetch: [], navigate: [], replaceState: [] };
    const timers = new Map();
    let timerId = 0;
    root = {
        attrs: new Map(),
        regions: [new Region('table', 'old table'), new Region('foot', 'old foot')],
        setAttribute(name, value) { this.attrs.set(name, value); },
        removeAttribute(name) { this.attrs.delete(name); },
        querySelectorAll() { return this.regions; },
        listeners: new Map(),
        addEventListener(type, fn) { this.listeners.set(type, fn); },
        removeEventListener(type) { this.listeners.delete(type); },
    };
    const form = {
        getAttribute: () => '/admin/course-categories',
        closest: () => root,
        addEventListener: (type, fn) => listeners.set(type, fn),
        removeEventListener: type => listeners.delete(type),
    };
    const pending = [];
    const win = {
        location: { href: 'https://t.localhost/admin/course-categories', assign: url => calls.navigate.push(url) },
        history: { state: null, replaceState: (state, title, url) => calls.replaceState.push(url) },
        document: { importNode: node => node },
        AbortController: class { constructor() { this.signal = { aborted: false }; } abort() { this.signal.aborted = true; } },
        setTimeout: fn => { timers.set(++timerId, fn); return timerId; },
        clearTimeout: id => timers.delete(id),
        fetch: (url, options) => {
            calls.fetch.push({ url, options });
            const d = deferred();
            pending.push(d);
            return d.promise;
        },
    };
    const parse = html => ({
        querySelector: selector => {
            const name = selector.match(/"([^"]+)"/)[1];
            return html.includes(name) ? { label: `${html}:${name}` } : null;
        },
    });
    const instance = initUiKitLiveFilter(form, {
        window: win,
        parse,
        params: () => Object.entries(fields),
    });
    const fire = (type, event = {}) => listeners.get(type)({ preventDefault() {}, ...event });
    const flushTimers = () => { const fns = [...timers.values()]; timers.clear(); fns.forEach(fn => fn()); };
    const ok = html => ({ ok: true, redirected: false, url: '', text: async () => html });
    const tick = () => new Promise(resolve => setImmediate(resolve));
    return { fields, fire, flushTimers, timers, calls, pending, ok, tick, instance };
}

test('submit swaps every region and records the filtered URL', async () => {
    const t = setup();
    t.fields.status = 'active';
    t.fire('submit');
    assert.equal(t.calls.fetch[0].url, 'https://t.localhost/admin/course-categories?status=active');
    assert.equal(root.attrs.get('aria-busy'), 'true');
    t.pending[0].resolve(t.ok('table foot'));
    await t.tick();
    assert.deepEqual(root.regions.map(r => r.label), ['table foot:table', 'table foot:foot']);
    assert.deepEqual(t.calls.replaceState, ['https://t.localhost/admin/course-categories?status=active']);
    assert.equal(root.attrs.has('aria-busy'), false);
    t.instance.destroy();
});

test('a late response from an older request changes nothing', async () => {
    const t = setup();
    t.fields.keyword = 'S';
    t.fire('submit');
    t.fields.keyword = 'So';
    t.fire('submit');
    assert.equal(t.calls.fetch[0].options.signal.aborted, true);
    t.pending[1].resolve(t.ok('new table foot'));
    await t.tick();
    t.pending[0].resolve(t.ok('stale table foot'));
    await t.tick();
    assert.deepEqual(root.regions.map(r => r.label), ['new table foot:table', 'new table foot:foot']);
    assert.deepEqual(t.calls.replaceState, ['https://t.localhost/admin/course-categories?keyword=So']);
    assert.equal(t.calls.navigate.length, 0);
    t.instance.destroy();
});

test('an older request failing late does not reload or clear the busy flag', async () => {
    const t = setup();
    t.fields.keyword = 'a';
    t.fire('submit');
    t.fields.keyword = 'ab';
    t.fire('submit');
    t.pending[0].reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
    await t.tick();
    assert.equal(t.calls.navigate.length, 0);
    assert.equal(root.attrs.get('aria-busy'), 'true');
    t.instance.destroy();
});

test('typing waits for a pause, skips IME composition and unchanged queries', async () => {
    const t = setup();
    const search = { type: 'search' };
    t.fields.keyword = 'Sơ';
    t.fire('compositionstart');
    t.fire('input', { target: search, isComposing: true });
    assert.equal(t.timers.size, 0);
    t.fire('compositionend', { target: search });
    assert.equal(t.timers.size, 1);
    t.fire('input', { target: search });
    assert.equal(t.timers.size, 1, 'a new keystroke restarts the wait');
    assert.equal(t.calls.fetch.length, 0);
    t.flushTimers();
    assert.equal(t.calls.fetch.length, 1);
    t.pending[0].resolve(t.ok('table foot'));
    await t.tick();
    t.fire('input', { target: search });
    t.flushTimers();
    assert.equal(t.calls.fetch.length, 1, 'same query is not fetched twice');
    t.fire('input', { target: { type: 'text' } });
    assert.equal(t.timers.size, 0, 'only the search box filters while typing');
    t.instance.destroy();
});

test('Enter or the search button runs at once and cancels the pending wait', () => {
    const t = setup();
    t.fields.keyword = 'x';
    t.fire('input', { target: { type: 'search' } });
    t.fire('submit');
    assert.equal(t.timers.size, 0);
    assert.equal(t.calls.fetch.length, 1);
    t.instance.destroy();
});

for (const [name, response] of [
    ['HTTP error', { ok: false, redirected: false, url: '' }],
    ['redirect (expired session)', { ok: true, redirected: true, url: 'https://t.localhost/login' }],
    ['missing region', { ok: true, redirected: false, url: '', text: async () => 'table only' }],
]) {
    test(`${name} falls back to a normal page load`, async () => {
        const t = setup();
        t.fire('submit');
        t.pending[0].resolve(response);
        await t.tick();
        assert.deepEqual(t.calls.navigate, [response.redirected ? response.url : 'https://t.localhost/admin/course-categories']);
        assert.equal(root.regions[0].label, 'old table');
        t.instance.destroy();
    });
}

test('network failure falls back to a normal page load', async () => {
    const t = setup({ status: 'inactive' });
    t.fire('submit');
    t.pending[0].reject(new TypeError('offline'));
    await t.tick();
    assert.deepEqual(t.calls.navigate, ['https://t.localhost/admin/course-categories?status=inactive']);
    t.instance.destroy();
});

test('an older response whose body arrives after a newer request is dropped', async () => {
    const t = setup();
    const body = deferred();
    t.fields.keyword = 'a';
    t.fire('submit');
    t.pending[0].resolve({ ok: true, redirected: false, url: '', text: () => body.promise });
    await t.tick();
    t.fields.keyword = 'ab';
    t.fire('submit');
    body.resolve('stale table foot');
    await t.tick();
    assert.equal(root.regions[0].label, 'old table');
    assert.equal(t.calls.replaceState.length, 0);
    t.instance.destroy();
});

test('composition guards hold even when the browser omits one of the signals', () => {
    const t = setup();
    const search = { type: 'search' };
    t.fields.keyword = 'Tr';
    t.fire('compositionstart');
    t.fire('input', { target: search, isComposing: false });
    assert.equal(t.timers.size, 0, 'composition started: wait for compositionend');
    t.fire('compositionend', { target: search });
    t.flushTimers();
    t.fields.keyword = 'Tru';
    t.fire('input', { target: search, isComposing: true });
    assert.equal(t.timers.size, 0, 'isComposing alone also defers');
    t.instance.destroy();
});

test('a save announced by a dialog form in the same scope refetches the list and cleans up on destroy', async () => {
    const t = setup({ keyword: 'So' });
    root.listeners.get('ui-dialog-form-saved')();
    assert.equal(t.calls.fetch.length, 1);
    assert.equal(t.calls.fetch[0].url, 'https://t.localhost/admin/course-categories?keyword=So');
    t.pending[0].resolve(t.ok('table foot'));
    await t.tick();
    assert.deepEqual(root.regions.map(r => r.label), ['table foot:table', 'table foot:foot']);
    t.instance.destroy();
    assert.equal(root.listeners.has('ui-dialog-form-saved'), false);
});

test('choosing in a select filters at once; other change targets do not', async () => {
    const t = setup();
    t.fields.status = 'active';
    t.fire('change', { target: { tagName: 'INPUT' } });
    assert.equal(t.calls.fetch.length, 0);
    t.fire('change', { target: { tagName: 'SELECT' } });
    assert.equal(t.calls.fetch.length, 1);
    assert.equal(t.calls.fetch[0].url, 'https://t.localhost/admin/course-categories?status=active');
    t.pending[0].resolve(t.ok('table foot'));
    await t.tick();
    t.instance.destroy();
});
