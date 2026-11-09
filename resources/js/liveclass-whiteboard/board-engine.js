import { clamp, round1, strokeHit, uid } from './geometry';
import { centredView, fitScales } from './board-view';
import { extendStroke, growStroke } from './board-stroke';

// Business behaviour follows the AllVia e-board: six pen colours, six note
// colours, ten undo steps, a 20px grid, wheel zoom 0.3–3 on a blank board and
// 0.5–3 of the fitted size on a document, and an eraser that removes whole strokes.
export const PEN_COLORS = [
    { key: 'blue', value: '#2755ea' },
    { key: 'red', value: '#e53935' },
    { key: 'green', value: '#43a047' },
    { key: 'orange', value: '#fb8c00' },
    { key: 'purple', value: '#8e24aa' },
    { key: 'black', value: '#333333' },
];
export const NOTE_COLORS = ['#fff59d', '#f48fb1', '#80deea', '#a5d6a7', '#ffcc80', '#ce93d8'];
export const SIZE_LIMITS = { pen: [1, 10], highlighter: [10, 40], eraser: [10, 50], laser: [12, 48] };
export const DEFAULT_SETTINGS = { color: PEN_COLORS[0].value, pen: 3, highlighter: 20, eraser: 20, laser: 12 };

const MAX_HISTORY = 10;
const GRID = 20;
const NOTE_WIDTH = 200;
const NOTE_HEIGHT = 150;
const BLANK_ZOOM = [0.3, 3];
const DOCUMENT_ZOOM = [0.5, 3];
const DOCUMENT_MARGIN = 24;
const POINTER_EVENT_MS = 50;
const VIEW_EVENT_MS = 150;

const emptyPage = () => ({ paths: [], notes: [] });
const snapshotOf = (page) => ({ paths: page.paths.slice(), notes: page.notes.map((note) => ({ ...note })) });
const isStroke = (stroke) => stroke && typeof stroke.id === 'string' && Array.isArray(stroke.points);
const isNote = (note) => note && typeof note.id === 'string' && Number.isFinite(note.x) && Number.isFinite(note.y);

/** Reads `{ page: { paths, notes } }` into a Map, dropping malformed entries. */
function pagesFrom(data, pageCount) {
    const pages = new Map();
    Object.entries(data ?? {}).forEach(([page, value]) => {
        const number = Number(page);
        if (Number.isInteger(number) && number >= 1 && number <= pageCount) {
            pages.set(number, {
                paths: Array.isArray(value?.paths) ? value.paths.filter(isStroke) : [],
                notes: Array.isArray(value?.notes) ? value.notes.filter(isNote) : [],
            });
        }
    });

    return pages;
}

function pagesToObject(pages) {
    const result = {};
    pages.forEach((data, page) => {
        if (data.paths.length || data.notes.length) {
            result[page] = data;
        }
    });

    return result;
}

/**
 * Canvas board: strokes and notes per page in world coordinates (PDF points
 * when a document is open), one view transform, undo history per page.
 *
 * Every change is also emitted as a plain event object through `onEvent`;
 * board-sync.js sends the presenter's events to learners. A viewer keeps the
 * presenter's board in a read-only remote layer under its own private layer.
 */
export class BoardEngine {
    constructor({ canvas, onChange = () => {}, onEvent = () => {}, onLaser = () => {}, onPersist = () => {}, onResize = () => {} }) {
        this.canvas = canvas;
        this.context = canvas.getContext('2d');
        this.base = document.createElement('canvas');
        this.baseContext = this.base.getContext('2d');
        this.onChange = onChange;
        this.onEvent = onEvent;
        this.onLaser = onLaser;
        this.onPersist = onPersist;
        this.onResize = onResize;

        this.tool = 'pen';
        this.settings = { ...DEFAULT_SETTINGS };
        this.pages = new Map();
        this.remotePages = new Map();
        this.history = new Map();
        this.page = 1;
        this.pageCount = 1;
        this.view = { scale: 1, x: 0, y: 0 };
        this.background = null;
        this.size = { width: 0, height: 0, dpr: 1 };
        this.pointers = new Map();
        this.gesture = null;
        this.hover = null;
        this.baseDirty = true;
        this.frame = 0;
        this.lastPointerEventAt = 0;
        this.lastViewEventAt = 0;
        this.noteEdit = null;

        this.handlePointerDown = this.handlePointerDown.bind(this);
        this.handlePointerMove = this.handlePointerMove.bind(this);
        this.handlePointerUp = this.handlePointerUp.bind(this);
        this.handlePointerLeave = this.handlePointerLeave.bind(this);
        this.handleWheel = this.handleWheel.bind(this);

        canvas.addEventListener('pointerdown', this.handlePointerDown);
        canvas.addEventListener('pointermove', this.handlePointerMove);
        canvas.addEventListener('pointerup', this.handlePointerUp);
        canvas.addEventListener('pointercancel', this.handlePointerUp);
        canvas.addEventListener('pointerleave', this.handlePointerLeave);
        canvas.addEventListener('wheel', this.handleWheel, { passive: false });
        canvas.addEventListener('contextmenu', (event) => event.preventDefault());

        this.resizeObserver = new ResizeObserver(() => this.resize());
        this.resizeObserver.observe(canvas.parentElement);
        this.resize();
    }

