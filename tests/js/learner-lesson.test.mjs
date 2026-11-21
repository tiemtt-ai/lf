import test from 'node:test';
import assert from 'node:assert/strict';
import { POSITION_EVERY_SECONDS, shouldReportPosition, resumePosition, learnerActivity, learnerCompletion, furtherStatus, PROGRESS_EVENT, asksToResume, clock, playedPercent, stamp } from '../../resources/js/learner-lesson.js';

// ADR-0030: the page only reports what happened; positions go at most every 15 s of playback,
// a learner resumes where they stopped, and the server's answer is what the page shows.

const config = (extra = {}) => ({
    status: 'not_started', percentage: 0, resumeAt: 0, tracksPosition: true, durationKnown: true,
    progressUrl: '/progress', mediaUrl: '/media', csrf: 'token', messages: { failed: 'Failed' }, ...extra,
});

function withFetch(answers) {
    const calls = [];
    globalThis.fetch = async (url, options = {}) => {
        calls.push({ url, body: options.body ? JSON.parse(options.body) : null });
        const answer = answers(url, calls.length);

        return { ok: answer.ok ?? true, status: answer.status ?? 200, json: async () => answer.json };
    };

    return calls;
}

const progress = (status, percentage) => ({ json: { progress: { status, percentage, completed: status === 'completed' } } });

test('a position is reported at most once per interval', () => {
    assert.equal(POSITION_EVERY_SECONDS, 15);
    assert.equal(shouldReportPosition(null, 1000), true);
    assert.equal(shouldReportPosition(1000, 1000 + 14_999), false);
    assert.equal(shouldReportPosition(1000, 1000 + 15_000), true);
});

test('playback resumes from the stored second unless it is at the end', () => {
    assert.equal(resumePosition(120, 600), 120);
    assert.equal(resumePosition(596, 600), 0);
    assert.equal(resumePosition('42', NaN), 42);
    assert.equal(resumePosition(null, 600), 0);
});

test('the server answer replaces the shown status', async () => {
    const calls = withFetch(() => progress('completed', 100));
    const activity = learnerActivity(config({ tracksPosition: false }));
    await activity.markDone();
    assert.deepEqual(calls[0].body, { event: 'completed' });
    assert.equal(activity.completed, true);
    assert.equal(activity.percentage, 100);
    assert.equal(activity.busy, false);
});

test('a refused event shows the error and keeps the old status', async () => {
    withFetch(() => ({ ok: false, status: 403 }));
    const activity = learnerActivity(config({ status: 'in_progress', tracksPosition: false }));
    await activity.markDone();
    assert.equal(activity.status, 'in_progress');
    assert.equal(activity.error, 'Failed');
});

test('time updates send the playback position no more than once per interval', async () => {
    const calls = withFetch(() => progress('in_progress', 10));
    const activity = learnerActivity(config());
    activity.$refs = { player: { currentTime: 60 } };
    activity.onTime();
    activity.onTime();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(calls.length, 1);
    assert.deepEqual(calls[0].body, { event: 'position', position_seconds: 60 });
});

test('the end of a Media without a known duration also sends completed', async () => {
    const calls = withFetch((url, n) => progress(n === 1 ? 'in_progress' : 'completed', n === 1 ? 0 : 100));
    const activity = learnerActivity(config({ durationKnown: false }));
    activity.$refs = { player: { duration: Infinity, currentTime: 300 } };
    activity.onEnded();
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.deepEqual(calls.map((call) => call.body.event), ['position', 'completed']);
    assert.equal(activity.completed, true);

    const known = withFetch(() => progress('in_progress', 50));
    const other = learnerActivity(config());
    other.$refs = { player: { duration: 600, currentTime: 600 } };
    other.onEnded();
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.deepEqual(known.map((call) => call.body.event), ['position']);
});

test('a player without a source does not show an error, an expired one is fetched again once', async () => {
    const calls = withFetch(() => ({ json: { url: '/fresh' } }));
    const activity = learnerActivity(config());
    activity.$nextTick = async () => {};
    await activity.onError();
    assert.equal(activity.error, '');
    assert.equal(calls.length, 0);

    const player = { currentTime: 42, addEventListener() {}, load() {} };
    activity.$refs = { player };
    activity.src = '/expired';
    await activity.onError();
    assert.equal(activity.src, '/fresh');
    assert.equal(activity.error, '');
    await activity.onError();
    assert.equal(activity.error, 'Failed');
});

