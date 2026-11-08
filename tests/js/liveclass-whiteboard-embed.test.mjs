import test, { mock } from 'node:test';
import assert from 'node:assert/strict';
import { EmbedPlayer, HANDSHAKE_LIMIT_MS, PLAY_CONFIRM_MS, controlUrl, embedProvider } from '../../resources/js/liveclass-whiteboard/board-embed.js';
import { playMedia } from '../../resources/js/liveclass-whiteboard/board-media.js';

// LF-Media § Whiteboard Rendering Exception, phase 2: YouTube/Vimeo through postMessage.

const YT = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';
const VIMEO = 'https://player.vimeo.com/video/76979871';

test('only the server\'s normalized embed URLs are controlled', () => {
    assert.equal(embedProvider(YT), 'youtube');
    assert.equal(embedProvider(VIMEO), 'vimeo');
    for (const url of [
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
        'https://www.youtube-nocookie.com.evil.test/embed/dQw4w9WgXcQ',
        'http://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        `${YT}?autoplay=1`,
        `${YT}/../x`,
        'https://player.vimeo.com/video/',
        'https://evil.test/https://player.vimeo.com/video/1',
        null,
        42,
    ]) {
        assert.equal(embedProvider(url), null, String(url));
        assert.equal(controlUrl(url, 'https://a.test'), null);
    }
});

test('control parameters: YouTube API with the page origin; Vimeo without tracking', () => {
    const yt = new URL(controlUrl(YT, 'http://hkca.localhost:8010'));
    assert.equal(yt.origin + yt.pathname, YT);
    assert.equal(yt.searchParams.get('enablejsapi'), '1');
    assert.equal(yt.searchParams.get('origin'), 'http://hkca.localhost:8010');
    assert.equal(yt.searchParams.get('playsinline'), '1');
    const vimeo = new URL(controlUrl(VIMEO, 'http://hkca.localhost:8010'));
    assert.equal(vimeo.origin + vimeo.pathname, VIMEO);
    assert.equal(vimeo.searchParams.get('dnt'), '1');
    assert.equal(vimeo.searchParams.get('origin'), null);
});

function harness(provider = 'youtube') {
    let clock = 1_000_000;
    const listeners = new Set();
    const win = {
        addEventListener: (type, fn) => { if (type === 'message') { listeners.add(fn); } },
        removeEventListener: (type, fn) => { listeners.delete(fn); },
    };
    const posted = [];
    const contentWindow = { postMessage: (message, origin) => posted.push({ message: JSON.parse(message), origin }) };
    let onLoad = null;
    const frame = { contentWindow, addEventListener: (type, fn) => { if (type === 'load') { onLoad = fn; } } };
    const player = new EmbedPlayer({ frame, provider, win, now: () => clock });
    const origin = provider === 'youtube' ? 'https://www.youtube-nocookie.com' : 'https://player.vimeo.com';
    const events = [];
    ['play', 'pause', 'seeked', 'volumechange', 'loadedmetadata'].forEach((type) => player.addEventListener(type, () => events.push(type)));

    return {
        player,
        posted,
        events,
        listeners,
        load: () => onLoad(),
        tick: (ms) => { clock += ms; },
        send: (data, { source = contentWindow, from = origin } = {}) => listeners.forEach((fn) => fn({ source, origin: from, data: JSON.stringify(data) })),
        commands: () => posted.filter((item) => item.message.event === 'command' || item.message.method).map((item) => item.message.func ?? item.message.method),
    };
}

test('YouTube: handshake starts on load, foreign messages are ignored, queued commands flush once ready', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] });
    const h = harness();
    h.player.muted = true;
    h.player.currentTime = 12;
    h.player.currentTime = 20;
    assert.equal(h.posted.length, 0, 'nothing is posted before the frame loads');
    h.load();
    t.mock.timers.tick(600);
    assert.ok(h.posted.filter((item) => item.message.event === 'listening').length >= 2);
    assert.ok(h.posted.every((item) => item.origin === 'https://www.youtube-nocookie.com'));

    h.send({ event: 'onReady' }, { source: {} });
    h.send({ event: 'onReady' }, { from: 'https://evil.test' });
    assert.equal(h.player.ready, false, 'another window or origin cannot drive the player');

    h.send({ event: 'onReady' });
    assert.equal(h.player.ready, true);
    assert.deepEqual(h.events, ['loadedmetadata']);
    assert.deepEqual(h.commands(), ['addEventListener', 'mute', 'seekTo']);
    assert.deepEqual(h.posted.find((item) => item.message.func === 'seekTo').message.args, [20, true], 'only the last seek');
    const before = h.posted.length;
    t.mock.timers.tick(2000);
    assert.equal(h.posted.length, before, 'the handshake stops once ready');
});

