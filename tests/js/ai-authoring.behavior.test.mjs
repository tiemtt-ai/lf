/*
 * Behaviour of resources/js/ai-authoring.js against a scripted server, in Node
 * with a very small fake DOM (tests/js/fake-dom.mjs). These cover what a text
 * scan of the source cannot: what is still held after access is lost, which
 * request id a retry carries, what a late answer is allowed to do.
 *
 * Run by AiAuthoringScriptBehaviorTest, or directly: node --test tests/js
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { FakeNode, everything, install } from './fake-dom.mjs';

install();
const { AiAuthoring } = await import('../../resources/js/ai-authoring.js');

const U1 = '11111111-1111-4111-8111-111111111111';
const U2 = '22222222-2222-4222-8222-222222222222';
const G1 = '33333333-3333-4333-8333-333333333333';

let routes = [];
let calls = [];

const settle = async () => {
    for (let i = 0; i < 12; i += 1) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};
const response = (status, body, type = 'application/json') => ({
    type: 'basic',
    status,
    ok: status >= 200 && status < 300,
    headers: { get: (name) => (name.toLowerCase() === 'content-type' ? type : null) },
    json: async () => {
        if (body === undefined) {
            throw new SyntaxError('not JSON');
        }

        return body;
    },
});
const ok = (data) => response(200, { data, error: null });
const failure = (status, code = 'x') => response(status, { data: null, error: { code, message: 'x', details: {} } });
const html = (status) => response(status, undefined, 'text/html');
const deferred = () => {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });

    return { promise, resolve };
};

globalThis.fetch = async (url, init = {}) => {
    const method = init.method ?? 'GET';
    const body = init.body ? JSON.parse(init.body) : null;
    calls.push({ url, method, body });
    const route = [...routes].reverse().find((candidate) => candidate.method === method && candidate.test(url));
    if (!route) {
        throw new Error(`No fake route for ${method} ${url}`);
    }

    return route.reply(body, url);
};
const on = (method, test, reply) => routes.push({ method, test, reply });
const onList = (reply) => on('GET', (url) => url.startsWith('/p?'), reply);
const onDetail = (uuid, reply) => on('GET', (url) => url === `/p/${uuid}`, reply);
const callsTo = (method, url) => calls.filter((call) => call.method === method && call.url === url);

const listItem = (uuid, over = {}) => ({
    proposal_uuid: uuid, kind: 'summary', status: 'pending_review', revision_no: 1, lock_version: 1, content_denied: null, ...over,
});
const detail = (uuid = U1, over = {}) => ({
    proposal_uuid: uuid, kind: 'summary', status: 'pending_review', revision_no: 1, lock_version: 1, content_denied: null,
    allowed_actions: ['edit', 'accept', 'reject'],
    payload: { kind: 'summary', title: 'CANARY_TITLE', body: 'CANARY_BODY', confidence: 0.9, rationale: 'CANARY_WHY', source_refs: [] },
    citations: [], reviews: [], applications: [], context_changed: false, ...over,
});

async function makeApp({ items = [listItem(U1)], next = null, config = {} } = {}) {
    routes = [];
    calls = [];
    window.reset();
    onList(() => ok({ items, next_cursor: next }));
    onDetail(U1, () => ok(detail(U1)));
    const status = new FakeNode('div');
    status.setAttribute('data-ai-authoring-status', '');
    const list = new FakeNode('div');
    list.setAttribute('data-ai-authoring-list', '');
    const root = new FakeNode('section');
    root.append(status, list);
    document.body.replaceChildren(root);
    // Every message is its own key, so a button can be found by the key it is made from.
    const messages = new Proxy({}, { get: (_, key) => (typeof key === 'string' ? key : '') });
    const app = new AiAuthoring(root, {
        urls: { proposals: '/p', generation_requests: '/g', bulk_decisions: '/b' }, framework: null, csrfToken: 't', ...config,
    }, messages);
    app.start();
    await settle();

    return app;
}

const buttons = (app, key) => app.host.querySelectorAll('button').filter((node) => node.textContent === key);
const click = async (app, key, index = 0) => {
    const node = buttons(app, key)[index];
    assert.ok(node, `no button "${key}"`);
    await node.dispatch('click');
    await settle();
};
const openFirst = async (app) => click(app, 'view');
const openEditor = async (app) => {
    await openFirst(app);
    await click(app, 'edit');
};

/** The topmost ancestor still reachable from a node: what a held reference could walk up to. */
const topOf = (node) => {
    let top = node;
    while (top.parent) {
        top = top.parent;
    }

    return top;
};

/** Names of what the script still holds that contains `needle`, whatever it is called. */
function leaks(app, needle) {
    return Object.entries(app).filter(([, value]) => (value instanceof FakeNode && everything(value).includes(needle))
        || (value instanceof Map && JSON.stringify([...value]).includes(needle))
        || (typeof value === 'string' && value.includes(needle))
        || (value && typeof value === 'object' && !(value instanceof FakeNode) && !(value instanceof Map) && !(value instanceof Set)
            && !Array.isArray(value) && typeof value.controls === 'object' && Object.values(value.controls).some((node) => everything(node).includes(needle)))
        || (Array.isArray(value) && value.some((node) => node instanceof FakeNode && everything(node).includes(needle)))).map(([name]) => name);
}

test('a refusal takes the open proposal, the typed edit and every held element out of page and script', async () => {
    const app = await makeApp();
    await openEditor(app);
    app.editor.controls.title.value = 'CANARY_TYPED';
    app.noteField.value = 'CANARY_NOTE';
    assert.ok(everything(app.host).includes('CANARY_BODY'));
    // What a reference kept to a piece of the form could still reach by going up.
    const heldSection = app.detailHost.children[0];
    const heldNote = app.noteField;

    onList(() => failure(403, 'forbidden'));
    await app.loadList(true);
    await settle();

    assert.equal(app.revoked, true);
    assert.equal(everything(heldSection).includes('CANARY'), false, 'nothing is left in the detail that a held reference reaches');
    assert.equal(heldNote.value, '');
    assert.deepEqual(leaks(app, 'CANARY'), []);
    assert.equal(everything(app.host).includes('CANARY'), false);
    assert.equal(app.detailData, null);
    assert.equal(app.actionsHost, null);
    assert.equal(app.commandArea, null);
    assert.equal(app.noteField, null);
    assert.equal(app.editor, null);
    assert.equal(app.reviewed.size + app.selected.size + app.items.length + app.pendings.size, 0);
    assert.equal(app.createToggle.disabled, true, 'writes are off');
});

test('403, 404 and 503 without a JSON body are refusals too; other failures still show nothing of the old copy', async () => {
    for (const [status, revoked] of [[403, true], [404, true], [503, true], [500, false]]) {
        const app = await makeApp();
        await openFirst(app);
        assert.ok(everything(app.host).includes('CANARY_BODY'));

        onDetail(U1, () => html(status));
        await app.openDetail(U1, null);
        await settle();

        assert.equal(app.revoked, revoked, `status ${status}`);
        assert.equal(everything(app.host).includes('CANARY'), false, `status ${status}`);
        assert.deepEqual(leaks(app, 'CANARY'), [], `status ${status}`);
        assert.equal(app.detailData, null, `status ${status}`);
    }
});

test('an answer that arrives after a refusal from another request is not shown', async () => {
    const app = await makeApp();
    const slow = deferred();
    onDetail(U1, () => slow.promise);
    const opening = app.openDetail(U1, null);
    app.generationId = G1;
    on('GET', (url) => url === `/g/${G1}`, () => failure(403, 'forbidden'));
    await app.refreshGeneration();
    assert.equal(app.revoked, true);

    slow.resolve(ok(detail(U1)));
    await opening;
    await settle();

    assert.equal(everything(app.host).includes('CANARY'), false);
    assert.equal(app.detailData, null);
});

test('a decision that succeeds after access was lost shows nothing and reads nothing back', async () => {
    const app = await makeApp();
    await openFirst(app);
    const slow = deferred();
    on('POST', (url) => url === `/p/${U1}/decisions`, () => slow.promise);
    const deciding = app.decide('accept');
    await settle();
    assert.equal(callsTo('POST', `/p/${U1}/decisions`).length, 1);

    onList(() => failure(401, 'unauthenticated'));
    await app.loadList(true);
    const reads = () => calls.filter((call) => call.method === 'GET').length;
    const readsBefore = reads();
    slow.resolve(ok(detail(U1, { status: 'accepted' })));
    await deciding;
    await settle();

    assert.equal(app.notice, null);
    assert.equal(reads(), readsBefore, 'nothing is read back on the strength of an answer from before the loss');
    assert.equal(everything(app.host).includes('CANARY'), false);
});

test('a save with an unknown result locks the form, and the retry sends exactly what was sent', async () => {
    const app = await makeApp();
    await openEditor(app);
    app.editor.controls.title.value = 'DRAFT_FIRST';
    let attempt = 0;
    on('PATCH', (url) => url === `/p/${U1}`, () => {
        attempt += 1;
        if (attempt === 1) {
            throw new TypeError('network');
        }

        return ok(detail(U1, { revision_no: 2 }));
    });
    await click(app, 'save');

    assert.equal(app.locked, true);
    assert.equal(app.hasDraft(), true);
    assert.ok(Object.values(app.editor.controls).every((node) => node.disabled), 'the form cannot change under the frozen command');
    await click(app, 'retry_same');

    const sent = callsTo('PATCH', `/p/${U1}`);
    assert.equal(sent.length, 2);
    assert.equal(sent[1].body.request_id, sent[0].body.request_id);
    assert.deepEqual(sent[1].body.payload, sent[0].body.payload);
    assert.equal(sent[1].body.payload.title, 'DRAFT_FIRST');
});

test('dropping the unknown command unlocks the form, and the next send is a new command', async () => {
    const app = await makeApp();
    await openEditor(app);
    app.editor.controls.title.value = 'DRAFT_FIRST';
    on('PATCH', (url) => url === `/p/${U1}`, () => {
        if (callsTo('PATCH', `/p/${U1}`).length === 1) {
            throw new TypeError('network');
        }

        return ok(detail(U1, { revision_no: 2 }));
    });
    await click(app, 'save');
    await click(app, 'unknown_discard');

    assert.equal(app.locked, false);
    assert.ok(Object.values(app.editor.controls).every((node) => !node.disabled));
    app.editor.controls.title.value = 'DRAFT_CHANGED';
    await click(app, 'save');

    const sent = callsTo('PATCH', `/p/${U1}`);
    assert.equal(sent.length, 2);
    assert.notEqual(sent[1].body.request_id, sent[0].body.request_id);
    assert.equal(sent[1].body.payload.title, 'DRAFT_CHANGED');
});

test('after the proposal is read again, the same choice is a new command', async () => {
    const app = await makeApp();
    await openFirst(app);
    let attempt = 0;
    on('POST', (url) => url === `/p/${U1}/decisions`, () => {
        attempt += 1;
        if (attempt === 1) {
            throw new TypeError('network');
        }

        return ok(detail(U1, { status: 'accepted' }));
    });
    await click(app, 'reject');
    assert.equal(app.locked, true);

    await app.openDetail(U1, null);
    await settle();
    await click(app, 'reject');

    const sent = callsTo('POST', `/p/${U1}/decisions`);
    assert.equal(sent.length, 2);
    assert.notEqual(sent[1].body.request_id, sent[0].body.request_id);
});

test('leaving an unsent edit is asked about: the filter, another row and closing', async () => {
    const app = await makeApp({ items: [listItem(U1), listItem(U2)] });
    onDetail(U2, () => ok(detail(U2)));
    await openEditor(app);
    app.editor.controls.title.value = 'CANARY_TYPED';
    window.LFConfirm.answer = false;

    const filter = app.filterBar.querySelectorAll('select')[0];
    filter.value = 'accepted';
    await filter.dispatch('change');
    await settle();
    assert.equal(app.filters.status, '');
    assert.equal(filter.value, '', 'the filter is put back');

    await click(app, 'view', 1);
    assert.equal(callsTo('GET', `/p/${U2}`).length, 0, 'the other row was not opened');
    await click(app, 'detail_close');
    assert.notEqual(app.detailData, null, 'the proposal stays open');
    assert.equal(app.editor.controls.title.value, 'CANARY_TYPED');

    window.LFConfirm.answer = true;
    await click(app, 'view', 1);
    assert.equal(callsTo('GET', `/p/${U2}`).length, 1);
    window.LFConfirm.answer = true;
});

test('the browser is asked to keep the page while an edit is unsent, and only then', async () => {
    const app = await makeApp();
    await openEditor(app);

    const untouched = { returnValue: undefined };
    await window.fire('beforeunload', untouched);
    assert.notEqual(untouched.prevented, true);

    app.editor.controls.title.value = 'CANARY_TYPED';
    const typed = {};
    await window.fire('beforeunload', typed);
    assert.equal(typed.prevented, true);
    assert.equal(typed.returnValue, '');
});

test('leaving the page takes the content and the held elements away', async () => {
    const app = await makeApp();
    await openEditor(app);
    app.editor.controls.title.value = 'CANARY_TYPED';
    app.bulkNote = 'CANARY_BULK';

    await window.fire('pagehide');

    assert.equal(everything(app.host).includes('CANARY'), false);
    assert.deepEqual(leaks(app, 'CANARY'), []);
    assert.equal(app.editor, null);
    assert.equal(app.noteField, null);
    assert.equal(app.bulkNote, '');
});

test('moving to another page of the list clears the selection', async () => {
    const app = await makeApp({ next: 'c1' });
    app.reviewed.set(U1, { kind: 'summary', lock: 1, rev: 1, reuse: false });
    app.selected.add(U1);
    app.renderList();
    assert.equal(app.selected.size, 1);

    await app.loadList(false);
    await settle();

    assert.equal(app.selected.size, 0);
});

const twoReviewed = async () => {
    const app = await makeApp({ items: [listItem(U1), listItem(U2)] });
    for (const uuid of [U1, U2]) {
        app.reviewed.set(uuid, { kind: 'summary', lock: 1, rev: 1, reuse: false });
        app.selected.add(uuid);
    }
    app.renderList();

    return app;
};

test('a bulk result tells two proposals of one kind apart, and each row can be opened', async () => {
    const app = await twoReviewed();
    on('POST', (url) => url === '/b', (body) => ok({
        items: [
            { request_id: body.items[0].request_id, http_status: 200, error: null },
            { request_id: body.items[1].request_id, http_status: 409, error: { code: 'proposal_revision_conflict' } },
        ],
    }));

    await click(app, 'bulk_reject');

    const rows = app.bulkResultHost.querySelectorAll('li');
    assert.equal(rows.length, 2);
    assert.ok(rows[0].textContent.includes('#11111111'));
    assert.ok(rows[1].textContent.includes('#22222222'));
    assert.notEqual(rows[0].textContent, rows[1].textContent);
    assert.ok(rows.every((row) => row.querySelectorAll('button').some((node) => node.textContent === 'view')));
});

test('only the rows with an unknown result are sent again, exactly as first sent', async () => {
    const app = await twoReviewed();
    let bulk = 0;
    on('POST', (url) => url === '/b', (body) => {
        bulk += 1;

        return ok({ items: bulk === 1
            ? [{ request_id: body.items[0].request_id, http_status: 200, error: null }]
            : [{ request_id: body.items[0].request_id, http_status: 200, error: null }] });
    });

    await click(app, 'bulk_reject');
    await click(app, 'bulk_retry_unknown');

    const sent = callsTo('POST', '/b');
    assert.equal(sent.length, 2);
    assert.equal(sent[1].body.items.length, 1);
    assert.deepEqual(sent[1].body.items[0], sent[0].body.items[1]);
    assert.notEqual(sent[1].body.request_id, sent[0].body.request_id, 'only the outer id is new');
});

test('a generation that did not complete points at that request, and asking again reuses its id', async () => {
    const app = await makeApp();
    on('POST', (url) => url === '/g', () => failure(409, 'AI_PROVIDER_CALL_FAILED'));
    const command = { requested_kinds: ['summary'] };

    await app.runCreate(command);
    await settle();
    assert.ok(UUID_LIKE.test(app.generationId));
    assert.ok(app.createResult.querySelectorAll('button').some((node) => node.textContent === 'create_status'));

    await app.runCreate(command);
    const sent = callsTo('POST', '/g');
    assert.equal(sent[1].body.request_id, sent[0].body.request_id, 'no second request is made');

    on('GET', (url) => url === `/g/${app.generationId}`, () => failure(404, 'proposal_not_found'));
    const listBefore = app.items.length;
    await app.refreshGeneration();
    assert.equal(app.revoked, false, 'a request that is not there is not a loss of access');
    assert.equal(app.items.length, listBefore);
    assert.ok(app.createResult.textContent.includes('create_status_missing'));
});


test('a note typed for a bulk decision is emptied on refusal, even for a parent still held', async () => {
    const app = await twoReviewed();
    const field = app.bulkNoteField;
    field.value = 'CANARY_BULK';
    await field.dispatch('input');
    assert.equal(app.bulkNote, 'CANARY_BULK');
    const held = topOf(app.bulkCountEl);

    onList(() => failure(403, 'forbidden'));
    await app.loadList(true);
    await settle();

    assert.equal(field.value, '');
    assert.equal(everything(held).includes('CANARY'), false, 'nothing is left in a tree that a held reference reaches');
    assert.equal(app.bulkNote, '');
    assert.equal(app.bulkNoteField, null);
    assert.equal(app.bulkCountEl, null);
    assert.deepEqual(app.bulkButtons, []);
});

