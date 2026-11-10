import test from 'node:test';
import assert from 'node:assert/strict';
import { POSITION_EVERY_SECONDS, shouldReportPosition, resumePosition, learnerActivity } from '../../resources/js/learner-lesson.js';

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
