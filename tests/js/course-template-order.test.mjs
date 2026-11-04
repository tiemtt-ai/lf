import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { courseTemplateOrder, courseTemplateRefreshOutline } from '../../resources/js/course-template-order.js';

// Invariants are listed at the top of resources/js/course-template-order.js.

class Toast {
    constructor() { this.hidden = true; this.textContent = ''; }
    getAttribute() { return null; }
}

function setup(lessonIds = [1, 2, 3, 4], { numbered = true } = {}) {
    const flash = new Toast();
    const pageError = new Toast();
    const scope = {
        getAttribute: name => ({ 'data-csrf': 'TOKEN', 'data-msg-session-expired': 'SESSION' })[name] ?? null,
        querySelector: selector => (selector.includes('flash') ? flash : pageError),
    };
    const win = { document: { activeElement: null }, fetches: [], pending: [] };
    win.fetch = (url, options) => {
        win.fetches.push({ url, options, ids: JSON.parse(options.body).item_ids });
        return new Promise((resolve, reject) => win.pending.push({ resolve, reject }));
    };

    const children = [];
    const list = {
        children,
        closest: () => scope,
        contains: node => node === win.document.activeElement,
        getAttribute: name => ({ 'data-reorder-url': '/reorder', 'data-reorder-field': 'item_ids', 'data-order-number': numbered ? '.number' : null, 'data-msg-saved': 'SAVED', 'data-msg-error': 'ERROR', 'data-msg-outside': 'OUTSIDE' })[name],
        getBoundingClientRect: () => ({ top: 100, bottom: 500, left: 200, right: 800 }),
        appendChild(node) { this.remove(node); children.push(node); },
        insertBefore(node, before) {
            this.remove(node);
            children.splice(before ? children.indexOf(before) : children.length, 0, node);
        },
        remove(node) { const at = children.indexOf(node); if (at >= 0) children.splice(at, 1); },
        events: [],
        dispatchEvent(event) { this.events.push(event); },
    };
    const row = id => {
        const number = { textContent: 'old' };
        const node = {
            id,
            number,
            style: {},
            hasAttribute: name => name === 'data-order-id',
            getAttribute: () => String(id),
            querySelector: () => number,
            get nextSibling() { return children[children.indexOf(node) + 1] ?? null; },
        };
        node.handle = { focused: 0, focus() { this.focused += 1; }, closest: () => node };
        return node;
    };
    lessonIds.forEach(id => children.push(row(id)));

    const order = courseTemplateOrder(list, {
        window: win,
        createEvent: (type, detail) => ({ type, detail }),
    });
    const ids = () => children.map(node => node.id);
    const numbers = () => children.map(node => node.number.textContent);
    const by = id => children.find(node => node.id === id);
    const reply = (status, payload) => ({ ok: status >= 200 && status < 300, status, json: async () => payload });
    const tick = () => new Promise(resolve => setImmediate(resolve));
    const drag = (id, beforeId) => {
        list.insertBefore(by(id), by(beforeId));
        return order.dropped();
    };

    // What Sortable does on a drag: choose, the rows move, unchoose with the release event, then sort if the row moved.
    const dragTo = (id, beforeId, clientY) => {
        const before = ids();
        order.sortable.onChoose();
        list.insertBefore(by(id), by(beforeId));
        return release(before, clientY);
    };
    const release = (before, clientY) => {
        const moved = before.some((value, index) => value !== children[index].id);
        order.sortable.onUnchoose({ originalEvent: { clientY } });
        return moved ? order.dropped() : undefined;
    };

    // Row 1 moves to the end, then the drag ends with this event.
    const endWith = originalEvent => {
        const before = ids();
        order.sortable.onChoose();
        list.insertBefore(by(1), null);
        const moved = before.some((value, index) => value !== children[index].id);
        order.sortable.onUnchoose({ originalEvent });
        return moved ? order.dropped() : undefined;
    };

    return { win, list, order, ids, numbers, by, reply, tick, drag, dragTo, release, endWith, flash, pageError };
}

