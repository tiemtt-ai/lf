// The learner's lesson page (ADR-0030): plays an Activity's Media from a short-lived URL and
// reports progress events. The server decides what counts; the page only reports what happened.

export const POSITION_EVERY_SECONDS = 15;

/** Whether enough playback time passed since the last report to send another position. */
export function shouldReportPosition(lastReportedAt, now, every = POSITION_EVERY_SECONDS) {
    return lastReportedAt === null || now - lastReportedAt >= every * 1000;
}

/** Where to resume: the stored position unless it is at (or past) the end. */
export function resumePosition(stored, duration) {
    const at = Number(stored) || 0;
    if (!Number.isFinite(duration) || duration <= 0) {
        return at;
    }

    return at >= duration - 5 ? 0 : at;
}

/** Whether a video stopped part-way first asks "Tiếp tục xem / Xem từ đầu" (MK); one near its end starts over. */
export function asksToResume(stored, duration) {
    const at = Number(stored) || 0;

    return at > 0 && resumePosition(at, Number(duration) > 0 ? Number(duration) : NaN) === at;
}

/** hh:mm:ss of whole seconds. */
export function clock(seconds) {
    const total = Math.max(0, Math.floor(Number(seconds) || 0));

    return [Math.floor(total / 3600), Math.floor(total / 60) % 60, total % 60].map((part) => String(part).padStart(2, '0')).join(':');
}