    destroy() {
        this.resizeObserver.disconnect();
        cancelAnimationFrame(this.frame);
    }

    // ---------------------------------------------------------------- state

    currentPage() {
        if (! this.pages.has(this.page)) {
            this.pages.set(this.page, emptyPage());
        }

        return this.pages.get(this.page);
    }

    pageHistory(page = this.page) {
        if (! this.history.has(page)) {
            this.history.set(page, { undo: [], redo: [] });
        }

        return this.history.get(page);
    }

    pushHistory(snapshot) {
        const history = this.pageHistory();
        history.undo.push(snapshot);
        if (history.undo.length > MAX_HISTORY) {
            history.undo.shift();
        }
        history.redo = [];
    }

    /**
     * Loads one material: its background (null for the blank board), its saved
     * state and, on a viewer, the presenter's pages as the remote layer.
     */
    setMaterial({ background = null, pageCount = 1, state = null, remote = null }) {
        this.cancelGesture();
        this.background = background;
        this.pageCount = Math.max(1, pageCount);
        this.pages = pagesFrom(state?.pages, this.pageCount);
        this.remotePages = pagesFrom(remote, this.pageCount);
        this.history = new Map();

        this.page = clamp(Number(state?.page) || 1, 1, this.pageCount);

        return this.preparePage().then(() => {
            if (state?.view && Number.isFinite(state.view.zoom)) {
                const [min, max] = this.zoomLimits();
                this.view = {
                    scale: clamp(state.view.zoom * this.baseScale(), min, max),
                    x: Number(state.view.x) || 0,
                    y: Number(state.view.y) || 0,
                };
            } else {
                this.resetView({ silent: true });
            }
            this.changed({ persist: false });
        });
    }

    serialize() {
        return {
            v: 1,
            page: this.page,
            view: { zoom: this.view.scale / this.baseScale(), x: round1(this.view.x), y: round1(this.view.y) },
            pages: pagesToObject(this.pages),
        };
    }

    /**
     * Own pages as plain data, for the presenter's snapshot. A stroke still being
     * drawn is included: viewers already have its first part.
     */
    pagesData() {
        const data = pagesToObject(this.pages);
        const gesture = this.gesture;
        if (gesture?.type === 'stroke') {
            const page = data[gesture.page] ?? { paths: [], notes: [] };
            data[gesture.page] = { ...page, paths: [...page.paths, { ...gesture.stroke, points: gesture.stroke.points.slice() }] };
        }

        return data;
    }

    /** Current page size in world units, so viewers without the file can place strokes. */
    pageSize() {
        return this.background ? { w: round1(this.background.width), h: round1(this.background.height) } : null;
    }

    // ---------------------------------------------------------------- remote layer

    remotePage(page = this.page) {
        if (! this.remotePages.has(page)) {
            this.remotePages.set(page, emptyPage());
        }

        return this.remotePages.get(page);
    }

    setRemotePages(data) {
        this.remotePages = pagesFrom(data, this.pageCount);
        this.baseDirty = true;
        this.changed({ persist: false });
    }

    /** A presenter who takes over continues the live board: the remote layer becomes their own. */
    adoptRemote() {
        this.cancelGesture();
        this.pages = this.remotePages;
        this.remotePages = new Map();
        this.history = new Map();
        this.baseDirty = true;
        this.changed();
    }

