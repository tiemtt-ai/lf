import test from 'node:test';
import assert from 'node:assert/strict';
import { parseEmbedLink, initUiKitEmbedSlot, UI_EMBED_CHANGE_EVENT, UI_EMBED_CLEAR_EVENT, UI_MEDIA_PREVIEW_EVENT } from '../../resources/js/admin-ui-kit-controls.js';

// Invariants:
// 1. The tile is filled iff the hidden value holds a link; the link only changes through OK (valid), Remove or an external clear.
// 2. An invalid link keeps the editor open with a message and never changes the value.
// 3. Every change is announced with `ui-embed-change` so the form can switch the video source.
// 4. Escape closes only the editor (the dialog must not close), an outside click cancels without committing.
// 5. The accepted links are exactly the server's: HTTPS, YouTube or Vimeo.

test('parseEmbedLink accepts what the server accepts and normalises it', () => {
    const yt = 'dQw4w9WgXcQ';
    for (const link of [`https://www.youtube.com/watch?v=${yt}`, `https://youtube.com/watch?v=${yt}&t=10`, `https://m.youtube.com/watch?v=${yt}`, `https://youtu.be/${yt}`, `https://www.youtube.com/shorts/${yt}`, `https://www.youtube.com/embed/${yt}`]) {
        assert.deepEqual(parseEmbedLink(link), {
            provider: 'youtube', id: yt, url: `https://www.youtube.com/watch?v=${yt}`,
            embedUrl: `https://www.youtube-nocookie.com/embed/${yt}`, thumbnail: `https://i.ytimg.com/vi/${yt}/hqdefault.jpg`,
        }, link);
    }
    for (const link of ['https://vimeo.com/76979871', 'https://www.vimeo.com/76979871', 'https://player.vimeo.com/video/76979871']) {
        assert.deepEqual(parseEmbedLink(link), { provider: 'vimeo', id: '76979871', url: 'https://vimeo.com/76979871', embedUrl: 'https://player.vimeo.com/video/76979871', thumbnail: '' }, link);
    }
});

test('parseEmbedLink rejects everything else', () => {
    for (const link of ['', '  ', 'dQw4w9WgXcQ', 'http://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://example.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=short', 'https://www.youtube.com/watch', 'https://www.youtube.com/channel/abc', 'https://vimeo.com/not-a-number', 'https://evil.com/vimeo.com/123', 'javascript:alert(1)', null, undefined]) {
        assert.equal(parseEmbedLink(link), null, String(link));
    }
});

class El {
    constructor(attrs = {}) {
        this.attrs = new Map(Object.entries(attrs));
        this.listeners = new Map();
        this.hidden = false;
        this.value = '';
        this.textContent = '';
        this.focused = 0;
    }
    getAttribute(name) { return this.attrs.has(name) ? this.attrs.get(name) : null; }
    setAttribute(name, value) { this.attrs.set(name, String(value)); }
    removeAttribute(name) { this.attrs.delete(name); }
    addEventListener(type, fn) { this.listeners.set(type, fn); }
    removeEventListener(type) { this.listeners.delete(type); }
    focus() { this.focused += 1; }
}

function setup({ saved = '', savedPreview = '', savedImage = '' } = {}) {
    const parts = {};
    for (const name of ['filled', 'add', 'image', 'fallback', 'view', 'remove', 'editor', 'input', 'ok', 'cancel', 'error', 'status']) parts[name] = new El();
    parts.value = new El(); parts.value.value = saved;
    parts.editor.hidden = true; parts.error.hidden = true;
    const events = [];
    const docListeners = new Map();
    const slot = new El({ 'data-saved-preview-url': savedPreview, 'data-saved-image': savedImage, 'data-saved-name': 'YouTube', 'data-invalid-message': 'INVALID', 'data-status-removed': 'Removed' });
    slot.querySelector = selector => parts[selector.match(/data-ui-embed-([a-z]+)/)[1]] ?? null;
    slot.closest = () => null;
    slot.contains = node => node === slot || node?.inside === true;
    slot.dispatchEvent = event => events.push(event);
    const doc = { addEventListener: (t, fn) => docListeners.set(t, fn), removeEventListener: t => docListeners.delete(t) };
    const instance = initUiKitEmbedSlot(slot, { createEvent: (type, detail) => ({ type, detail }), document: doc });
    const key = (key) => { const e = { key, prevented: 0, stopped: 0, preventDefault() { this.prevented++; }, stopPropagation() { this.stopped++; } }; parts.editor.listeners.get('keydown')(e); return e; };
    const click = el => el.listeners.get('click')();
    const changes = () => events.filter(e => e.type === UI_EMBED_CHANGE_EVENT).map(e => e.detail.url);
    return { slot, parts, events, instance, key, click, changes, docListeners };
}

const YT = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

test('empty tile: + opens the editor with an empty input and focus', () => {
    const t = setup();
    assert.equal(t.slot.getAttribute('data-state'), 'empty');
    assert.equal(t.parts.filled.hidden, true);
    assert.equal(t.parts.add.hidden, false);
    t.click(t.parts.add);
    assert.equal(t.parts.editor.hidden, false);
    assert.equal(t.parts.add.getAttribute('aria-expanded'), 'true');
    assert.equal(t.parts.input.focused, 1);
    assert.equal(t.parts.input.value, '');
});