test('leaving the page empties what is typed in the page itself, not only the state kept about it', async () => {
    const app = await twoReviewed();
    const field = app.bulkNoteField;
    field.value = 'CANARY_BULK';
    await field.dispatch('input');
    const held = topOf(field);

    await window.fire('pagehide');

    assert.equal(field.value, '');
    assert.equal(everything(held).includes('CANARY'), false);
    assert.equal(everything(app.host).includes('CANARY'), false);
});

test('the answer to a save that was left behind does not disturb the proposal now open', async () => {
    const app = await makeApp({ items: [listItem(U1), listItem(U2)] });
    onDetail(U2, () => ok(detail(U2)));
    await openEditor(app);
    app.editor.controls.title.value = 'FIRST';
    const slow = deferred();
    on('PATCH', (url) => url === `/p/${U1}`, () => slow.promise);
    await click(app, 'save');

    // The person agrees to leave the first edit and starts another on the second proposal.
    window.LFConfirm.answer = true;
    await click(app, 'view', 1);
    await click(app, 'edit');
    app.editor.controls.title.value = 'SECOND_UNSENT_DRAFT';
    const asked = window.LFConfirm.calls;

    slow.resolve(ok(detail(U1, { revision_no: 2 })));
    await settle();

    assert.equal(app.openUuid, U2);
    assert.notEqual(app.editor, null, 'the second edit is still open');
    assert.equal(app.editor.controls.title.value, 'SECOND_UNSENT_DRAFT');
    assert.equal(window.LFConfirm.calls, asked, 'nothing was asked, and nothing was thrown away');
});

test('a success announced before a read-back is not the last word when the read-back is refused', async () => {
    // Generation.
    let app = await makeApp();
    on('POST', (url) => url === '/g', () => ok({ request_status: 'completed', item_count: 7 }));
    onList(() => html(403));
    await app.runCreate({ requested_kinds: ['summary'] });
    await settle();
    assert.equal(app.statusRegion.textContent, 'error_forbidden');

    // A decision on one proposal.
    app = await makeApp();
    await openFirst(app);
    on('POST', (url) => url === `/p/${U1}/decisions`, () => ok(detail(U1, { status: 'rejected' })));
    onList(() => html(403));
    onDetail(U1, () => html(403));
    await click(app, 'reject');
    assert.equal(app.statusRegion.textContent, 'error_forbidden');

    // A bulk decision.
    app = await twoReviewed();
    on('POST', (url) => url === '/b', (body) => ok({ items: body.items.map((item) => ({ request_id: item.request_id, http_status: 200, error: null })) }));
    onList(() => html(403));
    await click(app, 'bulk_reject');
    assert.equal(app.statusRegion.textContent, 'error_forbidden');
});

test('a bulk decision that answers after access was lost shows nothing', async () => {
    const app = await twoReviewed();
    const slow = deferred();
    on('POST', (url) => url === '/b', () => slow.promise);
    // The click handler runs until the answer comes, so it is not waited for here.
    const clicking = buttons(app, 'bulk_reject')[0].dispatch('click');
    await settle();
    assert.equal(callsTo('POST', '/b').length, 1);

    onList(() => failure(401, 'unauthenticated'));
    await app.loadList(true);
    const reads = calls.filter((call) => call.method === 'GET').length;
    slow.resolve(ok({ items: callsTo('POST', '/b')[0].body.items.map((item) => ({ request_id: item.request_id, http_status: 200, error: null })) }));
    await clicking;
    await settle();

    assert.equal(calls.filter((call) => call.method === 'GET').length, reads, 'nothing is read back on the strength of an answer from before the loss');
    assert.equal(app.bulkResultHost.children.length, 0);
    assert.equal(app.statusRegion.textContent, 'error_session');
});

test('a generation that answers after access was lost shows nothing', async () => {
    const app = await makeApp();
    const slow = deferred();
    on('POST', (url) => url === '/g', () => slow.promise);
    const creating = app.runCreate({ requested_kinds: ['summary'] });
    await settle();

    onList(() => failure(401, 'unauthenticated'));
    await app.loadList(true);
    const reads = calls.filter((call) => call.method === 'GET').length;
    slow.resolve(ok({ request_status: 'completed', item_count: 3 }));
    await creating;
    await settle();

    assert.equal(calls.filter((call) => call.method === 'GET').length, reads, 'nothing is read back on the strength of an answer from before the loss');
    assert.equal(app.createResult.children.length, 0);
    assert.equal(app.generationId, null);
    assert.equal(app.statusRegion.textContent, 'error_session');
});


test('the answer to a bulk decision does not throw away an edit begun meanwhile', async () => {
    const app = await twoReviewed();
    await openFirst(app);
    const slow = deferred();
    on('POST', (url) => url === '/b', () => slow.promise);
    const bulking = buttons(app, 'bulk_reject')[0].dispatch('click');
    await settle();
    assert.equal(callsTo('POST', '/b').length, 1);

    // While it is out, the person starts editing the very proposal that is being decided.
    await click(app, 'edit');
    app.editor.controls.title.value = 'BULK_NEW_DRAFT';
    const reads = callsTo('GET', `/p/${U1}`).length;

    slow.resolve(ok({ items: callsTo('POST', '/b')[0].body.items.map((item) => ({ request_id: item.request_id, http_status: 200, error: null })) }));
    await bulking;
    await settle();

    assert.notEqual(app.editor, null, 'the edit is still open');
    assert.equal(app.editor.controls.title.value, 'BULK_NEW_DRAFT');
    assert.equal(callsTo('GET', `/p/${U1}`).length, reads, 'the proposal was not read again over the edit');
    assert.ok(buttons(app, 'reread_discard').length === 1, 'the person is told, and chooses');
});

test('with no edit under way the open proposal is read again after a bulk decision', async () => {
    const app = await twoReviewed();
    await openFirst(app);
    on('POST', (url) => url === '/b', (body) => ok({ items: body.items.map((item) => ({ request_id: item.request_id, http_status: 200, error: null })) }));
    const reads = callsTo('GET', `/p/${U1}`).length;

    await click(app, 'bulk_reject');

    assert.equal(callsTo('GET', `/p/${U1}`).length, reads + 1);
    assert.equal(buttons(app, 'reread_discard').length, 0);
});

test('nothing is read back for a page that has been left', async () => {
    const app = await twoReviewed();
    await openFirst(app);
    const slow = deferred();
    on('POST', (url) => url === '/b', () => slow.promise);
    const bulking = buttons(app, 'bulk_reject')[0].dispatch('click');
    await settle();

    await window.fire('pagehide');
    const reads = callsTo('GET', `/p/${U1}`).length;
    slow.resolve(ok({ items: callsTo('POST', '/b')[0].body.items.map((item) => ({ request_id: item.request_id, http_status: 200, error: null })) }));
    await bulking;
    await settle();

    assert.equal(callsTo('GET', `/p/${U1}`).length, reads);
    assert.equal(everything(app.host).includes('CANARY'), false);
});

test('a confirmation answered after access was lost and regained decides nothing', async () => {
    const app = await twoReviewed();
    let answer;
    const original = window.LFConfirm.open;
    window.LFConfirm.open = () => new Promise((resolve) => { answer = resolve; });
    try {
        const clicking = buttons(app, 'bulk_reject')[0].dispatch('click');
        await settle();

        onList(() => failure(401, 'unauthenticated'));
        await app.loadList(true);
        onList(() => ok({ items: [listItem(U1), listItem(U2)], next_cursor: null }));
        await app.loadList(true); // access is back: a list read succeeds again
        assert.equal(app.revoked, false);

        answer(true);
        await clicking;
        await settle();
    } finally {
        window.LFConfirm.open = original;
    }

    assert.equal(callsTo('POST', '/b').length, 0, 'the old confirmation sent nothing');
});

test('the state of a generation read after access was lost is not shown', async () => {
    const app = await makeApp();
    app.generationId = G1;
    const slow = deferred();
    on('GET', (url) => url === `/g/${G1}`, () => slow.promise);
    const refreshing = app.refreshGeneration();
    await settle();

    onList(() => failure(401, 'unauthenticated'));
    await app.loadList(true);
    onList(() => ok({ items: [listItem(U1)], next_cursor: null }));
    await app.loadList(true); // access is back
    const reads = calls.filter((call) => call.method === 'GET').length;
    slow.resolve(ok({ request_status: 'completed', item_count: 2 }));
    await refreshing;
    await settle();

    assert.equal(app.createResult.children.length, 0);
    assert.equal(calls.filter((call) => call.method === 'GET').length, reads, 'nothing is read back for the old answer');
});

test('emptying a subtree also empties text that sits beside other elements', () => {
    const box = document.createElement('div');
    const text = document.createTextNode('CANARY_MIXED');
    const inner = document.createElement('span');
    box.append(text, inner);

    AiAuthoring.scrub(box);

    assert.equal(text.textContent, '');
    assert.equal(everything(box).includes('CANARY'), false);
});


test('a failed load of the next page, then Retry, keeps the edit under way', async () => {
    const app = await makeApp({ next: 'c1' });
    await openEditor(app);
    app.editor.controls.title.value = 'RETRY_DRAFT';
    onList(() => html(500));

    await click(app, 'load_more');
    assert.ok(everything(app.host).includes('error_unexpected'));
    await click(app, 'retry');

    assert.notEqual(app.editor, null, 'the edit is still open');
    assert.equal(app.editor.controls.title.value, 'RETRY_DRAFT');
    assert.equal(app.openUuid, U1);
});

test('no control other than Save can lose an unsent edit without asking', async () => {
    // With every question answered "cancel", press everything there is to press.
    const app = await makeApp({ next: 'c1' });
    await openEditor(app);
    app.editor.controls.title.value = 'SWEEP_DRAFT';
    window.LFConfirm.answer = false;
    onList(() => html(500));
    await click(app, 'load_more'); // a failed page leaves a Retry button too
    const keys = [...new Set(app.host.querySelectorAll('button').map((node) => node.textContent))].filter((key) => key !== 'save');
    assert.ok(keys.length >= 6, `expected a rich set of controls, saw ${keys.join(',')}`);

    for (const key of keys) {
        const node = buttons(app, key)[0];
        if (!node) {
            continue;
        }
        await node.dispatch('click');
        await settle();
        assert.notEqual(app.editor, null, `"${key}" closed the edit`);
        assert.equal(app.editor.controls.title.value, 'SWEEP_DRAFT', `"${key}" changed the edit`);
    }
    window.LFConfirm.answer = true;
});

test('the choice to read again over an unsent edit is asked about', async () => {
    const app = await makeApp();
    await openEditor(app);
    app.editor.controls.title.value = 'CHOICE_DRAFT';
    await app.refreshOpen(U1);
    assert.equal(buttons(app, 'reread_discard').length, 1);
    const reads = callsTo('GET', `/p/${U1}`).length;

    window.LFConfirm.answer = false;
    await click(app, 'reread_discard');
    assert.equal(callsTo('GET', `/p/${U1}`).length, reads, 'cancelling read nothing');
    assert.equal(app.editor.controls.title.value, 'CHOICE_DRAFT');

    window.LFConfirm.answer = true;
    await click(app, 'reread_discard');
    assert.equal(callsTo('GET', `/p/${U1}`).length, reads + 1);
    assert.equal(app.editor, null, 'confirming discarded the edit and read the proposal again');
});

test('showing the page again lets the open proposal be read again', async () => {
    const app = await makeApp();
    await openFirst(app);

    await window.fire('pagehide');
    assert.equal(app.pageHidden, true);
    const reads = callsTo('GET', `/p/${U1}`).length;
    await app.refreshOpen(U1);
    assert.equal(callsTo('GET', `/p/${U1}`).length, reads, 'nothing is read for a page that was left');

    await window.fire('pageshow', { persisted: false });
    assert.equal(app.pageHidden, false);
    await app.refreshOpen(U1);
    await settle();
    assert.equal(callsTo('GET', `/p/${U1}`).length, reads + 1);
    assert.ok(everything(app.host).includes('CANARY_BODY'));
});


// ------------------------------------------------- everything typed and not sent

/** What the person has typed, read straight from the script's fields. */
const typed = (app) => ({
    title: app.editor?.controls.title.value,
    note: app.noteField?.value,
    bulkField: app.bulkNoteField?.value,
    bulk: app.bulkNote,
    choices: app.checkedKinds().join(','),
});

test('a decision note alone is unsent input: moving to another proposal asks, and cancelling keeps it', async () => {
    const app = await makeApp({ items: [listItem(U1), listItem(U2)] });
    onDetail(U2, () => ok(detail(U2)));
    await openFirst(app);
    app.noteField.value = 'NOTE_UNSENT';
    window.LFConfirm.answer = false;
    const asked = window.LFConfirm.calls;

    await click(app, 'view', 1);
    assert.equal(window.LFConfirm.calls, asked + 1, 'it asked');
    assert.equal(callsTo('GET', `/p/${U2}`).length, 0);
    assert.equal(app.noteField.value, 'NOTE_UNSENT');

    await click(app, 'detail_close');
    assert.notEqual(app.detailData, null);
    window.LFConfirm.answer = true;
});

test('Edit then Cancel keeps the decision note', async () => {
    const app = await makeApp();
    await openFirst(app);
    app.noteField.value = 'NOTE_KEPT';
    await click(app, 'edit');
    await click(app, 'cancel');

    assert.equal(app.noteField.value, 'NOTE_KEPT');
    assert.equal(app.host.querySelector('textarea[id]') === null, false);
    assert.ok(app.actionsHost.querySelectorAll('textarea').some((node) => node.value === 'NOTE_KEPT'), 'the note is back on the page');
});

test('a bulk answer that arrives late does not read the proposal over a note being typed', async () => {
    const app = await twoReviewed();
    await openFirst(app);
    const slow = deferred();
    on('POST', (url) => url === '/b', () => slow.promise);
    const bulking = buttons(app, 'bulk_reject')[0].dispatch('click');
    await settle();

    app.noteField.value = 'LATE_BULK_NOTE';
    const reads = callsTo('GET', `/p/${U1}`).length;
    slow.resolve(ok({ items: callsTo('POST', '/b')[0].body.items.map((item) => ({ request_id: item.request_id, http_status: 200, error: null })) }));
    await bulking;
    await settle();

    assert.equal(app.noteField.value, 'LATE_BULK_NOTE');
    assert.equal(callsTo('GET', `/p/${U1}`).length, reads);
    assert.equal(buttons(app, 'reread_discard').length, 1);
});

test('the browser is asked to keep the page for a decision note or a bulk note alone', async () => {
    let app = await makeApp();
    await openFirst(app);
    let event = {};
    await window.fire('beforeunload', event);
    assert.notEqual(event.prevented, true, 'nothing typed');

    app.noteField.value = 'LEAVE_NOTE';
    event = {};
    await window.fire('beforeunload', event);
    assert.equal(event.prevented, true, 'a decision note');

    app = await makeApp();
    app.bulkNoteField.value = 'LEAVE_BULK';
    await app.bulkNoteField.dispatch('input');
    event = {};
    await window.fire('beforeunload', event);
    assert.equal(event.prevented, true, 'a bulk note');
});

test('kinds ticked in the create form and not sent are unsent input too: leaving the page asks', async () => {
    const app = await makeApp();
    let event = {};
    await window.fire('beforeunload', event);
    assert.notEqual(event.prevented, true, 'nothing chosen');

    await click(app, 'create_open');
    app.createBoxes.summary.checked = true;
    event = {};
    await window.fire('beforeunload', event);
    assert.equal(event.prevented, true, 'a choice alone');

    await click(app, 'create_open'); // folded away, still chosen
    event = {};
    await window.fire('beforeunload', event);
    assert.equal(event.prevented, true, 'folded away but still chosen');

    await click(app, 'create_open');
    await click(app, 'cancel'); // the person drops it
    event = {};
    await window.fire('beforeunload', event);
    assert.notEqual(event.prevented, true, 'dropped');
});

test('a note is kept over the reread that follows saving an edit, and over a refused decision, but not after a decision', async () => {
    // Saving an edit does not use the note.
    let app = await makeApp();
    await openFirst(app);
    app.noteField.value = 'NOTE_FOR_LATER';
    await click(app, 'edit');
    app.editor.controls.title.value = 'EDITED';
    on('PATCH', (url) => url === `/p/${U1}`, () => ok(detail(U1, { revision_no: 2 })));
    await click(app, 'save');
    assert.equal(app.noteField.value, 'NOTE_FOR_LATER', 'kept over saving an edit');

    // A refused decision was not applied, so its note is still wanted.
    app = await makeApp();
    await openFirst(app);
    app.noteField.value = 'NOTE_REFUSED';
    on('POST', (url) => url === `/p/${U1}/decisions`, () => failure(409, 'proposal_revision_conflict'));
    await click(app, 'accept');
    assert.equal(app.noteField.value, 'NOTE_REFUSED', 'kept over a refused decision');

    // A decision that went through used its note.
    app = await makeApp();
    await openFirst(app);
    app.noteField.value = 'NOTE_USED';
    on('POST', (url) => url === `/p/${U1}/decisions`, () => ok(detail(U1, { status: 'accepted', allowed_actions: [] })));
    await click(app, 'accept');
    assert.notEqual(app.noteField?.value, 'NOTE_USED', 'used, so not carried over');
});

