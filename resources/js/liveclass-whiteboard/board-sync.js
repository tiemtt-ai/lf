// Real-time sync of the LiveClass Whiteboard (ADR-0002 § LiveClass Whiteboard
// Amendment, Real-time sync). The active presenter posts events to the server,
// which numbers them and broadcasts them on the Session's private channel.
// Viewers only receive: they subscribe first, buffer, load the cached state,
// then apply events in sequence; a gap triggers a full resync.
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { cancelsUnsentStroke, mergeStrokeEvent } from './board-stroke';

const FLUSH_MS = 100;
const HEARTBEAT_MS = 20_000;
const VIEWER_STATUS_MS = 60_000;
const STANDBY_STATUS_MS = 30_000;
const RETRY_MS = [1000, 2000, 5000, 10_000];
const STROKE_CHUNK = 300;
const MAX_QUEUE = 400;
// Must match LiveClassWhiteboardLiveState::EPHEMERAL_TYPES.
const EPHEMERAL = new Set(['pointer', 'view.change', 'video.sync']);

export class SyncHttpError extends Error {
    constructor(status) {
        super(`HTTP ${status}`);
        this.status = status;
    }
}

/**
 * Bytes an event takes once PHP's json_encode has escaped it: every non-ASCII
 * UTF-16 unit becomes \uXXXX and "/" becomes "\/".
 */
export function wireSize(value) {
    const json = JSON.stringify(value);
    let size = json.length;
    for (let index = 0; index < json.length; index += 1) {
        const code = json.charCodeAt(index);
        if (code > 0x7f) {
            size += 5;
        } else if (code === 47) {
            size += 1;
        }
    }

    return size;
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function request(url, { method = 'GET', body = null } = {}) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() } : {}),
        },
        body: body ? JSON.stringify(body) : null,
    });
    if (! response.ok) {
        throw new SyncHttpError(response.status);
    }

    return response.status === 204 ? null : response.json();
}

