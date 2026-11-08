// Trusted YouTube and Vimeo embeds on the board (LF-Media § Whiteboard Rendering
// Exception, phase 2). The provider's iframe is driven through postMessage; no
// provider script runs in the tenant page. EmbedPlayer looks like the part of
// HTMLMediaElement the board uses (play, pause, currentTime, muted, paused and the
// play/pause/seeked/volumechange/loadedmetadata events), so video, audio and embeds
// share one sync path.

const PROVIDERS = {
    youtube: {
        prefix: 'https://www.youtube-nocookie.com/embed/',
        target: 'https://www.youtube-nocookie.com',
        origins: ['https://www.youtube-nocookie.com', 'https://www.youtube.com'],
    },
    vimeo: {
        prefix: 'https://player.vimeo.com/video/',
        target: 'https://player.vimeo.com',
        origins: ['https://player.vimeo.com'],
    },
};

/** YouTube answers only after it hears "listening"; it is repeated until the player is ready. */
const HANDSHAKE_EVERY_MS = 250;
export const HANDSHAKE_LIMIT_MS = 15000;

/** A play that does not start within this time counts as blocked (sound) or failed (muted). */
export const PLAY_CONFIRM_MS = 4000;

/** A reported position this far from the expected one is a seek (YouTube has no seek event). */
const SEEK_JUMP_SECONDS = 1.5;

/** A mute state reported this soon after the board set it may predate the command. */
const MUTE_SETTLE_MS = 1500;

const YOUTUBE_STATE = { ENDED: 0, PLAYING: 1, PAUSED: 2, BUFFERING: 3 };

/** 'youtube', 'vimeo' or null: only the server's normalized embed URLs, with a plain id and nothing after it. */
export function embedProvider(url) {
    if (typeof url !== 'string') {
        return null;
    }
    for (const [name, provider] of Object.entries(PROVIDERS)) {
        if (url.startsWith(provider.prefix) && /^[A-Za-z0-9_-]+$/.test(url.slice(provider.prefix.length))) {
            return name;
        }
    }

    return null;
}

/** The embed URL with the provider's control parameters, or null when it is not a URL the board controls. */
export function controlUrl(url, origin) {
    const provider = embedProvider(url);
    if (! provider) {
        return null;
    }
    const control = new URL(url);
    if (provider === 'youtube') {
        control.searchParams.set('enablejsapi', '1');
        control.searchParams.set('origin', String(origin));
        control.searchParams.set('playsinline', '1');
        control.searchParams.set('rel', '0');
    } else {
        control.searchParams.set('dnt', '1');
        control.searchParams.set('playsinline', '1');
    }

    return control.toString();
}

function mediaError(name, message) {
    const error = new Error(message);
    error.name = name;

    return error;
}

export class EmbedPlayer extends EventTarget {
    /**
     * Created before the frame is attached, so its load event is not missed.
     *
     * @param {{frame: HTMLIFrameElement, provider: 'youtube'|'vimeo', win?: Window, now?: () => number}} options
     */
    constructor({ frame, provider, win = globalThis.window, now = () => Date.now() }) {
        super();
        if (! PROVIDERS[provider]) {
            throw new Error(`Unknown embed provider ${provider}`);
        }
        this.frame = frame;
        this.provider = provider;
        this.win = win;
        this.now = now;
        this.ready = false;
        this.destroyed = false;
        this.playing = false;
        this.time = 0;
        this.timeAt = now();
        this.mutedState = false;
        this.mutedSetAt = -Infinity;
        this.queue = [];
        this.waiters = [];
        this.timers = new Set();
        this.handshake = 0;
        this.receiver = (event) => this.receive(event);
        win.addEventListener('message', this.receiver);
        // Messages to a frame that has not loaded its provider page yet are dropped by the browser.
        frame.addEventListener('load', () => this.loaded());
    }

    // ------------------------------------------------------------ HTMLMediaElement-like surface

    get paused() {
        return ! this.playing;
    }

    get currentTime() {
        return this.playing ? this.time + Math.max(0, this.now() - this.timeAt) / 1000 : this.time;
    }

    set currentTime(seconds) {
        const time = Math.max(0, Number(seconds) || 0);
        this.setTime(time);
        this.command('seek', time);
    }

    get muted() {
        return this.mutedState;
    }

