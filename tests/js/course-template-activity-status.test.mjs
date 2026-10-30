import test from 'node:test';
import assert from 'node:assert/strict';
import { initActivityStatus } from '../../resources/js/course-template-activity-status.js';

// Invariants (resources/js/course-template-activity-status.js):
// 1. Starting never reloads the page; the dialog closes only when the new status shows the work has started.
// 2. Only a status in progress, or the one just started, is replaced; never one whose dialog is open.
// 3. A read that was overtaken by a newer one (a later poll or a start) changes nothing.
// 4. Polling stops when no status is in progress.

class Node {
    constructor(attrs = {}, children = []) {
        this.attrs = new Map(Object.entries(attrs));
        this.children = children;
        this.parent = null;
        children.forEach(child => { child.parent = this; });
        this.textContent = '';
        this.disabled = false;
    }
    getAttribute(name) { return this.attrs.has(name) ? this.attrs.get(name) : null; }
    setAttribute(name, value) { this.attrs.set(name, String(value)); }
    removeAttribute(name) { this.attrs.delete(name); }
    all() { return this.children.flatMap(child => [child, ...child.all()]); }
    matches(selector) {
        if (selector === '[data-activity-status]') return this.attrs.has('data-activity-status');
        if (selector === 'dialog[open]') return this.tag === 'dialog' && this.open === true;
        if (selector === 'dialog') return this.tag === 'dialog';
        if (selector === '[type="submit"]') return this.getAttribute('type') === 'submit';
        if (selector === '[data-activity-status-error]') return this.attrs.has('data-activity-status-error');
        if (selector === 'form[data-activity-status-start]') return this.tag === 'form' && this.attrs.has('data-activity-status-start');
        return false;
    }
    querySelectorAll(selector) { return this.all().filter(node => node.matches(selector)); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
    closest(selector) {
        for (let node = this; node; node = node.parent) if (node.matches(selector)) return node;
        return null;
    }
    replaceWith(next) {
        const siblings = this.parent.children;
        siblings[siblings.indexOf(this)] = next;
        next.parent = this.parent;
        this.parent = null;
    }
}

// A status element; an absent transcription carries its start dialog and form.
function status(key, value, { withDialog = false } = {}) {
    if (!withDialog) return new Node({ 'data-activity-status': key, 'data-status': value });
    const submit = new Node({ type: 'submit' });
    const error = new Node({ 'data-activity-status-error': '' });
    const form = new Node({ 'data-activity-status-start': key, 'data-error': 'FAILED' }, [error, submit]);
    form.tag = 'form';
    form.action = '/start';
    const dialog = new Node({}, [form]);
    dialog.tag = 'dialog';
    dialog.open = false;
    dialog.close = () => { dialog.open = false; };
    const node = new Node({ 'data-activity-status': key, 'data-status': value }, [dialog]);
    return Object.assign(node, { dialog, form, submit, error });
}

const page = (...statuses) => new Node({}, statuses);

function setup(root) {
    const pending = [];
    const timers = [];
    const requests = [];
    const listeners = new Map();
    const doc = {
        hidden: false,
        addEventListener(type, fn) { listeners.set(type, fn); },
        removeEventListener(type) { listeners.delete(type); },
        importNode: node => node,
    };
    const win = {
        location: { href: 'https://t.localhost/admin/course-templates/2/edit?tab=structure#x' },
        FormData: class { constructor(form) { this.form = form; } },
        setTimeout(fn) { timers.push(fn); return timers.length; },
        clearTimeout(id) { timers[id - 1] = null; },
        fetch(url, options = {}) {
            requests.push({ url, options });
            return new Promise((resolve, reject) => pending.push({ resolve, reject }));
        },
    };
    // The fake "HTML" is the parsed page itself.
    const instance = initActivityStatus(root, { window: win, document: doc, parse: html => html });
    const answer = (index, fresh, ok = true) => pending[index].resolve({ ok, text: async () => fresh });
    const tick = () => new Promise(resolve => setImmediate(resolve));
    const runTimer = () => { const fn = timers.findLast(Boolean); timers.length = 0; return fn?.(); };
    const submit = form => {
        let prevented = false;
        const done = listeners.get('submit')({ target: form, preventDefault() { prevented = true; } });
        return { done, prevented: () => prevented };
    };
    const live = timers => timers.filter(Boolean).length;
    return { instance, doc, requests, answer, tick, runTimer, submit, timers, live: () => live(timers) };
}

test('a started transcription closes the dialog, shows the new status and starts polling, without reloading', async () => {
    const absent = status('7:speech', 'absent', { withDialog: true });
    const root = page(absent);
    const t = setup(root);
    assert.equal(t.live(), 0, 'nothing in progress, no polling');
    absent.dialog.open = true;

    const { done, prevented } = t.submit(absent.form);
    assert.equal(prevented(), true, 'the form is not sent by the browser');
    assert.equal(absent.submit.disabled, true);
    assert.equal(t.requests[0].url, '/start');
    assert.equal(t.requests[0].options.method, 'POST');

    t.answer(0, page(status('7:speech', 'pending')));
    await done;
    assert.equal(absent.dialog.open, false);
    assert.equal(root.querySelector('[data-activity-status]').getAttribute('data-status'), 'pending');
    assert.equal(t.live(), 1, 'polling while in progress');
});

test('a start that did not take keeps the dialog open with the error and the button usable', async () => {
    const absent = status('7:speech', 'absent', { withDialog: true });
    const root = page(absent);
    const t = setup(root);
    absent.dialog.open = true;

    const first = t.submit(absent.form);
    t.answer(0, page(status('7:speech', 'absent')));
    await first.done;
    assert.equal(absent.dialog.open, true);
    assert.equal(absent.error.textContent, 'FAILED');
    assert.equal(absent.submit.disabled, false);
    assert.equal(root.querySelector('[data-activity-status]'), absent, 'the row is unchanged');

    t.submit(absent.form);
    assert.equal(absent.error.textContent, '', 'a new try clears the old error');
    assert.equal(absent.submit.disabled, true);
});

test('a network failure keeps the dialog open with the error', async () => {
    const absent = status('7:speech', 'absent', { withDialog: true });
    const t = setup(page(absent));
    absent.dialog.open = true;
    const { done } = t.submit(absent.form);
    t.answer(0, null, false);
    await done;
    assert.equal(absent.dialog.open, true);
    assert.equal(absent.error.textContent, 'FAILED');
});

test('polling swaps only statuses in progress and stops when none is left', async () => {
    const running = status('1:structured', 'processing');
    const idle = status('2:speech', 'absent', { withDialog: true });
    const root = page(running, idle);
    const t = setup(root);
    assert.equal(t.live(), 1);

    const read = t.runTimer();
    t.answer(0, page(status('1:structured', 'ready'), status('2:speech', 'failed')));
    await read;
    const [first, second] = root.querySelectorAll('[data-activity-status]');
    assert.equal(first.getAttribute('data-status'), 'ready');
    assert.equal(second, idle, 'a status that was not in progress is left alone');
    assert.equal(t.live(), 0, 'nothing in progress any more');
});

test('polling keeps going while the work is still in progress', async () => {
    const root = page(status('1:speech', 'pending'));
    const t = setup(root);
    const read = t.runTimer();
    t.answer(0, page(status('1:speech', 'processing')));
    await read;
    assert.equal(root.querySelector('[data-activity-status]').getAttribute('data-status'), 'processing');
    assert.equal(t.live(), 1);
});

test('a status whose dialog is open is never replaced', async () => {
    const running = status('3:speech', 'pending', { withDialog: true });
    running.dialog.open = true;
    const root = page(running);
    const t = setup(root);
    const read = t.runTimer();
    t.answer(0, page(status('3:speech', 'ready')));
    await read;
    assert.equal(root.querySelector('[data-activity-status]'), running);
});

test('a poll that was overtaken by a start changes nothing', async () => {
    const running = status('1:structured', 'processing');
    const absent = status('2:speech', 'absent', { withDialog: true });
    const root = page(running, absent);
    const t = setup(root);

    const read = t.runTimer();
    absent.dialog.open = true;
    const { done } = t.submit(absent.form);
    t.answer(1, page(status('1:structured', 'processing'), status('2:speech', 'pending')));
    await done;
    assert.equal(root.querySelectorAll('[data-activity-status]')[1].getAttribute('data-status'), 'pending');

    // The old poll answers last with the page as it was before the start.
    t.answer(0, page(status('1:structured', 'ready'), status('2:speech', 'absent')));
    await read;
    const [first, second] = root.querySelectorAll('[data-activity-status]');
    assert.equal(first.getAttribute('data-status'), 'processing', 'the stale read did not swap anything');
    assert.equal(second.getAttribute('data-status'), 'pending', 'the started status was not put back');
});

test('a hidden page does not read but keeps the poll scheduled', async () => {
    const root = page(status('1:speech', 'pending'));
    const t = setup(root);
    t.doc.hidden = true;
    await t.runTimer();
    assert.equal(t.requests.length, 0);
    assert.equal(t.live(), 1);
});

test('destroy stops polling and the submit handler', () => {
    const root = page(status('1:speech', 'pending'));
    const t = setup(root);
    t.instance.destroy();
    assert.equal(t.live(), 0);
});