test('a drop saves the complete group as JSON with the CSRF token and renumbers the rows', async () => {
    const t = setup();
    const done = t.drag(3, 1);
    assert.deepEqual(t.ids(), [3, 1, 2, 4]);
    assert.deepEqual(t.numbers(), ['01', '02', '03', '04']);
    assert.equal(t.win.fetches.length, 1);
    const { url, options, ids } = t.win.fetches[0];
    assert.equal(url, '/reorder');
    assert.equal(options.method, 'PATCH');
    assert.equal(options.headers['X-CSRF-TOKEN'], 'TOKEN');
    assert.deepEqual(ids, [3, 1, 2, 4]);
    t.win.pending[0].resolve(t.reply(200, { message: 'x' }));
    await done;
    assert.equal(t.flash.textContent, 'SAVED');
    assert.equal(t.flash.hidden, false);
    assert.equal(t.pageError.hidden, true);
});

test('nothing is sent when the order did not change', async () => {
    const t = setup();
    await t.order.dropped();
    assert.equal(t.win.fetches.length, 0);
});

test('only one request at a time; moves made meanwhile are sent, latest order only, when it returns', async () => {
    const t = setup();
    t.drag(2, 1);
    t.drag(3, 2);
    t.drag(4, 2);
    assert.equal(t.win.fetches.length, 1);
    assert.deepEqual(t.win.fetches[0].ids, [2, 1, 3, 4]);
    t.win.pending[0].resolve(t.reply(200, {}));
    await t.tick();
    assert.equal(t.flash.hidden, true, 'nothing is announced while a newer order is unsaved');
    assert.equal(t.win.fetches.length, 2);
    assert.deepEqual(t.win.fetches[1].ids, t.ids());
    t.win.pending[1].resolve(t.reply(200, {}));
    await t.tick();
    assert.equal(t.win.fetches.length, 2);
    assert.equal(t.flash.textContent, 'SAVED');
});

test('a refusal puts the rows and numbers back and shows the server reason', async () => {
    for (const status of [409, 422]) {
        const t = setup();
        t.drag(3, 1);
        t.win.pending[0].resolve(t.reply(status, { message: 'REASON' }));
        await t.tick();
        assert.deepEqual(t.ids(), [1, 2, 3, 4]);
        assert.deepEqual(t.numbers(), ['01', '02', '03', '04']);
        assert.equal(t.pageError.textContent, 'REASON');
        assert.equal(t.pageError.hidden, false);
        assert.equal(t.flash.hidden, true);
    }
});

test('moves made while a refused request was on its way go back too, and are not sent', async () => {
    const t = setup();
    t.drag(3, 1);
    t.drag(4, 1);
    t.win.pending[0].resolve(t.reply(422, { message: 'REASON' }));
    await t.tick();
    assert.deepEqual(t.ids(), [1, 2, 3, 4]);
    assert.equal(t.win.fetches.length, 1);
});

test('a refusal goes back to the last accepted order, not the page-load order', async () => {
    const t = setup();
    t.drag(2, 1);
    t.win.pending[0].resolve(t.reply(200, {}));
    await t.tick();
    t.drag(4, 2);
    t.win.pending[1].resolve(t.reply(422, { message: 'REASON' }));
    await t.tick();
    assert.deepEqual(t.ids(), [2, 1, 3, 4]);
});

test('a failure of the network, the session or the server restores and uses our own message, not the raw body', async () => {
    const cases = [
        [t => t.win.pending[0].reject(new Error('offline')), 'ERROR'],
        [t => t.win.pending[0].resolve(t.reply(419, { message: 'CSRF token mismatch.' })), 'SESSION'],
        [t => t.win.pending[0].resolve(t.reply(401, { message: 'Unauthenticated.' })), 'SESSION'],
        [t => t.win.pending[0].resolve(t.reply(500, { message: 'Server Error' })), 'ERROR'],
        [t => t.win.pending[0].resolve(t.reply(404, { message: 'SQLSTATE secret' })), 'ERROR'],
        [t => t.win.pending[0].resolve({ ok: false, status: 422, json: async () => { throw new Error('html'); } }), 'ERROR'],
    ];
    for (const [fail, message] of cases) {
        const t = setup();
        t.drag(3, 1);
        fail(t);
        await t.tick();
        assert.deepEqual(t.ids(), [1, 2, 3, 4]);
        assert.equal(t.pageError.textContent, message);
    }
});

