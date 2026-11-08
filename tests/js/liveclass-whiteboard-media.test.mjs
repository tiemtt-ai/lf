import test from 'node:test';
import assert from 'node:assert/strict';
import {
    asksListenMode, createMediaPool, defaultListenMode, needsSeek, playMedia, playingFrom, primeElement, resolveListenMode, shouldMute, targetTime, unlockAudio,
} from '../../resources/js/liveclass-whiteboard/board-media.js';

// LF-Core-LiveClass § Âm thanh, video và chế độ nghe.

test('the playing state of a media event: explicit wins, otherwise by type, a seek says nothing', () => {
    assert.equal(playingFrom({ type: 'video.seek', playing: true }), true);
    assert.equal(playingFrom({ type: 'video.play', playing: false }), false);
    assert.equal(playingFrom({ type: 'video.play' }), true);
    assert.equal(playingFrom({ type: 'video.pause' }), false);
    assert.equal(playingFrom({ type: 'video.seek' }), null);
    assert.equal(playingFrom(null), null);
});

test('the target position moves on only while playing, from server time', () => {
    const at = 1_000_000;
    assert.equal(targetTime({ time: 10, playing: true, at }, at + 2500), 12.5);
    assert.equal(targetTime({ time: 10, playing: false, at }, at + 2500), 10, 'paused stays');
    assert.equal(targetTime({ time: 10, playing: null, at }, at + 2500), 10, 'unknown stays');
    assert.equal(targetTime({ time: 10, playing: true, at }, null), 10, 'unknown clock: as sent');
    assert.equal(targetTime({ time: 10, playing: true }, at), 10, 'no stamp: as sent');
    assert.equal(targetTime({ time: 10, playing: true, at }, at - 5000), 10, 'a clock behind never goes back');
    assert.equal(targetTime({ time: 0, playing: true, at }, at + 1e12), 6 * 3600, 'a broken clock is capped');
    assert.equal(targetTime({ time: -1, playing: true, at }, at), null);
    assert.equal(targetTime({ time: 'x' }, at), null);
    assert.equal(targetTime({}, at), null);
    assert.equal(targetTime(null, at), null);
});

test('a viewer seeks only past one second of drift', () => {
    assert.equal(needsSeek(10, 11), false);
    assert.equal(needsSeek(10, 11.01), true);
    assert.equal(needsSeek(12.5, 11), true);
    assert.equal(needsSeek(10, null), false);
});

test('listening mode defaults by delivery mode; hybrid asks once; a stored answer wins', () => {
    assert.equal(defaultListenMode('offline'), 'room');
    assert.equal(defaultListenMode('hybrid'), 'room', 'muted until the learner answers');
    assert.equal(defaultListenMode('online'), 'remote');
    assert.equal(defaultListenMode(undefined), 'remote');
    assert.equal(resolveListenMode('offline', 'remote'), 'remote');
    assert.equal(resolveListenMode('online', 'room'), 'room');
    assert.equal(resolveListenMode('online', 'loud'), 'remote', 'garbage in storage is ignored');
    assert.equal(asksListenMode('hybrid', null), true);
    assert.equal(asksListenMode('hybrid', 'room'), false);
    assert.equal(asksListenMode('hybrid', 'nonsense'), true);
    assert.equal(asksListenMode('online', null), false);
    assert.equal(asksListenMode('offline', null), false);
});

test('only a viewer is ever muted: in the room or while sound is blocked', () => {
    assert.equal(shouldMute({ viewing: false, listen: 'room', blocked: true }), false, 'the presenter always has sound');
    assert.equal(shouldMute({ viewing: true, listen: 'room', blocked: false }), true);
    assert.equal(shouldMute({ viewing: true, listen: 'remote', blocked: true }), true);
    assert.equal(shouldMute({ viewing: true, listen: 'remote', blocked: false }), false);
});

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((ok, fail) => { resolve = ok; reject = fail; });

    return { promise, resolve, reject };
}

function fakeElement(results) {
    const element = { muted: null, plays: [], log: [] };
    element.play = () => {
        element.plays.push(element.muted);
        const next = results.shift();

        return next instanceof Promise ? next : (next ? Promise.reject(next) : Promise.resolve());
    };

    return element;
}

const blocked = Object.assign(new Error('blocked'), { name: 'NotAllowedError' });
const aborted = Object.assign(new Error('aborted'), { name: 'AbortError' });

test('sound allowed: plays with the sound asked for', async () => {
    const element = fakeElement([null]);
    assert.equal(await playMedia(element, { muted: false, isCurrent: () => true }), 'playing');
    assert.deepEqual(element.plays, [false]);
});

test('sound blocked: the caller hears it before the element mutes, then it plays muted', async () => {
    const element = fakeElement([blocked, null]);
    const seen = [];
    const result = await playMedia(element, { muted: false, isCurrent: () => true, onBlocked: () => seen.push(element.muted) });
    assert.equal(result, 'muted');
    assert.deepEqual(seen, [false], 'onBlocked runs while the element still has sound');
    assert.deepEqual(element.plays, [false, true]);
    assert.equal(element.muted, true);
});

test('a refused muted play is a failure, never retried', async () => {
    const element = fakeElement([blocked]);
    let called = false;
    assert.equal(await playMedia(element, { muted: true, isCurrent: () => true, onBlocked: () => { called = true; } }), 'failed');
    assert.equal(called, false);
    assert.deepEqual(element.plays, [true]);
});