test('a document opens in a new tab, or records first when no tab can be opened', async () => {
    const order = [];
    const calls = withFetch((url) => (url === '/media' ? { json: { url: '/file.pdf' } } : progress('completed', 100)));
    const tab = { location: {} };
    globalThis.window = { open: () => tab, location: {} };
    const activity = learnerActivity(config({ tracksPosition: false }));
    await activity.openDocument();
    assert.equal(tab.location.href, '/file.pdf');
    assert.equal(tab.opener, null);
    assert.deepEqual(calls.map((call) => call.url), ['/media', '/progress']);
    assert.equal(activity.completed, true);

    const location = {};
    Object.defineProperty(location, 'href', { set: (value) => order.push(['navigate', value]) });
    globalThis.window = { open: () => null, location };
    withFetch((url) => {
        order.push(['fetch', url]);

        return url === '/media' ? { json: { url: '/file.pdf' } } : progress('completed', 100);
    });
    await learnerActivity(config({ tracksPosition: false })).openDocument();
    assert.deepEqual(order, [['fetch', '/media'], ['fetch', '/progress'], ['navigate', '/file.pdf']]);
    delete globalThis.window;
});

test('an external link records its opening and the link itself opens the tab', async () => {
    const calls = withFetch(() => progress('completed', 100));
    const activity = learnerActivity(config({ tracksPosition: false }));
    await activity.openLink();
    assert.deepEqual(calls.map((call) => [call.url, call.body]), [['/progress', { event: 'opened' }]]);
    assert.equal(activity.completed, true);

    withFetch(() => ({ ok: false, status: 403 }));
    const refused = learnerActivity(config({ tracksPosition: false }));
    await refused.openLink();
    assert.equal(refused.error, 'Failed');
    assert.equal(refused.completed, false);
});

test('a video stopped part-way asks before playing, unless it is near its end (MK popup)', () => {
    assert.equal(asksToResume(120, 600), true);
    assert.equal(asksToResume(0, 600), false);
    assert.equal(asksToResume(596, 600), false);
    assert.equal(asksToResume(30, 0), true);
});

test('the popup footer shows hh:mm:ss and the played share rounded down', () => {
    assert.equal(clock(33.9), '00:00:33');
    assert.equal(clock(3725), '01:02:05');
    assert.equal(playedPercent(32.9, 1808, false), 1);
    assert.equal(playedPercent(150, 600, false), 25);
    assert.equal(playedPercent(10, 600, true), 100);
    assert.equal(playedPercent(10, 0, false), 0);
    assert.equal(stamp(new Date(2026, 10, 19, 20, 49)), '2026-11-19 20:49');
});

test('opening asks first; continuing resumes, starting over plays from zero', async () => {
    const calls = withFetch((url) => (url === '/media' ? { json: { url: 'https://cdn/v.mp4' } } : progress('in_progress', 1)));
    const activity = learnerActivity(config({ resumeAt: 120, duration: 600, playedSeconds: 150 }));
    let loaded = null;
    const player = {
        currentTime: 0, duration: 600,
        addEventListener: (event, callback) => { if (event === 'loadedmetadata') loaded = callback; },
        load: () => loaded?.(), play: () => Promise.resolve(),
    };
    activity.$refs = { player };
    activity.$nextTick = async () => {};

    activity.openMedia();
    assert.equal(activity.asking, true);
    assert.equal(activity.started, false);
    assert.equal(activity.resumeClock, '00:02:00');
    assert.equal(activity.watchedClock, '00:02:30');
    assert.equal(activity.watchedPercent, 25);

    activity.startOver();
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(activity.asking, false);
    assert.equal(activity.started, true);
    assert.equal(player.currentTime, 0);
    assert.match(activity.viewed, /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/);
    assert.deepEqual(calls.map((call) => call.body?.event ?? call.url), ['/media', 'opened']);
});

test('the card share follows playback and gives way to the completed label (MK "Đã nghe 25%")', async () => {
    // No duration from the course: the file's own once loaded.
    const calls = withFetch(() => progress('completed', 100));
    const activity = learnerActivity(config({ duration: 0 }));
    const player = { currentTime: 0, duration: 52 };
    activity.$refs = { player };
    activity.mediaDuration = 52;
    for (const time of [10, 11, 12, 13]) {
        player.currentTime = time;
        activity.lastReportedAt = Date.now();
        activity.onTime();
    }
    assert.equal(activity.cardPercent, 25);
    assert.equal(calls.length, 0);

    activity.onPause();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(activity.completed, true);
    assert.equal(activity.cardPercent, 0);
    assert.equal(activity.watchedPercent, 100);
});

// The class page: content completed there shows on its cards and in "Hoàn thành x/y" at once, without a reload.

function withWindow() {
    const events = [];
    globalThis.window = { dispatchEvent: (event) => events.push(event) };

    return events;
}

const deferred = () => {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });

    return { promise, resolve };
};

