import test from 'node:test';
import assert from 'node:assert/strict';
import { initUiKitMediaSlot, UI_MEDIA_PREVIEW_EVENT } from '../../resources/js/admin-ui-kit-controls.js';

// Invariants:
// 1. The tile is filled iff there is a pending file, or a saved file that is not marked removed.
// 2. At most one object URL is alive per slot; every discarded, replaced or destroyed pending file revokes it.
// 3. Remove on a saved file only sets the flag; remove on a pending file discards it and keeps the flag as it was.
// 4. A file chosen while the flag is set keeps the flag (the server lets the replacement win).
// 5. A cancelled picker (no files) returns to the base state. A form reset clears flag and pending file.
// 6. View announces exactly the URL of what the tile shows; nothing when there is nothing viewable.

class El {
    constructor(attrs = {}) {
        this.attrs = new Map(Object.entries(attrs));
        this.listeners = new Map();
        this.hidden = false;
        this.textContent = '';
        this.value = '';
        this.files = [];
        this.tabIndex = 0;
        this.focused = 0;
    }
    getAttribute(name) { return this.attrs.has(name) ? this.attrs.get(name) : null; }
    setAttribute(name, value) { this.attrs.set(name, String(value)); }
    removeAttribute(name) { this.attrs.delete(name); }
    addEventListener(type, fn) { this.listeners.set(type, fn); }
    removeEventListener(type) { this.listeners.delete(type); }
    focus() { this.focused += 1; }
}

function setup({ saved = true, removable = true, kind = 'image', previewable = true, savedImage = '/thumb.png' } = {}) {
    const parts = {
        input: new El(), filled: new El(), empty: new El(), image: new El(savedImage ? { src: savedImage } : {}),
        fallback: new El(), view: new El(), remove: new El(), status: new El(),
    };
    const flag = removable ? new El() : null;
    if (flag) flag.value = '0';
    const map = { ...parts, 'remove-flag': flag };
    const events = [];
    const slotListeners = new Map();
    const urls = { created: [], revoked: [] };
    const formListeners = new Map();
    const timers = [];
    const slot = new El({
        'data-saved': String(saved), 'data-saved-name': 'saved.png', 'data-saved-preview-url': saved ? '/saved-full.png' : '',
        'data-pending-previewable': String(previewable), 'data-kind': kind, 'data-saved-mime': 'image/png',
        'data-status-selected': 'Selected: :name', 'data-status-removed': 'Removed',
    });
    slot.querySelector = selector => map[selector.match(/data-ui-media-([a-z-]+)/)[1]] ?? null;
    slot.closest = () => ({
        addEventListener: (type, fn) => formListeners.set(type, fn),
        removeEventListener: type => formListeners.delete(type),
    });
    slot.dispatchEvent = event => events.push(event);
    slot.addEventListener = (type, fn) => slotListeners.set(type, fn);
    slot.removeEventListener = type => slotListeners.delete(type);
    const instance = initUiKitMediaSlot(slot, {
        url: {
            createObjectURL: file => { const url = `blob:${file.name}:${urls.created.length}`; urls.created.push(url); return url; },
            revokeObjectURL: url => urls.revoked.push(url),
        },
        createEvent: (type, detail) => ({ type, detail }),
        setTimeout: fn => timers.push(fn),
    });
    const changes = [];
    parts.input.dispatchEvent = event => changes.push(event.type);
    const choose = (name, type = 'image/png') => {
        parts.input.files = name ? [{ name, type }] : [];
        parts.input.value = name ?? '';
        parts.input.listeners.get('change')();
    };
    const click = (el) => el.listeners.get('click')();
    const state = () => slot.getAttribute('data-state');
    const alive = () => urls.created.filter(url => !urls.revoked.includes(url));
    return { slot, parts, flag, events, urls, instance, choose, click, state, alive, formListeners, timers, changes, slotListeners };
}