test('an interrupted play (pause or new source first) is not reported as a failure', async () => {
    assert.equal(await playMedia(fakeElement([aborted]), { muted: false, isCurrent: () => true }), 'interrupted');
    assert.equal(await playMedia(fakeElement([blocked, aborted]), { muted: false, isCurrent: () => true }), 'interrupted');
});

test('a late refusal for media that was closed meanwhile changes nothing', async () => {
    const late = deferred();
    const element = fakeElement([late.promise]);
    let current = true;
    let called = false;
    const pending = playMedia(element, { muted: false, isCurrent: () => current, onBlocked: () => { called = true; } });
    current = false;
    late.reject(blocked);
    assert.equal(await pending, 'stale');
    assert.equal(called, false, 'no "Bật tiếng" for closed media');
    assert.equal(element.muted, false, 'not muted behind the caller');
    assert.deepEqual(element.plays, [false], 'no retry');
});

test('media closed during the muted retry is stale too', async () => {
    const late = deferred();
    const element = fakeElement([blocked, late.promise]);
    let current = true;
    const pending = playMedia(element, { muted: false, isCurrent: () => current });
    await new Promise((resolve) => setTimeout(resolve, 0));
    current = false;
    late.resolve();
    assert.equal(await pending, 'stale');
});

test('a success that arrives after the media closed is stale', async () => {
    const late = deferred();
    const element = fakeElement([late.promise]);
    let current = true;
    const pending = playMedia(element, { muted: false, isCurrent: () => current });
    current = false;
    late.resolve();
    assert.equal(await pending, 'stale');
});

test('unlockAudio plays silence unmuted at volume 0 inside the click and never throws', () => {
    const made = [];
    class FakeAudio {
        constructor(src) {
            this.src = src;
            this.muted = false;
            this.volume = 1;
            this.attributes = {};
            made.push(this);
        }

        setAttribute(name, value) { this.attributes[name] = value; }

        play() {
            this.playedWith = { muted: this.muted, volume: this.volume };

            return Promise.resolve();
        }

        pause() { this.paused = true; }
    }
    const started = [];
    class FakeContext {
        constructor() { this.sampleRate = 48000; this.destination = {}; }

        resume() { return Promise.resolve(); }

        createBuffer(channels, length, rate) { return { channels, length, rate }; }

        createBufferSource() { return { connect() {}, start: (at) => started.push(at) }; }

        close() { return Promise.resolve(); }
    }
    unlockAudio({ Audio: FakeAudio, AudioContext: FakeContext });
    assert.equal(made.length, 1);
    assert.match(made[0].src, /^data:audio\/wav;base64,/);
    assert.deepEqual(made[0].playedWith, { muted: false, volume: 0 }, 'played synchronously, unmuted, silent');
    assert.equal(made[0].attributes.playsinline, 'true');
    assert.deepEqual(started, [0]);

    assert.doesNotThrow(() => unlockAudio({ Audio: class { constructor() { throw new Error('no audio'); } } }));
    assert.doesNotThrow(() => unlockAudio(undefined));
    assert.doesNotThrow(() => unlockAudio({}));
});

// iPhone allows sound per element: the board reuses one element per kind.

class FakeElement {
    constructor(play = () => Promise.resolve()) {
        this.attrs = {};
        this.muted = true;
        this.paused = true;
        this.loads = 0;
        this.playImpl = play;
    }

    get src() { return this.attrs.src ?? ''; }

    set src(value) { this.attrs.src = value; }

    getAttribute(name) { return this.attrs[name] ?? null; }

    removeAttribute(name) { delete this.attrs[name]; }

    play() {
        this.playedWith = { muted: this.muted, src: this.src };
        this.paused = false;

        return this.playImpl(this);
    }

    pause() { this.paused = true; }

    load() { this.loads += 1; }
}

test('the media pool gives the same element for a kind every time, one per kind', () => {
    const made = [];
    const pool = createMediaPool((tag) => { made.push(tag); return { tag }; });

    const audio = pool.take('audio');
    assert.equal(pool.take('audio'), audio);
    assert.notEqual(pool.take('video'), audio);
    assert.deepEqual(made, ['audio', 'video']);
});

test('priming an idle element plays silence unmuted inside the click, then empties it', async () => {
    const element = new FakeElement();
    primeElement(element);

    assert.equal(element.playedWith.muted, false, 'played synchronously and unmuted');
    assert.match(element.playedWith.src, /^data:audio\/wav;base64,/);
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(element.getAttribute('src'), null);
    assert.equal(element.paused, true);
    assert.equal(element.loads, 1);
});

test('priming never touches an element in use, nor a source set while the silence played', async () => {
    const busy = new FakeElement();
    busy.src = 'https://lf.test/media/1';
    primeElement(busy);
    assert.equal(busy.playedWith, undefined);
    assert.equal(busy.src, 'https://lf.test/media/1');

    let finish;
    const raced = new FakeElement(() => new Promise((resolve) => { finish = resolve; }));
    primeElement(raced);
    raced.src = 'https://lf.test/media/2';
    raced.paused = false;
    finish();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(raced.src, 'https://lf.test/media/2');
    assert.equal(raced.paused, false);
});

test('a refused priming play still empties the element and never throws', async () => {
    const element = new FakeElement(() => Promise.reject(new Error('NotAllowedError')));
    assert.doesNotThrow(() => primeElement(element));
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(element.getAttribute('src'), null);
    assert.doesNotThrow(() => primeElement({}));
});