/** "YYYY-MM-DD HH:mm" in the browser's time, as the popup footer shows the last opening. */
export function stamp(date) {
    const two = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${two(date.getMonth() + 1)}-${two(date.getDate())} ${two(date.getHours())}:${two(date.getMinutes())}`;
}

/**
 * The share played as the popup footer shows it: the furthest second reached in normal playback over the duration,
 * whole percents rounded down; complete once the server says so.
 */
export function playedPercent(played, duration, completed) {
    if (completed) {
        return 100;
    }

    return Number(duration) > 0 ? Math.min(100, Math.floor((100 * (Number(played) || 0)) / Number(duration))) : 0;
}

/**
 * Full screen for the player frame. Safari on iPhone has no full screen for page elements, only for the video itself
 * (its own player); a video without a loaded source cannot go full screen there yet. A browser that has the call but
 * refuses the frame (a WebView, a permission policy) gets the video instead; one that says up front it has no full
 * screen for page elements (an app's WebView) goes to the video during the tap itself, so a video not loaded yet
 * answers no and the button can play it first. Whether it went, or was asked, is returned.
 */
export function enterFullscreen(target, video, doc = globalThis.document) {
    const toVideo = () => {
        if (typeof video?.webkitEnterFullscreen !== 'function') {
            return false;
        }
        try {
            video.webkitEnterFullscreen();

            return true;
        } catch {
            return false;
        }
    };
    const request = target?.requestFullscreen ?? target?.webkitRequestFullscreen;
    if (typeof request !== 'function' || !fullscreenAllowed(doc)) {
        return toVideo();
    }
    try {
        Promise.resolve(request.call(target)).catch(toVideo);

        return true;
    } catch {
        return toVideo();
    }
}

/**
 * Whether the button can do anything: the frame where the page may go full screen, else the video itself (Safari on
 * iPhone). An embedded YouTube/Vimeo player there has neither; its own control does it.
 */
export function canEnterFullscreen(target, video, doc = document) {
    const request = target?.requestFullscreen ?? target?.webkitRequestFullscreen;

    return (fullscreenAllowed(doc) && typeof request === 'function') || typeof video?.webkitEnterFullscreen === 'function';
}

/** Whether the page may put its elements in full screen; a browser that does not say is taken at its word later. */
function fullscreenAllowed(doc) {
    return (doc?.fullscreenEnabled ?? doc?.webkitFullscreenEnabled ?? true) !== false;
}

/** The element in full screen; Safari before 16.4 (older iPads) only has the prefixed names. */
export function fullscreenElement(doc = document) {
    return doc.fullscreenElement ?? doc.webkitFullscreenElement ?? null;
}

/** Leaves full screen if the page is in it, and says whether it was. */
export function leaveFullscreen(doc = document) {
    if (! fullscreenElement(doc)) {
        return false;
    }
    const exit = doc.exitFullscreen ?? doc.webkitExitFullscreen;
    if (typeof exit === 'function') {
        try {
            Promise.resolve(exit.call(doc)).catch(() => {});
        } catch {
            // Already left.
        }
    }

    return true;
}

const STATUS_RANK = { not_started: 0, in_progress: 1, completed: 2 };

/** Activity Progress only moves forward (not started → in progress → completed), so a late answer never takes one back. */
export function furtherStatus(current, next) {
    return (STATUS_RANK[next] ?? -1) > (STATUS_RANK[current] ?? -1) ? next : current;
}

/** The window event every card of an Activity, and every count that includes it, follows on the class page. */
export const PROGRESS_EVENT = 'lf-learn-progress';

function announceProgress(activity, status) {
    const target = globalThis.window;
    if (activity && typeof target?.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
        target.dispatchEvent(new CustomEvent(PROGRESS_EVENT, { detail: { activity, status } }));
    }
}

/**
 * "Hoàn thành x/y nội dung bắt buộc" of a Session and the class "Tỷ lệ hoàn thành": the required Activities and those
 * already completed come from the server; an Activity completed on the page counts at once.
 */
export function learnerCompletion(config) {
    const required = (config.required ?? []).map(Number);

    return {
        done: (config.done ?? []).map(Number).filter((id) => required.includes(id)),

        get count() {
            return this.done.length;
        },

        get total() {
            return required.length;
        },

        get finished() {
            return this.total > 0 && this.count >= this.total;
        },

        get percent() {
            return this.total > 0 ? Math.round((100 * this.count) / this.total) : 0;
        },

        /** The Session line: "✓ Đã hoàn thành buổi học" once all are done, else "Hoàn thành :done/:total …". */
        get text() {
            const labels = config.labels ?? {};

            return this.finished
                ? labels.done ?? ''
                : String(labels.progress ?? '').replace(':done', String(this.count)).replace(':total', String(this.total));
        },

        record(detail) {
            const activity = Number(detail?.activity);
            if (detail?.status === 'completed' && required.includes(activity) && !this.done.includes(activity)) {
                this.done = [...this.done, activity];
            }
        },
    };
}

export function learnerActivity(config) {
    return {
        status: config.status,
        percentage: config.percentage,
        busy: false,
        error: '',
        src: '',
        started: false,
        lastReportedAt: null,
        retried: false,
        // The MK video popup: the resume question, the seconds played and the last opening.
        asking: false,
        resumeAt: Number(config.resumeAt) || 0,
        played: Number(config.playedSeconds) || 0,
        lastTime: null,
        viewed: config.viewedAt || '',
        mediaDuration: 0,

        get completed() {
            return this.status === 'completed';
        },

        /** The card's state ("Đã mở", "Đã xem", "Đã nghe"), as the server writes it for the same status. */
        get stateLabel() {
            const labels = config.labels ?? {};
            if (this.completed) {
                return labels.completed ?? '';
            }

            return this.status !== 'not_started' ? labels.opened ?? '' : '';
        },

        /** Another card of this Activity (a Lesson over several Sessions) recorded progress. */
        syncProgress(detail) {
            if (config.activityId && Number(detail?.activity) === Number(config.activityId)) {
                this.status = furtherStatus(this.status, detail.status);
            }
        },

        /** The Activity's duration, or the file's own once loaded when the course did not give one. */
        get length() {
            return Number(config.duration) || this.mediaDuration;
        },

        get watchedClock() {
            return clock(this.completed ? Math.max(this.played, this.length) : this.played);
        },

        get watchedPercent() {
            return playedPercent(this.played, this.length, this.completed);
        },

        /** The card's share while playing (MK "Đã nghe 25%"); once complete the card says so instead. */
        get cardPercent() {
            return this.completed ? 0 : playedPercent(this.played, this.length, false);
        },

        get resumeClock() {
            return clock(this.resumeAt);
        },

        /** Opening the popup: a video stopped part-way asks first; otherwise it plays (once). */
        openMedia() {
            if (this.started || this.busy) {
                return;
            }
            if (asksToResume(this.resumeAt, config.duration)) {
                this.asking = true;

                return;
            }
            this.play();
        },

        continuePlay() {
            this.asking = false;
            this.play();
        },

        /** "Xem từ đầu": report how far playback got, then play from the first second. */
        startOver() {
            this.asking = false;
            const player = this.$refs.player;
            if (!this.started) {
                this.play(true);

                return;
            }
            if (config.tracksPosition && player.currentTime > 0) {
                this.lastReportedAt = Date.now();
                this.send('position', { position_seconds: player.currentTime }).catch(() => {});
            }
            player.currentTime = 0;
            player.play().catch(() => {});
        },

        toggleFullscreen() {
            if (leaveFullscreen()) {
                return;
            }
            if (!enterFullscreen(this.$refs.screen ?? this.$refs.player, this.$refs.player)) {
                this.playFullscreen();
            }
        },

        /**
         * Safari on iPhone: a video without its source cannot go full screen yet, so the button plays it (as "Tiếp tục
         * xem") and goes full screen once it has loaded. Whether it started is returned.
         */
        playFullscreen() {
            const player = this.$refs.player;
            if (this.started || this.busy || player?.tagName !== 'VIDEO') {
                return false;
            }
            this.asking = false;
            const enter = () => enterFullscreen(null, player);
            player.addEventListener('loadedmetadata', enter, { once: true });
            // A start that failed (the link, the network) leaves nothing behind: the next play is not taken full screen.
            this.play().then(() => {
                if (!this.started) {
                    player.removeEventListener('loadedmetadata', enter);
                }
            });

            return true;
        },

        async send(event, extra = {}) {
            const response = await fetch(config.progressUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ event, ...extra }),
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const data = await response.json();
            this.status = furtherStatus(this.status, data.progress.status);
            this.percentage = data.progress.percentage;
            announceProgress(config.activityId, this.status);
            // Content this one completed with it (attendance completes the Link học), counted as the server does.
            const others = data.progress.status === 'completed' && Array.isArray(data.progress.lesson_completed) ? data.progress.lesson_completed : [];
            for (const other of others) {
                if (Number(other) !== Number(config.activityId)) {
                    announceProgress(Number(other), 'completed');
                }
            }

            return data.progress;
        },

        async mediaUrl() {
            const response = await fetch(config.mediaUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error(String(response.status));
            }

            return (await response.json()).url;
        },

        /** Video and audio: load the Media on the learner's click, then resume where they stopped (or from the start). */
        async play(fromStart = false) {
            if (this.busy) {
                return;
            }
            this.busy = true;
            this.error = '';
            try {
                this.src = await this.mediaUrl();
                this.started = true;
                await this.$nextTick();
                const player = this.$refs.player;
                player.addEventListener('loadedmetadata', () => {
                    this.mediaDuration = Number.isFinite(player.duration) ? player.duration : 0;
                    const at = fromStart ? 0 : resumePosition(this.resumeAt, player.duration);
                    if (at > 0) {
                        player.currentTime = at;
                    }
                    player.play().catch(() => {});
                }, { once: true });
                player.load();
                this.viewed = stamp(new Date());
                await this.send('opened');
            } catch {
                this.error = config.messages.failed;
            } finally {
                this.busy = false;
            }
        },

        onTime() {
            const now = Date.now();
            // Played time only grows with normal playback, as the server counts it; a jump ahead does not.
            const time = this.$refs.player.currentTime;
            if (time > this.played && this.lastTime !== null && time - this.lastTime >= 0 && time - this.lastTime <= 3) {
                this.played = time;
            }
            this.lastTime = time;
            if (config.tracksPosition && shouldReportPosition(this.lastReportedAt, now)) {
                this.lastReportedAt = now;
                this.send('position', { position_seconds: this.$refs.player.currentTime }).catch(() => {});
            }
        },

        onPause() {
            if (config.tracksPosition && this.$refs.player.currentTime > 0) {
                this.lastReportedAt = Date.now();
                this.send('position', { position_seconds: this.$refs.player.currentTime }).catch(() => {});
            }
        },

        onEnded() {
            const player = this.$refs.player;
            if (!config.tracksPosition) {
                return;
            }
            this.send('position', { position_seconds: player.duration || player.currentTime })
                .then(() => (this.completed || config.durationKnown ? null : this.send('completed')))
                .catch(() => {});
        },

        /** The signed URL expired while playing: fetch a new one once and continue from the same second. */
        async onError() {
            // A player without a source yet (before "Phát") is not an error.
            if (!this.src) {
                return;
            }
            if (this.retried) {
                this.error = config.messages.failed;

                return;
            }
            this.retried = true;
            const player = this.$refs.player;
            const at = player.currentTime;
            try {
                this.src = await this.mediaUrl();
                await this.$nextTick();
                player.addEventListener('loadedmetadata', () => {
                    player.currentTime = at;
                    player.play().catch(() => {});
                }, { once: true });
                player.load();
            } catch {
                this.error = config.messages.failed;
            }
        },

        /** Documents open in a new tab (LF-Media); the tab is opened on the click so no popup blocker stops it. */
        async openDocument() {
            const tab = window.open('', '_blank');
            this.error = '';
            try {
                const url = await this.mediaUrl();
                if (tab) {
                    tab.opener = null;
                    tab.location.href = url;
                    await this.send('opened');
                } else {
                    // No new tab (blocked): record first, since leaving the page would cancel the request.
                    await this.send('opened').catch(() => {});
                    window.location.href = url;
                }
            } catch {
                tab?.close();
                this.error = config.messages.failed;
            }
        },

        /** External platform links (ADR-0035): the link itself opens the tab; the click records `opened`. */
        openLink() {
            this.error = '';

            return this.send('opened').catch(() => {
                this.error = config.messages.failed;
            });
        },

        /** Embedded videos (YouTube, Vimeo) show on click; opening counts for the `view` rule. */
        async showEmbed() {
            this.started = true;
            this.send('opened').catch(() => {});
        },

        async markDone() {
            this.busy = true;
            this.error = '';
            try {
                await this.send('completed');
            } catch {
                this.error = config.messages.failed;
            } finally {
                this.busy = false;
            }
        },
    };
}
