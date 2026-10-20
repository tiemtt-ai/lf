import test from 'node:test';
import assert from 'node:assert/strict';
import { initUiKitDialogForm, serializeForm, UI_DIALOG_FORM_SAVED_EVENT } from '../../resources/js/admin-ui-kit-dialog-form.js';

// Invariants:
// 1. Only the latest open/submit may touch the page; late responses after a newer request, a close or destroy change nothing.
// 2. A form with unsaved changes is never closed without asking; Escape, the x button and Cancel behave the same.
// 3. While a submit is in flight nothing can close the dialog or send a second request.
// 4. A validation failure keeps the dialog and the user's input, marks the fields, and the next attempt starts clean.
// 5. A successful save closes without asking, shows the server message and announces `ui-dialog-form-saved`.
// 6. Every control created for a form is destroyed when the dialog closes; the dialog is empty when closed.

class El {
    constructor(attrs = {}) {
        this.attrs = new Map(Object.entries(attrs));
        this.listeners = new Map();
        this.children = [];
        this.hidden = false;
        this.disabled = false;
        this.isConnected = true;
        this.textContent = '';
        this.focused = 0;
        this.open = false;
        this.className = '';
        this.id = '';
    }
    getAttribute(name) { return this.attrs.has(name) ? this.attrs.get(name) : null; }
    setAttribute(name, value) { this.attrs.set(name, String(value)); }
    hasAttribute(name) { return this.attrs.has(name); }
    removeAttribute(name) { this.attrs.delete(name); }
    addEventListener(type, fn) { this.listeners.set(type, fn); }
    removeEventListener(type) { this.listeners.delete(type); }
    focus() { this.focused += 1; }
    append(child) { this.children.push(child); child.parent = this; }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter(c => c !== this); }
}

function dialogEl(label = 'dialog') {
    const d = new El({ 'data-msg-load-error': 'LOAD', 'data-msg-save-error': 'SAVE', 'data-msg-session-expired': 'SESSION', 'data-msg-required-input': 'Enter :field.', 'data-msg-required-select': 'Select :field.', 'data-field-id-prefix': 'category-form-', 'data-msg-discard-title': 'DISCARD-TITLE', 'data-msg-discard-text': 'DISCARD-TEXT', 'data-msg-discard': 'DISCARD' });
    d.label = label;
    d.showModal = () => { d.open = true; d.calls.push('showModal'); };
    d.close = () => { if (!d.open) return; d.open = false; d.calls.push('close'); d.listeners.get('close')?.({ target: d }); };
    d.calls = [];
    d.querySelector = () => null;
    d.replaceChildren = (...nodes) => { d.children = nodes; };
    return d;
}

function makeForm({ field = true } = {}) {
    const submit = new El(); const cancel = new El(); const feedbackNode = new El();
    const nameField = new El(); nameField.closest = () => nameField;
    const nameInput = new El(); nameInput.id = 'category-form-name'; nameInput.closest = () => nameField;
    nameInput.name = 'name'; nameInput.value = 'A'; nameInput.tagName = 'INPUT';
    const form = new El({ action: '/admin/course-categories' });
    form.name = nameInput;
    form.nameField = nameField;
    form.submit = submit;
    form.cancel = cancel;
    form.feedbackNode = feedbackNode;
    form.querySelector = selector => {
        if (selector.includes('feedback')) return feedbackNode;
        if (selector.includes('data-ui-dialog-form-progress')) return form.progress;
        if (selector.includes('name="name"')) return field ? nameInput : null;
        if (selector.startsWith('#') && form.byId?.[selector.slice(1)]) return form.byId[selector.slice(1)];
        if (selector.startsWith('label[for=')) return form.labelFor ? form.labelFor(selector) : { textContent: '* Tên danh mục' };
        if (selector.includes('-ui-kit')) return null;
        if (selector === '#category-form-name-error') return nameField.children.at(-1) ?? null;
        if (selector.startsWith('select')) return nameInput;
        return null;
    };
    form.querySelectorAll = selector => {
        if (selector === '[required]') return [nameInput];
        if (selector.includes('data-ui-dialog-form-submit')) return [submit, cancel];
        if (selector.includes('data-ui-dialog-form-error')) return nameField.children;
        if (selector.includes('aria-invalid')) return [nameInput];
        if (selector.includes('select[data-ui-kit-select]')) return [form.selectEl];
        if (selector.includes('input[type="file"]')) return form.fileInputs;
        return [];
    };
    form.fileInputs = [];
    form.progress = new El({ 'data-text-uploading': 'Uploading :percent%', 'data-text-processing': 'Processing', 'data-text-cancelled': 'Upload cancelled' });
    form.progress.hidden = true;
    form.progressBar = new El(); form.progressBar.style = {};
    form.progressText = new El();
    form.progress.querySelector = selector => (selector.includes('progress-bar') ? form.progressBar : form.progressText);
    form.selectEl = new El();
    form.other = new El();
    form.other.scrolled = 0;
    form.other.scrollIntoView = () => { form.other.scrolled += 1; };
    form.byId = { 'category-form-other-ui-kit': form.other };
    return form;
}

