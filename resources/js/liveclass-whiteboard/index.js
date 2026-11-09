// LiveClass Whiteboard page (ADR-0002 § LiveClass Whiteboard Amendment).
// The page loads only this entry: it brings its own Alpine instance.
import Alpine from 'alpinejs';
import { BoardEngine, DEFAULT_SETTINGS, SIZE_LIMITS } from './board-engine';
import { NotPdfError, PdfTooLargeError, PlaceholderBackground, openPdf, readPdfResponse } from './board-pdf';
import { readJson, writeJson } from './board-storage';
import { MEDIA_KINDS, MEDIA_SYNC_MS, asksListenMode, needsSeek, playMedia, playingFrom, primeElement, createMediaPool, resolveListenMode, shouldMute, targetTime, unlockAudio } from './board-media';
import { EmbedPlayer, controlUrl, embedProvider } from './board-embed';
import { BoardSync } from './board-sync';
import { installLongPressTooltips } from './long-press-tooltip';

const SAVE_DELAY_MS = 400;
const TOAST_MS = 4000;
const REMOTE_LASER_MS = 1500;
// The view is not cached on the server (ADR: scroll is not cached), so the presenter repeats it for late joiners.
const VIEW_REPEAT_MS = 5000;
const MAX_SNAPSHOT_PAGES_BYTES = 900_000;
// `key` names local storage; `publicKey` is what viewers see (a local file's name never leaves the browser).
const BLANK = { key: 'blank', publicKey: 'blank', kind: 'blank', title: '', activityId: null, local: false };

/** Short stable hash, so a local PDF has a key for viewers without its file name. */
function hashKey(text) {
    let hash = 0x811c9dc5;
    for (let index = 0; index < text.length; index += 1) {
        hash ^= text.charCodeAt(index);
        hash = Math.imul(hash, 0x01000193);
    }

    return (hash >>> 0).toString(16);
}

const config = JSON.parse(document.getElementById('lf-wb-config')?.textContent || '{}');

class EndpointError extends Error {
    constructor(status) {
        super(`HTTP ${status}`);
        this.status = status;
    }
}

async function getJson(url) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store',
    });
    if (! response.ok) {
        throw new EndpointError(response.status);
    }

    return response.json();
}

// Kept out of Alpine's reactive state: DOM elements, reused for every media (board-media createMediaPool).
const mediaPool = createMediaPool((tag) => {
    const element = document.createElement(tag);
    element.controls = true;
    element.preload = 'metadata';
    element.playsInline = true;
    element.setAttribute('playsinline', '');

    return element;
});