    /**
     * Applies one presenter event to the remote layer. Events are replayed on
     * top of a snapshot that may already contain them, so each one is
     * idempotent. Returns false when the layer no longer matches (resync).
     */
    applyRemote(event) {
        const page = Number(event.page) || this.page;
        if (page < 1 || page > this.pageCount) {
            return true;
        }
        const target = this.remotePage(page);
        switch (event.type) {
            case 'stroke.add': {
                if (! isStroke(event.stroke)) {
                    break;
                }
                const existing = target.paths.find((stroke) => stroke.id === event.stroke.id);
                if (existing) {
                    growStroke(existing, event.stroke);
                } else {
                    target.paths = [...target.paths, { ...event.stroke, points: event.stroke.points.slice() }];
                }
                break;
            }
            case 'stroke.append': {
                const stroke = target.paths.find((item) => item.id === event.id);
                if (stroke && ! extendStroke(stroke, event.from, event.points)) {
                    return false;
                }
                break;
            }
            case 'stroke.remove': {
                const ids = new Set(Array.isArray(event.ids) ? event.ids : []);
                target.paths = target.paths.filter((stroke) => ! ids.has(stroke.id));
                break;
            }
            case 'page.clear':
                target.paths = [];
                target.notes = [];
                break;
            case 'board.clear':
                this.remotePages = new Map();
                break;
            case 'page.replace':
                target.paths = Array.isArray(event.paths) ? event.paths.filter(isStroke) : [];
                target.notes = Array.isArray(event.notes) ? event.notes.filter(isNote) : [];
                break;
            case 'note.add':
                if (isNote(event.note) && ! target.notes.some((note) => note.id === event.note.id)) {
                    target.notes = [...target.notes, { ...event.note }];
                }
                break;
            case 'note.update':
                target.notes = target.notes.map((note) => (note.id === event.id
                    ? { ...note, text: String(event.text ?? '').slice(0, 2000), x: Number(event.x) || 0, y: Number(event.y) || 0 }
                    : note));
                break;
            case 'note.remove':
                target.notes = target.notes.filter((note) => note.id !== event.id);
                break;
            default:
                return true;
        }
        if (page === this.page) {
            this.baseDirty = true;
            this.changed({ persist: false });
        }

        return true;
    }

    async preparePage() {
        if (this.background?.setPage) {
            await this.background.setPage(this.page);
        }
        this.baseDirty = true;
    }

    async setPage(page, { pageSize = null } = {}) {
        const next = clamp(Math.round(Number(page) || 1), 1, this.pageCount);
        if (pageSize && this.background?.setPageSize) {
            this.background.setPageSize(next, pageSize);
        }
        if (next === this.page) {
            return;
        }

        this.cancelGesture();
        const zoom = this.view.scale / this.baseScale();
        this.page = next;
        await this.preparePage();

        if (Math.abs(zoom - 1) < 0.001) {
            this.fitPage({ silent: true });
        }
        this.emit('page.change', { page: this.page });
        this.changed();
    }

    setTool(tool) {
        this.cancelGesture();
        this.tool = tool;
        this.hover = null;
        this.onLaser({ visible: false });
        this.baseDirty = true;
        this.changed({ persist: false });
    }

    setSetting(name, value) {
        if (name === 'color') {
            this.settings.color = value;
        } else if (SIZE_LIMITS[name]) {
            const [min, max] = SIZE_LIMITS[name];
            this.settings[name] = clamp(Math.round(Number(value) || min), min, max);
        }
        this.changed({ persist: false });
    }

    // ---------------------------------------------------------------- view

    /** 100% of a document is the whole page on screen (board-view.js). */
    baseScale() {
        return this.background ? this.fitScales().page : 1;
    }

    zoomLimits() {
        const base = this.baseScale();
        const [min, max] = this.background ? DOCUMENT_ZOOM : BLANK_ZOOM;

        return [min * base, max * base];
    }

    fitScales() {
        return fitScales(this.size, this.background, DOCUMENT_MARGIN);
    }

    zoomPercent() {
        return Math.round((this.view.scale / this.baseScale()) * 100);
    }

    zoomAt(factor, screenX = this.size.width / 2, screenY = this.size.height / 2) {
        const [min, max] = this.zoomLimits();
        const scale = clamp(this.view.scale * factor, min, max);
        const worldX = (screenX - this.view.x) / this.view.scale;
        const worldY = (screenY - this.view.y) / this.view.scale;

        this.view = { scale, x: screenX - worldX * scale, y: screenY - worldY * scale };
        this.viewChanged();
    }

