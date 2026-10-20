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
    hasAttribute(name) { return this.attrs.has(name); }
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
    const nav = [];
    const win = {
        location: { href: 'https://x.test/admin/course-templates/3/edit?tab=structure', origin: 'https://x.test', pathname: '/admin/course-templates/3/edit', search: '?tab=structure', assign: url => nav.push(url), reload: () => nav.push('reload') },
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
    return { scope, button, flash, pageError, create, events, fetches, confirms, pending, instance, click, tick, json, nav };
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

test('a success that answers a redirect reloads that page instead of toasting in place (same page: assign, then reload)', async () => {
    const t = setup();
    t.click(); await t.tick();
    t.pending[0].resolve(t.json(200, { message: 'Deleted', redirect: '/admin/course-templates/3/edit?tab=structure#course-template-direct-lessons' }));
    await t.tick(); await t.tick();
    assert.deepEqual(t.nav, ['https://x.test/admin/course-templates/3/edit?tab=structure#course-template-direct-lessons', 'reload']);
    assert.equal(t.flash.textContent, '');
    assert.deepEqual(t.events, []);
    const other = setup();
    other.click(); await other.tick();
    other.pending[0].resolve(other.json(200, { redirect: '/admin/course-templates' }));
    await other.tick(); await other.tick();
    assert.deepEqual(other.nav, ['https://x.test/admin/course-templates'], 'a different page is a plain navigation');
});

function bulkSetup({ confirm = async () => true } = {}) {
    const t = setup({ confirm });
    const boxes = [new El({ 'data-ui-bulk-item': '' }), new El({ 'data-ui-bulk-item': '' }), new El({ 'data-ui-bulk-item': '' })];
    boxes.forEach((box, i) => { box.value = String(10 + i); box.checked = false; });
    boxes[2].disabled = true;
    const all = new El({ 'data-ui-bulk-select-all': '' }); all.checked = false; all.indeterminate = false;
    const countNode = new El(); const bar = new El({ 'data-text-selected': 'Selected :count' }); bar.hidden = true;
    bar.querySelector = () => countNode;
    const trigger = new El({ 'data-url': '/admin/media/bulk', 'data-ui-bulk-delete': '', 'data-ui-bulk-name': 'media_ids[]', 'data-confirm-title': 'Delete files?', 'data-confirm-message': 'Delete :count files?', 'data-confirm-label': 'Delete' });
    const baseQuery = t.scope.querySelector;
    t.scope.querySelectorAll = selector => (selector.includes('bulk-item') ? boxes : []);
    t.scope.querySelector = selector => (selector.includes('select-all') ? all : selector.includes('bulk-bar') ? bar : baseQuery(selector));
    return { ...t, boxes, all, bar, countNode, trigger, bulkClick: () => t.scope.listeners.get('click')({ target: { closest: () => trigger } }), change: target => t.scope.listeners.get('change')({ target }) };
}

test('bulk selection: select-all checks the selectable items only, shows the bar with the count, and follows each item', () => {
    const t = bulkSetup();
    t.all.checked = true;
    t.change(t.all);
    assert.deepEqual(t.boxes.map(b => b.checked), [true, true, false], 'a disabled item is never chosen');
    assert.equal(t.bar.hidden, false);
    assert.equal(t.countNode.textContent, 'Selected 2');
    assert.equal(t.all.indeterminate, false);
    t.boxes[0].checked = false;
    t.change(t.boxes[0]);
    assert.equal(t.all.checked, false);
    assert.equal(t.all.indeterminate, true);
    assert.equal(t.countNode.textContent, 'Selected 1');
    t.boxes[1].checked = false;
    t.change(t.boxes[1]);
    assert.equal(t.bar.hidden, true);
    assert.equal(t.all.indeterminate, false);
});

test('bulk delete confirms with the count, sends every checked value, and nothing is sent when nothing is checked or the user declines', async () => {
    const empty = bulkSetup();
    empty.bulkClick(); await empty.tick();
    assert.equal(empty.confirms.length, 0);
    assert.equal(empty.fetches.length, 0);

    const declined = bulkSetup({ confirm: async () => false });
    declined.boxes[0].checked = true;
    declined.bulkClick(); await declined.tick();
    assert.equal(declined.confirms[0].message, 'Delete 1 files?');
    assert.equal(declined.fetches.length, 0);

    const t = bulkSetup();
    t.boxes[0].checked = true; t.boxes[1].checked = true; t.boxes[2].checked = true;
    t.bulkClick(); await t.tick();
    assert.equal(t.confirms[0].message, 'Delete 2 files?', 'a disabled box is not counted even if checked');
    assert.equal(t.fetches[0].url, '/admin/media/bulk');
    assert.deepEqual(t.fetches[0].options.body.rows, [['_method', 'DELETE'], ['_token', 'TOKEN'], ['media_ids[]', '10'], ['media_ids[]', '11']]);
    t.pending[0].resolve(t.json(200, { message: 'Deleted', redirect: '/admin/course-templates/3/edit?tab=structure#x' }));
    await t.tick(); await t.tick();
    assert.equal(t.nav.at(-1), 'reload');
});

test('a confirmed POST action (clean-up) sends a plain POST without method spoofing', async () => {
    const t = setup();
    const purge = new El({ 'data-url': '/admin/media/purge-orphan-storage', 'data-ui-confirm-post': '', 'data-confirm-title': 'Purge?', 'data-confirm-message': 'Sure?', 'data-confirm-label': 'Purge' });
    t.scope.listeners.get('click')({ target: { closest: () => purge } });
    await t.tick();
    assert.equal(t.confirms[0].tone, 'danger');
    assert.deepEqual(t.fetches[0].options.body.rows, [['_token', 'TOKEN']]);
    t.pending[0].resolve(t.json(200, { message: 'Done' }));
    await t.tick(); await t.tick();
    assert.equal(t.flash.textContent, 'Done');
});