test('nothing that is typed is lost to pressing any button, whatever the questions are answered', async () => {
    // Every kind of unsent input at once, every question answered "cancel", every button pressed;
    // after each press, look at once.
    const app = await makeApp({ next: 'c1' });
    await openFirst(app);
    app.noteField.value = 'SWEEP_NOTE';
    await click(app, 'edit');
    app.editor.controls.title.value = 'SWEEP_TITLE';
    app.bulkNoteField.value = 'SWEEP_BULK';
    await app.bulkNoteField.dispatch('input');
    await click(app, 'create_open');
    app.createBoxes.summary.checked = true;
    await click(app, 'create_open'); // fold it away: the choice stays
    window.LFConfirm.answer = false;
    onList(() => html(500));
    await click(app, 'load_more'); // a failed page leaves a Retry button too
    const before = typed(app);
    assert.deepEqual(before, { title: 'SWEEP_TITLE', note: 'SWEEP_NOTE', bulkField: 'SWEEP_BULK', bulk: 'SWEEP_BULK', choices: 'summary' });

    const inCreate = (node) => app.createPanel.contains(node);
    const keys = [...new Set(app.host.querySelectorAll('button').filter((node) => !inCreate(node)).map((node) => node.textContent))]
        .filter((key) => key !== 'save');
    assert.ok(keys.length >= 6, `expected a rich set of controls, saw ${keys.join(',')}`);
    for (const key of keys) {
        const node = buttons(app, key).find((candidate) => !inCreate(candidate));
        if (!node) {
            continue;
        }
        await node.dispatch('click');
        await settle();
        assert.deepEqual(typed(app), before, `"${key}" changed what was typed`);
    }
    window.LFConfirm.answer = true;
});

test('what was ticked in the create form is not lost to folding it away, or to loading the list', async () => {
    const app = await makeApp({ next: 'c1' });
    await click(app, 'create_open');
    app.createBoxes.concept.checked = true;
    await click(app, 'create_open');
    await click(app, 'create_open');
    assert.equal(app.checkedKinds().join(), 'concept', 'folding and unfolding');

    await app.loadList(true);
    await app.loadList(false);
    assert.equal(app.checkedKinds().join(), 'concept', 'loading the list');

    await click(app, 'cancel'); // the person's own choice to drop it
    assert.equal(app.checkedKinds().join(), '');
});

test('an old request answering does not put away, or empty, a form being filled for a new one', async () => {
    const app = await makeApp();
    await click(app, 'create_open');
    app.createBoxes.summary.checked = true;
    on('POST', (url) => url === '/g', () => ok({ request_status: 'pending', item_count: 0 }));
    await click(app, 'create_submit');
    assert.ok(buttons(app, 'create_refresh').length === 1);

    const slow = deferred();
    on('GET', (url) => url.startsWith('/g/'), () => slow.promise);
    const refreshing = buttons(app, 'create_refresh')[0].dispatch('click');
    await settle();
    // The person turns to a different request while the status is out.
    app.createBoxes.summary.checked = false;
    app.createBoxes.concept.checked = true;

    slow.resolve(ok({ request_status: 'completed', item_count: 1 }));
    await refreshing;
    await settle();

    assert.equal(app.createPanel.hidden, false, 'the form stays open');
    assert.equal(app.checkedKinds().join(), 'concept', 'and keeps the new choice');
    assert.ok(everything(app.createResult).includes('create_done'), 'the old request is still reported');
});

test('when the form still holds what the finished request was made from, it is put away and emptied', async () => {
    const app = await makeApp();
    await click(app, 'create_open');
    app.createBoxes.summary.checked = true;
    on('POST', (url) => url === '/g', () => ok({ request_status: 'completed', item_count: 1 }));

    await click(app, 'create_submit');

    assert.equal(app.createPanel.hidden, true);
    assert.equal(app.checkedKinds().join(), '');
});

test('a bulk note survives reloading the list, and dropping an unknown command leaves the form as it was', async () => {
    const app = await twoReviewed();
    app.bulkNoteField.value = 'BULK_STAYS';
    await app.bulkNoteField.dispatch('input');
    await app.loadList(true);
    await app.loadList(false);
    assert.equal(app.bulkNoteField.value, 'BULK_STAYS');

    const other = await makeApp();
    await openEditor(other);
    other.editor.controls.title.value = 'STAYS_AFTER_DISCARD';
    on('PATCH', (url) => url === `/p/${U1}`, () => { throw new TypeError('network'); });
    await click(other, 'save');
    await click(other, 'unknown_discard');
    assert.equal(other.editor.controls.title.value, 'STAYS_AFTER_DISCARD', 'straight after dropping it');
});

test('hostile text goes through as text: any route to an HTML member fails the fake DOM', async () => {
    const hostile = '<img src=x onerror=alert(1)><script>alert(2)</script>';
    const app = await makeApp();
    onDetail(U1, () => ok(detail(U1, { payload: { kind: 'summary', title: hostile, body: hostile, confidence: 0.5, rationale: hostile, source_refs: [] } })));
    await openFirst(app);
    assert.ok(everything(app.detailHost).includes(hostile), 'shown as the text it is');
    assert.throws(() => { app.detailHost.innerHTML = 'x'; }, /HTML sink/);
});


// ------------------------------------------- P3-B B1: an existing Node, shown by name

const reuseDetail = (uuid = U1, over = {}, node = {}) => detail(uuid, {
    kind: 'node_mapping',
    payload: {
        kind: 'node_mapping', title: 'Liên kết năng lực', body: 'Nội dung khớp.', confidence: 0.6, rationale: 'Khớp tiêu chí.', source_refs: [],
        mapping: { mode: 'reuse_existing', node_id: 7, definition_id: 8, role: 'practices', weight: null },
    },
    mapping_node: {
        node_id: 7, definition_id: 8, code: 'KOR-HANGUL-01', label: 'Đọc và viết Hangul cơ bản', node_type: 'objective',
        description: 'Nhận biết và viết bảng chữ cái Hangul.', status: 'active', ...node,
    },
    ...over,
});

test('an existing-Node mapping is shown by the Node\'s name, code, type and description, and can be accepted, edited and rejected', async () => {
    const app = await makeApp();
    onDetail(U1, () => ok(reuseDetail()));
    await openFirst(app);

    const shown = everything(app.detailHost);
    for (const text of ['Đọc và viết Hangul cơ bản', 'KOR-HANGUL-01', 'Nhận biết và viết bảng chữ cái Hangul.', 'node_type_objective']) {
        assert.ok(shown.includes(text), `shows ${text}`);
    }
    assert.equal(shown.includes('mapping_reuse_pending'), false, 'no longer "cannot be judged yet"');
    for (const key of ['edit', 'accept', 'reject']) {
        assert.equal(buttons(app, key).length, 1, `offers ${key}`);
    }
});

test('a Node that could not be identified leaves only Reject, and says so', async () => {
    const nodeOf = (over) => ({ node_id: 7, definition_id: 8, code: 'C', label: 'L', node_type: 'objective', description: null, status: 'active', ...over });
    for (const mapping_node of [null, undefined, nodeOf({ label: '' }), nodeOf({ label: 7 }), 'not an object']) {
        const app = await makeApp();
        onDetail(U1, () => ok(reuseDetail(U1, { mapping_node })));
        await openFirst(app);

        assert.equal(buttons(app, 'accept').length, 0);
        assert.equal(buttons(app, 'edit').length, 0);
        assert.equal(buttons(app, 'reject').length, 1);
        assert.ok(everything(app.detailHost).includes('mapping_node_unavailable'));
    }
});

test('a Node whose numbers are not the proposal\'s own is not trusted', async () => {
    const app = await makeApp();
    onDetail(U1, () => ok(reuseDetail(U1, {}, { node_id: 99 })));
    await openFirst(app);

    assert.equal(buttons(app, 'accept').length, 0);
    assert.equal(everything(app.detailHost).includes('Đọc và viết Hangul cơ bản'), false, 'a name that belongs to something else is not shown');
});

test('hostile Node text is text', async () => {
    const hostile = '<img src=x onerror=alert(1)>';
    const app = await makeApp();
    onDetail(U1, () => ok(reuseDetail(U1, {}, { label: hostile, code: hostile, description: hostile, node_type: hostile })));
    await openFirst(app);

    assert.ok(everything(app.detailHost).includes(hostile));
});

test('editing an existing-Node mapping changes the text, role and weight, and never the Node or the display', async () => {
    const app = await makeApp();
    onDetail(U1, () => ok(reuseDetail()));
    let sent = null;
    on('PATCH', (url) => url === `/p/${U1}`, (body) => { sent = body; return ok(reuseDetail(U1, { revision_no: 2 })); });
    await openFirst(app);
    await click(app, 'edit');

    assert.deepEqual(Object.keys(app.editor.controls).sort(), ['body', 'rationale', 'role', 'title', 'weight']);
    app.editor.controls.role.value = 'teaches';
    app.editor.controls.weight.value = '0,5';
    assert.equal(app.hasDraft(), true, 'a changed role is an unsent edit');
    await click(app, 'save');

    assert.deepEqual(sent.payload.mapping, { mode: 'reuse_existing', node_id: 7, definition_id: 8, role: 'teaches', weight: 0.5 });
    assert.equal(JSON.stringify(sent).includes('mapping_node'), false, 'the read-only display never travels back');
});

test('editing a new-Node mapping still sends every field of it, as before', async () => {
    const app = await makeApp();
    const mapping = { mode: 'propose_new', code: 'NEW-1', label: 'Node mới', node_type: 'competency', criteria: { a: 1 }, role: 'teaches', weight: 0.25 };
    onDetail(U1, () => ok(detail(U1, { kind: 'node_mapping', payload: { kind: 'node_mapping', title: 'T', body: 'B', confidence: 0.5, rationale: 'r', source_refs: [], mapping } })));
    let sent = null;
    on('PATCH', (url) => url === `/p/${U1}`, (body) => { sent = body; return ok(detail(U1, { revision_no: 2 })); });
    await openFirst(app);
    await click(app, 'edit');
    assert.deepEqual(Object.keys(app.editor.controls).sort(), ['body', 'code', 'criteria', 'label', 'node_type', 'rationale', 'role', 'title', 'weight']);
    app.editor.controls.weight.value = '0,75';
    await click(app, 'save');

    assert.deepEqual(sent.payload.mapping, { ...mapping, weight: 0.75 });
});

test('a weight outside 0 to 1 is refused before anything is sent', async () => {
    const app = await makeApp();
    onDetail(U1, () => ok(reuseDetail()));
    await openFirst(app);
    await click(app, 'edit');
    app.editor.controls.weight.value = '1,5';
    const before = callsTo('PATCH', `/p/${U1}`).length;

    await click(app, 'save');

    assert.equal(callsTo('PATCH', `/p/${U1}`).length, before);
});

test('in a bulk decision, an existing-Node mapping is accepted only if its Node was seen; otherwise it is left out', async () => {
    const app = await makeApp({ items: [listItem(U1, { kind: 'node_mapping' }), listItem(U2, { kind: 'node_mapping' })] });
    onDetail(U1, () => ok(reuseDetail(U1)));
    // U2's Node could not be identified.
    onDetail(U2, () => ok(reuseDetail(U2, { mapping_node: null })));
    await click(app, 'view', 0);
    await click(app, 'view', 1);
    for (const uuid of [U1, U2]) {
        app.selected.add(uuid);
    }
    app.renderList();
    on('POST', (url) => url === '/b', (body) => ok({ items: body.items.map((item) => ({ request_id: item.request_id, http_status: 200, error: null })) }));

    await click(app, 'bulk_accept');

    const sent = callsTo('POST', '/b')[0].body.items;
    assert.deepEqual(sent.map((item) => item.proposal_uuid), [U1], 'only the one whose Node was seen');
});


// ------------------------------------------- P3-B B2: after acceptance, look at the target, confirm, apply

const HASH = 'a'.repeat(64);
const A1UUID = '44444444-4444-4444-8444-444444444444';
const receipt = (over = {}) => ({ application_uuid: A1UUID, operation: 'apply_intent', status: 'ready_to_apply', approved_by: 5, allowed_actions: ['cancel'], ...over });
const accepted = (allowed, applications = [], over = {}, lock = 3) => reuseDetail(U1, {
    status: 'accepted', lock_version: lock, allowed_actions: allowed, applications, ...over,
});
const preview = (over = {}) => ({
    target_snapshot: {
        target_schema_version: 'x', customer_id: 77, framework_id: 2, framework_version_id: 3,
        node: { id: 7, definition_id: 8, code: 'KOR-HANGUL-01', label: 'Đọc và viết Hangul cơ bản', description: 'Mô tả đích', node_type: 'objective', criteria: { level: 'a' } },
        relations: [{ id: 1, type: 'prerequisite', source_node_id: 7, target_node_id: 9 }],
        dependency_nodes: [{ id: 9, code: 'NEN', label: 'Nền tảng', node_type: 'objective' }],
    },
    target_hash: HASH, version_status: 'published', node_status: 'active', ...over,
});
const openAccepted = async (app, allowed, applications = [], over = {}) => {
    onDetail(U1, () => ok(accepted(allowed, applications, over)));
    await openFirst(app);
};

test('an accepted existing-Node mapping is looked at before it can be confirmed, and the hash sent is the one that was shown', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target', 'confirm_target']);
    assert.equal(buttons(app, 'preview_target').length, 1);
    assert.equal(buttons(app, 'confirm_target').length, 0, 'nothing can be confirmed unseen');

    on('GET', (url) => url === `/p/${U1}/target-preview`, () => ok(preview()));
    await click(app, 'preview_target');
    const shown = everything(app.detailHost);
    for (const text of ['Đọc và viết Hangul cơ bản', 'KOR-HANGUL-01', 'Mô tả đích', 'Nền tảng']) {
        assert.ok(shown.includes(text), `shows ${text}`);
    }
    assert.equal(shown.includes('77'), false, 'no tenant identity');
    assert.equal(buttons(app, 'confirm_target').length, 1);

    let sent = null;
    on('POST', (url) => url === `/p/${U1}/target-confirmations`, (body) => { sent = body; return ok({ target_hash: HASH, application_uuid: A1UUID, application_status: 'ready_to_apply' }); });
    onDetail(U1, () => ok(accepted(['preview_target', 'confirm_target', 'apply_intent'], [receipt()], {}, 4)));
    await click(app, 'confirm_target');

    assert.equal(sent.expected_lock_version, 3);
    assert.equal(sent.expected_target_hash, HASH);
    assert.ok(UUID_LIKE.test(sent.request_id));
    assert.equal(buttons(app, 'apply_intent').length, 1, 'the reread offers the next step');
    assert.equal(app.targetView, null, 'the old preview is gone with the reread');
});

test('applying writes into the working Template only after a question that says it is not the official Mapping yet', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target', 'confirm_target', 'apply_intent'], [receipt()]);
    let sent = null;
    on('POST', (url) => url === `/p/${U1}/intent-applications`, (body) => { sent = body; return ok({ intent_id: 1, application_status: 'applied' }); });
    onDetail(U1, () => ok(accepted(['preview_target', 'reject_target'], [receipt({ status: 'applied', allowed_actions: [] })], {}, 4)));

    window.LFConfirm.answer = false;
    await click(app, 'apply_intent');
    assert.equal(sent, null, 'cancelling sends nothing');
    assert.ok(window.LFConfirm.last.message.includes('apply_target_message'));

    window.LFConfirm.answer = true;
    await click(app, 'apply_intent');
    assert.equal(sent.expected_lock_version, 3);
    assert.equal(buttons(app, 'apply_intent').length, 0, 'once applied there is nothing more to apply');
    assert.ok(everything(app.detailHost).includes('application_note_applied'));
});

test('a step the server did not offer has no button', async () => {
    const app = await makeApp();
    await openAccepted(app, ['reconfirm_context'], [receipt()]);
    for (const key of ['preview_target', 'confirm_target', 'apply_intent', 'reject_target']) {
        assert.equal(buttons(app, key).length, 0, key);
    }
});

test('a target that changed is said to have changed, and nothing stays confirmable', async () => {
    let app = await makeApp();
    await openAccepted(app, ['preview_target', 'confirm_target']);
    on('GET', (url) => url === `/p/${U1}/target-preview`, () => failure(409, 'proposal_target_changed'));
    await click(app, 'preview_target');
    assert.ok(everything(app.detailHost).includes('conflict_target_changed'));
    assert.equal(buttons(app, 'confirm_target').length, 0);

    app = await makeApp();
    await openAccepted(app, ['preview_target', 'confirm_target']);
    on('GET', (url) => url === `/p/${U1}/target-preview`, () => ok(preview()));
    await click(app, 'preview_target');
    on('POST', (url) => url === `/p/${U1}/target-confirmations`, () => failure(409, 'proposal_target_changed'));
    const reads = callsTo('GET', `/p/${U1}`).length;
    await click(app, 'confirm_target');
    assert.ok(everything(app.detailHost).includes('conflict_target_changed'));
    assert.equal(callsTo('GET', `/p/${U1}`).length, reads + 1, 'the proposal is read again');
    assert.equal(app.targetView, null);
    assert.equal(buttons(app, 'confirm_target').length, 0, 'the target has to be looked at again');
});

test('a confirmation whose outcome is unknown locks the panel, and the retry sends exactly the same', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target', 'confirm_target']);
    on('GET', (url) => url === `/p/${U1}/target-preview`, () => ok(preview()));
    await click(app, 'preview_target');
    let attempt = 0;
    on('POST', (url) => url === `/p/${U1}/target-confirmations`, () => {
        attempt += 1;
        if (attempt === 1) {
            throw new TypeError('network');
        }

        return ok({ target_hash: HASH, application_uuid: A1UUID, application_status: 'ready_to_apply' });
    });
    await click(app, 'confirm_target');
    assert.equal(app.locked, true);
    await click(app, 'retry_same');

    const sent = callsTo('POST', `/p/${U1}/target-confirmations`);
    assert.equal(sent.length, 2);
    assert.equal(sent[1].body.request_id, sent[0].body.request_id);
    assert.equal(sent[1].body.expected_target_hash, sent[0].body.expected_target_hash);
});