function newClientId() {
    const bytes = new Uint8Array(12);
    crypto.getRandomValues(bytes);

    return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * Transport only: the board decides what an event means. Handlers:
 * - status(status): 'connecting' | 'connected' | 'disconnected' | 'off'
 * - mode(mode, { adopt }): 'presenting' | 'viewing'; adopt = continue the live board
 * - presenter(name): the active presenter's name, or null
 * - state(state): async, apply a cached state (snapshot + events)
 * - event(event): async, apply one numbered event in order
 * - ephemeral(event): pointer or view, applied at once
 * - snapshot(): the presenter's board as one object
 * - ended(reason): 'ended' | 'revoked'
 * - error(kind): 'session' | 'too_large' | 'failed'
 */
export class BoardSync {
    constructor(config, handlers) {
        this.config = config;
        this.handlers = handlers;
        this.canPresent = config.role === 'presenter';
        this.clientId = newClientId();
        this.mode = this.canPresent ? 'pending' : 'viewing';
        this.lastSeq = 0;
        this.loading = false;
        this.buffer = [];
        this.applying = Promise.resolve();
        this.queue = [];
        this.flushTimer = 0;
        this.inflight = false;
        this.sentAt = 0;
        this.retry = 0;
        this.timers = [];
        this.stopped = false;
        this.echo = null;
    }

    get limit() {
        return (this.config.maxEventBytes || 8192) - 256;
    }

    start() {
        const key = import.meta.env.VITE_REVERB_APP_KEY;
        if (! key || ! this.config.channel) {
            this.handlers.status('off');
            if (this.canPresent) {
                this.claim(false);
            }

            return;
        }

        this.handlers.status('connecting');
        this.echo = new Echo({
            broadcaster: 'reverb',
            Pusher,
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
            authEndpoint: this.config.authUrl,
            csrfToken: csrfToken(),
        });

        this.echo.connector.pusher.connection.bind('state_change', ({ current }) => {
            if (current === 'connected') {
                this.handlers.status('connected');
            } else if (current === 'connecting' || current === 'initialized') {
                this.handlers.status('connecting');
            } else {
                this.handlers.status('disconnected');
            }
        });

        const channel = this.echo.private(this.config.channel);
        channel.listen('.board.events', (message) => this.receive(Array.isArray(message?.events) ? message.events : []));
        channel.listen('.board.presenter', (message) => this.presenterChanged(message ?? {}));
        channel.listen('.board.ended', () => this.end('ended'));
        channel.listen('.board.revoked', (message) => {
            if ((message?.user_ids ?? []).map(Number).includes(Number(this.config.userId))) {
                this.end('revoked');
            }
        });
        // Fires on every (re)subscription: whatever was missed while away is reloaded.
        channel.subscribed(() => {
            if (this.mode === 'viewing') {
                this.resync();
            } else if (this.mode === 'presenting') {
                this.heartbeat();
            }
        });
        channel.error(() => this.checkStatus());

        if (this.canPresent) {
            this.claim(false);
            this.every(HEARTBEAT_MS, () => this.heartbeat());
            this.every(STANDBY_STATUS_MS, () => {
                if (this.mode === 'viewing') {
                    this.checkStatus({ claimWhenFree: true });
                }
            });
        } else {
            this.every(VIEWER_STATUS_MS, () => this.checkStatus());
        }

        window.addEventListener('pagehide', () => this.release());
    }

    every(ms, callback) {
        this.timers.push(setInterval(callback, ms));
    }

    stop() {
        this.stopped = true;
        this.timers.forEach(clearInterval);
        clearTimeout(this.flushTimer);
        this.queue = [];
        this.echo?.disconnect();
    }

    end(reason) {
        if (this.stopped) {
            return;
        }
        this.stop();
        this.handlers.ended(reason);
    }

    fail(error) {
        if (error instanceof SyncHttpError && (error.status === 403 || error.status === 404)) {
            this.end('ended');
        } else if (error instanceof SyncHttpError && (error.status === 401 || error.status === 419)) {
            this.handlers.error('session');
        }
    }

    setMode(mode, options = {}) {
        if (this.mode === mode) {
            return;
        }
        this.mode = mode;
        this.queue = [];
        this.handlers.mode(mode, options);
    }

    // ------------------------------------------------------------ presenter lock

    async claim(takeover) {
        if (! this.canPresent || this.stopped) {
            return;
        }
        try {
            const result = await request(this.config.presenceUrl, { method: 'POST', body: { client_id: this.clientId, takeover } });
            this.handlers.presenter(result?.presenter?.name ?? null);
            if (result?.presenting) {
                const adopt = this.mode === 'viewing';
                this.setMode('presenting', { adopt });
                this.queue = [{ kind: 'snapshot' }];
                this.scheduleFlush();
            } else {
                this.setMode('viewing');
                this.resync();
            }
        } catch (error) {
            this.fail(error);
            if (this.mode === 'pending') {
                // No lock yet (server busy or offline): try again shortly, drawing stays local.
                setTimeout(() => this.claim(false), RETRY_MS[2]);
            }
        }
    }

    takeover() {
        return this.claim(true);
    }

    async heartbeat() {
        if (this.mode !== 'presenting' || this.stopped) {
            return;
        }
        try {
            const result = await request(this.config.presenceUrl, { method: 'POST', body: { client_id: this.clientId, takeover: false } });
            if (! result?.presenting) {
                this.lostLock(result?.presenter?.name ?? null);
            }
        } catch (error) {
            this.fail(error);
        }
    }

    lostLock(name) {
        this.handlers.presenter(name);
        this.setMode('viewing');
        this.resync();
    }

    release() {
        if (this.mode !== 'presenting' || ! this.config.releaseUrl || ! navigator.sendBeacon) {
            return;
        }
        const form = new FormData();
        form.append('client_id', this.clientId);
        form.append('_token', csrfToken());
        navigator.sendBeacon(this.config.releaseUrl, form);
    }

    presenterChanged({ name = null, client_id: clientId = null }) {
        if (this.mode === 'presenting' && clientId !== this.clientId) {
            this.lostLock(name);

            return;
        }
        this.handlers.presenter(name);
        if (this.mode === 'viewing' && this.canPresent && ! clientId) {
            // The presenter left: another presenter's board picks the live board up.
            this.claim(false);
        }
    }

    async checkStatus({ claimWhenFree = false } = {}) {
        try {
            const result = await request(this.config.statusUrl);
            const name = result?.presenter?.name ?? null;
            this.handlers.presenter(name);
            if (claimWhenFree && ! name) {
                this.claim(false);
            }
        } catch (error) {
            this.fail(error);
        }
    }

    // ------------------------------------------------------------ presenter publishing

    publish(event) {
        if (this.mode !== 'presenting' || this.stopped) {
            return;
        }
        const last = this.queue.at(-1)?.kind === 'event' ? this.queue.at(-1).event : null;
        const merged = mergeStrokeEvent(last, event);
        if (EPHEMERAL.has(event.type)) {
            // Only the latest pointer position or view matters.
            this.queue = this.queue.filter((item) => item.kind !== 'event' || item.event.type !== event.type);
            this.queue.push({ kind: 'event', event });
        } else if (merged && wireSize(merged) <= this.limit) {
            // The unsent parts of a stroke being drawn travel as one event.
            this.queue[this.queue.length - 1] = { kind: 'event', event: merged };
        } else if (cancelsUnsentStroke(last, event)) {
            // A stroke cut off before any of it was sent: nothing to tell viewers.
            this.queue.pop();

            return;
        } else {
            this.split(event).forEach((item) => this.queue.push(item));
        }
        if (this.queue.length > MAX_QUEUE) {
            this.refresh();

            return;
        }
        this.scheduleFlush();
    }

    /** Sends the whole board instead of single events; viewers reload it. */
    refresh() {
        this.queue = [{ kind: 'snapshot' }, { kind: 'event', event: { type: 'state.refresh' } }];
        this.scheduleFlush();
    }

    snapshotSoon() {
        if (this.mode === 'presenting' && ! this.queue.some((item) => item.kind === 'snapshot')) {
            this.queue.push({ kind: 'snapshot' });
            this.scheduleFlush();
        }
    }

    /** Long strokes go as stroke.add plus stroke.append parts, each part at most STROKE_CHUNK points; anything else too big becomes a refresh. */
    split(event) {
        if (wireSize(event) <= this.limit) {
            return [{ kind: 'event', event }];
        }
        if (event.type === 'stroke.add' && Array.isArray(event.stroke?.points)) {
            const points = event.stroke.points;
            const items = [{ kind: 'event', event: { ...event, stroke: { ...event.stroke, points: points.slice(0, STROKE_CHUNK) } } }];
            for (let from = STROKE_CHUNK; from < points.length; from += STROKE_CHUNK) {
                items.push({ kind: 'event', event: { type: 'stroke.append', page: event.page, id: event.stroke.id, from, points: points.slice(from, from + STROKE_CHUNK) } });
            }

            return items;
        }
        if (event.type === 'stroke.append' && Array.isArray(event.points)) {
            const items = [];
            for (let offset = 0; offset < event.points.length; offset += STROKE_CHUNK) {
                items.push({ kind: 'event', event: { ...event, from: event.from + offset, points: event.points.slice(offset, offset + STROKE_CHUNK) } });
            }

            return items;
        }

        return [{ kind: 'snapshot' }, { kind: 'event', event: { type: 'state.refresh' } }];
    }

    scheduleFlush(delay = FLUSH_MS) {
        if (this.flushTimer || this.inflight || ! this.queue.length) {
            return;
        }
        this.flushTimer = setTimeout(() => {
            this.flushTimer = 0;
            this.flush();
        }, delay);
    }

    async flush() {
        if (this.inflight || ! this.queue.length || this.mode !== 'presenting' || this.stopped) {
            return;
        }
        this.inflight = true;
        this.sentAt = Date.now();
        const first = this.queue[0];
        let batch = [];
        try {
            if (first.kind === 'snapshot') {
                this.queue.shift();
                batch = [first];
                // Taken at send time, after every earlier event was sent.
                await request(this.config.snapshotUrl, { method: 'POST', body: { client_id: this.clientId, state: this.handlers.snapshot() } });
            } else {
                while (batch.length < (this.config.maxBatch || 40) && this.queue[0]?.kind === 'event') {
                    batch.push(this.queue.shift());
                }
                const result = await request(this.config.eventsUrl, {
                    method: 'POST',
                    body: { client_id: this.clientId, events: batch.map((item) => item.event) },
                });
                if (result?.snapshot_requested) {
                    this.snapshotSoon();
                }
            }
            this.retry = 0;
        } catch (error) {
            this.inflight = false;
            this.flushFailed(error, batch);

            return;
        }
        this.inflight = false;
        // At most one request per FLUSH_MS, even while a stroke keeps the queue busy:
        // the events route allows 900 a minute (15 a second).
        this.scheduleFlush(Math.max(0, FLUSH_MS - (Date.now() - this.sentAt)));
    }

    flushFailed(error, batch) {
        const status = error instanceof SyncHttpError ? error.status : 0;
        if (status === 409) {
            this.lostLock(null);
            this.checkStatus();
        } else if (status === 422) {
            // Rejected as invalid or too large: viewers reload the whole board instead.
            if (batch[0]?.kind === 'snapshot') {
                this.handlers.error('too_large');
            } else {
                this.refresh();
            }
        } else if (status === 403 || status === 404 || status === 401 || status === 419) {
            this.fail(error);
        } else {
            // Offline, rate limited or busy: keep the order and try again.
            this.queue.unshift(...batch);
            const delay = RETRY_MS[Math.min(this.retry, RETRY_MS.length - 1)];
            this.retry += 1;
            if (this.retry === 3) {
                this.handlers.error('failed');
            }
            this.scheduleFlush(delay);
        }
    }

    // ------------------------------------------------------------ viewer ordering

    receive(events) {
        if (this.mode !== 'viewing' || this.stopped) {
            return;
        }
        events.forEach((event) => {
            if (event.seq === null || event.seq === undefined) {
                if (! this.loading) {
                    this.handlers.ephemeral(event);
                }

                return;
            }
            if (this.loading) {
                this.buffer.push(event);

                return;
            }
            this.ordered(event);
        });
    }

    ordered(event) {
        const seq = Number(event.seq);
        if (seq <= this.lastSeq) {
            return;
        }
        if (seq !== this.lastSeq + 1) {
            this.resync();

            return;
        }
        this.lastSeq = seq;
        if (event.type === 'state.refresh') {
            this.resync();

            return;
        }
        this.applying = this.applying.then(() => this.handlers.event(event)).catch(() => {});
    }

    /** Loads the cached state, then the events that arrived meanwhile. */
    resync() {
        if (this.loading || this.stopped) {
            return;
        }
        this.loading = true;
        this.buffer = [];
        this.applying = this.applying.then(async () => {
            let state = null;
            try {
                state = await request(this.config.stateUrl);
            } catch (error) {
                this.loading = false;
                this.fail(error);

                return;
            }
            if (this.mode !== 'viewing') {
                this.loading = false;

                return;
            }
            this.handlers.presenter(state?.presenter?.name ?? null);
            try {
                await this.handlers.state(state);
            } catch {
                // A material that failed to load still leaves the strokes in place.
            }
            this.lastSeq = Number(state?.seq) || 0;
            this.loading = false;
            const buffered = this.buffer.sort((a, b) => a.seq - b.seq);
            this.buffer = [];
            buffered.forEach((event) => this.ordered(event));
        }).catch(() => {
            this.loading = false;
        });
    }
}