function setup({ snapshotValue = 'a', defer = () => Promise.resolve(), hash = '', hashTrigger = false, triggerHidden = false, confirmAnswer = async () => true } = {}) {
    const confirms = [];
    const dialog = dialogEl('main');
    const flash = new El({ hidden: '' }); flash.hidden = true;
    const pageError = new El(); pageError.hidden = true;
    const trigger = new El({ 'data-url': '/admin/course-categories/5/edit' });
    if (triggerHidden) trigger.offsetParent = null;
    const scopeEvents = [];
    const scope = new El();
    scope.id = 'category-list';
    scope.querySelector = selector => {
        if (selector.includes('dialog[data-ui-dialog-form]')) return dialog;
        if (selector.includes('hash-open')) return hashTrigger ? trigger : null;
        if (selector.includes('flash')) return flash;
        if (selector.includes('page-error')) return pageError;
        return null;
    };
    scope.querySelectorAll = () => [trigger];
    scope.contains = () => true;
    const state = { value: snapshotValue };
    const fetches = [];
    const pending = [];
    const forms = [];
    const destroyed = [];
    const nav = [];
    const hashListeners = new Map();
    const docListeners = new Map();
    dialog.dispatchEvent = event => scopeEvents.push(event);
    const win = {
        AbortController: class { constructor() { this.signal = { aborted: false }; } abort() { this.signal.aborted = true; } },
        FormData: class { constructor(form) { this.form = form; } },
        document: { importNode: node => node, createElement: () => new El(), addEventListener: (type, fn) => docListeners.set(type, fn), removeEventListener: type => docListeners.delete(type) },
        location: { assign: url => nav.push(url), hash },
        addEventListener: (type, fn) => hashListeners.set(type, fn),
        removeEventListener: type => hashListeners.delete(type),
        CSS: { escape: x => x },
        fetch: (url, options) => {
            fetches.push({ url, options });
            let resolve; let reject;
            const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
            pending.push({ resolve, reject, options });
            return promise;
        },
    };
    const xhrs = [];
    class FakeXhr {
        constructor() { this.headers = {}; this.upload = {}; this.aborted = 0; xhrs.push(this); }
        open(method, url) { this.method = method; this.url = url; }
        setRequestHeader(name, value) { this.headers[name] = value; }
        send(body) { this.body = body; }
        abort() { this.aborted += 1; this.onabort?.(); }
        progress(loaded, total) { this.upload.onprogress({ lengthComputable: true, loaded, total }); }
        finish(status, payload) { this.status = status; this.responseText = JSON.stringify(payload); this.onload(); }
    }
    const beforeUnload = { added: 0, removed: 0 };
    win.addEventListener = (type, fn) => { if (type === 'beforeunload') beforeUnload.added += 1; else hashListeners.set(type, fn); };
    win.removeEventListener = type => { if (type === 'beforeunload') beforeUnload.removed += 1; else hashListeners.delete(type); };
    const instance = initUiKitDialogForm(scope, {
        window: win,
        XMLHttpRequest: FakeXhr,
        parse: html => ({ querySelector: () => (html === 'NO-FORM' ? null : (forms.push(makeForm()), forms.at(-1))) }),
        serialize: () => state.value,
        initSelect: () => ({ destroy: () => destroyed.push('select') }),
        initSlots: () => [{ destroy: () => destroyed.push('slot') }],
        createEvent: (type, detail) => ({ type, detail }),
        defer,
        confirm: options => { confirms.push(options); return confirmAnswer(options); },
    });
    const click = (target, which) => scope.listeners.get('click')({
        target: { closest: () => target },
    });
    const clickOn = (el, attr) => { el.attrs.set(attr, ''); click(el); el.attrs.delete(attr); };
    const tick = () => new Promise(resolve => setImmediate(resolve));
    const respond = (index, response) => pending[index].resolve(response);
    const html = body => ({ ok: true, redirected: false, text: async () => body });
    const json = (status, payload) => ({ ok: status >= 200 && status < 300, status, json: async () => payload });
    return { xhrs, beforeUnload, confirms, hashListeners, docListeners, win, dialog, scope, scopeEvents, trigger, flash, pageError, fetches, pending, forms, destroyed, nav, state, instance, win, clickOn, tick, respond, html, json };
}