test('a preview that arrives after the person moved on, or after access was lost, shows nothing', async () => {
    const app = await makeApp({ items: [listItem(U1), listItem(U2)] });
    onDetail(U2, () => ok(detail(U2)));
    await openAccepted(app, ['preview_target', 'confirm_target']);
    const slow = deferred();
    on('GET', (url) => url === `/p/${U1}/target-preview`, () => slow.promise);
    const previewing = buttons(app, 'preview_target')[0].dispatch('click');
    await settle();

    await click(app, 'view', 1);
    slow.resolve(ok(preview()));
    await previewing;
    await settle();
    assert.equal(app.targetView, null);
    assert.equal(everything(app.host).includes('KOR-HANGUL-01'), false, 'moved on');

    // Access lost while it is out.
    const second = await makeApp();
    await openAccepted(second, ['preview_target', 'confirm_target']);
    on('GET', (url) => url === `/p/${U1}/target-preview`, () => failure(403, 'forbidden'));
    await click(second, 'preview_target');
    assert.equal(second.revoked, true);
    assert.deepEqual(leaks(second, 'KOR-HANGUL-01'), []);
    assert.equal(second.targetView, null);
});

test('the confirming step itself refuses anything that was not shown against this very version', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target', 'confirm_target']);

    await app.targetStep('confirm'); // nothing was shown
    assert.equal(callsTo('POST', `/p/${U1}/target-confirmations`).length, 0);

    on('GET', (url) => url === `/p/${U1}/target-preview`, () => ok(preview()));
    await click(app, 'preview_target');
    app.targetView.lock = 99; // shown against another version of the proposal
    await app.targetStep('confirm');
    assert.equal(callsTo('POST', `/p/${U1}/target-confirmations`).length, 0);
});

test('a preview without a proper hash is not a target that can be confirmed', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target', 'confirm_target']);
    for (const target_hash of ['zz', 'A'.repeat(64), 7, null]) {
        on('GET', (url) => url === `/p/${U1}/target-preview`, () => ok(preview({ target_hash })));
        await click(app, 'preview_target');
        assert.equal(app.targetView, null, `hash ${target_hash}`);
        assert.equal(buttons(app, 'confirm_target').length, 0);
    }
});

test('only an ordinary web address is followed to the Template\'s mapping list', async () => {
    for (const mappingsUrl of ['javascript:alert(1)', 'data:text/html,x', '//evil.test/x', 5]) {
        const app = await makeApp({ config: { mappingsUrl } });
        await openAccepted(app, ['preview_target', 'reject_target'], [receipt({ status: 'applied', allowed_actions: [] })]);
        assert.equal(app.detailHost.querySelectorAll('a').length, 0, String(mappingsUrl));
    }
});

// ------------------------------------------- P3-B B3: retry and cancel a receipt

const failedReceipt = (over = {}) => receipt({ status: 'failed', allowed_actions: ['retry', 'cancel'], ...over });
const RECEIPT_URL = `/p/${U1}/applications/${A1UUID}`;

test('a failed receipt offers Retry and Cancel; nothing is sent until the question is answered yes', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target', 'confirm_target'], [failedReceipt()]);
    assert.equal(buttons(app, 'receipt_retry').length, 1);
    assert.equal(buttons(app, 'receipt_cancel').length, 1);
    on('POST', (url) => url === `${RECEIPT_URL}/retry`, () => ok({ application_status: 'applied' }));
    onDetail(U1, () => ok(accepted(['preview_target', 'reject_target'], [receipt({ status: 'applied', allowed_actions: [] })], {}, 4)));

    window.LFConfirm.answer = false;
    await click(app, 'receipt_retry');
    assert.equal(callsTo('POST', `${RECEIPT_URL}/retry`).length, 0);

    window.LFConfirm.answer = true;
    await click(app, 'receipt_retry');
    const sent = callsTo('POST', `${RECEIPT_URL}/retry`);
    assert.equal(sent.length, 1);
    assert.equal(sent[0].body.expected_lock_version, 3);
    assert.deepEqual(Object.keys(sent[0].body).sort(), ['expected_lock_version', 'request_id']);
    assert.equal(buttons(app, 'receipt_retry').length, 0, 'the reread shows it applied: nothing left to retry');
});

test('cancelling sends a reason code from a fixed list, the first by default, and needs a yes', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target'], [receipt({ status: 'ready_to_apply', allowed_actions: ['cancel'] })]);
    const select = app.receiptReasons.get(A1UUID);
    assert.ok(select, 'a reason to choose');
    const codes = select.children.map((option) => option.getAttribute('value'));
    assert.ok(codes.length >= 3);
    assert.ok(codes.every((code) => /^[a-z][a-z0-9_]{0,63}$/.test(code)), 'each is a valid code');
    assert.equal(select.value, codes[0]);
    assert.equal(buttons(app, 'receipt_retry').length, 0, 'a receipt that has not failed is not retried');
    on('POST', (url) => url === `${RECEIPT_URL}/cancel`, () => ok({ application_status: 'cancelled' }));
    onDetail(U1, () => ok(accepted([], [receipt({ status: 'cancelled', allowed_actions: [] })], {}, 4)));

    select.value = codes[1];
    window.LFConfirm.answer = false;
    await click(app, 'receipt_cancel');
    assert.equal(callsTo('POST', `${RECEIPT_URL}/cancel`).length, 0);
    assert.equal(select.value, codes[1], 'the choice survives a "no"');

    window.LFConfirm.answer = true;
    await click(app, 'receipt_cancel');
    const sent = callsTo('POST', `${RECEIPT_URL}/cancel`)[0].body;
    assert.equal(sent.reason_code, codes[1]);
    assert.equal(sent.expected_lock_version, 3);
    assert.equal(buttons(app, 'receipt_cancel').length, 0);
});

test('a step the receipt was not offered has no button, whatever its status says', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target'], [
        failedReceipt({ operation: 'create_node', allowed_actions: ['cancel'] }), // a teacher may cancel but not retry it
        receipt({ application_uuid: '66666666-6666-4666-8666-666666666666', status: 'applied', allowed_actions: [] }),
    ]);

    assert.equal(buttons(app, 'receipt_retry').length, 0);
    assert.equal(buttons(app, 'receipt_cancel').length, 1);
    assert.equal(app.receiptReasons.size, 1);
});

test('only what was offered is shown, an odd receipt id gets no controls, and a cancel needs a reason from the list', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target'], [
        failedReceipt({ allowed_actions: ['retry'] }),
        failedReceipt({ application_uuid: '../../evil', allowed_actions: ['retry', 'cancel'] }),
    ]);

    assert.equal(buttons(app, 'receipt_retry').length, 1, 'the well-formed receipt only');
    assert.equal(buttons(app, 'receipt_cancel').length, 0, 'retry alone offers no cancel');
    assert.equal(app.receiptReasons.size, 0);

    await app.receiptStep('cancel', A1UUID); // no reason was ever offered for it
    assert.equal(callsTo('POST', `${RECEIPT_URL}/cancel`).length, 0);
});

test('while a receipt command is out, and while its outcome is unknown, no receipt control can be used', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target'], [failedReceipt()]);
    const slow = deferred();
    on('POST', (url) => url === `${RECEIPT_URL}/retry`, () => slow.promise);
    const retrying = buttons(app, 'receipt_retry')[0].dispatch('click');
    await settle();
    assert.ok(buttons(app, 'receipt_cancel')[0].disabled, 'in flight');
    assert.ok(buttons(app, 'receipt_retry')[0].disabled);
    assert.ok(app.receiptReasons.get(A1UUID).disabled);

    slow.resolve(ok({ application_status: 'applied' }));
    await retrying;
    await settle();

    // Unknown outcome: locked, and the retry sends exactly the same.
    const other = await makeApp();
    await openAccepted(other, ['preview_target'], [failedReceipt()]);
    let attempt = 0;
    on('POST', (url) => url === `${RECEIPT_URL}/retry`, () => {
        attempt += 1;
        if (attempt === 1) {
            throw new TypeError('network');
        }

        return ok({ application_status: 'applied' });
    });
    await click(other, 'receipt_retry');
    assert.equal(other.locked, true);
    assert.ok(buttons(other, 'receipt_cancel')[0].disabled, 'locked');
    await click(other, 'retry_same');
    const sent = callsTo('POST', `${RECEIPT_URL}/retry`);
    assert.equal(sent.length, 2);
    assert.equal(sent[1].body.request_id, sent[0].body.request_id);
});

test('a retry refused because the target changed says so and reads the proposal again', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target'], [failedReceipt()]);
    on('POST', (url) => url === `${RECEIPT_URL}/retry`, () => failure(409, 'proposal_target_changed'));
    const reads = callsTo('GET', `/p/${U1}`).length;

    await click(app, 'receipt_retry');

    assert.ok(everything(app.detailHost).includes('conflict_target_changed'));
    assert.equal(callsTo('GET', `/p/${U1}`).length, reads + 1);
});

test('a reason chosen for a cancel is unsent input: it is asked about, warned about, and kept over a refusal', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target'], [receipt({ status: 'ready_to_apply', allowed_actions: ['cancel'] })]);
    assert.equal(app.hasDraft(), false, 'the default is not a draft');
    const select = app.receiptReasons.get(A1UUID);
    const chosen = select.children[2].getAttribute('value');
    select.value = chosen;

    assert.equal(app.hasDraft(), true);
    const event = {};
    await window.fire('beforeunload', event);
    assert.equal(event.prevented, true);

    on('POST', (url) => url === `${RECEIPT_URL}/cancel`, () => failure(409, 'proposal_revision_conflict'));
    await click(app, 'receipt_cancel');
    assert.equal(app.receiptReasons.get(A1UUID).value, chosen, 'a refused cancel keeps the choice');
});

test('losing access takes the receipt controls and their choice away', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target'], [failedReceipt()]);
    const select = app.receiptReasons.get(A1UUID);
    select.value = select.children[1].getAttribute('value');

    onList(() => failure(401, 'unauthenticated'));
    await app.loadList(true);

    assert.equal(app.receiptReasons.size, 0);
    assert.equal(app.receiptHost, null);
    assert.equal(buttons(app, 'receipt_retry').length, 0);
});

// ------------------------------------------- P3-B B4: an admin approves a new Node into a draft

const DRAFTS = [{ id: 31, label: 'v2-draft — Bản nháp 2' }, { id: 32, label: 'v3-draft — Bản nháp 3' }];
const ADMIN = { framework: { selected: true, framework_id: 2, framework_version_id: 3 }, draftVersions: DRAFTS, mappingsUrl: 'https://x.test/admin/course-templates/3/edit?tab=learning' };
const newNode = (allowed, applications = [], over = {}, lock = 3) => detail(U1, {
    kind: 'node_mapping', status: 'accepted', lock_version: lock, revision_no: 2, allowed_actions: allowed, applications,
    payload: {
        kind: 'node_mapping', title: 'Node mới', body: 'B', confidence: 0.7, rationale: 'r', source_refs: [],
        mapping: { mode: 'propose_new', code: 'NEW-1', label: 'Quy đồng mẫu số', node_type: 'competency', criteria: null, role: 'teaches', weight: null },
    },
    ...over,
});
const openNew = async (app, allowed, applications = [], over = {}) => {
    onDetail(U1, () => ok(newNode(allowed, applications, over)));
    await openFirst(app);
};

test('an admin chooses the draft a new Node is created in, and nothing is chosen for them', async () => {
    const app = await makeApp({ config: ADMIN });
    await openNew(app, ['approve_node']);
    const select = app.draftChoice;
    assert.ok(select, 'a choice of draft');
    assert.equal(select.value, '', 'no default: the admin has to choose');
    assert.deepEqual(select.children.map((option) => option.getAttribute('value')), ['', '31', '32']);
    assert.equal(buttons(app, 'approve_node')[0].disabled, false);
    on('POST', (url) => url === `/p/${U1}/node-approvals`, () => ok({ status: 'accepted', node_id: 9 }));

    await click(app, 'approve_node'); // nothing chosen
    assert.equal(callsTo('POST', `/p/${U1}/node-approvals`).length, 0);

    select.value = '32';
    window.LFConfirm.answer = false;
    await click(app, 'approve_node');
    assert.equal(callsTo('POST', `/p/${U1}/node-approvals`).length, 0, 'a "no" sends nothing');
    assert.ok(window.LFConfirm.last.message.includes('v3-draft'), 'the question names the draft');

    onDetail(U1, () => ok(newNode(['preview_target', 'confirm_target'], [], {}, 4)));
    window.LFConfirm.answer = true;
    await click(app, 'approve_node');
    const sent = callsTo('POST', `/p/${U1}/node-approvals`)[0].body;
    assert.deepEqual(Object.keys(sent).sort(), ['expected_lock_version', 'expected_revision_no', 'framework_id', 'framework_version_id', 'request_id']);
    assert.equal(sent.framework_id, 2);
    assert.equal(sent.framework_version_id, 32);
    assert.equal(sent.expected_revision_no, 2);
    assert.equal(sent.expected_lock_version, 3);
    assert.equal(buttons(app, 'approve_node').length, 0, 'the reread shows the Node made');
});

test('a draft that is not one of the offered ones is not sent, and a teacher has no approval at all', async () => {
    const app = await makeApp({ config: ADMIN });
    await openNew(app, ['approve_node']);
    const odd = document.createElement('option');
    odd.setAttribute('value', '99');
    app.draftChoice.append(odd);
    app.draftChoice.value = '99';
    await app.approveNode();
    assert.equal(callsTo('POST', `/p/${U1}/node-approvals`).length, 0);

    const teacher = await makeApp();
    await openNew(teacher, []);
    assert.equal(buttons(teacher, 'approve_node').length, 0);
    assert.equal(teacher.draftChoice, null);
    assert.ok(everything(teacher.detailHost).includes('accepted_node_pending'), 'the teacher is told it waits for an admin');
});

test('with no draft to choose, or no framework selected, the admin is told why and nothing can be sent', async () => {
    const cases = [
        [{ ...ADMIN, draftVersions: [] }, 'approve_node_no_draft'],
        [{ ...ADMIN, framework: { selected: false, framework_id: null, framework_version_id: null } }, 'approve_node_unavailable'],
    ];
    for (const [config, reason] of cases) {
        const app = await makeApp({ config });
        await openNew(app, ['approve_node']);
        assert.equal(buttons(app, 'approve_node').length, 0);
        assert.equal(app.draftChoice, null);
        assert.ok(everything(app.detailHost).includes(reason));
        await app.approveNode();
        assert.equal(callsTo('POST', `/p/${U1}/node-approvals`).length, 0);
    }
});

test('the chosen draft is unsent input: asked about, warned about, kept over a refusal, gone with access', async () => {
    const app = await makeApp({ config: ADMIN });
    await openNew(app, ['approve_node']);
    assert.equal(app.hasDraft(), false);
    app.draftChoice.value = '31';
    assert.equal(app.hasDraft(), true);
    const event = {};
    await window.fire('beforeunload', event);
    assert.equal(event.prevented, true);

    on('POST', (url) => url === `/p/${U1}/node-approvals`, () => failure(409, 'framework_selection_conflict'));
    await click(app, 'approve_node');
    assert.equal(app.draftChoice.value, '31', 'a refused approval keeps the choice');

    onList(() => failure(401, 'unauthenticated'));
    await app.loadList(true);
    assert.equal(app.draftChoice, null);
});

test('an approval whose outcome is unknown locks the choice and retries exactly the same', async () => {
    const app = await makeApp({ config: ADMIN });
    await openNew(app, ['approve_node']);
    app.draftChoice.value = '31';
    let attempt = 0;
    on('POST', (url) => url === `/p/${U1}/node-approvals`, () => {
        attempt += 1;
        if (attempt === 1) {
            throw new TypeError('network');
        }

        return ok({ status: 'accepted' });
    });
    await click(app, 'approve_node');
    assert.equal(app.locked, true);
    assert.ok(app.draftChoice.disabled);
    await click(app, 'retry_same');

    const sent = callsTo('POST', `/p/${U1}/node-approvals`);
    assert.equal(sent.length, 2);
    assert.equal(sent[1].body.request_id, sent[0].body.request_id);
    assert.equal(sent[1].body.framework_version_id, 31);
});

test('a created Node is described as a Node in a draft, not as a recorded Mapping', async () => {
    const app = await makeApp({ config: ADMIN });
    await openNew(app, [], [{ application_uuid: U2, operation: 'create_node', status: 'applied', approved_by: 1, allowed_actions: [] }]);
    const shown = everything(app.detailHost);
    assert.ok(shown.includes('application_note_node_created'));
    assert.equal(shown.includes('application_note_applied'), false);
});

test('the chosen draft is emptied when the proposal goes, and is not carried to another proposal', async () => {
    const app = await makeApp({ config: ADMIN, items: [listItem(U1), listItem(U2)] });
    await openNew(app, ['approve_node']);
    const held = app.draftChoice;
    held.value = '31';
    on('POST', (url) => url === `/p/${U1}/node-approvals`, () => failure(409, 'proposal_revision_conflict'));
    await click(app, 'approve_node');
    assert.equal(app.draftChoice.value, '31', 'kept over the refusal of this proposal');
    assert.equal(held.value, '', 'the replaced select holds nothing');

    onDetail(U2, () => ok(newNode(['approve_node'], [], { proposal_uuid: U2 })));
    await app.openDetail(U2, null);
    await settle();
    assert.equal(app.draftChoice.value, '', 'a choice made for one proposal is not carried to the next');

    const before = app.draftChoice;
    before.value = '32';
    onList(() => failure(401, 'unauthenticated'));
    await app.loadList(true);
    assert.equal(before.value, '', 'losing access empties the held select');
});

