import test from 'node:test';
import assert from 'node:assert/strict';
import { countdownText, installSessionCountdowns } from '../../resources/js/learner-session-countdown.js';

// An upcoming Session on the class page: on its own day the time left, before that the calendar days.

const labels = { countdown: 'Vào học sau: __TIME__', tomorrow: 'Ngày mai', days: 'Còn __DAYS__ ngày' };
// Sunday 22/11/2026 20:05 in Ho Chi Minh City (UTC+7); its day starts at 00:00 there.
const day = Date.UTC(2026, 10, 21, 17, 0, 0) / 1000;
const starts = day + (20 * 3600) + (5 * 60);
const at = (date, time) => Date.parse(`${date}T${time}+07:00`) / 1000;

test('on the Session\'s own day, from 00:00, the label counts down the time left', () => {
    assert.equal(countdownText(starts, day, at('2026-11-22', '00:00:00'), labels), 'Vào học sau: 20:05:00');
    assert.equal(countdownText(starts, day, at('2026-11-22', '19:04:59'), labels), 'Vào học sau: 01:00:01');
    assert.equal(countdownText(starts, day, starts + 30, labels), 'Vào học sau: 00:00:00', 'never below zero');
});

test('before that day it says "Ngày mai" or "Còn N ngày", in calendar days not 24-hour blocks', () => {
    assert.equal(countdownText(starts, day, at('2026-11-21', '23:59:59'), labels), 'Ngày mai');
    assert.equal(countdownText(starts, day, at('2026-11-21', '00:00:00'), labels), 'Ngày mai');
    // 30 hours ahead, yet two dates on: as the date beside it says.
    assert.equal(countdownText(starts, day, at('2026-11-20', '14:05:00'), labels), 'Còn 2 ngày');
    assert.equal(countdownText(starts, day, at('2026-11-20', '00:00:00'), labels), 'Còn 2 ngày');
    assert.equal(countdownText(starts, day, at('2026-11-15', '08:00:00'), labels), 'Còn 7 ngày');
});

test('every countdown button on the page is kept current once a second', () => {
    const label = { textContent: '' };
    const button = {
        dataset: { learnCountdown: String(starts), learnCountdownDay: String(day), countdownLabel: labels.countdown, countdownTomorrow: labels.tomorrow, countdownDays: labels.days },
        querySelector: () => label,
    };
    let now = at('2026-11-21', '22:00:00') * 1000;
    let tick = null;
    installSessionCountdowns({ querySelectorAll: () => [button] }, { Date: { now: () => now }, setInterval: (run, every) => { tick = run; assert.equal(every, 1000); } });
    assert.equal(label.textContent, 'Ngày mai');
    now = at('2026-11-22', '00:00:01') * 1000;
    tick();
    assert.equal(label.textContent, 'Vào học sau: 20:04:59');

    let started = false;
    installSessionCountdowns({ querySelectorAll: () => [] }, { setInterval: () => { started = true; } });
    assert.equal(started, false, 'a page without one starts no timer');
});