async function opened(t) {
    t.clickOn(t.trigger, 'data-ui-dialog-form-open');
    t.respond(0, t.html('FORM'));
    await t.tick(); await t.tick();
    return t.forms[0];
}

test('open fetches the fragment as XHR, fills the dialog, initialises controls, then shows it', async () => {
    const t = setup();
    const form = await opened(t);
    assert.equal(t.fetches[0].url, '/admin/course-categories/5/edit');
    assert.equal(t.fetches[0].options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.deepEqual(t.dialog.children, [form]);
    assert.deepEqual(t.destroyed, []);
    assert.equal(t.dialog.open, true);
    assert.equal(t.trigger.hasAttribute('aria-busy'), false);
});

test('the latest open wins: an older response arriving late is ignored', async () => {
    const t = setup();
    t.clickOn(t.trigger, 'data-ui-dialog-form-open');
    t.clickOn(t.trigger, 'data-ui-dialog-form-open');
    assert.equal(t.pending[0].options.signal.aborted, true);
    t.respond(1, t.html('SECOND'));
    await t.tick(); await t.tick();
    t.respond(0, t.html('FIRST'));
    await t.tick(); await t.tick();
    assert.equal(t.forms.length, 1);
    assert.equal(t.dialog.calls.filter(c => c === 'showModal').length, 1);
});

test('load failures show a page message and never open an empty dialog', async () => {
    const t = setup();
    t.clickOn(t.trigger, 'data-ui-dialog-form-open');
    t.respond(0, { ok: false, redirected: false, status: 500 });
    await t.tick();
    assert.equal(t.pageError.textContent, 'LOAD');
    assert.equal(t.pageError.hidden, false);
    assert.equal(t.dialog.open, false);
    t.clickOn(t.trigger, 'data-ui-dialog-form-open');
    t.respond(1, t.html('NO-FORM'));
    await t.tick();
    assert.equal(t.dialog.open, false);
    t.clickOn(t.trigger, 'data-ui-dialog-form-open');
    t.respond(2, { ok: true, redirected: true, url: '/login', text: async () => '' });
    await t.tick();
    assert.deepEqual(t.nav, ['/login']);
});

test('closing a clean form needs no question; closing empties the dialog and destroys every control', async () => {
    const t = setup();
    const form = await opened(t);
    t.clickOn(form.cancel, 'data-ui-dialog-form-cancel');
    assert.equal(t.dialog.open, false);
    assert.deepEqual(t.confirms, [], 'a clean form closes without a question');
    assert.deepEqual(t.dialog.children, []);
    assert.deepEqual(t.destroyed.sort(), ['select', 'slot']);
    assert.equal(t.trigger.focused, 1, 'focus returns to the opener');
});

test('closing a dirty form asks through the shared confirm dialog: Cancel keeps editing, the danger action discards', async () => {
    let answer = false;
    const t = setup({ confirmAnswer: async () => answer });
    const form = await opened(t);
    t.state.value = 'changed';
    t.clickOn(form.cancel, 'data-ui-dialog-form-cancel');
    await t.tick();
    assert.deepEqual(t.confirms, [{ title: 'DISCARD-TITLE', message: 'DISCARD-TEXT', confirmLabel: 'DISCARD', tone: 'danger' }]);
    assert.equal(t.dialog.open, true, 'cancelled: still editing');
    answer = true;
    t.clickOn(form.cancel, 'data-ui-dialog-form-cancel');
    await t.tick();
    assert.equal(t.confirms.length, 2);
    assert.equal(t.dialog.open, false);
});

test('a second close request while the question is open is ignored', async () => {
    let release;
    const t = setup({ confirmAnswer: () => new Promise(resolve => { release = resolve; }) });
    const form = await opened(t);
    t.state.value = 'changed';
    t.clickOn(form.cancel, 'data-ui-dialog-form-cancel');
    t.clickOn(form.cancel, 'data-ui-dialog-form-cancel');
    t.dialog.listeners.get('cancel')({ target: t.dialog, preventDefault() {} });
    assert.equal(t.confirms.length, 1);
    release(true);
    await t.tick();
    assert.equal(t.dialog.open, false);
});

test('Escape is intercepted: dirty asks, clean closes', async () => {
    const t = setup({ confirmAnswer: async () => false });
    await opened(t);
    let prevented = 0;
    t.state.value = 'changed';
    t.dialog.listeners.get('cancel')({ target: t.dialog, preventDefault: () => prevented++ });
    await t.tick();
    assert.equal(prevented, 1);
    assert.equal(t.confirms.length, 1);
    assert.equal(t.dialog.open, true);
    t.state.value = 'a';
    t.dialog.listeners.get('cancel')({ target: t.dialog, preventDefault: () => prevented++ });
    assert.equal(t.dialog.open, false);
    assert.equal(t.confirms.length, 1, 'clean: no second question');
});

test('success: POST as JSON, close without asking, show the message, announce the save', async () => {
    const t = setup();
    const form = await opened(t);
    t.state.value = 'changed';
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    assert.equal(t.fetches[1].options.method, 'POST');
    assert.equal(t.fetches[1].options.headers.Accept, 'application/json');
    assert.equal(t.fetches[1].url, '/admin/course-categories');
    assert.equal(form.submit.disabled, true);
    t.respond(1, t.json(201, { message: 'Created' }));
    await t.tick(); await t.tick();
    assert.equal(t.dialog.open, false);
    assert.deepEqual(t.confirms, [], 'a saved form is closed without asking');
    assert.equal(t.flash.textContent, 'Created');
    assert.equal(t.flash.hidden, false);
    assert.deepEqual(t.scopeEvents.map(e => e.type), [UI_DIALOG_FORM_SAVED_EVENT]);
});

test('validation failure keeps the dialog, marks the field, focuses it, and the next attempt starts clean', async () => {
    const t = setup();
    const form = await opened(t);
    const focusedBefore = form.name.focused;
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    t.respond(1, t.json(422, { errors: { name: ['Name is required'], unknown_field: ['Other problem'] } }));
    await t.tick(); await t.tick();
    assert.equal(t.dialog.open, true);
    assert.equal(form.submit.disabled, false);
    assert.equal(form.name.getAttribute('aria-invalid'), 'true');
    assert.equal(form.name.getAttribute('aria-describedby'), 'category-form-name-error');
    assert.equal(form.nameField.children.at(-1).textContent, 'Name is required');
    assert.equal(form.feedbackNode.textContent, 'Other problem');
    assert.equal(form.name.focused, focusedBefore + 1);
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    assert.equal(form.nameField.children.length, 0, 'old messages are removed before retrying');
    assert.equal(form.name.getAttribute('aria-invalid'), 'false');
    assert.equal(form.feedbackNode.textContent, '');
});

test('while a submit is in flight a second submit, Cancel and Escape do nothing', async () => {
    const t = setup();
    const form = await opened(t);
    t.state.value = 'changed';
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    assert.equal(t.fetches.length, 2, 'only one POST');
    t.clickOn(form.cancel, 'data-ui-dialog-form-cancel');
    t.dialog.listeners.get('cancel')({ target: t.dialog, preventDefault() {} });
    assert.equal(t.dialog.open, true);
    assert.deepEqual(t.confirms, [], 'nothing can ask while saving');
});

test('a response arriving after the dialog was closed or destroyed changes nothing', async () => {
    const t = setup();
    const form = await opened(t);
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    t.instance.destroy();
    t.respond(1, t.json(201, { message: 'Created' }));
    await t.tick(); await t.tick();
    assert.equal(t.flash.textContent, '');
    assert.deepEqual(t.scopeEvents, []);
});

test('expired session and network failures give a message and keep the dialog', async () => {
    const t = setup();
    const form = await opened(t);
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    t.respond(1, t.json(419, { message: 'CSRF token mismatch.' }));
    await t.tick(); await t.tick();
    assert.equal(form.feedbackNode.textContent, 'SESSION');
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    t.pending[2].reject(new TypeError('offline'));
    await t.tick(); await t.tick();
    assert.equal(form.feedbackNode.textContent, 'SAVE');
    assert.equal(form.submit.disabled, false);
    assert.equal(t.dialog.open, true);
});

test('focus returns to the matching opener when the original row was replaced', async () => {
    const t = setup();
    const form = await opened(t);
    const replacement = new El({ 'data-url': '/admin/course-categories/5/edit' });
    t.scope.querySelectorAll = () => [replacement];
    t.trigger.isConnected = false;
    t.clickOn(form.cancel, 'data-ui-dialog-form-cancel');
    assert.equal(replacement.focused, 1);
    assert.equal(t.trigger.focused, 0);
});

test('serializeForm ignores the token and method and describes files by name, size and date', () => {
    class FD {
        constructor() {
            this.rows = [['_token', 'x'], ['_method', 'PUT'], ['name', 'A'], ['empty', { name: '', size: 0, lastModified: 0 }], ['file', { name: 'a.png', size: 3, lastModified: 9 }]];
        }
        [Symbol.iterator]() { return this.rows[Symbol.iterator](); }
    }
    assert.equal(serializeForm({}, FD), 'name=A\nempty=\nfile=a.png:3:9');
});

test('an open that is replaced while its controls are settling never shows the older form', async () => {
    let release;
    const t = setup({ defer: () => new Promise(resolve => { release = resolve; }) });
    t.clickOn(t.trigger, 'data-ui-dialog-form-open');
    t.respond(0, t.html('FIRST'));
    await t.tick(); await t.tick();
    const releaseFirst = release;
    t.clickOn(t.trigger, 'data-ui-dialog-form-open');
    releaseFirst();
    await t.tick(); await t.tick();
    assert.equal(t.dialog.open, false, 'the superseded open does not show the dialog');
    t.respond(1, t.html('SECOND'));
    await t.tick(); await t.tick();
    release();
    await t.tick(); await t.tick();
    assert.equal(t.dialog.open, true);
    assert.equal(t.dialog.calls.filter(c => c === 'showModal').length, 1);
});

test('an existing description (such as a format hint) survives error marking and clearing', async () => {
    const t = setup();
    const form = await opened(t);
    form.name.setAttribute('aria-describedby', 'category-form-name-hint');
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    t.respond(1, t.json(422, { errors: { name: ['Required'] } }));
    await t.tick(); await t.tick();
    assert.equal(form.name.getAttribute('aria-describedby'), 'category-form-name-hint category-form-name-error');
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    assert.equal(form.name.getAttribute('aria-describedby'), 'category-form-name-hint');
});

test('an empty required field is reported inline in the page language, without a request', async () => {
    const t = setup();
    const form = await opened(t);
    form.name.value = '   ';
    const focusedBefore = form.name.focused;
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    assert.equal(t.fetches.length, 1, 'only the form load, nothing is posted');
    assert.equal(form.nameField.children.at(-1).textContent, 'Enter tên danh mục.');
    assert.equal(form.name.getAttribute('aria-invalid'), 'true');
    assert.equal(form.name.getAttribute('aria-describedby'), 'category-form-name-error');
    assert.equal(form.name.focused, focusedBefore + 1);
    assert.equal(form.submit.disabled, false);
    assert.equal(t.dialog.open, true);
});

test('typing a value clears that field message; a blank value keeps it', async () => {
    const t = setup();
    const form = await opened(t);
    form.name.value = '';
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    form.name.value = '  ';
    t.dialog.listeners.get('input')({ target: form.name });
    assert.equal(form.name.getAttribute('aria-invalid'), 'true');
    form.name.value = 'Korean';
    t.dialog.listeners.get('input')({ target: form.name });
    assert.equal(form.name.getAttribute('aria-invalid'), 'false');
    assert.equal(form.name.getAttribute('aria-describedby'), null);
    assert.equal(form.nameField.children.length, 0);
});

test('a required select uses the select wording and a filled form proceeds to the request', async () => {
    const t = setup();
    const form = await opened(t);
    form.name.value = '';
    form.name.tagName = 'SELECT';
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    assert.equal(form.nameField.children.at(-1).textContent, 'Select tên danh mục.');
    form.name.value = 'Korean';
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    assert.equal(t.fetches.length, 2);
    assert.equal(form.nameField.children.length, 0, 'the old message is gone when the form is valid');
});

test('a save that answers with a redirect navigates there and shows nothing in the list', async () => {
    const t = setup();
    const form = await opened(t);
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    t.respond(1, t.json(201, { message: 'Created', redirect: '/admin/course-templates/9/edit' }));
    await t.tick(); await t.tick();
    assert.deepEqual(t.nav, ['/admin/course-templates/9/edit']);
    assert.equal(t.flash.hidden, true);
    assert.deepEqual(t.scopeEvents, [], 'no list refresh: the page is leaving');
});

test('the label of a select adapted into a combobox is still found for the required message', async () => {
    const t = setup();
    const form = await opened(t);
    form.name.value = '';
    form.name.tagName = 'SELECT';
    form.labelFor = selector => (selector.includes('-ui-kit') ? { textContent: '* Danh mục' } : null);
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    assert.equal(form.nameField.children.at(-1).textContent, 'Select danh mục.');
});

test('a trigger can name the field to focus; the id prefix and the combobox form of the id are tried', async () => {
    const t = setup();
    t.trigger.attrs.set('data-ui-dialog-form-focus', 'other');
    t.clickOn(t.trigger, 'data-ui-dialog-form-open');
    t.respond(0, t.html('FORM'));
    await t.tick(); await t.tick();
    const form = t.forms[0];
    assert.equal(form.other.focused, 1);
    assert.equal(form.other.scrolled, 1);
    assert.equal(form.name.focused, 0, 'the default first field is not focused as well');
});

test('an unknown field name falls back to the first field', async () => {
    const t = setup();
    t.trigger.attrs.set('data-ui-dialog-form-focus', 'does_not_exist');
    t.clickOn(t.trigger, 'data-ui-dialog-form-open');
    t.respond(0, t.html('FORM'));
    await t.tick(); await t.tick();
    assert.equal(t.forms[0].name.focused, 1);
});

test('a link hash opens the form on that field when the page loads, and again on hashchange', async () => {
    const t = setup({ hash: '#other', hashTrigger: true });
    assert.equal(t.fetches.length, 1, 'opened on init');
    t.respond(0, t.html('FORM'));
    await t.tick(); await t.tick();
    assert.equal(t.forms[0].other.focused, 1);
    t.instance.destroy();
    assert.equal(t.hashListeners.size, 0);

    const later = setup({ hash: '', hashTrigger: true });
    assert.equal(later.fetches.length, 0);
    later.win.location.hash = '#other';
    later.hashListeners.get('hashchange')();
    assert.equal(later.fetches.length, 1);
});

test('no auto-open without a hash, without the marked trigger, or when the trigger is not visible', () => {
    assert.equal(setup({ hash: '', hashTrigger: true }).fetches.length, 0);
    assert.equal(setup({ hash: '#other', hashTrigger: false }).fetches.length, 0);
    assert.equal(setup({ hash: '#other', hashTrigger: true, triggerHidden: true }).fetches.length, 0, 'the Information tab is not the visible one');
});

test('a heading button outside the scope opens this scope\'s dialog only when it names the scope; destroy stops listening', async () => {
    const t = setup();
    const heading = new El({ 'data-url': '/admin/course-categories/create', 'data-ui-dialog-form-for': 'category-list' });
    const other = new El({ 'data-url': '/admin/other/create', 'data-ui-dialog-form-for': 'other-list' });
    const fake = el => ({ target: { closest: selector => (selector.includes(`for="${el.attrs.get('data-ui-dialog-form-for')}"`) ? el : null) } });
    const click = (el, inside) => { t.scope.contains = () => inside; t.docListeners.get('click')(fake(el)); };
    click(other, false);
    assert.equal(t.fetches.length, 0, 'a button naming another scope is ignored');
    click(heading, true);
    assert.equal(t.fetches.length, 0, 'a button inside the scope is left to the scope handler');
    click(heading, false);
    assert.equal(t.fetches.length, 1);
    assert.equal(t.fetches[0].url, '/admin/course-categories/create');
    t.instance.destroy();
    assert.equal(t.docListeners.size, 0);
});

async function openedWithFile(t) {
    const form = await opened(t);
    form.fileInputs.push({ files: [{ name: 'big.mp4' }] });
    t.state.value = 'changed';
    return form;
}

test('a chosen file is sent by XHR with progress; nothing can close the dialog meanwhile; success closes it', async () => {
    const t = setup();
    const form = await openedWithFile(t);
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    assert.equal(t.fetches.length, 1, 'only the form fetch happened, the save went by XHR');
    const xhr = t.xhrs[0];
    assert.equal(xhr.method, 'POST');
    assert.equal(xhr.url, '/admin/course-categories');
    assert.equal(xhr.headers.Accept, 'application/json');
    assert.equal(xhr.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(xhr.withCredentials, true);
    assert.equal(form.progress.hidden, false);
    assert.equal(form.submit.disabled, true);
    assert.equal(t.beforeUnload.added, 1, 'leaving the page is warned about while uploading');
    xhr.progress(40, 100);
    assert.equal(form.progressBar.style.width, '40%');
    assert.equal(form.progressBar.getAttribute('aria-valuenow'), '40');
    assert.equal(form.progressText.textContent, 'Uploading 40%');
    t.dialog.listeners.get('cancel')({ target: t.dialog, preventDefault() {} });
    await t.tick();
    assert.equal(t.dialog.open, true, 'Escape does nothing while uploading');
    xhr.progress(100, 100);
    assert.equal(form.progressText.textContent, 'Processing', 'all bytes sent: the server is still working');
    xhr.finish(201, { message: 'Created' });
    await t.tick(); await t.tick();
    assert.equal(t.beforeUnload.removed, 1);
    assert.equal(t.dialog.open, false);
    assert.equal(t.flash.textContent, 'Created');
});

test('cancelling the upload aborts it, keeps the dialog and the input, and says so without an error', async () => {
    const t = setup();
    const form = await openedWithFile(t);
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    const cancel = new El({ 'data-ui-dialog-form-upload-cancel': '' });
    t.xhrs[0].progress(10, 100);
    t.scope.listeners.get('click')({ target: { closest: () => cancel } });
    await t.tick(); await t.tick();
    assert.equal(t.xhrs[0].aborted, 1);
    assert.equal(t.dialog.open, true);
    assert.equal(form.progress.hidden, true);
    assert.equal(form.submit.disabled, false);
    assert.equal(form.cancel.disabled, false);
    assert.equal(form.feedbackNode.textContent, 'Upload cancelled');
    assert.equal(t.beforeUnload.removed, 1);
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    assert.equal(t.xhrs.length, 2, 'the user can try again');
});

test('a validation failure or a network error during an upload is reported and the form is usable again', async () => {
    const t = setup();
    const form = await openedWithFile(t);
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    t.xhrs[0].finish(422, { errors: { name: ['Name taken'] } });
    await t.tick(); await t.tick();
    assert.equal(t.dialog.open, true);
    assert.equal(form.progress.hidden, true);
    assert.equal(form.submit.disabled, false);
    assert.equal(form.nameField.children.at(-1).textContent, 'Name taken');
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    t.xhrs[1].onerror();
    await t.tick(); await t.tick();
    assert.equal(form.feedbackNode.textContent, 'SAVE');
    assert.equal(form.submit.disabled, false);
});

test('without a chosen file the save still uses fetch (no progress UI)', async () => {
    const t = setup();
    const form = await opened(t);
    t.state.value = 'changed';
    t.scope.listeners.get('submit')({ target: form, preventDefault() {} });
    assert.equal(t.xhrs.length, 0);
    assert.equal(t.fetches.length, 2);
    assert.equal(form.progress.hidden, true);
});

test('opening another form while one is shown (View -> Edit) replaces it and the close of the old one does not tear the new one down', async () => {
    const t = setup();
    await opened(t);
    t.dialog.close = () => { if (!t.dialog.open) return; t.dialog.open = false; t.dialog.calls.push('close'); queueMicrotask(() => t.dialog.listeners.get('close')?.({ target: t.dialog })); };
    t.clickOn(t.trigger, 'data-ui-dialog-form-open');
    t.respond(1, t.html('FORM2'));
    await t.tick(); await t.tick(); await t.tick();
    assert.equal(t.forms.length, 2);
    assert.equal(t.dialog.open, true, 'the second form is shown');
    assert.equal(t.dialog.children.at(-1), t.forms[1], 'and it is still the dialog content after the old close event fired');
});
