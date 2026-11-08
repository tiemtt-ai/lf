// Board strokes, notes and view live only in this browser (ADR-0002 § LiveClass
// Whiteboard Amendment), keyed by tenant, user, Session and material, as AllVia
// does. Every key starts with this prefix so logout can clear them all.
export const STORAGE_PREFIX = 'lf-wb:';

export function readJson(key) {
    try {
        const raw = window.localStorage.getItem(key);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

/** @returns {boolean} false when the browser refused to store it (quota, private mode). */
export function writeJson(key, value) {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));

        return true;
    } catch {
        return false;
    }
}

export function removeKey(key) {
    try {
        window.localStorage.removeItem(key);
    } catch {
        // Nothing to clear when storage is unavailable.
    }
}

/** A classroom touch screen is a shared device: logout removes every board key. */
export function clearAllBoards() {
    try {
        const keys = [];
        for (let index = 0; index < window.localStorage.length; index += 1) {
            const key = window.localStorage.key(index);
            if (key && key.startsWith(STORAGE_PREFIX)) {
                keys.push(key);
            }
        }
        keys.forEach((key) => window.localStorage.removeItem(key));
    } catch {
        // Storage unavailable: nothing was stored either.
    }
}
