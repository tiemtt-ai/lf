import test from 'node:test';
import assert from 'node:assert/strict';
import { showUiToast, hideUiToast, initUiKitToasts, initUiKitToastEvents } from '../../resources/js/admin-ui-kit-controls.js';

// Invariants: showing replaces the text and restarts one timer; the timer hides; a timeout of 0 stays until closed;
// closing or hiding clears the pending timer; a toast that is already visible on load starts its timer.

class El {
    constructor(attrs = {}, text = '') {
        this.attrs = new Map(Object.entries(attrs));
        this.hidden = true;
        this.children = {};
        this.listeners = new Map();
        this.textContent = text;
    }
    getAttribute(name) { return this.attrs.has(name) ? this.attrs.get(name) : null; }
    querySelector(selector) { return this.children[selector] ?? null; }
    addEventListener(type, fn) { this.listeners.set(type, fn); }
}

function setup(timeout = '4000') {
    const text = new El(); const close = new El();
    const node = new El({ 'data-ui-toast-timeout': timeout });
    node.children = { '[data-ui-toast-text]': text, '[data-ui-toast-close]': close };
    const timers = new Map(); let id = 0; const cleared = [];
    const deps = { setTimeout: fn => { timers.set(++id, fn); return id; }, clearTimeout: t => { cleared.push(t); timers.delete(t); } };
    return { node, text, close, timers, cleared, deps };
}

test('show sets the text, reveals the toast and hides it when the timer fires', () => {
    const t = setup();
    showUiToast(t.node, 'Saved', t.deps);
    assert.equal(t.text.textContent, 'Saved');
    assert.equal(t.node.hidden, false);
    assert.equal(t.timers.size, 1);
    [...t.timers.values()][0]();
    assert.equal(t.node.hidden, true);
});

test('showing again restarts the timer instead of stacking another', () => {
    const t = setup();
    showUiToast(t.node, 'One', t.deps);
    showUiToast(t.node, 'Two', t.deps);
    assert.equal(t.text.textContent, 'Two');
    assert.equal(t.timers.size, 1);
    assert.equal(t.cleared.length, 1);
});

test('timeout 0 keeps the toast until it is closed; closing clears any timer', () => {
    const keep = setup('0');
    showUiToast(keep.node, 'Problem', keep.deps);
    assert.equal(keep.timers.size, 0);
    assert.equal(keep.node.hidden, false);
    const t = setup();
    showUiToast(t.node, 'Saved', t.deps);
    initUiKitToasts({ querySelectorAll: () => [t.node] }, t.deps);
    t.close.listeners.get('click')();
    assert.equal(t.node.hidden, true);
    assert.equal(t.timers.size, 0);
});

test('a toast already visible on load (server flash) starts its timer', () => {
    const t = setup();
    t.node.hidden = false;
    t.text.textContent = 'Flash from the server';
    initUiKitToasts({ querySelectorAll: () => [t.node] }, t.deps);
    assert.equal(t.timers.size, 1);
    assert.equal(t.text.textContent, 'Flash from the server');
    hideUiToast(t.node, t.deps);
    assert.equal(t.timers.size, 0);
});

test('a ui-toast-show event shows the matching toast, success by default and danger on request', () => {
    const success = setup(); const danger = setup('0');
    const listeners = new Map();
    const doc = {
        querySelector: selector => (selector.includes('flash') ? success.node : selector.includes('page-error') ? danger.node : null),
        addEventListener: (type, fn) => listeners.set(type, fn),
        removeEventListener: type => listeners.delete(type),
    };
    const stop = initUiKitToastEvents(doc, success.deps);
    listeners.get('ui-toast-show')({ detail: { message: 'Saved' } });
    assert.equal(success.text.textContent, 'Saved');
    assert.equal(success.node.hidden, false);
    listeners.get('ui-toast-show')({ detail: { kind: 'danger', message: 'No' } });
    assert.equal(danger.text.textContent, 'No');
    assert.equal(danger.node.hidden, false);
    listeners.get('ui-toast-show')({ detail: { message: '' } });
    assert.equal(success.text.textContent, 'Saved', 'an empty message shows nothing');
    stop();
    assert.equal(listeners.size, 0);
});