test('after a failure the next move saves again', async () => {
    const t = setup();
    t.drag(3, 1);
    t.win.pending[0].reject(new Error('offline'));
    await t.tick();
    t.drag(2, 1);
    assert.equal(t.win.fetches.length, 2);
    t.win.pending[1].resolve(t.reply(200, {}));
    await t.tick();
    assert.equal(t.pageError.hidden, true, 'a later success clears the old error');
    assert.equal(t.flash.textContent, 'SAVED');
});

test('the arrow keys move one place, keep the focus on the handle and name the row for the pager', async () => {
    const t = setup();
    const item = t.by(2);
    t.order.move(item.handle, 1);
    assert.deepEqual(t.ids(), [1, 3, 2, 4]);
    assert.equal(item.handle.focused, 1);
    assert.equal(t.list.events.at(-1).detail.item, item);
    t.win.pending[0].resolve(t.reply(200, {}));
    await t.tick();
    t.order.move(item.handle, -1);
    assert.deepEqual(t.ids(), [1, 2, 3, 4]);
});

test('the arrow keys stop at both ends without a request', async () => {
    const t = setup();
    await t.order.move(t.by(1).handle, -1);
    await t.order.move(t.by(4).handle, 1);
    assert.deepEqual(t.ids(), [1, 2, 3, 4]);
    assert.equal(t.win.fetches.length, 0);
});

test('a drop keeps the current page of the pager', async () => {
    const t = setup();
    t.drag(3, 1);
    assert.equal(t.list.events.at(-1).type, 'course-template-order-changed');
    assert.equal(t.list.events.at(-1).detail.item, null);
});

test('a restore keeps the focus on the handle that had it', async () => {
    const t = setup();
    const item = t.by(2);
    t.win.document.activeElement = item.handle;
    t.order.move(item.handle, 1);
    const before = item.handle.focused;
    t.win.pending[0].resolve(t.reply(422, { message: 'REASON' }));
    await t.tick();
    assert.equal(item.handle.focused, before + 1);
});

test('the instance is shared per list, so the order accepted so far is never read again from moved rows', () => {
    const t = setup();
    t.drag(3, 1);
    assert.equal(courseTemplateOrder(t.list), t.order);
});

test('a list without numbers (activities, sections) leaves the text of its rows alone', () => {
    const t = setup([1, 2, 3], { numbered: false });
    t.drag(3, 1);
    assert.deepEqual(t.numbers(), ['old', 'old', 'old']);
    assert.deepEqual(t.win.fetches[0].ids, [3, 1, 2]);
});

test('the arrow keys pass over rows hidden by a phase tab and stop at the last visible row', async () => {
    const t = setup([1, 2, 3, 4, 5]);
    t.by(2).style.display = 'none';
    t.by(4).style.display = 'none';
    t.order.move(t.by(1).handle, 1);
    assert.deepEqual(t.ids(), [2, 3, 1, 4, 5], 'one press moved it past 3, the next row in sight');
    t.win.pending[0].resolve(t.reply(200, {}));
    await t.tick();
    t.order.move(t.by(1).handle, -1);
    assert.deepEqual(t.ids(), [2, 1, 3, 4, 5]);
    t.win.pending[1].resolve(t.reply(200, {}));
    await t.tick();
    await t.order.move(t.by(1).handle, -1);
    assert.deepEqual(t.ids(), [2, 1, 3, 4, 5], 'row 2 is hidden, so 1 is already first in sight');
    assert.equal(t.win.fetches.length, 2);
});

