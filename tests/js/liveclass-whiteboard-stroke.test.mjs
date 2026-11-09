import test from 'node:test';
import assert from 'node:assert/strict';
import { cancelsUnsentStroke, extendStroke, growStroke, mergeStrokeEvent } from '../../resources/js/liveclass-whiteboard/board-stroke.js';

// LF-Core-LiveClass § Whiteboard, Nét vẽ theo bút.

const pts = (from, to) => Array.from({ length: to - from }, (_, index) => [from + index, 0]);
const add = (count) => ({ type: 'stroke.add', page: 1, stroke: { id: 's1', tool: 'pen', points: pts(0, count) } });
const append = (from, to, id = 's1', page = 1) => ({ type: 'stroke.append', page, id, from, points: pts(from, to) });

test('unsent parts of one stroke fold into the queued add or append', () => {
    const merged = mergeStrokeEvent(add(3), append(3, 5));
    assert.equal(merged.type, 'stroke.add');
    assert.deepEqual(merged.stroke.points, pts(0, 5));

    const again = mergeStrokeEvent(append(5, 7), append(7, 9));
    assert.deepEqual({ from: again.from, points: again.points }, { from: 5, points: pts(5, 9) });
});

test('parts that do not join up, or belong to another stroke or page, stay separate', () => {
    assert.equal(mergeStrokeEvent(add(3), append(4, 6)), null);
    assert.equal(mergeStrokeEvent(add(3), append(3, 5, 's2')), null);
    assert.equal(mergeStrokeEvent(add(3), append(3, 5, 's1', 2)), null);
    assert.equal(mergeStrokeEvent({ type: 'note.add', page: 1 }, append(3, 5)), null);
    assert.equal(mergeStrokeEvent(null, append(3, 5)), null);
    assert.equal(mergeStrokeEvent(add(3), { type: 'stroke.remove', page: 1, ids: ['s1'] }), null);
});

test('merging does not change the queued event in place', () => {
    const queued = add(3);
    mergeStrokeEvent(queued, append(3, 5));
    assert.equal(queued.stroke.points.length, 3);
});

test('a viewer appends only the points it does not have yet', () => {
    const stroke = { id: 's1', points: pts(0, 5) };
    assert.equal(extendStroke(stroke, 5, pts(5, 8)), true);
    assert.deepEqual(stroke.points, pts(0, 8));

    // Replayed after a snapshot that already has part of it.
    assert.equal(extendStroke(stroke, 6, pts(6, 10)), true);
    assert.deepEqual(stroke.points, pts(0, 10));

    // Fully known already.
    assert.equal(extendStroke(stroke, 2, pts(2, 6)), true);
    assert.equal(stroke.points.length, 10);
});

test('a gap before the appended points asks for a resync', () => {
    const stroke = { id: 's1', points: pts(0, 3) };
    assert.equal(extendStroke(stroke, 5, pts(5, 8)), false);
    assert.equal(stroke.points.length, 3);
});

test('malformed parts are ignored', () => {
    const stroke = { id: 's1', points: pts(0, 3) };
    assert.equal(extendStroke(stroke, 'x', pts(3, 4)), true);
    assert.equal(extendStroke(stroke, 3, null), true);
    assert.equal(extendStroke(stroke, -1, pts(0, 4)), true);
    assert.equal(stroke.points.length, 3);
});

test('an add replayed after a shorter snapshot copy keeps the longer stroke', () => {
    const existing = { id: 's1', points: pts(0, 4) };
    growStroke(existing, { id: 's1', points: pts(0, 9) });
    assert.equal(existing.points.length, 9);

    growStroke(existing, { id: 's1', points: pts(0, 2) });
    assert.equal(existing.points.length, 9);
});

test('a stroke cut off before anything was sent cancels its queued add', () => {
    const remove = { type: 'stroke.remove', page: 1, ids: ['s1'] };
    assert.equal(cancelsUnsentStroke(add(4), remove), true);
    assert.equal(cancelsUnsentStroke(append(4, 6), remove), false, 'the add already went out');
    assert.equal(cancelsUnsentStroke(add(4), { ...remove, ids: ['s1', 's2'] }), false);
    assert.equal(cancelsUnsentStroke(add(4), { ...remove, ids: ['s9'] }), false);
});

test('a late joiner ends with the whole stroke, whatever the snapshot caught', () => {
    // Presenter: add(1) sent; then the snapshot is taken with 6 points while
    // the queue still holds append 1→4 and 4→6, followed by append 6→9.
    const viewer = { id: 's1', points: pts(0, 6) };
    [append(1, 4), append(4, 6), append(6, 9)].forEach((event) => {
        assert.equal(extendStroke(viewer, event.from, event.points), true);
    });
    assert.deepEqual(viewer.points, pts(0, 9));
});