    resetView({ silent = false } = {}) {
        if (this.background) {
            this.fitPage({ silent });

            return;
        }
        this.view = { scale: 1, x: 0, y: 0 };
        if (! silent) {
            this.viewChanged();
        }
    }

    fitPage({ silent = false } = {}) {
        if (! this.background) {
            return;
        }
        this.view = centredView(this.size, this.background, this.fitScales().page);
        if (! silent) {
            this.viewChanged();
        }
    }

    fitHeight({ silent = false } = {}) {
        if (! this.background) {
            return;
        }
        const [min, max] = this.zoomLimits();
        const scale = clamp(this.fitScales().height, min, max);
        this.view = {
            scale,
            x: (this.size.width - this.background.width * scale) / 2,
            y: DOCUMENT_MARGIN,
        };
        if (! silent) {
            this.viewChanged();
        }
    }

    fitWidth() {
        if (! this.background) {
            return;
        }
        const [min, max] = this.zoomLimits();
        const scale = clamp(this.fitScales().width, min, max);
        this.view = { scale, x: (this.size.width - this.background.width * scale) / 2, y: DOCUMENT_MARGIN };
        this.viewChanged();
    }

    viewChanged() {
        this.baseDirty = true;
        const now = performance.now();
        if (now - this.lastViewEventAt > VIEW_EVENT_MS) {
            this.lastViewEventAt = now;
            this.emit('view.change', this.viewData());
        }
        this.changed();
    }

    /** The view as zoom plus the world point at the screen centre, so screens of any size can follow it. */
    viewData() {
        const { width, height } = this.size;
        const { scale, x, y } = this.view;

        return {
            zoom: Math.round((scale / this.baseScale()) * 1000) / 1000,
            cx: round1((width / 2 - x) / scale),
            cy: round1((height / 2 - y) / scale),
        };
    }

    /** Shows the presenter's view; never emitted, so a viewer's own pan or zoom is what stops following. */
    followView(data) {
        const zoom = Number(data?.zoom);
        const cx = Number(data?.cx);
        const cy = Number(data?.cy);
        if (! Number.isFinite(zoom) || ! Number.isFinite(cx) || ! Number.isFinite(cy) || ! this.size.width) {
            return;
        }
        const [min, max] = this.zoomLimits();
        const scale = clamp(zoom * this.baseScale(), min, max);
        this.view = { scale, x: this.size.width / 2 - cx * scale, y: this.size.height / 2 - cy * scale };
        this.baseDirty = true;
        this.changed({ persist: false });
    }

    /** World point to screen point, for the presenter's laser on a viewer. */
    toScreen([x, y]) {
        return [x * this.view.scale + this.view.x, y * this.view.scale + this.view.y];
    }

    resize() {
        const parent = this.canvas.parentElement;
        const width = parent.clientWidth;
        const height = parent.clientHeight;
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        if (! width || ! height) {
            return;
        }

        const zoom = this.size.height ? this.view.scale / this.baseScale() : 1;
        this.size = { width, height, dpr };
        [this.canvas, this.base].forEach((canvas) => {
            canvas.width = Math.round(width * dpr);
            canvas.height = Math.round(height * dpr);
        });

        if (this.background && Math.abs(zoom - 1) < 0.001) {
            this.fitPage({ silent: true });
        }
        this.baseDirty = true;
        this.requestRender();
        this.changed({ persist: false });
        this.onResize();
    }

    screenPoint(event) {
        const rect = this.canvas.getBoundingClientRect();

        return [event.clientX - rect.left, event.clientY - rect.top];
    }

    toWorld([x, y]) {
        return [(x - this.view.x) / this.view.scale, (y - this.view.y) / this.view.scale];
    }

    // ---------------------------------------------------------------- pointer