function outlineFixture() {
    const section = (id, kids = []) => {
        const number = { textContent: 'old' };
        const childList = kids.length ? { children: kids } : null;
        return {
            id,
            number,
            childList,
            hasAttribute: name => name === 'data-section-id',
            getAttribute: () => String(id),
            querySelector: selector => (selector.includes('section-number') ? number : childList),
        };
    };
    const a1 = section(11);
    const a2 = section(12);
    const a = section(1, [a1, a2]);
    const b = section(2);
    const root = { children: [a, b] };
    const entry = id => {
        const number = { textContent: 'old' };
        return { id, number, getAttribute: () => String(id), querySelector: () => number };
    };
    const entries = [1, 11, 12, 2].map(entry);
    const ol = {
        children: entries,
        appendChild(node) { entries.splice(entries.indexOf(node), 1); entries.push(node); },
    };
    return { root, toc: { querySelector: () => ol }, a, b, a1, a2, entries };
}

test('moving a section renumbers its whole branch and reorders the table of contents', () => {
    const f = outlineFixture();
    f.root.children.reverse();
    f.a.childList.children.reverse();
    courseTemplateRefreshOutline(f.root, f.toc);
    assert.equal(f.b.number.textContent, '1');
    assert.equal(f.a.number.textContent, '2');
    assert.equal(f.a2.number.textContent, '2.1');
    assert.equal(f.a1.number.textContent, '2.2');
    assert.deepEqual(f.entries.map(e => e.id), [2, 1, 12, 11]);
    assert.deepEqual(f.entries.map(e => e.number.textContent), ['1', '2', '2.1', '2.2']);
});

test('the outline refresh works without a table of contents', () => {
    const f = outlineFixture();
    courseTemplateRefreshOutline(f.root, null);
    assert.equal(f.a2.number.textContent, '1.2');
});

test('a row released below its list goes back, says why and saves nothing', async () => {
    const t = setup();
    t.flash.hidden = false;
    await t.dragTo(1, null, 900);
    assert.deepEqual(t.ids(), [1, 2, 3, 4]);
    assert.deepEqual(t.numbers(), ['01', '02', '03', '04']);
    assert.equal(t.win.fetches.length, 0);
    assert.equal(t.pageError.textContent, 'OUTSIDE');
    assert.equal(t.pageError.hidden, false);
    assert.equal(t.flash.hidden, true, 'the previous saved message does not stay beside the refusal');
});

test('a row released above its list goes back too', async () => {
    const t = setup();
    await t.dragTo(4, 1, 20);
    assert.deepEqual(t.ids(), [1, 2, 3, 4]);
    assert.equal(t.win.fetches.length, 0);
    assert.equal(t.pageError.textContent, 'OUTSIDE');
});

test('a row taken straight out without passing another row still says why', async () => {
    const t = setup();
    t.order.sortable.onChoose();
    await t.release(t.ids(), 900);
    assert.equal(t.pageError.textContent, 'OUTSIDE');
    assert.equal(t.win.fetches.length, 0);
});

test('a release within the list or just past it saves as usual', async () => {
    for (const clientY of [100, 500, 88, 512]) {
        const t = setup();
        t.dragTo(1, null, clientY);
        assert.deepEqual(t.ids(), [2, 3, 4, 1], `release at ${clientY}`);
        assert.equal(t.win.fetches.length, 1);
        assert.equal(t.pageError.hidden, true);
    }
});

test('the first point past the tolerance is outside', async () => {
    for (const clientY of [87, 513]) {
        const t = setup();
        await t.dragTo(1, null, clientY);
        assert.deepEqual(t.ids(), [1, 2, 3, 4], `release at ${clientY}`);
    }
});

test('a touch release is read from changedTouches', async () => {
    const t = setup();
    t.order.sortable.onChoose();
    t.list.insertBefore(t.by(1), null);
    t.order.sortable.onUnchoose({ originalEvent: { changedTouches: [{ clientY: 900 }] } });
    await t.order.dropped();
    assert.deepEqual(t.ids(), [1, 2, 3, 4]);
    assert.equal(t.pageError.textContent, 'OUTSIDE');
});