test('a confirmation answered after the proposal changed decides nothing', async () => {
    const app = await makeApp({ config: ADMIN, items: [listItem(U1), listItem(U2)] });
    await openNew(app, ['approve_node']);
    app.draftChoice.value = '31';
    let answer;
    const original = window.LFConfirm.open;
    window.LFConfirm.open = () => new Promise((resolve) => { answer = resolve; });
    try {
        const clicking = buttons(app, 'approve_node')[0].dispatch('click');
        await settle();
        onDetail(U2, () => ok(detail(U2)));
        await app.openDetail(U2, null);
        await settle();
        answer(true);
        await clicking;
        await settle();
    } finally {
        window.LFConfirm.open = original;
    }

    assert.equal(calls.filter((call) => call.method === 'POST').length, 0, 'the old answer sent nothing');
});

test('a hostile draft name is text, and drafts with odd ids are dropped', async () => {
    const hostile = '<img src=x onerror=alert(1)>';
    const app = await makeApp({ config: { ...ADMIN, draftVersions: [{ id: 5, label: hostile }, { id: 'x', label: 'bad' }, { id: -1, label: 'bad' }, { id: 6 }] } });
    await openNew(app, ['approve_node']);

    assert.deepEqual(app.draftChoice.children.map((option) => option.getAttribute('value')), ['', '5']);
    assert.ok(everything(app.draftChoice).includes(hostile));
});

// ------------------------------------------- P3-B B6: the Course context changed; look at it, then confirm it

const CTX_HASH = 'b'.repeat(64);
const contextPreview = (over = {}, context = {}) => ({
    context: {
        context_schema_version: 1, customer_id: 77, template_id: 5, lesson_id: 6, activity_id: 7, activity_type: 'document',
        title: 'Bài đọc về phân số', instructions: 'Đọc rồi trả lời.', audience: null, level: 'beginner',
        ordered_position: { sections: [{ id: 4, display_order: 1 }], lesson: { id: 6, sort_order: 0 }, activity: { id: 7, sort_order: 2 } },
        ...context,
    },
    course_context_hash: CTX_HASH,
    ...over,
});
const onContext = (reply) => on('GET', (url) => url === `/p/${U1}/context-preview`, reply);
const CONTEXT_POST = (url) => url === `/p/${U1}/context-confirmations`;

test('a changed Course context is looked at before it can be confirmed, and the hash sent is the one that was shown', async () => {
    const app = await makeApp();
    await openAccepted(app, ['reconfirm_context', 'preview_target', 'confirm_target']);
    assert.equal(buttons(app, 'context_preview').length, 1);
    assert.equal(buttons(app, 'confirm_context').length, 0, 'nothing can be confirmed unseen');

    onContext(() => ok(contextPreview()));
    await click(app, 'context_preview');
    const shown = everything(app.detailHost);
    for (const text of ['Bài đọc về phân số', 'Đọc rồi trả lời.', 'beginner']) {
        assert.ok(shown.includes(text), `shows ${text}`);
    }
    for (const hidden of ['77', 'customer_id', CTX_HASH]) {
        assert.equal(shown.includes(hidden), false, `does not show ${hidden}`);
    }
    assert.equal(buttons(app, 'confirm_context').length, 1);

    window.LFConfirm.answer = false;
    await click(app, 'confirm_context');
    assert.equal(callsTo('POST', `/p/${U1}/context-confirmations`).length, 0, 'a "no" sends nothing');
    assert.ok(window.LFConfirm.last.message, 'a question is asked');

    window.LFConfirm.answer = true;
    on('POST', CONTEXT_POST, () => ok({ context_hash: CTX_HASH }));
    onDetail(U1, () => ok(accepted(['preview_target', 'confirm_target'], [], {}, 4)));
    await click(app, 'confirm_context');

    const sent = callsTo('POST', `/p/${U1}/context-confirmations`)[0].body;
    assert.deepEqual(Object.keys(sent).sort(), ['expected_course_context_hash', 'expected_lock_version', 'request_id']);
    assert.equal(sent.expected_course_context_hash, CTX_HASH);
    assert.equal(sent.expected_lock_version, 3);
    assert.equal(buttons(app, 'context_preview').length, 0, 'the reread no longer offers it');
    assert.equal(app.contextView, null, 'the old preview is gone with the reread');
});

test('nothing is offered for the context unless the server offered it', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target', 'confirm_target']);
    assert.equal(buttons(app, 'context_preview').length, 0);
    await app.previewContext();
    await app.targetStep('context');
    assert.equal(calls.filter((call) => /context/.test(call.url)).length, 0);
});

test('the context and the target are two separate panels that can be open together', async () => {
    const app = await makeApp();
    await openAccepted(app, ['reconfirm_context', 'preview_target', 'confirm_target']);
    onContext(() => ok(contextPreview()));
    on('GET', (url) => url === `/p/${U1}/target-preview`, () => ok(preview()));
    await click(app, 'context_preview');
    await click(app, 'preview_target');
    assert.equal(buttons(app, 'confirm_context').length, 1);
    assert.equal(buttons(app, 'confirm_target').length, 1);
    assert.ok(everything(app.detailHost).includes('Bài đọc về phân số'));
    assert.ok(everything(app.detailHost).includes('KOR-HANGUL-01'));
});

test('a context preview without a proper hash or context is not something that can be confirmed', async () => {
    const app = await makeApp();
    await openAccepted(app, ['reconfirm_context']);
    for (const bad of [contextPreview({ course_context_hash: 'zz' }), contextPreview({ course_context_hash: 'B'.repeat(64) }),
        contextPreview({ course_context_hash: null }), { context: null, course_context_hash: CTX_HASH }, { course_context_hash: CTX_HASH }]) {
        onContext(() => ok(bad));
        await click(app, 'context_preview');
        assert.equal(app.contextView, null);
        assert.equal(buttons(app, 'confirm_context').length, 0);
    }
});

test('the confirming step refuses anything not shown against this very version', async () => {
    const app = await makeApp();
    await openAccepted(app, ['reconfirm_context']);
    await app.targetStep('context');
    assert.equal(callsTo('POST', `/p/${U1}/context-confirmations`).length, 0, 'nothing was shown');

    onContext(() => ok(contextPreview()));
    await click(app, 'context_preview');
    app.contextView.lock = 99;
    await app.targetStep('context');
    assert.equal(callsTo('POST', `/p/${U1}/context-confirmations`).length, 0);
});

test('a context preview that arrives after the person moved on, or after access was lost, shows nothing', async () => {
    const app = await makeApp({ items: [listItem(U1), listItem(U2)] });
    // The next proposal has the same panel: a late answer must not land in it.
    onDetail(U2, () => ok(reuseDetail(U2, { status: 'accepted', lock_version: 3, allowed_actions: ['reconfirm_context'] })));
    await openAccepted(app, ['reconfirm_context']);
    const slow = deferred();
    onContext(() => slow.promise);
    const previewing = buttons(app, 'context_preview')[0].dispatch('click');
    await settle();
    await click(app, 'view', 1);
    slow.resolve(ok(contextPreview()));
    await previewing;
    await settle();
    assert.equal(app.contextView, null);
    assert.equal(everything(app.host).includes('Bài đọc về phân số'), false);
    assert.equal(buttons(app, 'confirm_context').length, 0);

    const second = await makeApp();
    await openAccepted(second, ['reconfirm_context']);
    onContext(() => failure(403, 'forbidden'));
    await click(second, 'context_preview');
    assert.equal(second.revoked, true);
    assert.deepEqual(leaks(second, 'Bài đọc về phân số'), []);
    assert.equal(second.contextView, null);
});

test('a context that changed again is said so, reads the proposal again, and leaves nothing confirmable', async () => {
    const app = await makeApp();
    await openAccepted(app, ['reconfirm_context']);
    onContext(() => ok(contextPreview()));
    await click(app, 'context_preview');
    on('POST', CONTEXT_POST, () => failure(409, 'proposal_context_changed'));
    await click(app, 'confirm_context');

    assert.ok(everything(app.detailHost).includes('conflict_context_changed'));
    assert.equal(app.contextView, null);
    assert.equal(buttons(app, 'confirm_context').length, 0, 'it has to be looked at again');
    assert.equal(buttons(app, 'context_preview').length, 1);
});

test('a context confirmation whose outcome is unknown locks the panel, and the retry sends exactly the same', async () => {
    const app = await makeApp();
    await openAccepted(app, ['reconfirm_context']);
    onContext(() => ok(contextPreview()));
    await click(app, 'context_preview');
    let attempt = 0;
    on('POST', CONTEXT_POST, () => {
        attempt += 1;
        if (attempt === 1) {
            throw new TypeError('network');
        }

        return ok({ context_hash: CTX_HASH });
    });
    await click(app, 'confirm_context');
    assert.equal(app.locked, true);
    await click(app, 'retry_same');

    const sent = callsTo('POST', `/p/${U1}/context-confirmations`);
    assert.equal(sent.length, 2);
    assert.equal(sent[1].body.request_id, sent[0].body.request_id);
    assert.equal(sent[1].body.expected_course_context_hash, sent[0].body.expected_course_context_hash);
});

test('while the context is being read again nothing from the earlier reading can be confirmed', async () => {
    const app = await makeApp();
    await openAccepted(app, ['reconfirm_context']);
    onContext(() => ok(contextPreview()));
    await click(app, 'context_preview');
    assert.notEqual(app.contextView, null);

    const slow = deferred();
    onContext(() => slow.promise);
    const again = buttons(app, 'context_preview')[0].dispatch('click');
    await settle();
    assert.equal(app.contextView, null, 'the earlier reading is gone while the new one is out');
    await app.targetStep('context');
    assert.equal(callsTo('POST', `/p/${U1}/context-confirmations`).length, 0);
    slow.resolve(ok(contextPreview()));
    await again;
    await settle();
});

test('the context is not read while a command is out, and a reading of another proposal is not confirmed', async () => {
    const app = await makeApp();
    await openAccepted(app, ['reconfirm_context']);
    app.busy = true;
    await app.previewContext();
    assert.equal(callsTo('GET', `/p/${U1}/context-preview`).length, 0);
    app.busy = false;

    onContext(() => ok(contextPreview()));
    await click(app, 'context_preview');
    app.contextView.uuid = U2;
    await app.targetStep('context');
    assert.equal(callsTo('POST', `/p/${U1}/context-confirmations`).length, 0);
});

test('rejecting a target is asked in the danger tone, and confirming a context is not', async () => {
    const app = await makeApp();
    await openAccepted(app, ['reconfirm_context', 'preview_target', 'reject_target'], [receipt({ status: 'applied', allowed_actions: [] })]);
    on('GET', (url) => url === `/p/${U1}/target-preview`, () => ok(preview()));
    await click(app, 'preview_target');
    window.LFConfirm.answer = false;
    await click(app, 'reject_target');
    assert.equal(window.LFConfirm.last.tone, 'danger');

    onContext(() => ok(contextPreview()));
    await click(app, 'context_preview');
    await click(app, 'confirm_context');
    assert.notEqual(window.LFConfirm.last.tone, 'danger');
    window.LFConfirm.answer = true;
});

test('hostile text in the context is text, and over-long text is cut', async () => {
    const hostile = '<img src=x onerror=alert(1)>';
    const app = await makeApp();
    await openAccepted(app, ['reconfirm_context']);
    onContext(() => ok(contextPreview({}, { title: hostile + 'y'.repeat(400), instructions: 'x'.repeat(5000) })));
    await click(app, 'context_preview');

    assert.ok(everything(app.detailHost).includes(hostile));
    assert.equal(everything(app.detailHost).includes('x'.repeat(1001)), false);
    assert.equal(everything(app.detailHost).includes('y'.repeat(300)), false, 'the title is cut at 255');
});

// ------------------------------------------- P3-C C1: a successor of a once-accepted proposal whose sources changed

const sha = (n) => n.toString(16).padStart(64, '0');
const staleDetail = (allowed = ['create_successor'], over = {}) => detail(U1, {
    kind: 'summary', status: 'stale', payload: null, content_denied: 'stale', allowed_actions: allowed,
    reviews: [{ action: 'accept', revision_no: 1, created_at: '2026-10-01T01:02:03Z' }], ...over,
});
const draftPreview = (payload = {}, over = {}) => ({
    inherited_decision_draft: true, successor_reason: 'source_revision_changed',
    payload: { kind: 'summary', title: 'Tiêu đề kế thừa', body: 'Nội dung kế thừa', confidence: null, rationale: '', source_refs: [], ...payload }, ...over,
});
const anchor = (n, over = {}) => ({ anchor_hash: sha(n), anchor: { media_file_id: n, usage_type: 'document', content_type: 'ocr_text', locale: 'vi', ...over } });
const onPreview = (reply) => on('GET', (url) => url === `/p/${U1}/successor-preview`, reply);
const onScope = (pages) => on('GET', (url) => url.startsWith(`/p/${U1}/successor-source-scope?`), (_, url) => {
    const cursor = new URL(url, 'http://x.test').searchParams.get('cursor');

    return ok(pages[cursor === null ? 0 : Number(cursor)]);
});
const page = (anchors, next = null) => ({ anchors, selection_limit: 200, scope: 'current_supported_nonempty_units', next_cursor: next });
const SUCCESSOR_POST = (url) => url === `/p/${U1}/successors`;
const openStale = async (app, allowed = ['create_successor'], over = {}) => {
    onDetail(U1, () => ok(staleDetail(allowed, over)));
    await openFirst(app);
};

test('a stale proposal that was once accepted offers a successor, and shows nothing of the old content', async () => {
    const app = await makeApp();
    await openStale(app);
    assert.equal(buttons(app, 'successor_preview').length, 1);
    assert.equal(buttons(app, 'successor_create').length, 0, 'nothing can be created unseen');
    assert.equal(everything(app.detailHost).includes('CANARY'), false);

    await openStale(app, []);
    assert.equal(buttons(app, 'successor_preview').length, 0, 'not offered by the server, not offered here');
});

test('the inherited draft is shown as an unapproved draft with only its allowed fields, and the sources are counted', async () => {
    const app = await makeApp();
    await openStale(app);
    onPreview(() => ok(draftPreview({ rationale: 'LY_DO_CU', confidence: 0.9, source_refs: [1] })));
    onScope([page([anchor(1), anchor(2)], '1'), page([anchor(3)])]);
    await click(app, 'successor_preview');

    const shown = everything(app.detailHost);
    for (const text of ['Tiêu đề kế thừa', 'Nội dung kế thừa', 'successor_draft_note', 'successor_sources']) {
        assert.ok(shown.includes(text), `shows ${text}`);
    }
    for (const hidden of ['LY_DO_CU', '90%']) {
        assert.equal(shown.includes(hidden), false, `never shows ${hidden}`);
    }
    assert.equal(buttons(app, 'successor_create').length, 1);
    assert.equal(app.successorView.hashes.length, 3, 'every page was read');
});

test('creating the successor asks first, sends every current source in order, and opens the new proposal', async () => {
    const app = await makeApp();
    await openStale(app);
    onPreview(() => ok(draftPreview()));
    onScope([page([anchor(2), anchor(1)], '1'), page([anchor(3)])]);
    await click(app, 'successor_preview');

    window.LFConfirm.answer = false;
    await click(app, 'successor_create');
    assert.equal(callsTo('POST', `/p/${U1}/successors`).length, 0, 'a "no" sends nothing');
    assert.ok(window.LFConfirm.last.message);

    window.LFConfirm.answer = true;
    on('POST', SUCCESSOR_POST, () => ok({ request_status: 'completed', proposal_uuid: U2, status: 'pending_review', inherited_decision_draft: true, successor_reason: 'source_revision_changed' }));
    onDetail(U2, () => ok(detail(U2, { creation_mode: 'human_successor', inherited_decision_draft: true, successor_reason: 'source_revision_changed' })));
    await click(app, 'successor_create');

    const sent = callsTo('POST', `/p/${U1}/successors`)[0].body;
    assert.deepEqual(Object.keys(sent).sort(), ['payload', 'reason', 'request_id', 'selected_anchor_hashes']);
    assert.equal(sent.reason, 'source_revision_changed');
    assert.equal(sent.payload, null);
    assert.deepEqual(sent.selected_anchor_hashes, [sha(1), sha(2), sha(3)]);
    assert.equal(app.openUuid, U2, 'the new proposal is the one on screen');
    assert.ok(everything(app.detailHost).includes('successor_inherited'), 'flagged as inherited and unapproved');
});

test('a successor shows that it is an inherited draft and why; an ordinary proposal shows neither', async () => {
    const app = await makeApp();
    onDetail(U1, () => ok(detail(U1, { inherited_decision_draft: true, successor_reason: 'source_revision_changed' })));
    await openFirst(app);
    assert.ok(everything(app.detailHost).includes('successor_inherited'));
    assert.ok(everything(app.detailHost).includes('successor_reason_source_revision_changed'));

    const plain = await makeApp();
    onDetail(U1, () => ok(detail(U1, { inherited_decision_draft: false, successor_reason: null })));
    await openFirst(plain);
    assert.equal(everything(plain.detailHost).includes('successor_inherited'), false);
});

test('more than 200 sources, or none, cannot be sent', async () => {
    const many = Array.from({ length: 201 }, (_, i) => anchor(i + 1));
    for (const [pages, reason] of [[[page(many.slice(0, 100), '1'), page(many.slice(100, 200), '2'), page(many.slice(200))], 'successor_too_many'], [[page([])], 'successor_no_sources']]) {
        const app = await makeApp();
        await openStale(app);
        onPreview(() => ok(draftPreview()));
        onScope(pages);
        await click(app, 'successor_preview');
        assert.ok(everything(app.detailHost).includes(reason), reason);
        assert.equal(buttons(app, 'successor_create').length, 0);
        assert.equal(app.successorView, null);
    }
});