    set muted(value) {
        this.mutedState = Boolean(value);
        this.mutedSetAt = this.now();
        this.command(this.mutedState ? 'mute' : 'unmute');
    }

    /** Resolves once the provider reports playing; rejects like an element when blocked, paused or closed. */
    play() {
        if (this.destroyed) {
            return Promise.reject(mediaError('AbortError', 'closed'));
        }
        this.command('play');
        if (this.playing) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const waiter = { resolve, reject, muted: this.mutedState, timer: 0 };
            this.waiters.push(waiter);
            this.armWaiter(waiter);
        });
    }

    pause() {
        this.command('pause');
        this.settleWaiters(mediaError('AbortError', 'paused'));
    }

    destroy() {
        this.destroyed = true;
        this.ready = false;
        this.win.removeEventListener('message', this.receiver);
        clearInterval(this.handshake);
        this.timers.forEach((timer) => clearTimeout(timer));
        this.timers.clear();
        this.queue = [];
        this.settleWaiters(mediaError('AbortError', 'closed'));
    }

    // ------------------------------------------------------------ waiting for play

    armWaiter(waiter) {
        this.clearTimer(waiter.timer);
        // Until the player answers at all, allow for it to load first.
        const wait = this.ready ? PLAY_CONFIRM_MS : HANDSHAKE_LIMIT_MS + PLAY_CONFIRM_MS;
        waiter.timer = this.later(() => {
            this.waiters = this.waiters.filter((item) => item !== waiter);
            waiter.reject(waiter.muted || this.mutedState
                ? mediaError('Error', 'embedded player did not start')
                : mediaError('NotAllowedError', 'embedded player did not start with sound'));
        }, wait);
    }

    settleWaiters(error = null) {
        const waiters = this.waiters;
        this.waiters = [];
        waiters.forEach((waiter) => {
            this.clearTimer(waiter.timer);
            if (error) {
                waiter.reject(error);
            } else {
                waiter.resolve();
            }
        });
    }

    later(callback, ms) {
        const timer = setTimeout(() => {
            this.timers.delete(timer);
            callback();
        }, ms);
        this.timers.add(timer);

        return timer;
    }

    clearTimer(timer) {
        if (timer) {
            clearTimeout(timer);
            this.timers.delete(timer);
        }
    }

    // ------------------------------------------------------------ state

    setTime(time) {
        this.time = time;
        this.timeAt = this.now();
    }

    /** A position report; a jump the clock does not explain is a seek. */
    reportTime(seconds, { seekEvent = false } = {}) {
        const time = Number(seconds);
        if (! Number.isFinite(time) || time < 0) {
            return;
        }
        const jumped = Math.abs(time - this.currentTime) > SEEK_JUMP_SECONDS;
        this.setTime(time);
        if (seekEvent || (jumped && this.provider === 'youtube')) {
            this.emit('seeked');
        }
    }

    setPlaying(playing) {
        if (playing === this.playing) {
            if (playing) {
                this.settleWaiters();
            }

            return;
        }
        // Freeze the estimated position at the change.
        this.setTime(this.currentTime);
        this.playing = playing;
        if (playing) {
            this.settleWaiters();
        }
        this.emit(playing ? 'play' : 'pause');
    }

    reportMuted(muted) {
        // Right after the board set it, a report may still describe the old state.
        if (this.now() - this.mutedSetAt < MUTE_SETTLE_MS || Boolean(muted) === this.mutedState) {
            return;
        }
        this.mutedState = Boolean(muted);
        this.emit('volumechange');
    }

    emit(type) {
        try {
            this.dispatchEvent(new Event(type));
        } catch {
            // A listener must never break the player.
        }
    }

    // ------------------------------------------------------------ messages

    loaded() {
        if (this.destroyed || this.ready || this.provider !== 'youtube') {
            return;
        }
        clearInterval(this.handshake);
        const started = this.now();
        this.post({ event: 'listening', id: 1, channel: 'widget' });
        this.handshake = setInterval(() => {
            if (this.ready || this.destroyed || this.now() - started > HANDSHAKE_LIMIT_MS) {
                clearInterval(this.handshake);

                return;
            }
            this.post({ event: 'listening', id: 1, channel: 'widget' });
        }, HANDSHAKE_EVERY_MS);
    }

    markReady() {
        if (this.ready || this.destroyed) {
            return;
        }
        this.ready = true;
        clearInterval(this.handshake);
        if (this.provider === 'youtube') {
            this.post({ event: 'command', func: 'addEventListener', args: ['onStateChange'], id: 1, channel: 'widget' });
        } else {
            ['play', 'pause', 'ended', 'seeked', 'timeupdate'].forEach((name) => this.post({ method: 'addEventListener', value: name }));
        }
        const queue = this.queue;
        this.queue = [];
        queue.forEach(([name, value]) => this.send(name, value));
        this.waiters.forEach((waiter) => this.armWaiter(waiter));
        this.emit('loadedmetadata');
    }

    command(name, value) {
        if (this.destroyed) {
            return;
        }
        if (! this.ready) {
            // Only the last of each kind matters once the player is ready.
            this.queue = this.queue.filter(([queued]) => queued !== name && ! (['play', 'pause'].includes(name) && ['play', 'pause'].includes(queued)) && ! (['mute', 'unmute'].includes(name) && ['mute', 'unmute'].includes(queued)));
            this.queue.push([name, value]);

            return;
        }
        this.send(name, value);
    }

    send(name, value) {
        if (this.provider === 'youtube') {
            const calls = {
                play: ['playVideo', []],
                pause: ['pauseVideo', []],
                seek: ['seekTo', [value, true]],
                mute: ['mute', []],
                unmute: ['unMute', []],
            };
            const [func, args] = calls[name];
            this.post({ event: 'command', func, args, id: 1, channel: 'widget' });

            return;
        }
        const calls = {
            play: { method: 'play' },
            pause: { method: 'pause' },
            seek: { method: 'setCurrentTime', value },
            mute: { method: 'setMuted', value: true },
            unmute: { method: 'setMuted', value: false },
        };
        this.post(calls[name]);
    }

    post(message) {
        try {
            this.frame.contentWindow?.postMessage(JSON.stringify(message), PROVIDERS[this.provider].target);
        } catch {
            // A frame that navigated away simply stops answering.
        }
    }

    receive(event) {
        if (this.destroyed || ! this.frame.contentWindow || event.source !== this.frame.contentWindow
            || ! PROVIDERS[this.provider].origins.includes(event.origin)) {
            return;
        }
        let data = event.data;
        if (typeof data === 'string') {
            try {
                data = JSON.parse(data);
            } catch {
                return;
            }
        }
        if (! data || typeof data !== 'object') {
            return;
        }
        if (this.provider === 'youtube') {
            this.receiveYoutube(data);
        } else {
            this.receiveVimeo(data);
        }
    }

    receiveYoutube(data) {
        if (['onReady', 'initialDelivery', 'infoDelivery'].includes(data.event)) {
            this.markReady();
        }
        const info = data.info;
        if (data.event === 'onStateChange') {
            this.youtubeState(Number(info));
        } else if ((data.event === 'infoDelivery' || data.event === 'initialDelivery') && info && typeof info === 'object') {
            if (info.currentTime !== undefined) {
                this.reportTime(info.currentTime);
            }
            if (info.playerState !== undefined) {
                this.youtubeState(Number(info.playerState));
            }
            if (typeof info.muted === 'boolean') {
                this.reportMuted(info.muted);
            }
        }
    }

    youtubeState(state) {
        if (state === YOUTUBE_STATE.PLAYING) {
            this.setPlaying(true);
        } else if (state !== YOUTUBE_STATE.BUFFERING) {
            // Buffering keeps the current state; unstarted, cued, paused and ended stop it.
            this.setPlaying(false);
        }
    }

    receiveVimeo(data) {
        const seconds = data.data?.seconds;
        switch (data.event) {
            case 'ready':
                this.markReady();
                break;
            case 'play':
                this.reportTime(seconds);
                this.setPlaying(true);
                break;
            case 'pause':
            case 'ended':
                this.reportTime(seconds);
                this.setPlaying(false);
                break;
            case 'seeked':
                this.reportTime(seconds, { seekEvent: true });
                break;
            case 'timeupdate':
                this.reportTime(seconds);
                break;
            case 'error':
                if (data.data?.method === 'play') {
                    this.settleWaiters(mediaError(data.data?.name === 'NotAllowedError' ? 'NotAllowedError' : 'Error', 'play refused'));
                }
                break;
            default:
                break;
        }
    }
}