    handlePointerDown(event) {
        if (event.pointerType === 'mouse' && event.button !== 0 && event.button !== 1) {
            return;
        }
        event.preventDefault();
        try {
            this.canvas.setPointerCapture?.(event.pointerId);
        } catch {
            // The pointer is already gone (e.g. a pen lifted mid-event); drawing works without capture.
        }
        const point = this.screenPoint(event);
        this.pointers.set(event.pointerId, point);

        // Two fingers always pan and pinch, whatever the tool.
        if (event.pointerType === 'touch' && this.pointers.size === 2) {
            this.cancelGesture({ keepPointers: true });
            const [a, b] = [...this.pointers.values()];
            this.gesture = {
                type: 'pinch',
                distance: Math.max(1, Math.hypot(a[0] - b[0], a[1] - b[1])),
                middle: [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2],
                view: { ...this.view },
            };

            return;
        }
        if (this.gesture) {
            return;
        }

        const pans = event.button === 1 || event.altKey || this.tool === 'move';
        if (pans) {
            this.gesture = { type: 'pan', pointerId: event.pointerId, start: point, view: { ...this.view } };
            this.canvas.dataset.panning = 'true';

            return;
        }

        const world = this.toWorld(point);
        switch (this.tool) {
            case 'pen':
            case 'highlighter':
                this.gesture = {
                    type: 'stroke',
                    pointerId: event.pointerId,
                    page: this.page,
                    stroke: {
                        id: uid('s'),
                        tool: this.tool,
                        color: this.settings.color,
                        width: round1(this.settings[this.tool] / this.view.scale),
                        points: [[round1(world[0]), round1(world[1])]],
                    },
                };
                // Sent while drawing, so viewers see the line follow the pen (§ Nét vẽ theo bút).
                this.emit('stroke.add', { page: this.page, stroke: { ...this.gesture.stroke, points: this.gesture.stroke.points.slice() } });
                this.requestRender();
                break;
            case 'eraser':
                this.gesture = { type: 'erase', pointerId: event.pointerId, before: snapshotOf(this.currentPage()), erased: false };
                this.eraseAt(world);
                break;
            case 'note':
                this.addNote(world);
                break;
            case 'laser':
                this.gesture = { type: 'laser', pointerId: event.pointerId };
                this.laserAt(point, true);
                break;
            default:
                break;
        }
    }

    handlePointerMove(event) {
        const point = this.screenPoint(event);
        if (this.pointers.has(event.pointerId)) {
            this.pointers.set(event.pointerId, point);
        }

        const gesture = this.gesture;
        if (! gesture) {
            this.hoverAt(point, event.pointerType);

            return;
        }

        if (gesture.type === 'pinch') {
            if (this.pointers.size < 2) {
                return;
            }
            const [a, b] = [...this.pointers.values()];
            const distance = Math.max(1, Math.hypot(a[0] - b[0], a[1] - b[1]));
            const middle = [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];
            const [min, max] = this.zoomLimits();
            const scale = clamp(gesture.view.scale * (distance / gesture.distance), min, max);
            const worldX = (gesture.middle[0] - gesture.view.x) / gesture.view.scale;
            const worldY = (gesture.middle[1] - gesture.view.y) / gesture.view.scale;
            this.view = { scale, x: middle[0] - worldX * scale, y: middle[1] - worldY * scale };
            this.viewChanged();

            return;
        }
        if (event.pointerId !== gesture.pointerId) {
            return;
        }

        switch (gesture.type) {
            case 'pan':
                this.view = {
                    scale: gesture.view.scale,
                    x: gesture.view.x + point[0] - gesture.start[0],
                    y: gesture.view.y + point[1] - gesture.start[1],
                };
                this.viewChanged();
                break;
            case 'stroke': {
                // Some browsers return an empty list (or none at all); the event itself is then the only sample.
                const coalesced = event.getCoalescedEvents?.() ?? [];
                const samples = coalesced.length ? coalesced : [event];
                const points = gesture.stroke.points;
                const from = points.length;
                const minStep = 0.75 / this.view.scale;
                samples.forEach((sample) => {
                    const [x, y] = this.toWorld(this.screenPoint(sample));
                    const last = points[points.length - 1];
                    if (Math.hypot(x - last[0], y - last[1]) >= minStep) {
                        points.push([round1(x), round1(y)]);
                    }
                });
                if (points.length > from) {
                    this.emit('stroke.append', { page: gesture.page, id: gesture.stroke.id, from, points: points.slice(from) });
                }
                this.requestRender();
                break;
            }
            case 'erase':
                this.hover = point;
                this.eraseAt(this.toWorld(point));
                break;
            case 'laser':
                this.laserAt(point, true);
                break;
            default:
                break;
        }
    }