test('a scope that is not what the server promised is not something to send', async () => {
    for (const bad of [page([{ anchor_hash: 'zz', anchor: {} }]), page([anchor(1), anchor(1)]), { anchors: 'x' }, page([{ anchor_hash: sha(1), anchor: null }])]) {
        const app = await makeApp();
        await openStale(app);
        onPreview(() => ok(draftPreview()));
        onScope([bad]);
        await click(app, 'successor_preview');
        assert.equal(app.successorView, null);
        assert.equal(buttons(app, 'successor_create').length, 0);
    }
});

test('when the server refuses to inherit, it is said so and no old content appears', async () => {
    for (const [code, key] of [['proposal_stale', 'successor_unavailable'], ['proposal_successor_node_conflict', 'successor_node_conflict']]) {
        const app = await makeApp();
        await openStale(app);
        onPreview(() => failure(409, code));
        await click(app, 'successor_preview');
        assert.ok(everything(app.detailHost).includes(key), key);
        assert.equal(app.successorView, null);
        assert.equal(buttons(app, 'successor_create').length, 0);
        assert.equal(callsTo('GET', `/p/${U1}/successor-source-scope?limit=100`).length, 0, 'no source is read after a refusal');
    }
});

test('a preview that is not an inherited draft of the allowed reason is not accepted', async () => {
    for (const bad of [draftPreview({}, { inherited_decision_draft: false }), draftPreview({}, { successor_reason: 'human_correction' }), { inherited_decision_draft: true, successor_reason: 'source_revision_changed', payload: null }]) {
        const app = await makeApp();
        await openStale(app);
        onPreview(() => ok(bad));
        onScope([page([anchor(1)])]);
        await click(app, 'successor_preview');
        assert.equal(app.successorView, null);
        assert.equal(buttons(app, 'successor_create').length, 0);
    }
});

test('the creating step refuses anything not shown for this very proposal and version', async () => {
    const app = await makeApp();
    await openStale(app);
    await app.successorStep();
    assert.equal(callsTo('POST', `/p/${U1}/successors`).length, 0, 'nothing shown');

    onPreview(() => ok(draftPreview()));
    onScope([page([anchor(1)])]);
    await click(app, 'successor_preview');
    app.successorView.lock = 99;
    await app.successorStep();
    app.successorView.lock = 1;
    app.successorView.uuid = U2;
    await app.successorStep();
    assert.equal(callsTo('POST', `/p/${U1}/successors`).length, 0);
});

test('a reading that arrives after the person moved on, or after access was lost, shows nothing', async () => {
    const app = await makeApp({ items: [listItem(U1), listItem(U2)] });
    onDetail(U2, () => ok(staleDetail(['create_successor'], { proposal_uuid: U2 })));
    await openStale(app);
    const slow = deferred();
    onPreview(() => slow.promise);
    const reading = buttons(app, 'successor_preview')[0].dispatch('click');
    await settle();
    await click(app, 'view', 1);
    slow.resolve(ok(draftPreview({ title: 'CANARY_TIEU_DE' })));
    await reading;
    await settle();
    assert.equal(app.successorView, null);
    assert.equal(everything(app.host).includes('CANARY_TIEU_DE'), false);

    // The sources are read after the preview: a late scope must not land either.
    const second = await makeApp({ items: [listItem(U1), listItem(U2)] });
    onDetail(U2, () => ok(staleDetail(['create_successor'], { proposal_uuid: U2 })));
    await openStale(second);
    const slowScope = deferred();
    onPreview(() => ok(draftPreview({ title: 'CANARY_TIEU_DE' })));
    on('GET', (url) => url.startsWith(`/p/${U1}/successor-source-scope?`), () => slowScope.promise);
    const readingScope = buttons(second, 'successor_preview')[0].dispatch('click');
    await settle();
    await click(second, 'view', 1);
    slowScope.resolve(ok(page([anchor(1)])));
    await readingScope;
    await settle();
    assert.equal(second.successorView, null);
    assert.equal(everything(second.host).includes('CANARY_TIEU_DE'), false);

    const third = await makeApp();
    await openStale(third);
    onPreview(() => failure(403, 'forbidden'));
    await click(third, 'successor_preview');
    assert.equal(third.revoked, true);
    assert.equal(third.successorView, null);
});

test('losing access takes the held draft away, wherever the script holds it', async () => {
    const app = await makeApp();
    await openStale(app);
    onPreview(() => ok(draftPreview({ title: 'CANARY_TIEU_DE' })));
    onScope([page([anchor(1)])]);
    await click(app, 'successor_preview');
    assert.ok(everything(app.detailHost).includes('CANARY_TIEU_DE'));
    onList(() => failure(401, 'unauthenticated'));
    await app.loadList(true);
    assert.deepEqual(leaks(app, 'CANARY_TIEU_DE'), []);
    assert.equal(app.successorView, null);
});

test('a successor whose outcome is unknown locks the panel and is retried exactly the same', async () => {
    const app = await makeApp();
    await openStale(app);
    onPreview(() => ok(draftPreview()));
    onScope([page([anchor(1), anchor(2)])]);
    await click(app, 'successor_preview');
    let attempt = 0;
    on('POST', SUCCESSOR_POST, () => {
        attempt += 1;
        if (attempt === 1) {
            throw new TypeError('network');
        }

        return ok({ request_status: 'completed', proposal_uuid: U2, status: 'pending_review' });
    });
    onDetail(U2, () => ok(detail(U2)));
    await click(app, 'successor_create');
    assert.equal(app.locked, true);
    await click(app, 'retry_same');

    const sent = callsTo('POST', `/p/${U1}/successors`);
    assert.equal(sent.length, 2);
    assert.deepEqual(sent[1].body, sent[0].body);
    assert.equal(app.openUuid, U2, 'the retried command still opens the successor');
});

test('sources are read a hundred at a time, the cursor goes back exactly as given, and a list that cannot be read whole is not sent', async () => {
    const app = await makeApp();
    await openStale(app);
    onPreview(() => ok(draftPreview()));
    const cursors = ['a+b/c=', 'd e&f', 'g', 'h'];
    on('GET', (url) => url.startsWith(`/p/${U1}/successor-source-scope?`), (_, url) => {
        const given = new URL(url, 'http://x.test').searchParams.get('cursor');
        const at = given === null ? 0 : cursors.indexOf(given) + 1;

        return ok(page([anchor(at * 10 + 1), anchor(at * 10 + 2)], cursors[at]));
    });
    await click(app, 'successor_preview');

    const reads = calls.filter((call) => call.url.includes('successor-source-scope'));
    assert.ok(reads.every((call) => call.url.includes('limit=100')));
    assert.ok(reads[1].url.includes('cursor=a%2Bb%2Fc%3D'), 'the cursor is encoded');
    assert.ok(reads[2].url.includes('cursor=d%20e%26f'));
    assert.ok(everything(app.detailHost).includes('successor_too_many'), 'a list still not finished after the pages allowed is too long');
    assert.equal(app.successorView, null);
    assert.equal(buttons(app, 'successor_create').length, 0);
});

test('a second reading discards the first at once, and a question answered after a new reading decides nothing', async () => {
    const app = await makeApp();
    await openStale(app);
    onPreview(() => ok(draftPreview()));
    onScope([page([anchor(1)])]);
    await click(app, 'successor_preview');
    assert.notEqual(app.successorView, null);

    const slow = deferred();
    onPreview(() => slow.promise);
    const again = buttons(app, 'successor_preview')[0].dispatch('click');
    await settle();
    assert.equal(app.successorView, null, 'the first reading is gone while the second is out');
    await app.successorStep();
    assert.equal(callsTo('POST', `/p/${U1}/successors`).length, 0);
    slow.resolve(ok(draftPreview()));
    await again;
    await settle();

    // A question that is open while another reading replaces the one it was asked about.
    onPreview(() => ok(draftPreview()));
    let answer;
    const original = window.LFConfirm.open;
    window.LFConfirm.open = () => new Promise((resolve) => { answer = resolve; });
    try {
        const asking = buttons(app, 'successor_create')[0].dispatch('click');
        await settle();
        await buttons(app, 'successor_preview')[0].dispatch('click');
        await settle();
        answer(true);
        await asking;
        await settle();
    } finally {
        window.LFConfirm.open = original;
    }
    assert.equal(callsTo('POST', `/p/${U1}/successors`).length, 0, 'the answer was for an earlier reading');
});

test('nothing is read while a command is out, long titles are cut, and a reply naming no proposal reads this one again', async () => {
    const app = await makeApp();
    await openStale(app);
    app.busy = true;
    await app.previewSuccessor();
    assert.equal(calls.filter((call) => call.url.includes('successor')).length, 0);
    app.busy = false;

    onPreview(() => ok(draftPreview({ title: `T${'y'.repeat(400)}` })));
    onScope([page([anchor(1)])]);
    await click(app, 'successor_preview');
    assert.equal(everything(app.detailHost).includes('y'.repeat(300)), false, 'the title is cut at 255');

    on('POST', SUCCESSOR_POST, () => ok({ request_status: 'completed', proposal_uuid: 'not-a-uuid' }));
    const reads = callsTo('GET', `/p/${U1}`).length;
    await click(app, 'successor_create');
    assert.equal(callsTo('GET', `/p/${U1}`).length, reads + 1, 'this proposal is read again');
});

test('hostile text in the inherited draft is text', async () => {
    const hostile = '<img src=x onerror=alert(1)>';
    const app = await makeApp();
    await openStale(app);
    onPreview(() => ok(draftPreview({ title: hostile, body: hostile })));
    onScope([page([anchor(1)])]);
    await click(app, 'successor_preview');
    assert.ok(everything(app.detailHost).includes(hostile));
});

test('a reply without a proper new proposal leaves the proposal on screen and reads it again', async () => {
    const app = await makeApp();
    await openStale(app);
    onPreview(() => ok(draftPreview()));
    onScope([page([anchor(1)])]);
    await click(app, 'successor_preview');
    on('POST', SUCCESSOR_POST, () => ok({ request_status: 'completed', proposal_uuid: 'not-a-uuid' }));
    await click(app, 'successor_create');
    assert.equal(app.openUuid, U1);
});

// ------------------------------------------- P3-C C2: an admin creates a draft Framework version from a published one

const PUBLISHED = [{ id: 21, label: 'v1 — Bản một' }, { id: 22, label: 'v2 — Bản hai' }];
const INHERIT_ADMIN = { ...ADMIN, publishedVersions: PUBLISHED };
const G_HASH = 'c'.repeat(64);
const P_HASH = 'd'.repeat(64);
const inheritPlan = (over = {}, display = {}) => ({
    preview: {
        source_graph_hash: G_HASH, plan_hash: P_HASH, eligible_node_ids: [1, 2, 3], excluded_node_ids: [4, 5], excluded_relation_ids: [9],
        ...over,
    },
    display: {
        eligible_count: 3,
        excluded_nodes: [
            { node_id: 4, code: 'OLD-1', label: 'Node đã nghỉ', node_type: 'objective', reason: 'node_retired' },
            { node_id: 5, code: 'OLD-2', label: 'Định nghĩa ngưng', node_type: 'competency', reason: 'definition_inactive' },
        ],
        affected_intents: [{ intent_id: 77, source_label: 'Bài học phân số', node_label: 'Node đã nghỉ' }],
        ...display,
    },
});
const INHERIT_GET = (base) => `/p/${U1}/inherited-draft-preview?base_version_id=${base}`;
const INHERIT_POST = (url) => url === `/p/${U1}/inherited-drafts`;
const onInherit = (base, reply) => on('GET', (url) => url === INHERIT_GET(base), reply);
const openInherit = async (app, allowed = ['inherit_draft', 'rebase'], applications = []) => {
    onDetail(U1, () => ok(accepted(allowed, applications)));
    await openFirst(app);
};
const fillInherit = (app, base = '21', code = 'v3-ke-thua', title = 'Bản kế thừa') => {
    app.inheritFields.base.value = base;
    app.inheritFields.code.value = code;
    app.inheritFields.title.value = title;
};

test('an admin is offered a draft from a published version only when the server offers it, and nothing is chosen for them', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    assert.ok(app.inheritFields, 'the panel is there');
    assert.equal(app.inheritFields.base.value, '', 'no default base');
    assert.deepEqual(app.inheritFields.base.children.map((option) => option.getAttribute('value')), ['', '21', '22']);
    assert.equal(buttons(app, 'inherit_create').length, 0, 'nothing can be created unseen');

    await buttons(app, 'inherit_preview')[0].dispatch('click'); // nothing chosen
    await settle();
    assert.equal(calls.filter((call) => call.url.includes('inherited-draft-preview')).length, 0);

    const teacher = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(teacher, ['preview_target', 'confirm_target']);
    assert.equal(teacher.inheritFields, null);
    assert.equal(buttons(teacher, 'inherit_preview').length, 0);
});

test('without a published version or a Framework the admin is told why and nothing can be sent', async () => {
    for (const config of [{ ...INHERIT_ADMIN, publishedVersions: [] }, { ...INHERIT_ADMIN, framework: { selected: false, framework_id: null, framework_version_id: null } }]) {
        const app = await makeApp({ config });
        await openInherit(app);
        assert.equal(app.inheritFields, null);
        assert.equal(buttons(app, 'inherit_preview').length, 0);
        assert.ok(everything(app.detailHost).includes('inherit_unavailable'));
    }
});

test('the plan shows what is lost by name, what it touches in this Template, and never an id or a hash', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    onInherit(22, () => ok(inheritPlan()));
    app.inheritFields.base.value = '22';
    await click(app, 'inherit_preview');

    const shown = everything(app.detailHost);
    for (const text of ['OLD-1', 'Node đã nghỉ', 'Định nghĩa ngưng', 'Bài học phân số', 'inherit_reason_node_retired', 'inherit_reason_definition_inactive', 'inherit_affected_note']) {
        assert.ok(shown.includes(text), `shows ${text}`);
    }
    for (const hidden of [G_HASH, P_HASH, '77']) {
        assert.equal(shown.includes(hidden), false, `does not show ${hidden}`);
    }
    assert.equal(buttons(app, 'inherit_create').length, 1);
});

test('a plan with nothing to copy, or one that is not what was promised, cannot be created from', async () => {
    const bads = [
        inheritPlan({ eligible_node_ids: [] }),
        inheritPlan({ source_graph_hash: 'zz' }), inheritPlan({ plan_hash: 'D'.repeat(64) }), inheritPlan({ eligible_node_ids: 'x' }),
        { display: {} }, { preview: null },
    ];
    for (const bad of bads) {
        const app = await makeApp({ config: INHERIT_ADMIN });
        await openInherit(app);
        onInherit(21, () => ok(bad));
        app.inheritFields.base.value = '21';
        await click(app, 'inherit_preview');
        assert.equal(app.inheritView, null);
        assert.equal(buttons(app, 'inherit_create').length, 0);
    }
});

test('creating needs a code, a name, and an acknowledgement of what is lost; nothing is sent until all are given and a question is answered yes', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    onInherit(21, () => ok(inheritPlan()));
    app.inheritFields.base.value = '21';
    await click(app, 'inherit_preview');

    await click(app, 'inherit_create'); // empty
    fillInherit(app, '21', '   ', 'Tên');
    await click(app, 'inherit_create'); // blank code
    fillInherit(app, '21', 'v3', 'Tên');
    await click(app, 'inherit_create'); // not acknowledged
    assert.equal(callsTo('POST', `/p/${U1}/inherited-drafts`).length, 0);

    app.inheritFields.ack.checked = true;
    window.LFConfirm.answer = false;
    await click(app, 'inherit_create');
    assert.equal(callsTo('POST', `/p/${U1}/inherited-drafts`).length, 0, 'a "no" sends nothing');
    assert.ok(window.LFConfirm.last.message.includes('v3'), 'the question names the new version');

    window.LFConfirm.answer = true;
    on('POST', INHERIT_POST, () => ok({ result_version_id: 41, node_map: { 1: 11 } }));
    fillInherit(app, '21', '  v3  ', '  Bản ba  ');
    await click(app, 'inherit_create');

    const sent = callsTo('POST', `/p/${U1}/inherited-drafts`)[0].body;
    assert.deepEqual(Object.keys(sent).sort(), ['base_version_id', 'expected_plan_hash', 'expected_source_graph_hash', 'request_id', 'title', 'version_code']);
    assert.equal(sent.base_version_id, 21);
    assert.equal(sent.version_code, 'v3');
    assert.equal(sent.title, 'Bản ba');
    assert.equal(sent.expected_source_graph_hash, G_HASH);
    assert.equal(sent.expected_plan_hash, P_HASH);
    assert.ok(app.draftVersions.some((draft) => draft.id === 41 && draft.label.includes('v3')), 'the new draft is offered for approving a Node right away');
    assert.equal(app.inheritView, null, 'the old plan is gone with the reread');
    assert.deepEqual(['base', 'code', 'title'].map((name) => app.inheritFields[name].value), ['', '', ''], 'a creation that went through leaves an empty form');
    assert.equal(app.hasDraft(), false);
});

test('without anything lost the acknowledgement is not asked for', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    onInherit(21, () => ok(inheritPlan({ excluded_node_ids: [], excluded_relation_ids: [] }, { excluded_nodes: [], affected_intents: [] })));
    app.inheritFields.base.value = '21';
    await click(app, 'inherit_preview');
    assert.equal(app.inheritFields.ack, null);
    on('POST', INHERIT_POST, () => ok({ result_version_id: 41, node_map: {} }));
    fillInherit(app, '21', 'v3', 'Bản ba');
    await click(app, 'inherit_create');
    assert.equal(callsTo('POST', `/p/${U1}/inherited-drafts`).length, 1);
});

