// PDF inside the board (LF-Media § Whiteboard Rendering Exception): pdf.js
// draws each page on a canvas. The whole file is loaded once, because a signed
// URL expires and pdf.js cannot switch the URL of an open document.
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';
import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

export const MAX_PDF_BYTES = 50 * 1024 * 1024;
// iOS Safari refuses canvases above about 16.7 million pixels.
const MAX_CANVAS_PIXELS = 16_000_000;
const RENDER_DELAY_MS = 120;

export class PdfTooLargeError extends Error {}
export class NotPdfError extends Error {}

/** Reads a fetch Response into bytes, refusing files over the board limit. */
export async function readPdfResponse(response) {
    const declared = Number(response.headers.get('Content-Length') || 0);
    if (declared > MAX_PDF_BYTES) {
        throw new PdfTooLargeError();
    }

    const buffer = await response.arrayBuffer();
    if (buffer.byteLength > MAX_PDF_BYTES) {
        throw new PdfTooLargeError();
    }

    return new Uint8Array(buffer);
}

export function looksLikePdf(bytes) {
    // "%PDF" may follow a few bytes of junk; pdf.js allows up to 1024.
    const head = new TextDecoder('latin1').decode(bytes.subarray(0, 1024));

    return head.includes('%PDF-');
}

export async function openPdf(bytes) {
    if (! looksLikePdf(bytes)) {
        throw new NotPdfError();
    }

    const task = pdfjs.getDocument({
        data: bytes,
        disableRange: true,
        disableStream: true,
        disableAutoFetch: true,
        isEvalSupported: false,
        enableXfa: false,
    });
    const document = await task.promise;

    return new PdfBackground(document, task);
}

/** The board background for an open PDF: page size in PDF points and a cached page bitmap. */
export class PdfBackground {
    constructor(document, task) {
        this.document = document;
        this.task = task;
        this.pageCount = document.numPages;
        this.width = 595;
        this.height = 842;
        this.pageNumber = 0;
        this.page = null;
        this.bitmap = null;
        this.bitmapScale = 0;
        this.renderTask = null;
        this.pendingScale = 0;
        this.timer = 0;
        this.destroyed = false;
    }

    async setPage(number) {
        if (number === this.pageNumber && this.page) {
            return;
        }
        this.cancelRender();
        const page = await this.document.getPage(number);
        const viewport = page.getViewport({ scale: 1 });
        this.page = page;
        this.pageNumber = number;
        this.width = viewport.width;
        this.height = viewport.height;
        this.bitmap = null;
        this.bitmapScale = 0;
    }

    /** Draws the cached page and schedules a sharper render when the zoom changed. */
    draw(context, view, dpr, onReady) {
        if (this.bitmap) {
            context.drawImage(this.bitmap, 0, 0, this.width, this.height);
        }

        const wanted = this.targetScale(view.scale * dpr);
        if (Math.abs(wanted - this.bitmapScale) / wanted > 0.15 && wanted !== this.pendingScale) {
            this.pendingScale = wanted;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.render(wanted, onReady), this.bitmap ? RENDER_DELAY_MS : 0);
        }
    }

    targetScale(scale) {
        const maxScale = Math.sqrt(MAX_CANVAS_PIXELS / (this.width * this.height));

        return Math.max(0.1, Math.min(scale, maxScale));
    }

    async render(scale, onReady) {
        if (this.destroyed || ! this.page) {
            return;
        }
        this.cancelRender();
        const page = this.page;
        const viewport = page.getViewport({ scale });
        const canvas = document.createElement('canvas');
        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);

        this.renderTask = page.render({ canvasContext: canvas.getContext('2d'), viewport });
        try {
            await this.renderTask.promise;
        } catch {
            return; // Cancelled by a newer render or a page change.
        } finally {
            this.renderTask = null;
            if (this.pendingScale === scale) {
                this.pendingScale = 0;
            }
        }
        if (page !== this.page || this.destroyed) {
            return;
        }
        this.bitmap = canvas;
        this.bitmapScale = scale;
        onReady();
    }

    cancelRender() {
        clearTimeout(this.timer);
        this.pendingScale = 0;
        this.renderTask?.cancel();
        this.renderTask = null;
    }

    destroy() {
        this.destroyed = true;
        this.cancelRender();
        this.bitmap = null;
        this.task.destroy();
    }
}

/**
 * What a viewer shows while the presenter has a PDF from their own device open:
 * blank pages of the same size, so strokes land where the teacher drew them.
 * The file itself never leaves the teacher's browser.
 */
export class PlaceholderBackground {
    constructor(pageCount, firstSize = null) {
        this.pageCount = Math.max(1, Number(pageCount) || 1);
        this.sizes = new Map();
        this.width = 595;
        this.height = 842;
        this.pageNumber = 1;
        if (firstSize) {
            this.setPageSize(1, firstSize);
        }
    }

    setPageSize(number, size) {
        const width = Number(size?.w);
        const height = Number(size?.h);
        if (width > 0 && height > 0 && width < 20000 && height < 20000) {
            this.sizes.set(number, { width, height });
            if (number === this.pageNumber) {
                this.width = width;
                this.height = height;
            }
        }
    }

    async setPage(number) {
        this.pageNumber = number;
        const size = this.sizes.get(number);
        if (size) {
            this.width = size.width;
            this.height = size.height;
        }
    }

    draw() {}

    destroy() {}
}