test('OK with a valid link fills the tile with its thumbnail, announces the change and moves focus to the tile', () => {
    const t = setup();
    t.click(t.parts.add);
    t.parts.input.value = ' https://youtu.be/dQw4w9WgXcQ ';
    t.click(t.parts.ok);
    assert.equal(t.parts.value.value, YT, 'the normalised link is what gets submitted');
    assert.equal(t.slot.getAttribute('data-state'), 'filled');
    assert.equal(t.parts.editor.hidden, true);
    assert.equal(t.parts.add.hidden, true);
    assert.equal(t.parts.image.getAttribute('src'), 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
    assert.equal(t.parts.image.hidden, false);
    assert.equal(t.parts.fallback.hidden, true);
    assert.deepEqual(t.changes(), [YT]);
    assert.equal(t.parts.view.focused, 1);
});

test('Enter confirms; an invalid link keeps the editor open with the message and changes nothing', () => {
    const t = setup();
    t.click(t.parts.add);
    t.parts.input.value = 'https://example.com/video';
    const enter = t.key('Enter');
    assert.equal(enter.prevented, 1, 'Enter must not submit the surrounding form');
    assert.equal(t.parts.error.textContent, 'INVALID');
    assert.equal(t.parts.error.hidden, false);
    assert.equal(t.parts.input.getAttribute('aria-invalid'), 'true');
    assert.equal(t.parts.editor.hidden, false);
    assert.equal(t.parts.value.value, '');
    assert.deepEqual(t.changes(), []);
    t.parts.input.value = YT;
    t.key('Enter');
    assert.equal(t.parts.value.value, YT);
    assert.equal(t.parts.error.hidden, true);
});

test('Vimeo shows the video icon (no thumbnail); the preview event carries the embed URL', () => {
    const t = setup();
    t.click(t.parts.add);
    t.parts.input.value = 'https://vimeo.com/76979871';
    t.click(t.parts.ok);
    assert.equal(t.parts.image.hidden, true);
    assert.equal(t.parts.fallback.hidden, false);
    t.click(t.parts.view);
    assert.deepEqual(t.events.at(-1), { type: UI_MEDIA_PREVIEW_EVENT, detail: { name: 'Vimeo', url: 'https://player.vimeo.com/video/76979871', kind: 'embed', mimeType: 'text/html' } });
});

test('Escape closes only the editor and stops the event so the dialog stays open; Cancel does the same', () => {
    const t = setup();
    t.click(t.parts.add);
    t.parts.input.value = YT;
    const esc = t.key('Escape');
    assert.equal(esc.prevented, 1);
    assert.equal(esc.stopped, 1);
    assert.equal(t.parts.editor.hidden, true);
    assert.equal(t.parts.value.value, '', 'nothing was committed');
    assert.equal(t.parts.add.focused, 1, 'focus returns to the tile');
    t.click(t.parts.add);
    t.click(t.parts.cancel);
    assert.equal(t.parts.editor.hidden, true);
    assert.deepEqual(t.changes(), []);
});

test('a click outside cancels the editor without committing; a click inside does not', () => {
    const t = setup();
    t.click(t.parts.add);
    t.parts.input.value = YT;
    t.docListeners.get('pointerdown')({ target: { inside: true } });
    assert.equal(t.parts.editor.hidden, false);
    t.docListeners.get('pointerdown')({ target: { inside: false } });
    assert.equal(t.parts.editor.hidden, true);
    assert.equal(t.parts.value.value, '');
});

test('a saved embed shows its saved thumbnail and preview; Remove empties it and announces', () => {
    const t = setup({ saved: YT, savedPreview: 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', savedImage: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg' });
    assert.equal(t.slot.getAttribute('data-state'), 'filled');
    assert.equal(t.parts.view.hidden, false);
    t.click(t.parts.remove);
    assert.equal(t.parts.value.value, '');
    assert.equal(t.slot.getAttribute('data-state'), 'empty');
    assert.equal(t.parts.status.textContent, 'Removed');
    assert.deepEqual(t.changes(), ['']);
    assert.equal(t.parts.add.focused, 1);
});

test('a saved embed that cannot be previewed (blocked by readiness) hides View but keeps Remove', () => {
    const t = setup({ saved: YT, savedPreview: '', savedImage: '' });
    assert.equal(t.parts.view.hidden, true);
    assert.equal(t.parts.remove.hidden, false);
    assert.equal(t.parts.fallback.hidden, false);
});

test('an external clear (a video file was chosen) empties the tile and announces; clearing an empty tile is silent', () => {
    const t = setup({ saved: YT });
    t.slot.listeners.get(UI_EMBED_CLEAR_EVENT)();
    assert.equal(t.parts.value.value, '');
    assert.deepEqual(t.changes(), ['']);
    t.slot.listeners.get(UI_EMBED_CLEAR_EVENT)();
    assert.deepEqual(t.changes(), [''], 'no second announcement');
});

test('init is idempotent per element and destroy removes the listeners', () => {
    const t = setup();
    assert.equal(initUiKitEmbedSlot(t.slot), t.instance);
    t.instance.destroy();
    assert.equal(t.docListeners.size, 0);
    assert.equal(t.slot.listeners.size, 0);
    assert.equal(t.parts.editor.listeners.size, 0);
});