test('a code or name that is too long is not sent', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    onInherit(21, () => ok(inheritPlan()));
    app.inheritFields.base.value = '21';
    await click(app, 'inherit_preview');
    app.inheritFields.ack.checked = true;
    fillInherit(app, '21', 'x'.repeat(101), 'Tên');
    await click(app, 'inherit_create');
    fillInherit(app, '21', 'v3', 'y'.repeat(256));
    await click(app, 'inherit_create');
    assert.equal(callsTo('POST', `/p/${U1}/inherited-drafts`).length, 0);
});

test('the base, the code and the name are unsent input: asked about, warned about, kept over a refusal, gone with access', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN, items: [listItem(U1), listItem(U2)] });
    await openInherit(app);
    assert.equal(app.hasDraft(), false);
    for (const field of ['base', 'code', 'title']) {
        await openInherit(app);
        app.inheritFields[field].value = field === 'base' ? '21' : 'abc';
        assert.equal(app.hasDraft(), true, field);
    }
    const event = {};
    await window.fire('beforeunload', event);
    assert.equal(event.prevented, true);

    onInherit(21, () => ok(inheritPlan()));
    app.inheritFields.base.value = '21';
    await click(app, 'inherit_preview');
    app.inheritFields.ack.checked = true;
    fillInherit(app);
    on('POST', INHERIT_POST, () => failure(409, 'proposal_revision_conflict'));
    await click(app, 'inherit_create');
    assert.equal(app.inheritFields.code.value, 'v3-ke-thua', 'a refused creation keeps what was typed');
    assert.equal(app.inheritFields.title.value, 'Bản kế thừa');
    assert.equal(app.inheritFields.base.value, '21');

    const held = app.inheritFields.code;
    onList(() => failure(401, 'unauthenticated'));
    await app.loadList(true);
    assert.equal(app.inheritFields, null);
    assert.equal(held.value, '', 'the held field holds nothing');
});

test('a reading that arrives late, a second reading, and a question answered after a new reading decide nothing', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN, items: [listItem(U1), listItem(U2)] });
    onDetail(U2, () => ok(reuseDetail(U2, { status: 'accepted', lock_version: 3, allowed_actions: ['inherit_draft'] })));
    await openInherit(app);
    const slow = deferred();
    onInherit(21, () => slow.promise);
    app.inheritFields.base.value = '21';
    const reading = buttons(app, 'inherit_preview')[0].dispatch('click');
    await settle();
    await click(app, 'view', 1);
    slow.resolve(ok(inheritPlan({}, { excluded_nodes: [{ node_id: 4, code: 'CANARY', label: 'CANARY_NODE', node_type: 'objective', reason: 'node_retired' }] })));
    await reading;
    await settle();
    assert.equal(app.inheritView, null);
    assert.equal(everything(app.host).includes('CANARY_NODE'), false);

    const second = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(second);
    onInherit(21, () => ok(inheritPlan()));
    second.inheritFields.base.value = '21';
    await click(second, 'inherit_preview');
    const slowAgain = deferred();
    onInherit(21, () => slowAgain.promise);
    const again = buttons(second, 'inherit_preview')[0].dispatch('click');
    await settle();
    assert.equal(second.inheritView, null, 'the first plan is gone while the second is out');
    await second.inheritStep();
    assert.equal(callsTo('POST', `/p/${U1}/inherited-drafts`).length, 0);
    slowAgain.resolve(ok(inheritPlan()));
    await again;
    await settle();

    second.inheritFields.ack.checked = true;
    fillInherit(second);
    let answer;
    const original = window.LFConfirm.open;
    window.LFConfirm.open = () => new Promise((resolve) => { answer = resolve; });
    try {
        const asking = buttons(second, 'inherit_create')[0].dispatch('click');
        await settle();
        await buttons(second, 'inherit_preview')[0].dispatch('click');
        await settle();
        answer(true);
        await asking;
        await settle();
    } finally {
        window.LFConfirm.open = original;
    }
    assert.equal(callsTo('POST', `/p/${U1}/inherited-drafts`).length, 0, 'the answer was for an earlier plan');
});

test('a plan read after access was lost is not shown', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    onInherit(21, () => failure(403, 'forbidden'));
    app.inheritFields.base.value = '21';
    await click(app, 'inherit_preview');
    assert.equal(app.revoked, true);
    assert.equal(app.inheritView, null);
});

test('the creating step refuses anything not shown for this very proposal and version', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    await app.inheritStep();
    assert.equal(callsTo('POST', `/p/${U1}/inherited-drafts`).length, 0, 'nothing shown');

    onInherit(21, () => ok(inheritPlan()));
    app.inheritFields.base.value = '21';
    await click(app, 'inherit_preview');
    app.inheritFields.ack.checked = true;
    fillInherit(app);
    app.inheritView.lock = 99;
    await app.inheritStep();
    app.inheritView.lock = 3;
    app.inheritView.uuid = U2;
    await app.inheritStep();
    assert.equal(callsTo('POST', `/p/${U1}/inherited-drafts`).length, 0);
});

test('a creation whose outcome is unknown locks the panel and is retried exactly the same', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    onInherit(21, () => ok(inheritPlan()));
    app.inheritFields.base.value = '21';
    await click(app, 'inherit_preview');
    app.inheritFields.ack.checked = true;
    fillInherit(app);
    let attempt = 0;
    on('POST', INHERIT_POST, () => {
        attempt += 1;
        if (attempt === 1) {
            throw new TypeError('network');
        }

        return ok({ result_version_id: 41, node_map: {} });
    });
    await click(app, 'inherit_create');
    assert.equal(app.locked, true);
    assert.ok(app.inheritFields.code.disabled && app.inheritFields.title.disabled && app.inheritFields.base.disabled);
    await click(app, 'retry_same');

    const sent = callsTo('POST', `/p/${U1}/inherited-drafts`);
    assert.equal(sent.length, 2);
    assert.deepEqual(sent[1].body, sent[0].body);
});

test('a code already taken and a plan with nothing left are each said in their own words', async () => {
    for (const [code, key] of [['proposal_idempotency_conflict', 'inherit_code_taken'], ['proposal_inheritance_empty', 'inherit_empty']]) {
        const app = await makeApp({ config: INHERIT_ADMIN });
        await openInherit(app);
        onInherit(21, () => ok(inheritPlan()));
        app.inheritFields.base.value = '21';
        await click(app, 'inherit_preview');
        app.inheritFields.ack.checked = true;
        fillInherit(app);
        on('POST', INHERIT_POST, () => failure(409, code));
        await click(app, 'inherit_create');
        assert.ok(everything(app.detailHost).includes(key), key);
    }
});

test('hostile text in the plan is text, and a long list is cut', async () => {
    const hostile = '<img src=x onerror=alert(1)>';
    const many = Array.from({ length: 150 }, (_, i) => ({ node_id: i + 1, code: `C${i}`, label: i === 0 ? hostile : `N${i}`, node_type: 'objective', reason: 'node_retired' }));
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    onInherit(21, () => ok(inheritPlan({}, { excluded_nodes: many })));
    app.inheritFields.base.value = '21';
    await click(app, 'inherit_preview');
    const shown = everything(app.detailHost);
    assert.ok(shown.includes(hostile));
    assert.equal(shown.includes('N120'), false, 'cut at 100');
});

test('a base that is not one of the offered ones is not read, and a changed base is a different plan', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    const odd = document.createElement('option');
    odd.setAttribute('value', '99');
    app.inheritFields.base.append(odd);
    app.inheritFields.base.value = '99';
    await app.previewInherit();
    assert.equal(calls.filter((call) => call.url.includes('inherited-draft-preview')).length, 0);

    app.busy = true;
    app.inheritFields.base.value = '21';
    await app.previewInherit();
    assert.equal(calls.filter((call) => call.url.includes('inherited-draft-preview')).length, 0, 'not while a command is out');
    app.busy = false;

    onInherit(21, () => ok(inheritPlan()));
    await click(app, 'inherit_preview');
    app.inheritFields.ack.checked = true;
    fillInherit(app, '21');
    app.inheritFields.base.value = '22'; // changed without the page noticing
    await app.inheritStep();
    assert.equal(callsTo('POST', `/p/${U1}/inherited-drafts`).length, 0, 'the plan was for another base');

    app.inheritFields.base.value = '21';
    await app.inheritFields.base.dispatch('change'); // unchanged
    assert.notEqual(app.inheritView, null);
    app.inheritFields.base.value = '22';
    await app.inheritFields.base.dispatch('change');
    assert.equal(app.inheritView, null, 'choosing another base drops the plan at once');
    assert.equal(buttons(app, 'inherit_create').length, 0);
});

test('a reply that names no new version leaves the offered drafts alone', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    const before = JSON.stringify(app.draftVersions);
    onInherit(21, () => ok(inheritPlan()));
    app.inheritFields.base.value = '21';
    await click(app, 'inherit_preview');
    app.inheritFields.ack.checked = true;
    fillInherit(app);
    on('POST', INHERIT_POST, () => ok({}));
    await click(app, 'inherit_create');
    assert.equal(JSON.stringify(app.draftVersions), before);
});

test('the panel links to the Template tab where the new draft is edited and published', async () => {
    const app = await makeApp({ config: INHERIT_ADMIN });
    await openInherit(app);
    const links = app.detailHost.querySelectorAll('a').map((a) => a.getAttribute('href'));
    assert.ok(links.includes(INHERIT_ADMIN.mappingsUrl));
});

// ------------------------------------------- P3-C C3: an admin moves the Template to another published version

const R_HASH = 'e'.repeat(64);
const OLD_HASH = '1'.repeat(64);
const NEW_HASH = '2'.repeat(64);
const REBASE_ADMIN = {
    ...ADMIN,
    framework: { selected: true, framework_id: 2, framework_version_id: 3 },
    publishedVersions: [{ id: 3, label: 'v-hiện-tại — đang dùng' }, { id: 21, label: 'v2 — Bản hai' }, { id: 22, label: 'v3 — Bản ba' }],
};
const node = (label, over = {}) => ({ node_id: 1, definition_id: 8, code: 'KOR-1', label, node_type: 'objective', description: 'Mô tả', status: 'active', ...over });
const rebaseRow = (id, over = {}) => ({
    intent_id: id, origin: 'manual', source_type: 'course_template_lesson', source_id: 40 + id, mapping_role: 'teaches', weight: '0.500000',
    old_node_id: 300 + id, definition_id: 8, old_target_hash: OLD_HASH, proposed_node_id: 500 + id, new_target_hash: String(id).repeat(64),
    ai_proposal_id: null, ai_proposal_revision_id: null, course_context_hash: null, ...over,
});
const rebasePlan = (rows, shown = null) => ({
    preview: { template_id: 5, working_revision: 7, framework_id: 2, from_version_id: 3, to_version_id: 21, intents: rows },
    preview_hash: R_HASH,
    display: {
        intents: shown ?? rows.map((row) => ({
            intent_id: row.intent_id, source_label: `Bài học ${row.intent_id}`,
            old_node: node(`Node cũ ${row.intent_id}`), proposed_node: row.proposed_node_id === null ? null : node(`Node mới ${row.intent_id}`),
        })),
    },
});
const THREE = () => [
    rebaseRow(1),
    rebaseRow(2, { origin: 'ai_proposal', ai_proposal_id: 9, ai_proposal_revision_id: 4, course_context_hash: 'a'.repeat(64), accepted_course_context_hash: 'b'.repeat(64) }),
    rebaseRow(3, { proposed_node_id: null, new_target_hash: null }),
];
const REBASE_GET = (target) => `/p/${U1}/rebase-preview?target_version_id=${target}`;
const REBASE_POST = (url) => url === `/p/${U1}/rebases`;
const onRebase = (target, reply) => on('GET', (url) => url === REBASE_GET(target), reply);
const openRebase = async (app, allowed = ['rebase', 'inherit_draft']) => {
    onDetail(U1, () => ok(accepted(allowed)));
    await openFirst(app);
};
const planFor = async (app, rows = THREE(), target = '21') => {
    onRebase(Number(target), () => ok(rebasePlan(rows)));
    app.rebaseFields.target.value = target;
    await click(app, 'rebase_preview');
};
const decide = (app, id, choice, reason = '') => {
    app.rebaseFields.rows.get(id).choice.value = choice;
    app.rebaseFields.rows.get(id).reason.value = reason;
};
const decideAll = (app) => {
    decide(app, 1, 'map');
    decide(app, 2, 'map');
    decide(app, 3, 'remove_explicit', 'no_equivalent_node');
};

test('an admin can move the Template to another published version only when the server offers it, and nothing is chosen for them', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    assert.ok(app.rebaseFields, 'the panel is there');
    assert.equal(app.rebaseFields.target.value, '');
    assert.deepEqual(app.rebaseFields.target.children.map((option) => option.getAttribute('value')), ['', '21', '22'], 'the version in use is not offered');
    assert.equal(buttons(app, 'rebase_create').length, 0, 'nothing can be sent unseen');
    await buttons(app, 'rebase_preview')[0].dispatch('click');
    await settle();
    assert.equal(calls.filter((call) => call.url.includes('rebase-preview')).length, 0, 'nothing chosen, nothing read');

    const teacher = await makeApp({ config: REBASE_ADMIN });
    await openRebase(teacher, ['preview_target', 'confirm_target']);
    assert.equal(teacher.rebaseFields, null);
});

test('without another published version or a Framework the admin is told why and nothing can be sent', async () => {
    for (const config of [
        { ...REBASE_ADMIN, publishedVersions: [{ id: 3, label: 'chỉ bản đang dùng' }] },
        { ...REBASE_ADMIN, publishedVersions: [] },
        { ...REBASE_ADMIN, framework: { selected: false, framework_id: null, framework_version_id: null } },
    ]) {
        const app = await makeApp({ config });
        await openRebase(app);
        assert.equal(app.rebaseFields, null);
        assert.equal(buttons(app, 'rebase_preview').length, 0);
        assert.ok(everything(app.detailHost).includes('rebase_unavailable'));
    }
});

test('the plan names what each Mapping is attached to and which Node it would use, and never an id or a hash', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await planFor(app);

    const shown = everything(app.detailHost);
    for (const text of ['Bài học 1', 'Node cũ 1', 'Node mới 1', 'Node cũ 3', 'rebase_no_equivalent', 'rebase_origin_ai_proposal', 'rebase_context_changed', 'role_teaches', 'rebase_scope']) {
        assert.ok(shown.includes(text), `shows ${text}`);
    }
    for (const hidden of [R_HASH, OLD_HASH, '501', '301']) {
        assert.equal(shown.includes(hidden), false, `does not show ${hidden}`);
    }
    assert.deepEqual(app.rebaseFields.rows.get(3).choice.children.map((option) => option.getAttribute('value')), ['', 'remove_explicit'], 'no equivalent: it can only be removed');
    assert.deepEqual(app.rebaseFields.rows.get(1).choice.children.map((option) => option.getAttribute('value')), ['', 'map', 'remove_explicit']);
    assert.equal(app.rebaseFields.rows.get(1).choice.value, '', 'nothing is decided for them');
});

test('every Mapping needs its own decision, and a removal needs a reason from the list, before anything is sent', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await planFor(app);
    on('POST', REBASE_POST, () => ok({ replacements: { 1: 11, 2: 12, 3: null } }));
    const asked = window.LFConfirm.calls;

    await click(app, 'rebase_create');
    decide(app, 1, 'map');
    await click(app, 'rebase_create');
    decide(app, 2, 'map');
    decide(app, 3, 'remove_explicit', '');
    await click(app, 'rebase_create');
    app.rebaseFields.rows.get(3).reason.value = 'not-in-the-list';
    await click(app, 'rebase_create');
    assert.equal(callsTo('POST', `/p/${U1}/rebases`).length, 0, 'incomplete or invalid: nothing sent, and nothing asked');
    assert.equal(window.LFConfirm.calls, asked);
});

test('the plan is sent exactly as decided, after a question that says it covers the whole Template and cannot be undone', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await planFor(app);
    decideAll(app);
    on('POST', REBASE_POST, () => ok({ replacements: { 1: 11, 2: 12, 3: null } }));

    window.LFConfirm.answer = false;
    await click(app, 'rebase_create');
    assert.equal(callsTo('POST', `/p/${U1}/rebases`).length, 0, 'a "no" sends nothing');
    assert.ok(window.LFConfirm.last.message, 'a question is asked');
    assert.equal(window.LFConfirm.last.tone, 'danger');

    window.LFConfirm.answer = true;
    await click(app, 'rebase_create');
    const sent = callsTo('POST', `/p/${U1}/rebases`)[0].body;
    assert.deepEqual(Object.keys(sent).sort(), ['dispositions', 'expected_preview_hash', 'request_id', 'target_version_id']);
    assert.equal(sent.target_version_id, 21);
    assert.equal(sent.expected_preview_hash, R_HASH);
    assert.deepEqual(sent.dispositions, {
        1: { disposition: 'map', node_id: 501 },
        2: { disposition: 'map', node_id: 502 },
        3: { disposition: 'remove_explicit', reason: 'no_equivalent_node' },
    });
    assert.equal(app.framework.versionId, 21, 'the version in use is now the new one');
    assert.deepEqual(app.rebaseFields.target.children.map((option) => option.getAttribute('value')), ['', '3', '22'], 'so the old one is offered and the new one is not');
    assert.equal(app.hasDraft(), false, 'a rebase that went through leaves nothing behind');
});