test('saved image fills the tile with View and Remove, no upload tile', () => {
    const t = setup();
    assert.equal(t.state(), 'filled');
    assert.equal(t.parts.filled.hidden, false);
    assert.equal(t.parts.empty.hidden, true);
    assert.equal(t.parts.image.getAttribute('src'), '/thumb.png');
    assert.equal(t.parts.view.hidden, false);
    assert.equal(t.parts.remove.hidden, false);
    assert.equal(t.parts.input.tabIndex, -1);
});

test('no saved file shows only the upload tile', () => {
    const t = setup({ saved: false, savedImage: '' });
    assert.equal(t.state(), 'empty');
    assert.equal(t.parts.filled.hidden, true);
    assert.equal(t.parts.empty.hidden, false);
    assert.equal(t.parts.input.tabIndex, 0);
});

test('remove saved then choose again replaces: flag stays set, tile shows the new file', () => {
    const t = setup();
    t.click(t.parts.remove);
    assert.equal(t.flag.value, '1');
    assert.equal(t.state(), 'empty');
    assert.equal(t.parts.empty.hidden, false);
    assert.equal(t.parts.status.textContent, 'Removed');
    assert.equal(t.parts.input.focused, 1, 'focus moves to the upload tile');
    t.choose('new.png');
    assert.equal(t.state(), 'filled');
    assert.equal(t.parts.image.getAttribute('src'), 'blob:new.png:0');
    assert.equal(t.flag.value, '1');
    assert.equal(t.parts.status.textContent, 'Selected: new.png');
});

test('removing a pending file discards it and shows the saved file again, flag untouched', () => {
    const t = setup();
    t.choose('new.png');
    t.click(t.parts.remove);
    assert.equal(t.flag.value, '0');
    assert.equal(t.parts.input.value, '');
    assert.equal(t.state(), 'filled');
    assert.equal(t.parts.image.getAttribute('src'), '/thumb.png');
    assert.deepEqual(t.alive(), []);
});

test('removing a pending file after the saved one was removed returns to empty', () => {
    const t = setup();
    t.click(t.parts.remove);
    t.choose('new.png');
    t.click(t.parts.remove);
    assert.equal(t.state(), 'empty');
    assert.equal(t.flag.value, '1');
    assert.deepEqual(t.alive(), []);
});

test('replacing the pending file revokes the previous URL: at most one alive', () => {
    const t = setup({ saved: false, savedImage: '' });
    t.choose('a.png');
    t.choose('b.png');
    t.choose('c.png');
    assert.equal(t.alive().length, 1);
    assert.equal(t.alive()[0], 'blob:c.png:2');
    t.instance.destroy();
    assert.deepEqual(t.alive(), []);
});

test('a cancelled picker returns to the base state and revokes', () => {
    const t = setup({ saved: false, savedImage: '' });
    t.choose('a.png');
    t.choose(null);
    assert.equal(t.state(), 'empty');
    assert.deepEqual(t.alive(), []);
});

test('view announces the saved URL, then the pending URL, never an empty one', () => {
    const t = setup();
    t.click(t.parts.view);
    assert.deepEqual(t.events.at(-1), { type: UI_MEDIA_PREVIEW_EVENT, detail: { name: 'saved.png', url: '/saved-full.png', kind: 'image', mimeType: 'image/png' } });
    t.choose('new.png');
    t.click(t.parts.view);
    assert.deepEqual(t.events.at(-1).detail, { name: 'new.png', url: 'blob:new.png:0', kind: 'image', mimeType: 'image/png' });
    const none = setup({ saved: false, savedImage: '' });
    assert.equal(none.parts.view.hidden, true);
    none.click(none.parts.view);
    assert.equal(none.events.length, 0, 'a view click with nothing to show announces nothing');
});