test('a row released beside its list goes back and says why', async () => {
    for (const clientX of [187, 813]) {
        const t = setup();
        await t.endWith({ type: 'drop', clientX, clientY: 300 });
        assert.deepEqual(t.ids(), [1, 2, 3, 4], `release at ${clientX}`);
        assert.equal(t.win.fetches.length, 0);
        assert.equal(t.pageError.textContent, 'OUTSIDE');
    }
});

test('a release within the tolerance beside the list saves as usual', async () => {
    for (const clientX of [188, 812]) {
        const t = setup();
        t.endWith({ type: 'drop', clientX, clientY: 300 });
        assert.deepEqual(t.ids(), [2, 3, 4, 1], `release at ${clientX}`);
        assert.equal(t.win.fetches.length, 1);
        assert.equal(t.pageError.hidden, true);
    }
});

test('a dragend over the list was called off: back, nothing sent, nothing said', async () => {
    for (const [clientX, clientY] of [[200, 300], [800, 300], [500, 100], [500, 500]]) {
        const t = setup();
        await t.endWith({ type: 'dragend', clientX, clientY });
        assert.deepEqual(t.ids(), [1, 2, 3, 4], `dragend at ${clientX},${clientY}`);
        assert.equal(t.win.fetches.length, 0);
        assert.equal(t.pageError.hidden, true);
        assert.equal(t.flash.hidden, true);
    }
});

test('a dragend in the gap around the list is a release there and saves', async () => {
    for (const [clientX, clientY] of [[199, 300], [801, 300], [500, 99], [500, 501]]) {
        const t = setup();
        t.endWith({ type: 'dragend', clientX, clientY });
        assert.deepEqual(t.ids(), [2, 3, 4, 1], `dragend at ${clientX},${clientY}`);
        assert.equal(t.win.fetches.length, 1);
    }
});

test('a dragend outside the list goes back and says why', async () => {
    const t = setup();
    await t.endWith({ type: 'dragend', clientX: 1000, clientY: 300 });
    assert.deepEqual(t.ids(), [1, 2, 3, 4]);
    assert.equal(t.win.fetches.length, 0);
    assert.equal(t.pageError.textContent, 'OUTSIDE');
});

test('a drop over the list and a pointer release over the list save', async () => {
    for (const type of ['drop', 'pointerup', 'touchend']) {
        const t = setup();
        t.endWith({ type, clientX: 500, clientY: 300 });
        assert.deepEqual(t.ids(), [2, 3, 4, 1], type);
        assert.equal(t.win.fetches.length, 1, type);
    }
});

test('a touch the system took back goes back without a word, even in the gap', async () => {
    for (const clientY of [300, 505]) {
        const t = setup();
        await t.endWith({ type: 'touchcancel', changedTouches: [{ clientX: 500, clientY }] });
        assert.deepEqual(t.ids(), [1, 2, 3, 4], `touch at ${clientY}`);
        assert.equal(t.win.fetches.length, 0);
        assert.equal(t.pageError.hidden, true);
    }
});

test('a touch released beside the list is read from changedTouches', async () => {
    const t = setup();
    await t.endWith({ type: 'touchend', changedTouches: [{ clientX: 1000, clientY: 300 }] });
    assert.deepEqual(t.ids(), [1, 2, 3, 4]);
    assert.equal(t.pageError.textContent, 'OUTSIDE');
});

test('a drag called off leaves an earlier move on its way alone', async () => {
    const t = setup();
    t.drag(2, 1);
    assert.equal(t.win.fetches.length, 1);
    t.order.sortable.onChoose();
    t.list.insertBefore(t.by(3), null);
    t.order.sortable.onUnchoose({ originalEvent: { type: 'dragend', clientX: 500, clientY: 300 } });
    t.order.dropped();
    assert.deepEqual(t.ids(), [2, 1, 3, 4]);
    t.win.pending[0].resolve(t.reply(200, {}));
    await t.tick();
    assert.equal(t.win.fetches.length, 1, 'nothing more to save');
    assert.deepEqual(t.ids(), [2, 1, 3, 4]);
});