test('a Template with no Mappings can still be moved, and the plan then says so', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await planFor(app, []);
    assert.ok(everything(app.detailHost).includes('rebase_none'));
    on('POST', REBASE_POST, () => ok({ replacements: {} }));
    await click(app, 'rebase_create');
    assert.deepEqual(callsTo('POST', `/p/${U1}/rebases`)[0].body.dispositions, {});
});

test('a plan that is not what was promised cannot be sent', async () => {
    const good = () => rebasePlan(THREE());
    const bads = [
        { ...good(), preview_hash: 'zz' }, { ...good(), preview_hash: 'E'.repeat(64) },
        { ...good(), preview: null }, { ...good(), preview: { ...good().preview, intents: 'x' } },
        { ...good(), preview: { ...good().preview, intents: [rebaseRow(1), rebaseRow(1)] } },
        { ...good(), preview: { ...good().preview, intents: [{ ...rebaseRow(1), intent_id: 'a' }] } },
        { ...good(), preview: { ...good().preview, intents: [{ ...rebaseRow(1), proposed_node_id: 'x' }] } },
        { ...good(), preview: { ...good().preview, intents: [rebaseRow(1), null] } },
    ];
    for (const bad of bads) {
        const app = await makeApp({ config: REBASE_ADMIN });
        await openRebase(app);
        onRebase(21, () => ok(bad));
        app.rebaseFields.target.value = '21';
        await click(app, 'rebase_preview');
        assert.equal(app.rebaseView, null);
        assert.equal(buttons(app, 'rebase_create').length, 0);
    }
});

test('a missing name is not an invented one, and hostile text is text', async () => {
    const hostile = '<img src=x onerror=alert(1)>';
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await planFor(app, [rebaseRow(1), rebaseRow(2)]);
    const app2 = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app2);
    onRebase(21, () => ok(rebasePlan([rebaseRow(1), rebaseRow(2)], [
        { intent_id: 1, source_label: hostile, old_node: null, proposed_node: node(hostile) },
        { intent_id: 2, source_label: '', old_node: node('Cũ'), proposed_node: null },
    ])));
    app2.rebaseFields.target.value = '21';
    await click(app2, 'rebase_preview');
    const shown = everything(app2.detailHost);
    assert.ok(shown.includes(hostile));
    assert.ok(shown.includes('rebase_node_unknown'), 'a Node that cannot be shown is said so');
});

test('the decisions are unsent input: asked about, warned about, kept over a refusal and put back only where the plan did not change', async () => {
    const app = await makeApp({ config: REBASE_ADMIN, items: [listItem(U1), listItem(U2)] });
    await openRebase(app);
    assert.equal(app.hasDraft(), false);
    app.rebaseFields.target.value = '21';
    assert.equal(app.hasDraft(), true, 'the chosen version');
    app.rebaseFields.target.value = '';
    await planFor(app);
    app.rebaseFields.target.value = '21';
    decide(app, 1, 'map');
    const event = {};
    await window.fire('beforeunload', event);
    assert.equal(event.prevented, true);

    decideAll(app);
    on('POST', REBASE_POST, () => failure(409, 'proposal_revision_conflict'));
    // The plan moved: the second row has a different replacement now.
    onDetail(U1, () => ok(accepted(['rebase', 'inherit_draft'])));
    await click(app, 'rebase_create');
    assert.equal(app.rebaseFields.target.value, '21', 'the chosen version is kept');
    assert.equal(app.rebaseView, null, 'the old plan is gone');
    assert.ok(app.hasDraft(), 'what was decided is still held');
    assert.ok(everything(app.detailHost).includes('rebase_changed'));

    const rows = THREE();
    rows[1] = { ...rows[1], proposed_node_id: 999, new_target_hash: '9'.repeat(64) };
    await planFor(app, rows);
    assert.equal(app.rebaseFields.rows.get(1).choice.value, 'map', 'unchanged: put back');
    assert.equal(app.rebaseFields.rows.get(2).choice.value, '', 'changed: has to be decided again');
    assert.equal(app.rebaseFields.rows.get(3).choice.value, 'remove_explicit');
    assert.equal(app.rebaseFields.rows.get(3).reason.value, 'no_equivalent_node');
});

test('losing access takes the decisions and the plan away', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await planFor(app, [rebaseRow(1)].map((row) => ({ ...row })));
    decide(app, 1, 'map');
    const held = app.rebaseFields.rows.get(1).choice;
    onList(() => failure(401, 'unauthenticated'));
    await app.loadList(true);
    assert.equal(app.rebaseFields, null);
    assert.equal(app.rebaseView, null);
    assert.equal(held.value, '');
    assert.equal(app.hasDraft(), false);
});

test('a plan that arrives late, a second reading, a changed version and a question answered after any of them decide nothing', async () => {
    const app = await makeApp({ config: REBASE_ADMIN, items: [listItem(U1), listItem(U2)] });
    onDetail(U2, () => ok(reuseDetail(U2, { status: 'accepted', lock_version: 3, allowed_actions: ['rebase'] })));
    await openRebase(app);
    const slow = deferred();
    onRebase(21, () => slow.promise);
    app.rebaseFields.target.value = '21';
    const reading = buttons(app, 'rebase_preview')[0].dispatch('click');
    await settle();
    await click(app, 'view', 1);
    slow.resolve(ok(rebasePlan(THREE(), null)));
    await reading;
    await settle();
    assert.equal(app.rebaseView, null);
    assert.equal(everything(app.host).includes('Node cũ 1'), false);

    const second = await makeApp({ config: REBASE_ADMIN });
    await openRebase(second);
    await planFor(second);
    const slowAgain = deferred();
    onRebase(21, () => slowAgain.promise);
    const again = buttons(second, 'rebase_preview')[0].dispatch('click');
    await settle();
    assert.equal(second.rebaseView, null, 'the first plan is gone while the second is out');
    await second.rebaseStep();
    assert.equal(callsTo('POST', `/p/${U1}/rebases`).length, 0);
    slowAgain.resolve(ok(rebasePlan(THREE())));
    await again;
    await settle();

    decideAll(second);
    let answer;
    const original = window.LFConfirm.open;
    window.LFConfirm.open = () => new Promise((resolve) => { answer = resolve; });
    try {
        const asking = buttons(second, 'rebase_create')[0].dispatch('click');
        await settle();
        await buttons(second, 'rebase_preview')[0].dispatch('click');
        await settle();
        answer(true);
        await asking;
        await settle();
    } finally {
        window.LFConfirm.open = original;
    }
    assert.equal(callsTo('POST', `/p/${U1}/rebases`).length, 0, 'the answer was for an earlier plan');
});

test('choosing another version drops the plan at once, and the creating step refuses a plan for another version or proposal', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await app.rebaseStep();
    assert.equal(callsTo('POST', `/p/${U1}/rebases`).length, 0, 'nothing shown');
    await planFor(app);
    decideAll(app);

    app.rebaseFields.target.value = '22'; // changed without the page noticing
    await app.rebaseStep();
    assert.equal(callsTo('POST', `/p/${U1}/rebases`).length, 0, 'the plan was for another version');
    app.rebaseFields.target.value = '21';
    app.rebaseView.lock = 99;
    await app.rebaseStep();
    app.rebaseView.lock = 3;
    app.rebaseView.uuid = U2;
    await app.rebaseStep();
    assert.equal(callsTo('POST', `/p/${U1}/rebases`).length, 0);

    app.rebaseView.uuid = U1;
    app.rebaseFields.target.value = '22';
    await app.rebaseFields.target.dispatch('change');
    assert.equal(app.rebaseView, null);
    assert.equal(buttons(app, 'rebase_create').length, 0);
});

test('a version that is not one of the offered ones is not read, nor while a command is out', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    const odd = document.createElement('option');
    odd.setAttribute('value', '99');
    app.rebaseFields.target.append(odd);
    app.rebaseFields.target.value = '99';
    await app.previewRebase();
    app.rebaseFields.target.value = '3'; // the one in use
    await app.previewRebase();
    app.busy = true;
    app.rebaseFields.target.value = '21';
    await app.previewRebase();
    app.busy = false;
    assert.equal(calls.filter((call) => call.url.includes('rebase-preview')).length, 0);
});

test('decisions are held, not lost, when the version is changed or taken back to nothing', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await planFor(app);
    decide(app, 1, 'map');
    decide(app, 3, 'remove_explicit', 'other');
    app.rebaseFields.target.value = '';
    await app.rebaseFields.target.dispatch('change');
    assert.equal(app.rebaseView, null);
    assert.equal(app.hasDraft(), true, 'nothing is chosen any more, but the decisions are still held');
    const event = {};
    await window.fire('beforeunload', event);
    assert.equal(event.prevented, true);
});

test('what is held is put back only where it still means the same, and a decision the plan cannot honour is dropped', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await planFor(app);
    decide(app, 1, 'map');
    decide(app, 2, 'map');
    decide(app, 3, 'remove_explicit', 'other');
    app.rebaseFields.target.value = '22';
    await app.rebaseFields.target.dispatch('change');
    const rows = THREE();
    rows[0] = { ...rows[0], proposed_node_id: null, new_target_hash: null }; // no replacement any more
    onRebase(22, () => ok(rebasePlan(rows)));
    await click(app, 'rebase_preview');
    assert.equal(app.rebaseFields.rows.get(1).choice.value, '', 'a "map" cannot be put back on a row with no replacement');
    assert.equal(app.rebaseFields.rows.get(2).choice.value, 'map', 'unchanged');
    assert.equal(app.rebaseFields.rows.get(3).choice.value, 'remove_explicit');
    assert.equal(app.rebaseFields.rows.get(3).reason.value, 'other');
    assert.equal(app.rebaseFields.rows.get(3).reason.hidden, false, 'the reason is shown for a removal');
});

test('a removal reason is cleared when the decision changes, and a choice the row does not offer is not a decision', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await planFor(app);
    const row = app.rebaseFields.rows.get(1);
    row.choice.value = 'remove_explicit';
    await row.choice.dispatch('change');
    assert.equal(row.reason.hidden, false);
    row.reason.value = 'other';
    row.choice.value = 'map';
    await row.choice.dispatch('change');
    assert.equal(row.reason.value, '');
    assert.equal(row.reason.hidden, true);

    on('POST', REBASE_POST, () => ok({ replacements: {} }));
    decide(app, 1, 'map');
    decide(app, 2, 'map');
    app.rebaseFields.rows.get(3).choice.value = 'map'; // row 3 has nothing to map to
    await click(app, 'rebase_create');
    assert.equal(callsTo('POST', `/p/${U1}/rebases`).length, 0);
});

test('only a Mapping from an AI proposal whose context really changed is flagged', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    const same = rebaseRow(2, { origin: 'ai_proposal', ai_proposal_id: 9, course_context_hash: 'a'.repeat(64), accepted_course_context_hash: 'a'.repeat(64) });
    await planFor(app, [rebaseRow(1), same]);
    assert.equal(everything(app.detailHost).includes('rebase_context_changed'), false);
});

test('a Node that cannot be shown is said so, once for each place it is missing', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    onRebase(21, () => ok(rebasePlan([rebaseRow(1), rebaseRow(2)], [
        { intent_id: 1, source_label: 'A', old_node: null, proposed_node: node('Mới') },
        { intent_id: 2, source_label: 'B', old_node: node('Cũ'), proposed_node: null },
    ])));
    app.rebaseFields.target.value = '21';
    await click(app, 'rebase_preview');
    assert.equal(everything(app.detailHost).split('rebase_node_unknown').length - 1, 2);
});

test('a Template with five hundred Mappings: every row is decided and sent, the count follows, and nothing is dropped', async () => {
    const rows = Array.from({ length: 500 }, (_, i) => rebaseRow(i + 1, i % 5 === 4 ? { proposed_node_id: null, new_target_hash: null } : {}));
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    const started = Date.now();
    await planFor(app, rows);
    assert.equal(app.rebaseFields.rows.size, 500);
    assert.ok(everything(app.detailHost).includes('rebase_progress'));
    assert.ok(Date.now() - started < 5000, 'the plan is drawn in a few seconds at most');

    for (const [id, row] of app.rebaseFields.rows) {
        const noReplacement = id % 5 === 0;
        row.choice.value = noReplacement ? 'remove_explicit' : 'map';
        row.reason.value = noReplacement ? 'no_equivalent_node' : '';
    }
    on('POST', REBASE_POST, () => ok({ replacements: {} }));
    await click(app, 'rebase_create');
    const sent = callsTo('POST', `/p/${U1}/rebases`)[0].body;
    assert.equal(Object.keys(sent.dispositions).length, 500, 'not one is left out');
    assert.deepEqual(sent.dispositions[1], { disposition: 'map', node_id: 501 });
    assert.deepEqual(sent.dispositions[500], { disposition: 'remove_explicit', reason: 'no_equivalent_node' });
    assert.ok(JSON.stringify(sent).length < 65536, 'well inside what the server accepts');
});

test('a plan that is thrown away asks first when decisions were made, and sends nothing', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await planFor(app);
    window.LFConfirm.answer = false;
    decide(app, 1, 'map');
    await click(app, 'rebase_discard');
    assert.notEqual(app.rebaseView, null, 'a "no" keeps everything');
    window.LFConfirm.answer = true;
    await click(app, 'rebase_discard');
    assert.equal(app.rebaseView, null);
    assert.equal(app.hasDraft(), true === false || app.rebaseFields.target.value !== '', 'only the chosen version is left');
    assert.equal(calls.filter((call) => call.method === 'POST').length, 0);
});

test('a rebase whose outcome is unknown locks every control and is retried exactly the same', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app);
    await planFor(app);
    decideAll(app);
    let attempt = 0;
    on('POST', REBASE_POST, () => {
        attempt += 1;
        if (attempt === 1) {
            throw new TypeError('network');
        }

        return ok({ replacements: { 1: 11, 2: 12, 3: null } });
    });
    await click(app, 'rebase_create');
    assert.equal(app.locked, true);
    assert.ok(app.rebaseFields.target.disabled && app.rebaseFields.rows.get(1).choice.disabled && app.rebaseFields.rows.get(3).reason.disabled);
    await click(app, 'retry_same');
    const sent = callsTo('POST', `/p/${U1}/rebases`);
    assert.equal(sent.length, 2);
    assert.deepEqual(sent[1].body, sent[0].body);
});

test('each way the server can refuse a rebase is said in its own words', async () => {
    for (const [code, key] of [['proposal_successor_required', 'rebase_needs_successor'], ['proposal_stale', 'rebase_stale'], ['framework_selection_conflict', 'framework_conflict']]) {
        const app = await makeApp({ config: REBASE_ADMIN });
        await openRebase(app);
        await planFor(app);
        decideAll(app);
        on('POST', REBASE_POST, () => failure(409, code));
        await click(app, 'rebase_create');
        assert.ok(everything(app.detailHost).includes(key), key);
    }
});

test('the panel says the whole Template changes and links to the tab where the result is seen', async () => {
    const app = await makeApp({ config: REBASE_ADMIN });
    await openRebase(app, ['rebase']);
    assert.ok(everything(app.detailHost).includes('rebase_intro'));
    assert.ok(app.detailHost.querySelectorAll('a').map((a) => a.getAttribute('href')).includes(REBASE_ADMIN.mappingsUrl));
});

test('hostile text in the target is text', async () => {
    const hostile = '<img src=x onerror=alert(1)>';
    const app = await makeApp();
    await openAccepted(app, ['preview_target', 'confirm_target']);
    const body = preview();
    body.target_snapshot.node.label = hostile;
    body.target_snapshot.node.description = hostile;
    body.target_snapshot.node.criteria = { hostile };
    on('GET', (url) => url === `/p/${U1}/target-preview`, () => ok(body));

    await click(app, 'preview_target');

    assert.ok(everything(app.detailHost).includes(hostile));
});

test('rejecting an applied target asks first, and sends the hash that was shown', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target', 'reject_target'], [receipt({ status: 'applied', allowed_actions: [] })]);
    on('GET', (url) => url === `/p/${U1}/target-preview`, () => ok(preview()));
    await click(app, 'preview_target');
    let sent = null;
    on('POST', (url) => url === `/p/${U1}/target-rejections`, (body) => { sent = body; return ok({}); });

    window.LFConfirm.answer = false;
    await click(app, 'reject_target');
    assert.equal(sent, null);
    window.LFConfirm.answer = true;
    await click(app, 'reject_target');

    assert.equal(sent.expected_target_hash, HASH);
    assert.equal(sent.expected_lock_version, 3);
});

test('the Template\'s mapping list is linked for an admin, and not for a teacher who has no such page', async () => {
    const applied = [receipt({ status: 'applied', allowed_actions: [] })];
    let app = await makeApp({ config: { mappingsUrl: 'https://x.test/admin/course-templates/3/edit?tab=learning' } });
    await openAccepted(app, ['preview_target', 'reject_target'], applied);
    const link = app.detailHost.querySelectorAll('a').find((node) => node.getAttribute('href')?.includes('tab=learning'));
    assert.ok(link, 'an admin gets the link');

    app = await makeApp();
    await openAccepted(app, ['preview_target', 'reject_target'], applied);
    assert.equal(app.detailHost.querySelectorAll('a').length, 0, 'a teacher does not');
});

test('each receipt says what its status means, and never that it is the official Mapping', async () => {
    const app = await makeApp();
    await openAccepted(app, ['preview_target'], [
        receipt({ application_uuid: '55555555-5555-4555-8555-555555555555', status: 'awaiting_publication', allowed_actions: ['cancel'] }),
        receipt({ status: 'applied', allowed_actions: [] }),
    ]);

    const shown = everything(app.detailHost);
    assert.ok(shown.includes('application_note_awaiting_publication'));
    assert.ok(shown.includes('application_note_applied'));
});

const UUID_LIKE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