test('progress only moves forward, so a late answer never takes it back', async () => {
    assert.equal(furtherStatus('not_started', 'in_progress'), 'in_progress');
    assert.equal(furtherStatus('in_progress', 'completed'), 'completed');
    assert.equal(furtherStatus('completed', 'in_progress'), 'completed');
    assert.equal(furtherStatus('in_progress', 'unknown'), 'in_progress');

    // "Mở nội dung" answered after "Đánh dấu đã học": the card stays completed.
    const opened = deferred();
    globalThis.fetch = async (url, options) => {
        const { event } = JSON.parse(options.body);
        if (event === 'opened') {
            await opened.promise;

            return { ok: true, json: async () => progress('in_progress', 0).json };
        }

        return { ok: true, json: async () => progress('completed', 100).json };
    };
    const activity = learnerActivity(config({ tracksPosition: false }));
    const late = activity.send('opened');
    await activity.markDone();
    opened.resolve();
    await late;
    assert.equal(activity.completed, true);
});

test('a card says what the server recorded and tells the page, once it answers', async () => {
    const events = withWindow();
    withFetch(() => progress('completed', 100));
    const labels = { opened: 'Đã mở', completed: 'Đã mở' };
    const activity = learnerActivity(config({ tracksPosition: false, activityId: 235, labels }));
    assert.equal(activity.stateLabel, '');
    await activity.markDone();
    assert.equal(activity.stateLabel, 'Đã mở');
    assert.equal(events.length, 1);
    assert.equal(events[0].type, PROGRESS_EVENT);
    assert.deepEqual(events[0].detail, { activity: 235, status: 'completed' });

    // Attendance completed the Link học with it: the page counts that too, and only on a completion.
    events.length = 0;
    withFetch(() => ({ json: { progress: { status: 'completed', percentage: 100, lesson_completed: [214, 217] } } }));
    await learnerActivity(config({ tracksPosition: false, activityId: 217, labels })).send('opened');
    assert.deepEqual(events.map((event) => event.detail), [{ activity: 217, status: 'completed' }, { activity: 214, status: 'completed' }]);
    events.length = 0;
    withFetch(() => ({ json: { progress: { status: 'in_progress', percentage: 10, lesson_completed: [214] } } }));
    await learnerActivity(config({ activityId: 218, labels })).send('position', { position_seconds: 5 });
    assert.deepEqual(events.map((event) => event.detail), [{ activity: 218, status: 'in_progress' }]);

    // A refused event tells nothing.
    events.length = 0;
    withFetch(() => ({ ok: false, status: 403 }));
    await learnerActivity(config({ tracksPosition: false, activityId: 236, labels })).markDone();
    assert.equal(events.length, 0);
    delete globalThis.window;

    const opening = learnerActivity(config({ status: 'in_progress', labels: { opened: 'Đã mở', completed: 'Đã xem' } }));
    assert.equal(opening.stateLabel, 'Đã mở');
    const video = learnerActivity(config({ status: 'in_progress', labels: { opened: '', completed: 'Đã xem' } }));
    assert.equal(video.stateLabel, '');
});

test('another card of the same Activity follows; other Activities and older states do not change it', () => {
    const card = learnerActivity(config({ activityId: 235 }));
    card.syncProgress({ activity: 999, status: 'completed' });
    assert.equal(card.completed, false);
    card.syncProgress({ activity: '235', status: 'completed' });
    assert.equal(card.completed, true);
    card.syncProgress({ activity: 235, status: 'in_progress' });
    assert.equal(card.completed, true);
    // The lesson page gives no id: it follows nothing.
    const lesson = learnerActivity(config());
    lesson.syncProgress({ activity: undefined, status: 'completed' });
    assert.equal(lesson.completed, false);
});

test('"Hoàn thành x/y" counts a required Activity completed on the page once, and only a required one', () => {
    const labels = { done: '✓ Đã hoàn thành buổi học', progress: 'Hoàn thành :done/:total nội dung bắt buộc' };
    const session = learnerCompletion({ required: [235, 236], done: [], labels });
    assert.equal(session.text, 'Hoàn thành 0/2 nội dung bắt buộc');
    assert.equal(session.percent, 0);

    session.record({ activity: 235, status: 'in_progress' });
    session.record({ activity: 999, status: 'completed' });
    assert.equal(session.count, 0);

    session.record({ activity: 235, status: 'completed' });
    session.record({ activity: '235', status: 'completed' });
    assert.equal(session.text, 'Hoàn thành 1/2 nội dung bắt buộc');
    assert.equal(session.percent, 50);
    assert.equal(session.finished, false);

    session.record({ activity: 236, status: 'completed' });
    assert.equal(session.finished, true);
    assert.equal(session.text, '✓ Đã hoàn thành buổi học');
    assert.equal(session.percent, 100);

    // The class figure: 19/34 → 20/34, 56% → 59%, as the server rounds it.
    const required = Array.from({ length: 34 }, (_, i) => i + 1);
    const figure = learnerCompletion({ required, done: required.slice(0, 19).concat([500]) });
    assert.equal(figure.count, 19);
    assert.equal(figure.percent, 56);
    figure.record({ activity: 20, status: 'completed' });
    assert.equal(`${figure.count}/${figure.total}`, '20/34');
    assert.equal(figure.percent, 59);
});
