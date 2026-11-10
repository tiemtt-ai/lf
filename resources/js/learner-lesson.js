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

        get completed() {
            return this.status === 'completed';
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
            this.status = data.progress.status;
            this.percentage = data.progress.percentage;

            return data.progress;
        },

        async mediaUrl() {
            const response = await fetch(config.mediaUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error(String(response.status));
            }

            return (await response.json()).url;
        },

        /** Video and audio: load the Media on the learner's click, then resume where they stopped. */
        async play() {
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
                    const at = resumePosition(config.resumeAt, player.duration);
                    if (at > 0) {
                        player.currentTime = at;
                    }
                    player.play().catch(() => {});
                }, { once: true });
                player.load();
                await this.send('opened');
            } catch {
                this.error = config.messages.failed;
            } finally {
                this.busy = false;
            }
        },

        onTime() {
            const now = Date.now();
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