    handlePointerUp(event) {
        this.pointers.delete(event.pointerId);
        const gesture = this.gesture;
        if (! gesture) {
            return;
        }

        if (gesture.type === 'pinch') {
            if (this.pointers.size < 2) {
                this.gesture = null;
                this.changed();
            }

            return;
        }
        if (event.pointerId !== gesture.pointerId) {
            return;
        }

        this.gesture = null;
        delete this.canvas.dataset.panning;

        if (gesture.type === 'stroke' && event.type !== 'pointercancel') {
            // Viewers already have every point: the stroke was sent while it was drawn.
            const page = this.currentPage();
            this.pushHistory(snapshotOf(page));
            page.paths = [...page.paths, gesture.stroke];
            this.baseDirty = true;
            this.changed();
        } else if (gesture.type === 'stroke') {
            this.dropLiveStroke(gesture);
        } else if (gesture.type === 'erase') {
            if (gesture.erased) {
                this.changed();
            }
        } else if (gesture.type === 'laser') {
            this.laserAt(this.screenPoint(event), false);
        } else if (gesture.type === 'pan') {
            this.changed();
        }
        this.requestRender();
    }

    handlePointerLeave(event) {
        if (event.pointerType !== 'touch' && ! this.gesture) {
            this.hover = null;
            this.onLaser({ visible: false });
            this.requestRender();
        }
    }

    handleWheel(event) {
        event.preventDefault();
        const [x, y] = this.screenPoint(event);
        const delta = event.deltaMode === 1 ? event.deltaY * 16 : event.deltaY;
        this.zoomAt(Math.exp(-delta * 0.0015), x, y);
    }

    hoverAt(point, pointerType) {
        if (this.tool === 'eraser' && pointerType !== 'touch') {
            this.hover = point;
            this.requestRender();
        } else if (this.tool === 'laser' && pointerType !== 'touch') {
            this.laserAt(point, false);
        }
    }

    laserAt(point, active) {
        this.onLaser({ visible: true, active, x: point[0], y: point[1], size: this.settings.laser });
        const now = performance.now();
        if (now - this.lastPointerEventAt > POINTER_EVENT_MS) {
            this.lastPointerEventAt = now;
            const [x, y] = this.toWorld(point);
            this.emit('pointer', { page: this.page, x: round1(x), y: round1(y), size: this.settings.laser, active });
        }
    }

    cancelGesture({ keepPointers = false } = {}) {
        if (this.gesture?.type === 'erase' && this.gesture.erased) {
            this.changed();
        }
        if (this.gesture?.type === 'stroke') {
            this.dropLiveStroke(this.gesture);
        }
        this.gesture = null;
        delete this.canvas.dataset.panning;
        if (! keepPointers) {
            this.pointers.clear();
        }
        this.requestRender();
    }

    /** A stroke cut off before the pen lifted is not kept; viewers drop the part they got. */
    dropLiveStroke(gesture) {
        this.emit('stroke.remove', { page: gesture.page, ids: [gesture.stroke.id] });
    }

    eraseAt(world) {
        const gesture = this.gesture;
        const page = this.currentPage();
        const radius = this.settings.eraser / 2 / this.view.scale;
        const removed = page.paths.filter((stroke) => strokeHit(stroke, world, radius)).map((stroke) => stroke.id);
        if (! removed.length) {
            this.requestRender();

            return;
        }

        if (! gesture.erased) {
            this.pushHistory(gesture.before);
            gesture.erased = true;
        }
        const ids = new Set(removed);
        page.paths = page.paths.filter((stroke) => ! ids.has(stroke.id));
        this.baseDirty = true;
        this.emit('stroke.remove', { page: this.page, ids: removed });
        this.requestRender();
    }

    // ---------------------------------------------------------------- notes

    addNote([x, y]) {
        const page = this.currentPage();
        this.pushHistory(snapshotOf(page));
        const note = {
            id: uid('n'),
            x: round1(x),
            y: round1(y),
            width: round1(NOTE_WIDTH / this.view.scale),
            height: round1(NOTE_HEIGHT / this.view.scale),
            color: NOTE_COLORS[Math.floor(Math.random() * NOTE_COLORS.length)],
            text: '',
        };
        page.notes = [...page.notes, note];
        this.emit('note.add', { page: this.page, note: { ...note } });
        this.changed({ focusNote: note.id });
    }

