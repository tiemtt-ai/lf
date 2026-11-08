// Video and audio on the board (LF-Core-LiveClass § Âm thanh, video và chế độ nghe).
// Pure helpers, kept apart from the Alpine component so they can be tested in Node.

export const MEDIA_KINDS = ['video', 'embed', 'audio'];

/** The presenter repeats the position this often while media plays (`video.sync`, not cached). */
export const MEDIA_SYNC_MS = 5000;

/** A viewer seeks only when it is further than this from the presenter. */
export const DRIFT_SECONDS = 1;

/** A stamp older than this is treated as this old: a clock gone wrong must not jump to the end. */
const MAX_ELAPSED_SECONDS = 6 * 3600;

export const LISTEN_MODES = ['room', 'remote'];

// One sample of silence (AllVia VCR utils/unlockAudio.ts).
const SILENT_WAV = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';

/**
 * Whether the presenter's media is playing, from a media event: an explicit
 * `playing` wins; otherwise play means playing, pause means paused and a seek
 * says nothing (null).
 */
export function playingFrom(event) {
    if (typeof event?.playing === 'boolean') {
        return event.playing;
    }
    if (event?.type === 'video.play') {
        return true;
    }

    return event?.type === 'video.pause' ? false : null;
}

/**
 * The presenter's position now, in seconds, from a media event or snapshot
 * stamped with server time `at` (ms). Only playing media moves on; without a
 * stamp or a known server clock the sent time is used as it is.
 *
 * @param {{time?: number, playing?: boolean|null, at?: number}|null} state
 * @param {number|null} serverNow server time in ms, or null when unknown
 * @returns {number|null} null when the state has no usable time
 */
export function targetTime(state, serverNow) {
    const time = Number(state?.time);
    if (state?.time === undefined || state?.time === null || ! Number.isFinite(time) || time < 0) {
        return null;
    }
    const at = Number(state.at);
    if (state.playing !== true || ! state.at || ! Number.isFinite(at) || serverNow === null || ! Number.isFinite(serverNow)) {
        return time;
    }
    const elapsed = Math.min(MAX_ELAPSED_SECONDS, Math.max(0, (serverNow - at) / 1000));

    return time + elapsed;
}

export function needsSeek(current, target) {
    return target !== null && Number.isFinite(Number(current)) && Math.abs(Number(current) - target) > DRIFT_SECONDS;
}

/** offline and hybrid start in the room (muted, no echo with the room speaker); online starts remote. */
export function defaultListenMode(deliveryMode) {
    return deliveryMode === 'offline' || deliveryMode === 'hybrid' ? 'room' : 'remote';
}

export function resolveListenMode(deliveryMode, stored) {
    return LISTEN_MODES.includes(stored) ? stored : defaultListenMode(deliveryMode);
}

/** A hybrid Session asks each learner once; the answer is kept for that Session. */
export function asksListenMode(deliveryMode, stored) {
    return deliveryMode === 'hybrid' && ! LISTEN_MODES.includes(stored);
}

/** The one rule for a board's sound: only a viewer is ever muted, in the room or while sound is blocked. */
export function shouldMute({ viewing, listen, blocked }) {
    return Boolean(viewing) && (listen === 'room' || Boolean(blocked));
}

/**
 * Plays an element with the sound asked for. When the browser refuses sound
 * without a click, it plays muted instead, so the learner keeps the position,
 * and calls `onBlocked` before muting (the caller's mute rule must already say
 * "muted" when the element's volumechange event arrives).
 *
 * @returns {Promise<'playing'|'muted'|'interrupted'|'failed'|'stale'>}
 *   'stale' when the element is no longer the open media after an await.
 */
export async function playMedia(element, { muted, isCurrent, onBlocked = () => {} }) {
    element.muted = muted;
    try {
        await element.play();

        return isCurrent() ? 'playing' : 'stale';
    } catch (error) {
        if (! isCurrent()) {
            return 'stale';
        }
        if (error?.name === 'AbortError') {
            // A pause or a new source came first: not a failure to report.
            return 'interrupted';
        }
        if (muted || error?.name !== 'NotAllowedError') {
            return 'failed';
        }
    }
    onBlocked();
    element.muted = true;
    try {
        await element.play();

        return isCurrent() ? 'muted' : 'stale';
    } catch (error) {
        if (! isCurrent()) {
            return 'stale';
        }

        return error?.name === 'AbortError' ? 'interrupted' : 'failed';
    }
}

/**
 * One audio and one video element per board, reused for every media opened.
 * iPhone/Safari allows sound per element, after a click on that element: a new
 * element for each media would ask the learner for "Bật tiếng" every time the
 * presenter opens something new.
 */
export function createMediaPool(create) {
    const elements = new Map();

    return {
        take(tag) {
            if (! elements.has(tag)) {
                elements.set(tag, create(tag));
            }

            return elements.get(tag);
        },
    };
}

/**
 * Called inside a click on an idle pooled element (no source): plays silence
 * unmuted, so that element may play sound later without another click. An
 * element in use is left alone; a source set meanwhile is never touched.
 */
export function primeElement(element) {
    try {
        if (element.getAttribute('src')) {
            return;
        }
        element.muted = false;
        element.src = SILENT_WAV;
        const release = () => {
            if (element.getAttribute('src') === SILENT_WAV) {
                element.pause();
                element.removeAttribute('src');
                element.load();
            }
        };
        const played = element.play?.();
        if (played?.then) {
            played.then(release, release);
        } else {
            release();
        }
    } catch {
        // Best effort, like unlockAudio.
    }
}

/**
 * Called inside a click: plays a silent sound unmuted, which lets
 * iPhone/Safari play sound on this page afterwards (AllVia VCR unlockAudio).
 * Never throws and never waits.
 */
export function unlockAudio(win = globalThis.window) {
    try {
        if (win?.Audio) {
            const silent = new win.Audio(SILENT_WAV);
            silent.setAttribute?.('playsinline', 'true');
            // volume 0, not muted: a muted play does not count as an unlock on iOS.
            silent.volume = 0;
            const played = silent.play?.();
            played?.then?.(() => silent.pause(), () => {});
        }
        const Context = win?.AudioContext || win?.webkitAudioContext;
        if (Context) {
            const context = new Context();
            context.resume?.()?.catch?.(() => {});
            const source = context.createBufferSource();
            source.buffer = context.createBuffer(1, 1, context.sampleRate || 44100);
            source.connect(context.destination);
            source.start(0);
            setTimeout(() => context.close?.()?.catch?.(() => {}), 1000);
        }
    } catch {
        // Unlocking is best effort; the "Bật tiếng" button stays available.
    }
}
