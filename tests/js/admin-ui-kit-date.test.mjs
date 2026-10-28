import test from 'node:test';
import assert from 'node:assert/strict';
import { toIso, isoToDisplay, parseIso, monthCells, isDayAllowed, parseIsoDateTime, joinDateTime, isoDateTimeToDisplay } from '../../resources/js/admin-ui-kit-date.js';

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

// Date and time: the server gets yyyy-mm-ddThh:mm (what <input type=datetime-local> sent); the field shows dd/mm/yyyy hh:mm.

test('date-time values parse, join and display with zero padding', () => {
    assert.deepEqual(parseIsoDateTime('2026-06-09T07:05'), { date: '2026-06-09', hour: 7, minute: 5 });
    assert.deepEqual(parseIsoDateTime('2026-06-09T23:59:30'), { date: '2026-06-09', hour: 23, minute: 59 }, 'seconds tolerated');
    assert.equal(joinDateTime('2026-06-09', 7, 5), '2026-06-09T07:05');
    assert.equal(isoDateTimeToDisplay('2026-06-09T07:05'), '09/06/2026 07:05');
    assert.equal(isoDateTimeToDisplay(''), '');
});

test('impossible dates or times are rejected, not rolled over', () => {
    for (const bad of ['2025-02-29T10:00', '2026-06-09T24:00', '2026-06-09T10:60', '2026-06-09', '2026-06-09 10:00', null]) {
        assert.equal(parseIsoDateTime(bad), null, String(bad));
        assert.equal(isoDateTimeToDisplay(bad), '', String(bad));
    }
});

test('a date-time min / max limits the days by their date part', () => {
    assert.equal(isDayAllowed('2026-06-10', '2026-06-10T18:00', ''), true, 'the min day itself stays selectable');
    assert.equal(isDayAllowed('2026-06-09', '2026-06-10T00:00', ''), false);
    assert.equal(isDayAllowed('2026-06-20', '', '2026-06-20T08:00'), true);
    assert.equal(isDayAllowed('2026-06-21', '', '2026-06-20T23:59'), false);
});