Alpine.data('lfWhiteboard', () => ({
    strings: config.strings ?? {},
    colors: config.colors ?? [],
    className: config.className ?? '',
    materials: config.materials ?? [],
    canPresent: config.role === 'presenter',

    // Sync: 'pending' until the presenter lock answers; viewers are always 'viewing'.
    mode: config.role === 'presenter' ? 'pending' : 'viewing',
    syncStatus: 'connecting',
    presenterName: null,
    following: true,
    remoteNotes: [],
    remoteLaser: { visible: false, x: 0, y: 0, size: 12, active: false },
    endedReason: 'ended',

    tool: 'pen',
    settings: { ...DEFAULT_SETTINGS },
    page: 1,
    pageCount: 1,
    zoomPercent: 100,
    canUndo: false,
    canRedo: false,
    hasPageContent: false,
    hasAnyContent: false,
    notes: [],
    view: { scale: 1, x: 0, y: 0 },
    laser: { visible: false, active: false, x: 0, y: 0, size: 12 },

    material: { ...BLANK },
    drawerOpen: false,
    refreshing: false,
    loading: false,
    media: null,
    // Viewer sound (LF-Core-LiveClass § Âm thanh, video và chế độ nghe): 'room' plays muted, 'remote' with sound.
    deliveryMode: config.deliveryMode ?? 'online',
    listenMode: 'remote',
    soundBlocked: false,
    toast: '',
    ended: false,
    resumePage: 0,
    fullscreen: false,
    fullscreenSupported: Boolean(document.documentElement.requestFullscreen),

    // Kept outside Alpine's reactivity: the engine owns canvas state.
    engineRef: null,
    background: null,
    saveTimer: 0,
    pendingState: null,
    storageWarned: false,
    toastTimer: 0,
    noteDrag: null,
    openToken: 0,
    syncRef: null,
    lastView: null,
    remoteLaserTimer: 0,
    // Media: a token per opened item (stale awaits and listeners check it), the
    // presenter's last known playing state and the server clock offset in ms.
    mediaToken: 0,
    stopMediaListeners: null,
    // The controlled YouTube/Vimeo player of the open embed; it stands in for a media element.
    embedPlayerRef: null,
    remotePlaying: null,
    clockOffset: null,
    listenAsked: false,
    // Attendance QR code (LF-Core-LiveClass § Attendance, Điểm danh bằng mã QR), refreshed while the dialog is open.
    attendance: { svg: '', checkedIn: 0, learners: null, failed: false },
    attendanceTimer: 0,
    // The code over the whole screen, for the back of a big room; remembered while the board is open.
    attendanceLarge: false,
    attendanceView: 'closed',

    init() {
        const engine = new BoardEngine({
            canvas: this.$refs.canvas,
            onChange: (state) => this.applyState(state),
            onEvent: (event) => this.record(event),
            onLaser: (laser) => { this.laser = { ...this.laser, ...laser }; },
            onPersist: (state) => this.persist(state),
            onResize: () => this.resized(),
        });
        // Alpine wraps assigned objects in proxies; the engine must stay plain.
        this.engineRef = () => engine;

        const saved = readJson(config.settingsKey);
        if (saved) {
            Object.entries(saved.settings ?? {}).forEach(([name, value]) => engine.setSetting(name, value));
            if (['laser', 'move', 'pen', 'highlighter', 'eraser', 'note'].includes(saved.tool)) {
                engine.setTool(saved.tool);
            }
        }

        engine.setMaterial({ state: readJson(this.ownKey(BLANK.key)) });
        this.listenMode = resolveListenMode(this.deliveryMode, readJson(this.listenKey()));

        installLongPressTooltips(this.$root);
        this.installShortcuts();
        document.addEventListener('fullscreenchange', () => { this.fullscreen = Boolean(document.fullscreenElement); });
        window.addEventListener('pagehide', () => this.flushSave());
        this.startSync();
        if (this.isViewing) {
            this.$nextTick(() => this.askListenIfNeeded());
        }
    },

    engine() {
        return this.engineRef();
    },

    sync() {
        return this.syncRef?.() ?? null;
    },

    get isViewing() {
        return this.mode === 'viewing';
    },

    get isPresenting() {
        return this.mode === 'presenting';
    },

    applyState(state) {
        this.tool = state.tool;
        this.settings = state.settings;
        this.page = state.page;
        this.pageCount = state.pageCount;
        this.zoomPercent = state.zoomPercent;
        this.canUndo = state.canUndo;
        this.canRedo = state.canRedo;
        this.hasPageContent = state.hasPageContent;
        this.hasAnyContent = state.hasAnyContent;
        this.view = state.view;
        if (! this.noteDrag) {
            this.notes = state.notes;
        }
        this.remoteNotes = state.remoteNotes;
        if (state.focusNote) {
            this.$nextTick(() => this.$root.querySelector(`[data-note="${state.focusNote}"] textarea`)?.focus());
        }
    },

    /** Board events: the active presenter sends them; a viewer's own pan or zoom stops following. */
    record(event) {
        if (this.isViewing) {
            if (event.type === 'view.change') {
                this.following = false;
            }

            return;
        }
        if (! this.isPresenting) {
            return;
        }
        if (event.type === 'page.change') {
            this.sync()?.publish({ ...event, page_size: this.engine().pageSize() });
            this.sync()?.publish({ type: 'view.change', ...this.engine().viewData() });
        } else {
            this.sync()?.publish(event);
        }
    },

    // ------------------------------------------------------------ sync

    startSync() {
        if (! config.sync?.stateUrl) {
            this.syncStatus = 'off';
            this.mode = this.canPresent ? 'presenting' : 'viewing';

            return;
        }
        const sync = new BoardSync({ ...config.sync, role: config.role, userId: config.userId }, {
            status: (status) => { this.syncStatus = status; },
            mode: (mode, options) => this.modeChanged(mode, options),
            presenter: (name) => { this.presenterName = name; },
            state: (state) => this.applySyncState(state),
            event: (event) => this.applySyncEvent(event),
            ephemeral: (event) => this.applyEphemeral(event),
            snapshot: () => this.snapshot(),
            ended: (reason) => {
                this.endedReason = reason;
                this.ended = true;
                this.closeMedia();
            },
            error: (kind) => this.notify({
                session: this.strings.session_expired,
                too_large: this.strings.sync_snapshot_too_large,
                failed: this.strings.sync_send_failed,
            }[kind] ?? this.strings.load_failed),
        });
        this.syncRef = () => sync;
        sync.start();
        setInterval(() => {
            if (this.isPresenting) {
                sync.publish({ type: 'view.change', ...this.engine().viewData() });
            }
        }, VIEW_REPEAT_MS);
        // While media plays for the class, viewers get its position (not cached; late joiners use `at`).
        setInterval(() => {
            const element = this.mediaElement();
            // An embed repeats its position while paused too: YouTube reports no seek event.
            if (this.isPresenting && this.media && ! this.media.preview && element && (! element.paused || this.media.kind === 'embed')) {
                sync.publish({ type: 'video.sync', ...this.mediaPayload(element) });
            }
        }, MEDIA_SYNC_MS);
    },

    modeChanged(mode, { adopt = false } = {}) {
        this.flushSave();
        this.mode = mode;
        const engine = this.engine();
        if (mode === 'presenting') {
            this.following = true;
            this.remoteLaser = { ...this.remoteLaser, visible: false };
            // The presenter always has sound; the listening mode is a viewer's.
            this.soundBlocked = false;
            this.applyMute();
            if (adopt) {
                // Taking over continues the live board where the previous presenter left it.
                engine.adoptRemote();
            }

            return;
        }
        // Becoming a viewer: the own layer switches to this browser's private viewer notes.
        this.drawerOpen = false;
        if (this.media?.preview) {
            this.closeMedia();
        } else {
            this.applyMute();
        }
        this.askListenIfNeeded();
        engine.setMaterial({
            background: this.background,
            pageCount: engine.pageCount,
            state: { ...(readJson(this.ownKey(this.material.key)) ?? {}), page: engine.page, view: null },
        });
    },

    takeOver() {
        this.sync()?.takeover();
    },

    followPresenter() {
        this.following = true;
        if (this.lastView) {
            this.engine().followView(this.lastView);
        }
    },

    resized() {
        if (this.isViewing && this.following && this.lastView) {
            this.engine().followView(this.lastView);
        } else if (this.isPresenting) {
            this.sync()?.publish({ type: 'view.change', ...this.engine().viewData() });
        }
    },

    get syncLabel() {
        return this.strings[`sync_${this.syncStatus}`] ?? '';
    },

    get presenterLabel() {
        if (this.isPresenting) {
            return this.strings.sync_presenting;
        }
        if (! this.presenterName) {
            return this.strings.sync_waiting;
        }

        return this.strings.sync_following.replace(':name', this.presenterName);
    },

    materialDescriptor() {
        const material = this.material;
        const descriptor = { key: material.publicKey, kind: material.kind };
        if (material.kind === 'pdf') {
            descriptor.page_count = this.engine().pageCount;
            descriptor.page_size = this.engine().pageSize();
            if (material.local) {
                descriptor.local = true;
            } else {
                descriptor.activity_id = material.activityId;
            }
        }

        return descriptor;
    },

    /** The media shown to the class, for a snapshot; a private preview is not part of the board. */
    mediaState() {
        if (! this.media || this.media.preview) {
            return null;
        }
        const element = this.mediaElement();

        return element ? this.mediaPayload(element) : { id: this.media.id, kind: this.media.kind, time: 0, playing: true };
    },

    mediaPayload(element) {
        return {
            id: this.media.id,
            kind: this.media.kind,
            time: Math.round(element.currentTime * 10) / 10,
            playing: ! element.paused,
        };
    },

    /** The presenter's board for late joiners; a very large board sends only the current page. */
    snapshot() {
        const engine = this.engine();
        let pages = engine.pagesData();
        if (JSON.stringify(pages).length > MAX_SNAPSHOT_PAGES_BYTES) {
            pages = pages[engine.page] ? { [engine.page]: pages[engine.page] } : {};
        }

        return {
            v: 1,
            material: this.materialDescriptor(),
            page: engine.page,
            view: engine.viewData(),
            pages,
            video: this.mediaState(),
        };
    },

    /** Tells viewers about a new material; strokes saved earlier for it follow in a snapshot. */
    announceMaterial() {
        const sync = this.sync();
        if (! this.isPresenting || ! sync) {
            return;
        }
        sync.publish({ type: 'material.change', material: this.materialDescriptor(), page: this.engine().page });
        sync.publish({ type: 'view.change', ...this.engine().viewData() });
        if (this.engine().hasAnyContent()) {
            sync.refresh();
        } else {
            sync.snapshotSoon();
        }
    },

    async applySyncState(state) {
        if (Number(state?.now) > 0) {
            // Accurate to the response time; media sync tolerates a second.
            this.clockOffset = Number(state.now) - Date.now();
        }
        const snapshot = state?.snapshot;
        if (snapshot?.material) {
            await this.followMaterial(snapshot.material, { page: snapshot.page, remote: snapshot.pages ?? {} });
            if (snapshot.view) {
                this.lastView = snapshot.view;
                if (this.following) {
                    this.engine().followView(snapshot.view);
                }
            }
            await this.followVideo(snapshot.video);
        } else if (! state?.seq) {
            // Nothing presented yet: an empty board until the presenter starts.
            await this.followMaterial({ key: 'blank', kind: 'blank' }, { page: 1, remote: {} });
        } else {
            this.engine().setRemotePages({});
        }
        for (const event of state?.events ?? []) {
            await this.applySyncEvent(event);
        }
    },

    async applySyncEvent(event) {
        const engine = this.engine();
        switch (event.type) {
            case 'material.change':
                await this.followMaterial(event.material ?? {}, { page: event.page, remote: {} });
                break;
            case 'page.change':
                await engine.setPage(event.page, { pageSize: event.page_size });
                if (this.following && this.lastView) {
                    engine.followView(this.lastView);
                }
                break;
            case 'video.play':
            case 'video.pause':
            case 'video.seek':
                await this.followVideo({ id: event.id, time: event.time, at: event.at, playing: playingFrom(event) });
                break;
            case 'video.close':
                this.closeMedia();
                break;
            default:
                if (! engine.applyRemote(event)) {
                    this.sync()?.resync();
                }
                break;
        }
    },

    applyEphemeral(event) {
        if (event.type === 'view.change') {
            this.lastView = event;
            if (this.following) {
                this.engine().followView(event);
            }
        } else if (event.type === 'pointer') {
            if (Number(event.page) !== this.engine().page) {
                return;
            }
            const [x, y] = this.engine().toScreen([Number(event.x) || 0, Number(event.y) || 0]);
            const size = Math.max(6, Math.min(64, Number(event.size) || 12));
            this.remoteLaser = { visible: true, x, y, size, active: Boolean(event.active) };
            clearTimeout(this.remoteLaserTimer);
            this.remoteLaserTimer = setTimeout(() => { this.remoteLaser = { ...this.remoteLaser, visible: false }; }, REMOTE_LASER_MS);
        } else if (event.type === 'video.sync') {
            // A heartbeat only corrects the media already open; opening follows the numbered events.
            this.followVideo({ id: event.id, time: event.time, at: event.at, playing: playingFrom(event) }, { open: false }).catch(() => {});
        }
    },

    get remoteLaserStyle() {
        const size = this.remoteLaser.active ? this.remoteLaser.size * 1.25 : this.remoteLaser.size;

        return {
            width: `${size}px`,
            height: `${size}px`,
            transform: `translate3d(${this.remoteLaser.x - size / 2}px, ${this.remoteLaser.y - size / 2}px, 0)`,
        };
    },

    /**
     * Finds a material by id in this board's own list, reloading it once; URLs in events are never used.
     * `pdf`: the presenter shows it as a PDF, so a list that still has the Office file unconverted is stale.
     */
    async findMaterial(id, { pdf = false } = {}) {
        const wanted = Number(id);
        let item = this.materials.find((material) => Number(material.id) === wanted);
        if ((! item || (pdf && item.kind !== 'pdf')) && config.materialsUrl) {
            await this.refreshMaterials();
            item = this.materials.find((material) => Number(material.id) === wanted);
        }

        return item ?? null;
    },

    /** Shows the presenter's material on a viewer, with this browser's private layer for it. */
    async followMaterial(descriptor, { page = 1, remote = {} } = {}) {
        const engine = this.engine();
        const kind = descriptor.kind === 'pdf' ? 'pdf' : 'blank';
        const publicKey = kind === 'blank' ? 'blank' : String(descriptor.key ?? '');
        if (publicKey === this.material.publicKey) {
            engine.setRemotePages(remote);
            await engine.setPage(page, { pageSize: descriptor.page_size });

            return;
        }

        const token = ++this.openToken;
        this.flushSave();
        let background = null;
        let material = { ...BLANK };
        if (kind === 'pdf') {
            const local = Boolean(descriptor.local);
            const activityId = local ? null : Number(descriptor.activity_id) || null;
            const item = activityId ? await this.findMaterial(activityId, { pdf: true }) : null;
            material = {
                key: local ? publicKey : `activity:${activityId}`,
                publicKey,
                kind: 'pdf',
                title: item?.title ?? '',
                activityId,
                local: local || ! item,
            };
            if (item && item.kind === 'pdf') {
                this.loading = true;
                try {
                    const response = await fetch(await this.materialUrl(item), { credentials: 'same-origin', cache: 'no-store' });
                    if (! response.ok) {
                        throw new Error(String(response.status));
                    }
                    background = await openPdf(await readPdfResponse(response));
                } catch {
                    this.notify(this.strings.load_failed);
                }
                this.loading = false;
            }
            if (! background) {
                // A file from the teacher's device, or one this board cannot load: strokes on blank pages.
                material.local = true;
                background = new PlaceholderBackground(descriptor.page_count, descriptor.page_size);
            }
        }
        if (token !== this.openToken) {
            background?.destroy();

            return;
        }

        this.background?.destroy();
        this.background = background;
        this.material = material;
        const saved = readJson(this.ownKey(material.key));
        await engine.setMaterial({
            background,
            pageCount: background?.pageCount ?? 1,
            state: { ...(saved ?? {}), page, view: null },
            remote,
        });
        if (descriptor.page_size && background?.setPageSize) {
            background.setPageSize(engine.page, descriptor.page_size);
        }
        if (this.following && this.lastView) {
            engine.followView(this.lastView);
        }
    },

    serverNow() {
        return this.clockOffset === null ? null : Date.now() + this.clockOffset;
    },

    /**
     * Opens, plays, pauses or seeks the presenter's video or audio on a viewer,
     * at the presenter's position now (`at` is server time). `open: false` only
     * corrects media already open.
     */
    async followVideo(video, { open = true } = {}) {
        if (! video?.id) {
            this.closeMedia();

            return;
        }
        const wanted = Number(video.id);
        if (Number(this.media?.id) !== wanted || this.media?.preview) {
            if (! open) {
                return;
            }
            const item = await this.findMaterial(wanted);
            if (! item || ! MEDIA_KINDS.includes(item.kind)) {
                return;
            }
            await this.openMedia(item, { autoplay: false });
        }
        const token = this.mediaToken;
        const element = this.mediaElement();
        if (! element || Number(this.media?.id) !== wanted) {
            return;
        }
        if (typeof video.playing === 'boolean') {
            this.remotePlaying = video.playing;
        }
        const target = targetTime({ ...video, playing: video.playing ?? this.remotePlaying }, this.serverNow());
        if (needsSeek(element.currentTime, target)) {
            element.currentTime = target;
        }
        if (video.playing === true && element.paused) {
            // Not awaited: a slow start must not hold up the events after it; the token guards staleness.
            this.playFollowing(element, token);
        } else if (video.playing === false && ! element.paused) {
            element.pause();
        }
    },

    /** Plays the presenter's media on a viewer with the listening mode's sound, muted when the browser blocks sound. */
    async playFollowing(element, token) {
        const result = await playMedia(element, {
            muted: this.expectedMuted(),
            isCurrent: () => token === this.mediaToken && this.mediaElement() === element,
            onBlocked: () => { this.soundBlocked = true; },
        });
        if (result === 'muted') {
            this.notify(this.strings.sound_blocked);
        } else if (result === 'failed') {
            this.notify(this.strings.sync_tap_play);
        }
    },

    // ------------------------------------------------------------ sound

    listenKey() {
        return `${config.storageNamespace}:listen`;
    },

    expectedMuted() {
        return shouldMute({ viewing: this.isViewing, listen: this.listenMode, blocked: this.soundBlocked });
    },

    applyMute() {
        const element = this.mediaElement();
        if (element) {
            element.muted = this.expectedMuted();
        }
    },

    setListen(mode) {
        this.listenMode = mode;
        writeJson(this.listenKey(), mode);
    },

    /** Turns sound on from a click: the only way sound starts on a viewer, which also unlocks iPhone audio. */
    enableSound() {
        unlockAudio();
        // The idle element of the other kind is allowed sound now too, for the next media.
        ['audio', 'video'].forEach((tag) => primeElement(mediaPool.take(tag)));
        this.soundBlocked = false;
        this.setListen('remote');
        const element = this.mediaElement();
        if (! element) {
            return;
        }
        element.muted = false;
        if (element.paused && this.remotePlaying === true) {
            // play() runs before playFollowing's first await, so still inside the click.
            this.playFollowing(element, this.mediaToken);
        }
    },

    toggleListen() {
        if (this.listenMode === 'room' || this.soundBlocked) {
            this.enableSound();

            return;
        }
        this.soundBlocked = false;
        this.setListen('room');
        this.applyMute();
    },

    chooseListen(mode) {
        this.$refs.listenDialog?.close();
        if (mode === 'remote') {
            this.enableSound();
        } else {
            this.setListen('room');
            this.applyMute();
        }
    },

    askListenIfNeeded() {
        if (! this.isViewing || this.listenAsked || this.ended || ! asksListenMode(this.deliveryMode, readJson(this.listenKey()))) {
            return;
        }
        this.listenAsked = true;
        this.$refs.listenDialog?.showModal?.();
    },

    get listenLabel() {
        return this.listenMode === 'room' ? this.strings.listen_room : this.strings.listen_remote;
    },

    /** What the viewer actually hears: remote mode can still be silent while the browser blocks sound. */
    get soundOn() {
        return this.listenMode === 'remote' && ! this.soundBlocked;
    },

    get listenHelp() {
        if (this.soundBlocked) {
            return this.strings.enable_sound_help;
        }

        return this.listenMode === 'room' ? this.strings.listen_room_help : this.strings.listen_remote_help;
    },

    get showZoomHint() {
        return this.isPresenting && Boolean(this.media) && ! this.media.preview && this.deliveryMode !== 'offline';
    },

    // ------------------------------------------------------------ storage

    storageKey(materialKey) {
        return `${config.storageNamespace}:${materialKey}`;
    },

    /** A viewer's private strokes and notes are kept apart from the board it presents. */
    ownKey(materialKey) {
        return this.storageKey(materialKey) + (this.isViewing ? ':viewer' : '');
    },

    persist(state) {
        this.pendingState = { key: this.ownKey(this.material.key), state };
        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(() => this.flushSave(), SAVE_DELAY_MS);
    },

    flushSave() {
        clearTimeout(this.saveTimer);
        const pending = this.pendingState;
        this.pendingState = null;
        if (pending && ! writeJson(pending.key, pending.state) && ! this.storageWarned) {
            this.storageWarned = true;
            this.notify(this.strings.storage_full);
        }
    },

    saveSettings() {
        writeJson(config.settingsKey, { tool: this.tool, settings: this.settings });
    },

    // ------------------------------------------------------------ tools

    selectTool(tool) {
        this.engine().setTool(tool);
        this.saveSettings();
    },

    setColor(color) {
        this.engine().setSetting('color', color);
        if (this.tool !== 'pen' && this.tool !== 'highlighter') {
            this.engine().setTool('pen');
        }
        this.saveSettings();
    },

    get sizeKey() {
        return SIZE_LIMITS[this.tool] ? this.tool : null;
    },

    get sizeLimits() {
        return SIZE_LIMITS[this.sizeKey] ?? [1, 10];
    },

    get sizeValue() {
        return this.sizeKey ? this.settings[this.sizeKey] : 0;
    },

    setSize(value) {
        if (this.sizeKey) {
            this.engine().setSetting(this.sizeKey, value);
            this.saveSettings();
        }
    },

    undo() { this.engine().undo(); },
    redo() { this.engine().redo(); },
    clearPage() { this.engine().clearPage(); },

    // The toolbar button shows the code large in the middle of the screen; pressed again it moves to a corner,
    // where it keeps refreshing while the lesson goes on. Pressing the corner code makes it large again.
    openAttendance() {
        this.setAttendanceView(this.attendanceView === 'large' ? 'corner' : 'large');
    },

    setAttendanceView(view) {
        const dialog = this.$refs.attendanceDialog;
        if (! dialog) {
            return;
        }
        const wasOpen = dialog.open;
        if (wasOpen) {
            dialog.close();
        }
        this.attendanceView = view;
        this.attendanceLarge = view === 'large';
        if (view === 'large') {
            dialog.showModal();
        } else {
            dialog.show();
        }
        if (! wasOpen) {
            this.attendance = { ...this.attendance, svg: '', failed: false };
            this.loadAttendanceCode();
        }
    },

    closeAttendance() {
        // Moving between large and the corner closes the dialog for a moment: only a real close stops the code.
        if (this.$refs.attendanceDialog?.open) {
            return;
        }
        this.attendanceView = 'closed';
        this.attendanceLarge = false;
        clearTimeout(this.attendanceTimer);
        this.attendanceTimer = 0;
    },

    async loadAttendanceCode() {
        clearTimeout(this.attendanceTimer);
        if (! this.$refs.attendanceDialog?.open) {
            return;
        }
        let next = 5;
        try {
            const response = await fetch(config.attendanceCodeUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (! response.ok) {
                throw new Error(String(response.status));
            }
            const data = await response.json();
            this.attendance = { svg: data.svg, checkedIn: data.checked_in, learners: data.learners, failed: false };
            next = Math.max(1, Number(data.refresh_in) || 30);
        } catch {
            this.attendance = { ...this.attendance, svg: '', failed: true };
        }
        if (this.$refs.attendanceDialog?.open) {
            this.attendanceTimer = setTimeout(() => this.loadAttendanceCode(), next * 1000);
        }
    },

    askClearAll() {
        this.$refs.clearAllDialog.showModal();
    },

    confirmClearAll() {
        this.engine().clearAll();
        this.$refs.clearAllDialog.close();
    },

    zoom(factor) { this.engine().zoomAt(factor); },
    resetView() { this.engine().resetView(); },
    fitWidth() { this.engine().fitWidth(); },
    fitHeight() { this.engine().fitHeight(); },
    goPage(page) { this.engine().setPage(page); },

    // ------------------------------------------------------------ notes

    get notesTransform() {
        return { transform: `translate(${this.view.x}px, ${this.view.y}px) scale(${this.view.scale})` };
    },

    noteStyle(note) {
        return {
            left: `${note.x}px`,
            top: `${note.y}px`,
            width: `${note.width}px`,
            height: `${note.height}px`,
            '--lf-wb-note': note.color,
        };
    },

    noteFocus(id) { this.engine().beginNoteEdit(id); },

    noteInput(note, text) {
        note.text = text;
        this.engine().setNoteText(note.id, text);
    },

    noteBlur(id) { this.engine().endNoteEdit(id); },

    removeNote(id) { this.engine().removeNote(id); },

    startNoteDrag(event, id) {
        if (event.button !== undefined && event.button !== 0) {
            return;
        }
        event.preventDefault();
        const handle = event.currentTarget;
        handle.setPointerCapture?.(event.pointerId);
        this.engine().beginNoteEdit(id);
        this.noteDrag = { id, x: event.clientX, y: event.clientY };

        const move = (moveEvent) => {
            const drag = this.noteDrag;
            if (! drag) {
                return;
            }
            const dx = moveEvent.clientX - drag.x;
            const dy = moveEvent.clientY - drag.y;
            drag.x = moveEvent.clientX;
            drag.y = moveEvent.clientY;
            this.engine().moveNoteBy(id, dx, dy);
            const note = this.notes.find((item) => item.id === id);
            if (note) {
                note.x += dx / this.view.scale;
                note.y += dy / this.view.scale;
            }
        };
        const end = () => {
            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', end);
            handle.removeEventListener('pointercancel', end);
            this.noteDrag = null;
            this.engine().endNoteEdit(id);
        };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', end);
        handle.addEventListener('pointercancel', end);
    },

    get laserStyle() {
        const size = this.laser.active ? this.laser.size * 1.25 : this.laser.size;

        return {
            width: `${size}px`,
            height: `${size}px`,
            transform: `translate3d(${this.laser.x - size / 2}px, ${this.laser.y - size / 2}px, 0)`,
        };
    },

    // ------------------------------------------------------------ materials

    toggleDrawer() {
        this.drawerOpen = ! this.drawerOpen;
        // An Office file may have become a PDF since the list was loaded.
        if (this.drawerOpen && config.materialsUrl) {
            this.refreshMaterials();
        }
    },

    materialMeta(item) {
        const kind = (item.converted ? this.strings.kind_converted : this.strings[`kind_${item.kind}`]) ?? '';
        const parts = [kind];
        if (item.kind === 'document' && ['processing', 'failed'].includes(item.preview)) {
            parts.push(this.strings[`preview_${item.preview}`]);
        }
        if (item.page_count) {
            parts.push(this.strings.pages.replace(':count', item.page_count));
        }
        if (item.duration_seconds) {
            const total = Math.round(item.duration_seconds);
            const pad = (value) => String(value).padStart(2, '0');
            const hours = Math.floor(total / 3600);
            const minutes = Math.floor((total % 3600) / 60);
            const seconds = pad(total % 60);
            parts.push(hours ? `${hours}:${pad(minutes)}:${seconds}` : `${minutes}:${seconds}`);
        }

        return parts.filter(Boolean).join(' · ');
    },

    async refreshMaterials() {
        if (this.refreshing) {
            return;
        }
        this.refreshing = true;
        try {
            const data = await getJson(config.materialsUrl);
            this.materials = Array.isArray(data.materials) ? data.materials : [];
        } catch (error) {
            this.handleEndpointError(error);
        } finally {
            this.refreshing = false;
        }
    },

    async materialUrl(item) {
        const data = await getJson(item.url_endpoint);

        return data.url;
    },

    async openMaterial(item) {
        switch (item.kind) {
            case 'pdf':
                this.drawerOpen = false;
                await this.loadPdf(`activity:${item.id}`, item.title, async () => {
                    const response = await fetch(await this.materialUrl(item), { credentials: 'same-origin', cache: 'no-store' });
                    if (! response.ok) {
                        throw new EndpointError(response.status);
                    }

                    return readPdfResponse(response);
                }, item);
                break;
            case 'video':
            case 'embed':
            case 'audio':
                this.drawerOpen = false;
                await this.openMedia(item);
                break;
            default:
                await this.openInNewTab(item);
                break;
        }
    },

    async openLocalPdf(file) {
        if (! file) {
            return;
        }
        this.drawerOpen = false;
        const key = `local:${file.name}:${file.size}:${file.lastModified}`;
        await this.loadPdf(key, file.name, async () => {
            if (file.size > 50 * 1024 * 1024) {
                throw new PdfTooLargeError();
            }

            return new Uint8Array(await file.arrayBuffer());
        }, null);
    },

    /** Opens a PDF as the board background, restoring this browser's strokes for it. */
    async loadPdf(key, title, readBytes, item) {
        const token = ++this.openToken;
        this.flushSave();
        this.loading = true;
        let background = null;
        try {
            background = await openPdf(await readBytes());
        } catch (error) {
            this.loading = false;
            if (token !== this.openToken) {
                return;
            }
            if (error instanceof PdfTooLargeError && item) {
                this.notify(this.strings.too_large);
                await this.openInNewTab(item);
            } else if (error instanceof PdfTooLargeError) {
                this.notify(this.strings.too_large_local);
            } else if (error instanceof NotPdfError) {
                this.notify(this.strings.invalid_pdf);
            } else {
                this.handleEndpointError(error, this.strings.load_failed);
            }

            return;
        }
        if (token !== this.openToken) {
            background.destroy();

            return;
        }

        const saved = readJson(this.ownKey(key));
        const resumeAt = Number(saved?.page) || 1;
        // Audio keeps playing over a new PDF (a listening exercise); a video gives way to it.
        if (this.media?.kind !== 'audio') {
            this.closeMedia();
        }
        this.background?.destroy();
        this.background = background;
        this.material = {
            key,
            publicKey: item ? key : `local:${hashKey(key)}`,
            kind: 'pdf',
            title,
            activityId: item ? Number(item.id) : null,
            local: ! item,
        };
        await this.engine().setMaterial({
            background,
            pageCount: background.pageCount,
            state: saved ? { ...saved, page: 1 } : null,
        });
        this.loading = false;
        this.announceMaterial();

        // As in AllVia: offer to continue from the page reached last time.
        if (resumeAt > 1 && resumeAt <= background.pageCount) {
            this.resumePage = resumeAt;
            this.$refs.resumeDialog.showModal();
        }
    },

    answerResume(resume) {
        const page = this.resumePage;
        this.resumePage = 0;
        this.$refs.resumeDialog.close();
        if (resume && page > 1) {
            this.engine().setPage(page);
        }
    },

    resumeAnswered() {
        this.resumePage = 0;
    },

    closeMaterial() {
        this.openToken += 1;
        this.loading = false;
        if (this.material.key === BLANK.key) {
            return;
        }
        this.flushSave();
        this.background?.destroy();
        this.background = null;
        this.material = { ...BLANK };
        this.engine().setMaterial({ state: readJson(this.ownKey(BLANK.key)) }).then(() => this.announceMaterial());
    },

    async openInNewTab(item) {
        // Opened before the await so the browser treats it as the user's click.
        const win = window.open('', '_blank');
        try {
            const url = await this.materialUrl(item);
            if (win) {
                win.opener = null;
                win.location.replace(url);
            }
        } catch (error) {
            win?.close();
            this.handleEndpointError(error, this.strings.load_failed);
        }
    },

    // Uploaded video, trusted embedded video and uploaded audio play inside the
    // board, under the Media video rules: loaded on the user's click, released when
    // closed. `preview` keeps it on the presenter's screen: nothing is published.
    async openMedia(item, { autoplay = true, preview = false } = {}) {
        this.closeMedia();
        const token = this.mediaToken;
        const body = this.$refs.mediaBody;
        this.media = { id: item.id, title: item.title, kind: item.kind, preview };

        let element;
        if (item.kind === 'embed') {
            const frame = document.createElement('iframe');
            const src = controlUrl(item.embed_url, window.location.origin);
            frame.src = src ?? item.embed_url;
            frame.title = item.title;
            frame.allow = 'autoplay; encrypted-media; fullscreen; picture-in-picture';
            frame.allowFullscreen = true;
            frame.referrerPolicy = 'strict-origin-when-cross-origin';
            if (! src) {
                // Not a URL the board can control: shown, never synchronised.
                body.appendChild(frame);
                if (! preview) {
                    this.engine().emit('video.play', { id: item.id, kind: 'embed', time: 0, playing: true });
                }

                return;
            }
            const player = new EmbedPlayer({ frame, provider: embedProvider(item.embed_url) });
            this.embedPlayerRef = () => player;
            element = player;
            element.muted = this.expectedMuted();
            body.appendChild(frame);
        } else {
            let url;
            try {
                url = await this.materialUrl(item);
            } catch (error) {
                if (token === this.mediaToken) {
                    this.media = null;
                    this.handleEndpointError(error, this.strings.load_failed);
                }

                return;
            }
            if (token !== this.mediaToken) {
                return;
            }
            element = this.createMediaElement(item, url);
        }
        // Every listener ends with this item: a late pause or error of a closed element publishes nothing.
        const controller = new AbortController();
        const signal = controller.signal;
        this.stopMediaListeners = () => controller.abort();
        const current = () => token === this.mediaToken;
        if (item.kind !== 'embed') {
            this.retryOnError(element, item, current, signal);
        }
        const names = { play: 'video.play', pause: 'video.pause', seeked: 'video.seek' };
        Object.entries(names).forEach(([type, name]) => element.addEventListener(type, () => {
            if (current() && this.media && ! this.media.preview) {
                this.engine().emit(name, this.mediaPayload(element));
            }
        }, { signal }));
        // The player's own mute button changes the listening mode, so the label never lies.
        element.addEventListener('volumechange', () => {
            if (! current() || ! this.isViewing || element.muted === this.expectedMuted()) {
                return;
            }
            this.soundBlocked = false;
            this.setListen(element.muted ? 'room' : 'remote');
        }, { signal });
        if (item.kind !== 'embed') {
            body.appendChild(element);
        } else if (! preview) {
            // Viewers open an embed paused at once; its play/pause events follow.
            this.engine().emit('video.pause', this.mediaPayload(element));
        }
        if (autoplay) {
            // An embed waits for the presenter to press play in the provider's player.
            if (item.kind !== 'embed') {
                element.play().catch(() => {});
            }
        } else {
            await new Promise((resolve) => {
                element.addEventListener('loadedmetadata', resolve, { once: true, signal });
                element.addEventListener('error', resolve, { once: true, signal });
                signal.addEventListener('abort', resolve, { once: true });
                setTimeout(resolve, 5000);
            });
        }
    },

    /** The board's reused element for this kind (iPhone keeps sound allowed per element). */
    createMediaElement(item, url) {
        const element = mediaPool.take(item.kind === 'audio' ? 'audio' : 'video');
        element.muted = this.expectedMuted();
        element.src = url;

        return element;
    },

    retryOnError(element, item, current, signal) {
        let retried = false;
        // A signed URL expires; when a later range request fails, get a new one and resume.
        element.addEventListener('error', async () => {
            if (retried || ! current()) {
                return;
            }
            retried = true;
            const time = element.currentTime;
            const playing = ! element.paused;
            try {
                const fresh = await this.materialUrl(item);
                if (! current()) {
                    return;
                }
                element.src = fresh;
                element.currentTime = time;
                if (playing) {
                    await element.play();
                }
                retried = false;
            } catch (error) {
                if (current()) {
                    this.handleEndpointError(error, this.strings.load_failed);
                }
            }
        }, { signal });
    },

    previewMedia(item) {
        this.drawerOpen = false;
        this.openMedia(item, { preview: true });
    },

    /** Shows the privately previewed media to the class where it stands now. */
    showToClass() {
        if (! this.media?.preview || ! this.isPresenting) {
            return;
        }
        this.media = { ...this.media, preview: false };
        const element = this.mediaElement();
        if (element) {
            this.engine().emit(element.paused ? 'video.pause' : 'video.play', this.mediaPayload(element));
        } else if (this.media.kind === 'embed') {
            this.engine().emit('video.play', { id: this.media.id, kind: 'embed', time: 0, playing: true });
        }
    },

    /** The open media's player: an audio or video element, or the controlled embed player. */
    mediaElement() {
        return this.embedPlayerRef?.() ?? this.$refs.mediaBody?.querySelector('video, audio') ?? null;
    },

    closeMedia() {
        this.mediaToken += 1;
        this.stopMediaListeners?.();
        this.stopMediaListeners = null;
        this.embedPlayerRef?.().destroy();
        this.embedPlayerRef = null;
        const body = this.$refs.mediaBody;
        body?.querySelectorAll('video, audio').forEach((element) => {
            element.pause();
            element.removeAttribute('src');
            element.load();
        });
        if (body) {
            body.replaceChildren();
        }
        if (this.media && ! this.media.preview) {
            this.engine().emit('video.close', { id: this.media.id });
        }
        this.media = null;
        this.soundBlocked = false;
    },

    // ------------------------------------------------------------ page

    handleEndpointError(error, fallback = this.strings.load_failed) {
        if (error instanceof EndpointError && error.status === 403) {
            this.ended = true;
        } else if (error instanceof EndpointError && (error.status === 401 || error.status === 419)) {
            this.notify(this.strings.session_expired);
        } else {
            this.notify(fallback);
        }
    },

    notify(message) {
        this.toast = message;
        clearTimeout(this.toastTimer);
        this.toastTimer = setTimeout(() => { this.toast = ''; }, TOAST_MS);
    },

    leave() {
        this.flushSave();
        if (window.opener && ! window.opener.closed) {
            window.close();
        }
        window.location.href = config.backUrl;
    },

    toggleFullscreen() {
        if (document.fullscreenElement) {
            document.exitFullscreen?.();
        } else {
            document.documentElement.requestFullscreen?.().catch(() => {});
        }
    },

    installShortcuts() {
        window.addEventListener('keydown', (event) => {
            const target = event.target;
            if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) {
                return;
            }
            if (document.querySelector('dialog[open]')) {
                return;
            }
            const command = event.ctrlKey || event.metaKey;
            const key = event.key.toLowerCase();
            if (command && key === 'z') {
                event.preventDefault();
                event.shiftKey ? this.redo() : this.undo();
            } else if (command && key === 'y') {
                event.preventDefault();
                this.redo();
            } else if (this.material.kind === 'pdf' && ! this.isViewing && ['pagedown', 'arrowright'].includes(key)) {
                event.preventDefault();
                this.goPage(this.page + 1);
            } else if (this.material.kind === 'pdf' && ! this.isViewing && ['pageup', 'arrowleft'].includes(key)) {
                event.preventDefault();
                this.goPage(this.page - 1);
            } else if (key === 'escape' && this.drawerOpen) {
                this.drawerOpen = false;
            } else if (key === 'escape' && this.media) {
                this.closeMedia();
            }
        });
    },
}));

window.Alpine = Alpine;
Alpine.start();