test('a press and release with no position does not cancel anything', async () => {
    const t = setup();
    t.order.sortable.onChoose();
    t.order.sortable.onUnchoose({ originalEvent: {} });
    assert.equal(t.pageError.hidden, true);
});

test('a cancelled drag goes back to its own start, keeping a move still on its way', async () => {
    const t = setup();
    t.drag(2, 1);
    assert.equal(t.win.fetches.length, 1);
    await t.dragTo(3, null, 900).catch?.(() => {});
    assert.deepEqual(t.ids(), [2, 1, 3, 4], 'the earlier move stays');
    t.win.pending[0].resolve(t.reply(200, {}));
    await t.tick();
    assert.equal(t.win.fetches.length, 1, 'nothing more to save');
    assert.deepEqual(t.ids(), [2, 1, 3, 4]);
});

test('a cancelled drag sends a move it did not make when that move is still unsaved', async () => {
    const t = setup();
    t.drag(2, 1);
    t.drag(4, 2);
    t.dragTo(3, null, 900);
    assert.deepEqual(t.ids(), [4, 2, 1, 3]);
    t.win.pending[0].resolve(t.reply(200, {}));
    await t.tick();
    assert.equal(t.win.fetches.length, 2);
    assert.deepEqual(t.win.fetches[1].ids, [4, 2, 1, 3]);
});

test('a refusal that lands during a drag replaces the order the drag would go back to', async () => {
    const t = setup();
    t.drag(2, 1);
    t.order.sortable.onChoose();
    t.win.pending[0].resolve(t.reply(422, { message: 'NO' }));
    await t.tick();
    assert.deepEqual(t.ids(), [1, 2, 3, 4]);
    t.list.insertBefore(t.by(4), t.by(3));
    await t.release([1, 2, 3, 4], 900);
    assert.deepEqual(t.ids(), [1, 2, 3, 4], 'the refused order does not come back');
    assert.equal(t.win.fetches.length, 1);
});

test('the keyboard is not affected by the release rule', async () => {
    const t = setup();
    t.order.move(t.by(1).handle, 1);
    assert.deepEqual(t.ids(), [2, 1, 3, 4]);
    assert.equal(t.win.fetches.length, 1);
});

test('the views wire the handles, the pager and the plugin', () => {
    const read = path => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
    const lessons = read('resources/views/course-template-lessons/partials/list.blade.php');
    const node = read('resources/views/course-template-sections/partials/section-node.blade.php');
    const sections = read('resources/views/course-template-sections/partials/list.blade.php');
    const app = read('resources/js/app.js');
    for (const field of ['lesson_ids', 'activity_ids']) assert.match(lessons, new RegExp(`data-reorder-field="${field}"`));
    assert.match(lessons, /data-order-number="\.course-template-lesson-number"/);
    assert.match(lessons, /x-on:course-template-order-changed="\$event\.target === \$refs\.list && refresh\(\$event\.detail\.item\)"/, 'only the list of the pager moves its page, not an activity list inside it');
    assert.equal(lessons.match(/x-sort\.ghost="courseTemplateOrder\(\$el\)\.dropped\(\)"/g).length, 2);
    for (const [view, count] of [[lessons, 2], [node, 1], [sections, 1]]) {
        assert.equal(view.match(/x-sort:config="courseTemplateOrder\(\$el\)\.sortable"/g).length, count);
        assert.equal(view.match(/data-msg-outside="/g).length, count);
    }
    assert.equal(lessons.match(/x-show="query\.trim\(\) === ''/g).length, 2, 'no handle while searching');
    assert.match(lessons, /@js\(\$activityPhaseCounts\)\[phase\] > 1/, 'no handle on a phase tab with one activity');
    assert.match(node, /x-show="query\.trim\(\) === ''"/);
    assert.match(node, /data-reorder-field="section_ids"/);
    assert.match(sections, /data-reorder-field="section_ids"/);
    assert.match(sections, /x-on:course-template-order-changed="/);
    assert.match(app, /Alpine\.plugin\(sort\);\s*Alpine\.start\(\);/);
});