test('a non-image pending file shows the icon fallback; non-previewable kinds hide View', () => {
    const t = setup({ saved: false, savedImage: '', kind: 'document', previewable: false });
    t.choose('plan.docx', 'application/vnd.openxmlformats');
    assert.equal(t.state(), 'filled');
    assert.equal(t.parts.image.hidden, true);
    assert.equal(t.parts.fallback.hidden, false);
    assert.equal(t.parts.view.hidden, true);
    assert.equal(t.parts.remove.hidden, false);
    assert.equal(t.urls.created.length, 0, 'no object URL is made for what cannot be previewed');
    const img = setup({ saved: false, savedImage: '' });
    img.choose('notes.txt', 'text/plain');
    assert.equal(img.parts.fallback.hidden, false, 'a non-image chosen for an image slot does not become a broken image');
});

test('a broken thumbnail falls back to the icon instead of a broken image', () => {
    const t = setup();
    t.parts.image.listeners.get('error')();
    assert.equal(t.parts.image.hidden, true);
    assert.equal(t.parts.fallback.hidden, false);
    assert.equal(t.state(), 'filled');
});

test('without a remove flag a saved file cannot be removed from the tile', () => {
    const t = setup({ removable: false });
    assert.equal(t.parts.remove.hidden, true);
    t.click(t.parts.remove);
    assert.equal(t.state(), 'filled');
});

test('form reset clears the flag and any pending file', () => {
    const t = setup();
    t.click(t.parts.remove);
    t.choose('new.png');
    t.formListeners.get('reset')();
    t.parts.input.files = [];
    t.timers.splice(0).forEach(fn => fn());
    assert.equal(t.flag.value, '0');
    assert.equal(t.state(), 'filled');
    assert.equal(t.parts.image.getAttribute('src'), '/thumb.png');
    assert.deepEqual(t.alive(), []);
});

test('a file restored by the browser on init is treated as pending', () => {
    const parts = setup({ saved: false, savedImage: '' });
    parts.instance.destroy();
    parts.parts.input.files = [{ name: 'restored.png', type: 'image/png' }];
    const again = initUiKitMediaSlot(parts.slot, {
        url: { createObjectURL: f => `blob:${f.name}`, revokeObjectURL() {} },
        createEvent: (type, detail) => ({ type, detail }),
        setTimeout() {},
    });
    assert.ok(again);
    assert.equal(parts.slot.getAttribute('data-state'), 'filled');
    assert.equal(parts.parts.image.getAttribute('src'), 'blob:restored.png');
});

test('init is idempotent and destroy removes every listener', () => {
    const t = setup();
    const first = t.instance;
    assert.equal(initUiKitMediaSlot(t.slot), first);
    first.destroy();
    for (const el of [t.parts.input, t.parts.remove, t.parts.view, t.parts.image]) assert.equal(el.listeners.size, 0);
    assert.equal(t.formListeners.size, 0);
});

test('discarding a chosen file tells the form (a change event); removing a saved file does not', () => {
    const t = setup();
    t.choose('new.png');
    t.click(t.parts.remove);
    assert.deepEqual(t.changes, ['change'], 'a form that mirrors "a file is chosen" hears that none is');
    const saved = setup();
    saved.click(saved.parts.remove);
    assert.deepEqual(saved.changes, []);
});

test('an external clear (the embed alternative was chosen) empties a saved or chosen file without moving focus', () => {
    const saved = setup();
    saved.slotListeners.get('ui-media-clear')();
    assert.equal(saved.flag.value, '1');
    assert.equal(saved.state(), 'empty');
    assert.equal(saved.parts.input.focused, 0);
    const chosen = setup({ saved: false, savedImage: '' });
    chosen.choose('new.png');
    chosen.slotListeners.get('ui-media-clear')();
    assert.equal(chosen.state(), 'empty');
    assert.deepEqual(chosen.alive(), []);
    assert.deepEqual(chosen.changes, ['change']);
    chosen.slotListeners.get('ui-media-clear')();
    assert.deepEqual(chosen.changes, ['change'], 'clearing an empty tile does nothing');
});