    beginNoteEdit(id) {
        const note = this.currentPage().notes.find((item) => item.id === id);
        if (note) {
            this.noteEdit = { id, before: snapshotOf(this.currentPage()), text: note.text, x: note.x, y: note.y };
        }
    }

    setNoteText(id, text) {
        const note = this.currentPage().notes.find((item) => item.id === id);
        if (note) {
            note.text = String(text).slice(0, 2000);
        }
    }

    moveNoteBy(id, dx, dy) {
        const note = this.currentPage().notes.find((item) => item.id === id);
        if (! note) {
            return;
        }
        note.x = round1(note.x + dx / this.view.scale);
        note.y = round1(note.y + dy / this.view.scale);
        this.changed({ persist: false });
    }

    /** Ends a text edit or a drag; records one history step when something changed. */
    endNoteEdit(id) {
        const edit = this.noteEdit;
        this.noteEdit = null;
        const note = this.currentPage().notes.find((item) => item.id === id);
        if (! edit || edit.id !== id || ! note) {
            return;
        }
        if (note.text === edit.text && note.x === edit.x && note.y === edit.y) {
            return;
        }
        this.pushHistory(edit.before);
        this.emit('note.update', { page: this.page, id, text: note.text, x: note.x, y: note.y });
        this.changed();
    }

    removeNote(id) {
        const page = this.currentPage();
        if (! page.notes.some((note) => note.id === id)) {
            return;
        }
        this.noteEdit = null;
        this.pushHistory(snapshotOf(page));
        page.notes = page.notes.filter((note) => note.id !== id);
        this.emit('note.remove', { page: this.page, id });
        this.changed();
    }

    // ---------------------------------------------------------------- history

    canUndo() {
        return this.pageHistory().undo.length > 0;
    }

    canRedo() {
        return this.pageHistory().redo.length > 0;
    }

    undo() {
        this.step('undo', 'redo');
    }

    redo() {
        this.step('redo', 'undo');
    }

    step(from, to) {
        this.cancelGesture();
        const history = this.pageHistory();
        if (! history[from].length) {
            return;
        }
        const page = this.currentPage();
        history[to].push(snapshotOf(page));
        const snapshot = history[from].pop();
        page.paths = snapshot.paths;
        page.notes = snapshot.notes;
        this.baseDirty = true;
        this.emit('page.replace', { page: this.page, paths: page.paths, notes: page.notes });
        this.changed();
    }

    hasPageContent() {
        const page = this.currentPage();

        return page.paths.length > 0 || page.notes.length > 0;
    }

    hasAnyContent() {
        return [...this.pages.values()].some((page) => page.paths.length || page.notes.length);
    }

    clearPage() {
        if (! this.hasPageContent()) {
            return;
        }
        const page = this.currentPage();
        this.pushHistory(snapshotOf(page));
        page.paths = [];
        page.notes = [];
        this.baseDirty = true;
        this.emit('page.clear', { page: this.page });
        this.changed();
    }

    /** Removes every page's drawings and notes. Confirmed by the user first; not undoable. */
    clearAll() {
        this.cancelGesture();
        this.pages = new Map();
        this.history = new Map();
        this.baseDirty = true;
        this.emit('board.clear', {});
        this.changed();
    }

    // ---------------------------------------------------------------- render

    requestRender() {
        if (this.frame) {
            return;
        }
        this.frame = requestAnimationFrame(() => {
            this.frame = 0;
            this.render();
        });
    }

    render() {
        const { width, height, dpr } = this.size;
        if (! width) {
            return;
        }

        if (this.baseDirty) {
            this.renderBase();
            this.baseDirty = false;
        }

        const context = this.context;
        context.setTransform(1, 0, 0, 1, 0, 0);
        context.clearRect(0, 0, this.canvas.width, this.canvas.height);
        context.drawImage(this.base, 0, 0);

        context.setTransform(dpr * this.view.scale, 0, 0, dpr * this.view.scale, dpr * this.view.x, dpr * this.view.y);
        if (this.gesture?.type === 'stroke') {
            drawStroke(context, this.gesture.stroke);
        }

        if (this.tool === 'eraser' && this.hover) {
            context.setTransform(dpr, 0, 0, dpr, 0, 0);
            context.beginPath();
            context.arc(this.hover[0], this.hover[1], this.settings.eraser / 2, 0, Math.PI * 2);
            context.lineWidth = 1;
            context.strokeStyle = 'rgba(55, 65, 81, 0.7)';
            context.fillStyle = 'rgba(255, 255, 255, 0.35)';
            context.fill();
            context.stroke();
        }
    }

