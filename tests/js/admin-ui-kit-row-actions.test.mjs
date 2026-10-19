import test from 'node:test';
import assert from 'node:assert/strict';
import { initUiKitRowActions } from '../../resources/js/admin-ui-kit-row-actions.js';

// Invariants: nothing is sent without an explicit confirmation; a disabled button and a second click while busy do
// nothing; success refreshes the list and shows the message; a refusal shows the server's reason and refreshes too.

class El {
    constructor(attrs = {}) {
        this.attrs = new Map(Object.entries(attrs));
        this.hidden = true;
        this.disabled = false;
        this.textContent = '';
        this.focused = 0;
        this.listeners = new Map();
    }
    getAttribute(name) { return this.attrs.has(name) ? this.attrs.get(name) : null; }
    addEventListener(type, fn) { this.listeners.set(type, fn); }
    removeEventListener(type) { this.listeners.delete(type); }
    focus() { this.focused += 1; }
    closest() { return this.self; }
}

function setup({ confirm = async () => true, trigger = {} } = {}) {
    const flash = new El(); const pageError = new El(); const create = new El();
    const scope = new El({ 'data-csrf': 'TOKEN', 'data-msg-delete-error': 'DELETE-ERROR', 'data-msg-session-expired': 'SESSION' });
    scope.querySelector = selector => (selector.includes('flash') ? flash : selector.includes('page-error') ? pageError : create);
    scope.contains = () => true;
    const events = []; scope.dispatchEvent = event => events.push(event);
    const button = new El({ 'data-url': '/admin/course-categories/7', 'data-confirm-title': 'Delete?', 'data-confirm-message': 'Sure?', 'data-confirm-label': 'Delete', ...trigger });
    const target = { closest: () => button };
    const fetches = []; const confirms = []; const pending = [];
    const win = {
        FormData: class { constructor() { this.rows = []; } append(k, v) { this.rows.push([k, v]); } },
        fetch: (url, options) => { fetches.push({ url, options }); return new Promise((resolve, reject) => pending.push({ resolve, reject })); },
    };
    const instance = initUiKitRowActions(scope, {
        window: win,
        confirm: async options => { confirms.push(options); return confirm(options); },
        createEvent: (type, detail) => ({ type, detail }),
    });
    const click = () => scope.listeners.get('click')({ target });
    const tick = () => new Promise(resolve => setImmediate(resolve));
    const json = (status, payload) => ({ ok: status >= 200 && status < 300, status, json: async () => payload });
    return { scope, button, flash, pageError, create, events, fetches, confirms, pending, instance, click, tick, json };
}

test('confirmed delete sends DELETE as JSON with the token, then toasts, refreshes and refocuses', async () => {
    const t = setup();
    t.click(); await t.tick();
    assert.deepEqual(t.confirms[0], { title: 'Delete?', message: 'Sure?', confirmLabel: 'Delete', tone: 'danger', trigger: t.button });
    assert.equal(t.fetches[0].url, '/admin/course-categories/7');
    assert.deepEqual(t.fetches[0].options.body.rows, [['_method', 'DELETE'], ['_token', 'TOKEN']]);
    assert.equal(t.fetches[0].options.headers.Accept, 'application/json');
    t.pending[0].resolve(t.json(200, { message: 'Deleted' }));
    await t.tick(); await t.tick();
    assert.equal(t.flash.textContent, 'Deleted');
    assert.equal(t.flash.hidden, false);
    assert.deepEqual(t.events.map(e => e.type), ['ui-dialog-form-saved']);
    assert.equal(t.create.focused, 1);
});

test('cancelling the confirmation sends nothing', async () => {
    const t = setup({ confirm: async () => false });
    t.click(); await t.tick();
    assert.equal(t.fetches.length, 0);
    assert.deepEqual(t.events, []);
});

test('a disabled button does nothing, and a second click while busy is ignored', async () => {
    const off = setup({ trigger: { 'aria-disabled': 'true' } });
    off.click(); await off.tick();
    assert.equal(off.confirms.length, 0);
    const t = setup();
    t.click(); t.click(); await t.tick();
    assert.equal(t.confirms.length, 1);
    assert.equal(t.fetches.length, 1);
    t.pending[0].resolve(t.json(200, { message: 'Deleted' }));
    await t.tick(); await t.tick();
    t.click(); await t.tick();
    assert.equal(t.fetches.length, 2, 'usable again once the first one finished');
});

test('a refusal shows the server reason and still refreshes the list', async () => {
    const t = setup();
    t.click(); await t.tick();
    t.pending[0].resolve(t.json(409, { message: 'Still has 2 products' }));
    await t.tick(); await t.tick();
    assert.equal(t.pageError.textContent, 'Still has 2 products');
    assert.equal(t.pageError.hidden, false);
    assert.equal(t.flash.hidden, true);
    assert.deepEqual(t.events.map(e => e.type), ['ui-dialog-form-saved']);
});

test('expired session, server errors and network failures show a message and do not refresh', async () => {
    for (const [status, expected] of [[419, 'SESSION'], [500, 'DELETE-ERROR']]) {
        const t = setup();
        t.click(); await t.tick();
        t.pending[0].resolve(t.json(status, { message: 'x' }));
        await t.tick(); await t.tick();
        assert.equal(t.pageError.textContent, expected);
        assert.deepEqual(t.events, []);
    }
    const net = setup();
    net.click(); await net.tick();
    net.pending[0].reject(new TypeError('offline'));
    await net.tick(); await net.tick();
    assert.equal(net.pageError.textContent, 'DELETE-ERROR');
    net.instance.destroy();
    assert.equal(net.scope.listeners.size, 0);
});