test('YouTube: play resolves on "playing"; buffering changes nothing; pause and seek are reported', async () => {
    const h = harness();
    h.load();
    h.send({ event: 'onReady' });
    const playing = h.player.play();
    h.send({ event: 'infoDelivery', info: { playerState: 3, currentTime: 0 } });
    assert.equal(h.player.paused, true);
    h.send({ event: 'infoDelivery', info: { playerState: 1, currentTime: 0.2 } });
    await playing;
    assert.equal(h.player.paused, false);
    h.send({ event: 'onStateChange', info: 3 });
    assert.equal(h.player.paused, false, 'buffering while playing is not a pause');
    h.tick(2000);
    assert.ok(Math.abs(h.player.currentTime - 2.2) < 0.01, 'position moves on with the clock while playing');
    h.send({ event: 'infoDelivery', info: { currentTime: 2.6 } });
    assert.deepEqual(h.events, ['loadedmetadata', 'play'], 'a small difference is not a seek');
    h.send({ event: 'infoDelivery', info: { currentTime: 30 } });
    h.send({ event: 'onStateChange', info: 2 });
    assert.deepEqual(h.events, ['loadedmetadata', 'play', 'seeked', 'pause']);
    assert.equal(h.player.currentTime, 30);
});

test('a play that never starts is blocked (sound) or failed (muted), never pending forever', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] });
    const h = harness();
    h.load();
    h.send({ event: 'onReady' });
    const loud = h.player.play();
    t.mock.timers.tick(PLAY_CONFIRM_MS);
    await assert.rejects(loud, { name: 'NotAllowedError' });
    h.player.muted = true;
    const quiet = h.player.play();
    t.mock.timers.tick(PLAY_CONFIRM_MS);
    await assert.rejects(quiet, (error) => error.name === 'Error');

    const unanswered = harness();
    const never = unanswered.player.play();
    t.mock.timers.tick(HANDSHAKE_LIMIT_MS + PLAY_CONFIRM_MS);
    await assert.rejects(never, { name: 'NotAllowedError' }, 'a player that never answers still settles');
});

test('pause and close end a pending play like an element (AbortError), and close stops listening', async () => {
    const h = harness();
    h.load();
    h.send({ event: 'onReady' });
    const first = h.player.play();
    h.player.pause();
    await assert.rejects(first, { name: 'AbortError' });
    const second = h.player.play();
    h.player.destroy();
    await assert.rejects(second, { name: 'AbortError' });
    assert.equal(h.listeners.size, 0);
    await assert.rejects(h.player.play(), { name: 'AbortError' });
    const posted = h.posted.length;
    h.player.currentTime = 5;
    assert.equal(h.posted.length, posted, 'a closed player sends nothing');
});

test('a mute report right after the board set it is ignored; a later change is the viewer\'s own', () => {
    const h = harness();
    h.load();
    h.send({ event: 'onReady' });
    h.player.muted = true;
    h.send({ event: 'infoDelivery', info: { muted: false } });
    assert.equal(h.player.muted, true, 'stale report');
    assert.ok(! h.events.includes('volumechange'));
    h.tick(2000);
    h.send({ event: 'infoDelivery', info: { muted: false } });
    assert.equal(h.player.muted, false);
    assert.ok(h.events.includes('volumechange'));
});

test('Vimeo: subscribes on ready, follows play/pause/seeked, and a refused play is NotAllowedError', async () => {
    const h = harness('vimeo');
    h.player.currentTime = 7;
    h.send({ event: 'ready' });
    assert.deepEqual(h.commands(), ['addEventListener', 'addEventListener', 'addEventListener', 'addEventListener', 'addEventListener', 'setCurrentTime']);
    assert.ok(h.posted.every((item) => item.origin === 'https://player.vimeo.com'));
    const playing = h.player.play();
    h.send({ event: 'play', data: { seconds: 7 } });
    await playing;
    h.send({ event: 'seeked', data: { seconds: 15 } });
    h.send({ event: 'pause', data: { seconds: 16 } });
    assert.deepEqual(h.events, ['loadedmetadata', 'play', 'seeked', 'pause']);
    assert.equal(h.player.currentTime, 16);

    const refused = h.player.play();
    h.send({ event: 'error', data: { method: 'play', name: 'NotAllowedError' } });
    await assert.rejects(refused, { name: 'NotAllowedError' });
});

test('an embed takes the same muted fallback as an element when sound is blocked', async () => {
    const h = harness('vimeo');
    h.send({ event: 'ready' });
    let blocked = false;
    const result = playMedia(h.player, { muted: false, isCurrent: () => true, onBlocked: () => { blocked = true; } });
    h.send({ event: 'error', data: { method: 'play', name: 'NotAllowedError' } });
    await new Promise((resolve) => setImmediate(resolve));
    h.send({ event: 'play', data: { seconds: 0 } });
    assert.equal(await result, 'muted');
    assert.equal(blocked, true);
    assert.equal(h.player.muted, true);
    assert.deepEqual(h.commands().slice(-3), ['play', 'setMuted', 'play']);
});

mock.reset();