    renderBase() {
        const { width, height, dpr } = this.size;
        const context = this.baseContext;
        const { scale, x, y } = this.view;

        context.setTransform(dpr, 0, 0, dpr, 0, 0);
        context.clearRect(0, 0, width, height);

        if (this.background) {
            context.fillStyle = '#e9ebf0';
            context.fillRect(0, 0, width, height);
            context.setTransform(dpr * scale, 0, 0, dpr * scale, dpr * x, dpr * y);
            context.shadowColor = 'rgba(15, 23, 42, 0.18)';
            context.shadowBlur = 12 / scale;
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, this.background.width, this.background.height);
            context.shadowColor = 'transparent';
            this.background.draw(context, this.view, dpr, () => {
                this.baseDirty = true;
                this.requestRender();
            });
        } else {
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, width, height);
            drawGrid(context, this.view, width, height);
        }

        context.setTransform(dpr * scale, 0, 0, dpr * scale, dpr * x, dpr * y);
        this.remotePages.get(this.page)?.paths.forEach((stroke) => drawStroke(context, stroke));
        this.currentPage().paths.forEach((stroke) => drawStroke(context, stroke));
        context.globalAlpha = 1;
    }

    // ---------------------------------------------------------------- output

    emit(type, payload) {
        try {
            this.onEvent({ type, at: Date.now(), ...payload });
        } catch {
            // A listener must never break drawing.
        }
    }

    changed({ persist = true, focusNote = null } = {}) {
        this.requestRender();
        this.onChange({
            tool: this.tool,
            settings: { ...this.settings },
            page: this.page,
            pageCount: this.pageCount,
            zoomPercent: this.zoomPercent(),
            canUndo: this.canUndo(),
            canRedo: this.canRedo(),
            hasPageContent: this.hasPageContent(),
            hasAnyContent: this.hasAnyContent(),
            view: { ...this.view },
            notes: this.currentPage().notes.map((note) => ({ ...note })),
            remoteNotes: (this.remotePages.get(this.page)?.notes ?? []).map((note) => ({ ...note })),
            focusNote,
        });
        if (persist) {
            this.onPersist(this.serialize());
        }
    }
}

function drawGrid(context, view, width, height) {
    const step = GRID * view.scale;
    if (step < 6) {
        return;
    }
    context.beginPath();
    const startX = ((view.x % step) + step) % step;
    const startY = ((view.y % step) + step) % step;
    for (let x = startX; x <= width; x += step) {
        context.moveTo(Math.round(x) + 0.5, 0);
        context.lineTo(Math.round(x) + 0.5, height);
    }
    for (let y = startY; y <= height; y += step) {
        context.moveTo(0, Math.round(y) + 0.5);
        context.lineTo(width, Math.round(y) + 0.5);
    }
    context.lineWidth = 1;
    context.strokeStyle = '#eef0f5';
    context.stroke();
}

export function drawStroke(context, stroke) {
    const points = stroke.points;
    if (! points?.length) {
        return;
    }
    context.globalAlpha = stroke.tool === 'highlighter' ? 0.4 : 1;
    context.strokeStyle = stroke.color;
    context.fillStyle = stroke.color;
    context.lineWidth = stroke.width;
    context.lineCap = 'round';
    context.lineJoin = 'round';

    if (points.length === 1) {
        context.beginPath();
        context.arc(points[0][0], points[0][1], stroke.width / 2, 0, Math.PI * 2);
        context.fill();
        context.globalAlpha = 1;

        return;
    }

    context.beginPath();
    context.moveTo(points[0][0], points[0][1]);
    for (let index = 1; index < points.length - 1; index += 1) {
        const [x, y] = points[index];
        const [nextX, nextY] = points[index + 1];
        context.quadraticCurveTo(x, y, (x + nextX) / 2, (y + nextY) / 2);
    }
    const last = points[points.length - 1];
    context.lineTo(last[0], last[1]);
    context.stroke();
    context.globalAlpha = 1;
}
