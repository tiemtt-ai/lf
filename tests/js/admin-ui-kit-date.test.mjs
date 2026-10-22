import test from 'node:test';
import assert from 'node:assert/strict';
import { toIso, isoToDisplay, parseIso, monthCells, isDayAllowed } from '../../resources/js/admin-ui-kit-date.js';

// Invariants: the server always gets ISO (yyyy-mm-dd); the field shows dd/mm/yyyy; weeks start on Monday;
// impossible dates are rejected instead of rolling over; days outside min / max cannot be picked.

test('ISO and display forms convert both ways and keep zero padding', () => {
    assert.equal(toIso(new Date(2026, 5, 9)), '2026-06-09');
    assert.equal(isoToDisplay('2026-06-09'), '09/06/2026');
    assert.equal(isoToDisplay(''), '');
    assert.equal(isoToDisplay('09/06/2026'), '');
    assert.equal(isoToDisplay(null), '');
});

test('parseIso accepts real dates only', () => {
    assert.deepEqual(parseIso('2024-02-29'), { year: 2024, month: 1, day: 29 });
    assert.equal(parseIso('2025-02-29'), null);
    assert.equal(parseIso('2026-13-01'), null);
    assert.equal(parseIso('2026-6-9'), null);
    assert.equal(parseIso(''), null);
});

test('a month starts on the right Monday-based column and has the right length', () => {
    assert.deepEqual(monthCells(2026, 5), { offset: 0, days: 30 });   // 1 June 2026 is a Monday
    assert.deepEqual(monthCells(2026, 6), { offset: 2, days: 31 });   // 1 July 2026 is a Wednesday
    assert.deepEqual(monthCells(2026, 1), { offset: 6, days: 28 });   // 1 Feb 2026 is a Sunday
    assert.equal(monthCells(2024, 1).days, 29);
});

test('min and max limit the selectable days, both ends included', () => {
    assert.equal(isDayAllowed('2026-06-10', '2026-06-10', '2026-06-20'), true);
    assert.equal(isDayAllowed('2026-06-20', '2026-06-10', '2026-06-20'), true);
    assert.equal(isDayAllowed('2026-06-09', '2026-06-10', '2026-06-20'), false);
    assert.equal(isDayAllowed('2026-06-21', '2026-06-10', '2026-06-20'), false);
    assert.equal(isDayAllowed('2026-06-21', '', ''), true);
});
