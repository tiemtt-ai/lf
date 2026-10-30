/** Opt-in adapters for real native selects. All presentation is shared CSS. */
import { initUiKitDateInputs } from './admin-ui-kit-date.js';

export { initUiKitDateInputs };

const instances = new WeakMap();

/**
 * Initialise-once guard kept on the element itself. A module-level WeakMap is not enough: when the same file is
 * loaded twice (e.g. Vite dev serves an HMR-updated copy with `?t=`), each copy would initialise the same element.
 * Elements without a dataset (test fakes) fall back to the per-module WeakMap checks only.
 */
export function claimOnce(node, name) {
    const dataset = node?.dataset;
    if (!dataset) return true;
    const key = `uiKitReady${name}`;
    if (dataset[key]) return false;
    dataset[key] = '1';
    return true;
}

export function releaseClaim(node, name) {
    if (node?.dataset) delete node.dataset[`uiKitReady${name}`];
}

/**
 * Where an open list goes: below the control when it fits there, otherwise on the side with more room (a list at the
 * bottom of a dialog body would be cut by the body's scroll box). Distances are px inside the clipping boundary.
 */
export function choosePlacement({ spaceBelow, spaceAbove, listHeight }) {
    if (spaceBelow >= listHeight) return 'bottom';
    return spaceAbove > spaceBelow ? 'top' : 'bottom';
}

// The box that clips the list: the nearest scrolling/clipping ancestor, within the viewport.
function clippingBounds(node) {
    let top = 0;
    let bottom = window.innerHeight;
    for (let parent = node.parentElement; parent; parent = parent.parentElement) {
        const { overflowY } = getComputedStyle(parent);
        if (['auto', 'scroll', 'hidden', 'clip'].includes(overflowY)) {
            const rect = parent.getBoundingClientRect();
            top = Math.max(top, rect.top);
            bottom = Math.min(bottom, rect.bottom);
            break;
        }
    }
    return { top, bottom };
}

export function initUiKitSelect(select) {
    if (instances.has(select)) return instances.get(select);
    // A multiple select is the UI Kit "combobox chọn nhiều": the choices are chips in the control, the list stays open
    // while picking, × on a chip removes it and the clear button removes them all.
    const multiple = select.multiple;
    const root = select.closest('.lf-admin-ui');
    if (!root) return null;
    if (!claimOnce(select, 'Select')) return null;
    const label = root.querySelector(`label[for="${CSS.escape(select.id)}"]`);
    const wrapper = document.createElement('div');
    wrapper.className = 'ux-combo';
    const control = document.createElement('div');
    control.className = 'ux-combo__control';
    const input = document.createElement('input');
    input.type = 'text';
    input.id = `${select.id}-ui-kit`;
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-required', String(select.required));
    input.setAttribute('aria-invalid', select.getAttribute('aria-invalid') || 'false');
    if (select.hasAttribute('aria-describedby')) input.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'ux-clear cursor-interaction';
    clear.textContent = '×';
    clear.setAttribute('aria-label', select.dataset.uiClearLabel || label?.textContent?.trim() || 'Clear');
    const icon = document.createElement('span');
    icon.className = 'ux-combo__icon';
    icon.setAttribute('aria-hidden', 'true');
    const list = document.createElement('div');
    list.className = 'ux-combo__list';
    list.id = `${select.id}-ui-kit-options`;
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', label?.textContent?.trim() || select.name);
    if (multiple) list.setAttribute('aria-multiselectable', 'true');
    list.hidden = true;
    input.setAttribute('aria-controls', list.id);
    control.append(input, clear, icon);
    wrapper.append(control, list);
    select.after(wrapper);
    const previousLabelFor = label?.htmlFor;
    if (label) label.htmlFor = input.id;
    select.hidden = true;
    let active = -1;
    let searching = false;
    let matches = [];

    const selectedLabel = () => select.selectedOptions[0]?.textContent?.trim() || '';
    function renderChips() {
        control.querySelectorAll('.ux-chip').forEach(chip => chip.remove());
        for (const option of select.selectedOptions) {
            const chip = document.createElement('span');
            chip.className = 'ux-chip';
            const text = document.createElement('span');
            text.className = 'ux-chip__text';
            text.textContent = option.textContent.trim();
            chip.title = text.textContent;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ux-clear cursor-interaction';
            remove.textContent = '×';
            remove.tabIndex = -1;
            remove.setAttribute('aria-label', `${select.dataset.uiClearLabel || 'Clear'}: ${text.textContent}`);
            remove.addEventListener('mousedown', event => event.preventDefault());
            remove.addEventListener('click', event => {
                event.stopPropagation();
                choose(option);
            });
            chip.append(text, remove);
            control.insertBefore(chip, input);
        }
    }
    function render() {
        const query = searching ? input.value.toLocaleLowerCase() : '';
        matches = [...select.options].filter(option => !option.disabled && option.textContent.toLocaleLowerCase().includes(query));
        clear.hidden = multiple
            ? select.selectedOptions.length === 0
            : select.required || select.value === '' || ![...select.options].some(option => option.value === '');
        if (multiple) renderChips();
        list.replaceChildren();
        for (const [index, option] of matches.entries()) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'ux-option cursor-interaction';
            button.id = `${select.id}-ui-kit-option-${index}`;
            button.tabIndex = -1;
            button.setAttribute('role', 'option');
            const picked = multiple ? option.selected : select.value === option.value;
            button.setAttribute('aria-selected', String(picked));
            button.textContent = option.textContent;
            if (picked) {
                const check = document.createElement('span');
                check.textContent = '✓';
                check.setAttribute('aria-hidden', 'true');
                button.append(check);
            }
            button.addEventListener('mousedown', event => event.preventDefault());
            button.addEventListener('click', () => choose(option));
            list.append(button);
        }
        if (!matches.length) {
            const empty = document.createElement('div');
            empty.className = 'ui-caption';
            empty.setAttribute('role', 'status');
            empty.textContent = select.dataset.uiEmptyLabel || '';
            list.append(empty);
        }
    }
    function place() {
        const gap = 5;
        const control = wrapper.getBoundingClientRect();
        const bounds = clippingBounds(wrapper);
        wrapper.dataset.placement = choosePlacement({
            spaceBelow: bounds.bottom - control.bottom - gap,
            spaceAbove: control.top - bounds.top - gap,
            listHeight: list.offsetHeight,
        });
    }
    function close() {
        list.hidden = true;
        delete wrapper.dataset.placement;
        searching = false;
        active = -1;
        if (multiple) {
            input.value = '';
            input.placeholder = select.selectedOptions.length ? '' : (select.dataset.uiPlaceholder || '');
        } else {
            input.value = select.value === '' ? '' : selectedLabel();
            input.placeholder = selectedLabel();
        }
        input.removeAttribute('aria-activedescendant');
        input.setAttribute('aria-expanded', 'false');
    }
    function open() {
        if (select.disabled) return;
        render();
        list.hidden = false;
        place();
        input.setAttribute('aria-expanded', 'true');
    }
    function choose(option) {
        if (!option) return;
        if (multiple) {
            option.selected = !option.selected;
            input.setCustomValidity('');
            searching = false;
            input.value = '';
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            input.placeholder = select.selectedOptions.length ? '' : (select.dataset.uiPlaceholder || '');
            input.focus();
            return;
        }
        select.value = option.value;
        input.setCustomValidity('');
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
        close();
        render();
        input.focus();
    }
    function keydown(event) {
        if (event.key === 'Escape' && !list.hidden) {
            event.preventDefault();
            event.stopPropagation();
            close();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            open();
            active = Math.max(0, Math.min(matches.length - 1, active + (event.key === 'ArrowDown' ? 1 : -1)));
            [...list.children].forEach((item, index) => item.toggleAttribute('data-ui-active', index === active));
            const button = list.children[active];
            if (button?.getAttribute('role') === 'option') {
                input.setAttribute('aria-activedescendant', button.id);
                button.scrollIntoView({ block: 'nearest' });
            }
        } else if (event.key === 'Enter' && !list.hidden) {
            event.preventDefault();
            choose(matches[active < 0 ? 0 : active]);
        }
    }
    const onInput = () => { searching = true; active = -1; open(); };
    // The path is taken when the click happened: a picked option or a removed chip is already re-rendered (detached)
    // by the time the click reaches the document, so `contains(event.target)` would read it as a click outside.
    const onOutside = event => {
        const path = event.composedPath?.() ?? [];
        if (!(path.includes(wrapper) || wrapper.contains(event.target))) close();
    };
    const onBlur = event => { if (!wrapper.contains(event.relatedTarget)) close(); };
    const onClear = () => {
        if (!multiple) {
            choose([...select.options].find(option => option.value === ''));
            return;
        }
        [...select.options].forEach(option => { option.selected = false; });
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
        close();
        input.focus();
    };
    // Picking in a multiple select keeps the list open; any other change (or a single select) closes it.
    const onChange = () => {
        if (!(multiple && !list.hidden)) close();
        render();
        if (multiple && !list.hidden) place();
    };
    const onInvalid = event => {
        event.preventDefault();
        input.setCustomValidity(select.validationMessage);
        input.reportValidity();
    };
    input.disabled = select.disabled;
    input.addEventListener('click', open);
    input.addEventListener('input', onInput);
    input.addEventListener('keydown', keydown);
    wrapper.addEventListener('focusout', onBlur);
    clear.addEventListener('click', onClear);
    select.addEventListener('change', onChange);
    select.addEventListener('invalid', onInvalid);
    document.addEventListener('click', onOutside);
    close();
    render();
    const instance = { destroy() {
        document.removeEventListener('click', onOutside);
        select.removeEventListener('change', onChange);
        select.removeEventListener('invalid', onInvalid);
        if (label) label.htmlFor = previousLabelFor;
        select.hidden = false;
        wrapper.remove();
        releaseClaim(select, 'Select');
        instances.delete(select);
    } };
    instances.set(select, instance);
    return instance;
}

const toastTimers = new WeakMap();

/**
 * Toast: a message node `[data-ui-toast]` (text in `[data-ui-toast-text]`, optional `[data-ui-toast-close]`).
 * Showing replaces the text and restarts the auto-hide timer (`data-ui-toast-timeout` ms, 0 = stays until closed).
 */
export function hideUiToast(node, deps = {}) {
    const clear = deps.clearTimeout ?? globalThis.clearTimeout;
    if (toastTimers.has(node)) clear(toastTimers.get(node));
    toastTimers.delete(node);
    node.hidden = true;
}

export function showUiToast(node, text, deps = {}) {
    const set = deps.setTimeout ?? globalThis.setTimeout;
    const clear = deps.clearTimeout ?? globalThis.clearTimeout;
    if (toastTimers.has(node)) clear(toastTimers.get(node));
    toastTimers.delete(node);
    const target = node.querySelector?.('[data-ui-toast-text]') ?? node;
    target.textContent = text;
    node.hidden = false;
    const timeout = Number(node.getAttribute?.('data-ui-toast-timeout') ?? 0);
    if (timeout > 0) toastTimers.set(node, set(() => hideUiToast(node, deps), timeout));
}

export function initUiKitToasts(scope = document, deps = {}) {
    scope.querySelectorAll('[data-ui-toast]').forEach(node => {
        if (!claimOnce(node, 'Toast')) return;
        node.querySelector('[data-ui-toast-close]')?.addEventListener('click', () => hideUiToast(node, deps));
        if (!node.hidden) showUiToast(node, (node.querySelector('[data-ui-toast-text]') ?? node).textContent, deps);
    });
}

/**
 * Any script on the page (an Alpine dialog, say) can show the list toasts without importing this module:
 * `document.dispatchEvent(new CustomEvent('ui-toast-show', { detail: { kind: 'success' | 'danger', message } }))`.
 */
export function initUiKitToastEvents(doc = document, deps = {}) {
    const onShow = event => {
        const { kind = 'success', message = '' } = event.detail ?? {};
        const node = doc.querySelector(kind === 'danger' ? '[data-ui-dialog-form-page-error]' : '[data-ui-dialog-form-flash]');
        if (node && message) showUiToast(node, String(message), deps);
    };
    doc.addEventListener('ui-toast-show', onShow);
    return () => doc.removeEventListener('ui-toast-show', onShow);
}

export const UI_MEDIA_PREVIEW_EVENT = 'ui-media-preview';
/** Ask a media slot to empty itself (e.g. because its alternative was chosen). */
export const UI_MEDIA_CLEAR_EVENT = 'ui-media-clear';
export const UI_EMBED_CHANGE_EVENT = 'ui-embed-change';
export const UI_EMBED_CLEAR_EVENT = 'ui-embed-clear';

/**
 * Media slot: one tile per file (markup: components/ui-media-slot.blade.php).
 * Filled iff there is a pending file, or a saved file that is not marked removed. Remove on a saved file only
 * sets the hidden remove flag (the server detaches on save); remove on a pending file discards the choice and
 * returns to the base state (saved, or empty when the saved file was already removed). A file chosen while the
 * flag is set replaces the saved one on the server. View asks the form to open its preview through a bubbling
 * `ui-media-preview` event, so saved and pending files share one path.
 */
export function initUiKitMediaSlot(slot, deps = {}) {
    if (instances.has(slot)) return instances.get(slot);
    const part = name => slot.querySelector(`[data-ui-media-${name}]`);
    const input = part('input');
    if (!input) return null;
    if (!claimOnce(slot, 'MediaSlot')) return null;
    const filled = part('filled');
    const empty = part('empty');
    const image = part('image');
    const fallback = part('fallback');
    const viewButton = part('view');
    const removeButton = part('remove');
    const flag = part('remove-flag');
    const statusNode = part('status');
    const urlApi = deps.url ?? globalThis.URL;
    const createEvent = deps.createEvent ?? ((type, detail) => new CustomEvent(type, { bubbles: true, detail }));
    const defer = deps.setTimeout ?? ((fn) => globalThis.setTimeout(fn, 0));
    const saved = slot.getAttribute('data-saved') === 'true';
    const savedName = slot.getAttribute('data-saved-name') || '';
    const savedPreview = slot.getAttribute('data-saved-preview-url') || '';
    const savedMime = slot.getAttribute('data-saved-mime') || '';
    const savedImage = image.getAttribute('src') || '';
    const previewable = slot.getAttribute('data-pending-previewable') === 'true';
    const kind = slot.getAttribute('data-kind') || 'image';
    const selectedText = slot.getAttribute('data-status-selected') || ':name';
    const removedText = slot.getAttribute('data-status-removed') || '';
    let removed = flag?.value === '1';
    let pending = null;
    let imageBroken = false;

    const announce = text => { if (statusNode) statusNode.textContent = text; };
    const discardPending = () => {
        if (pending?.url) urlApi.revokeObjectURL(pending.url);
        pending = null;
    };
    const render = () => {
        const isFilled = pending !== null || (saved && !removed);
        const src = pending ? (pending.isImage ? pending.url : '') : savedImage;
        const showImage = isFilled && src !== '' && !imageBroken;
        slot.setAttribute('data-state', isFilled ? 'filled' : 'empty');
        filled.hidden = !isFilled;
        empty.hidden = isFilled;
        input.tabIndex = isFilled ? -1 : 0;
        if (showImage) image.setAttribute('src', src); else image.removeAttribute('src');
        image.hidden = !showImage;
        fallback.hidden = showImage;
        viewButton.hidden = !isFilled || (pending ? pending.url === '' || !previewable : savedPreview === '');
        removeButton.hidden = !isFilled || (!pending && !flag);
    };
    const syncFromInput = () => {
        discardPending();
        const file = input.files?.[0] ?? null;
        imageBroken = false;
        if (file) {
            const isImage = kind === 'image' && String(file.type).startsWith('image/');
            pending = { file, isImage, url: isImage || previewable ? urlApi.createObjectURL(file) : '' };
        }
    };
    const onChange = () => {
        syncFromInput();
        if (pending) announce(selectedText.replace(':name', pending.file.name));
        render();
    };
    // Empties the tile: a chosen file is discarded, a saved one is marked for removal. Returns whether anything changed.
    const clear = () => {
        if (pending) {
            discardPending();
            input.value = '';
            // Anything that mirrors "a file is chosen" (e.g. a form's own state) hears that it is not any more.
            input.dispatchEvent?.(new Event('change', { bubbles: true }));
        } else if (saved && !removed && flag) {
            removed = true;
            flag.value = '1';
        } else {
            return false;
        }
        announce(removedText);
        render();
        return true;
    };
    const onRemove = () => {
        if (!clear()) return;
        if (empty.hidden) viewButton.hidden ? removeButton.focus?.() : viewButton.focus?.();
        else input.focus?.();
    };
    const onView = () => {
        const url = pending ? pending.url : savedPreview;
        if (!url) return;
        slot.dispatchEvent(createEvent(UI_MEDIA_PREVIEW_EVENT, {
            name: pending ? pending.file.name : savedName,
            url,
            kind,
            mimeType: pending ? String(pending.file.type || '') : savedMime,
        }));
    };
    const onImageError = () => { imageBroken = true; render(); };
    const form = slot.closest?.('form') ?? null;
    const onReset = () => defer(() => {
        removed = false;
        if (flag) flag.value = '0';
        syncFromInput();
        render();
    });

    input.addEventListener('change', onChange);
    removeButton.addEventListener('click', onRemove);
    viewButton.addEventListener('click', onView);
    image.addEventListener('error', onImageError);
    form?.addEventListener('reset', onReset);
    slot.addEventListener?.(UI_MEDIA_CLEAR_EVENT, clear);
    syncFromInput();
    render();
    const instance = { destroy() {
        discardPending();
        input.removeEventListener('change', onChange);
        removeButton.removeEventListener('click', onRemove);
        viewButton.removeEventListener('click', onView);
        image.removeEventListener('error', onImageError);
        form?.removeEventListener('reset', onReset);
        slot.removeEventListener?.(UI_MEDIA_CLEAR_EVENT, clear);
        releaseClaim(slot, 'MediaSlot');
        instances.delete(slot);
    } };
    instances.set(slot, instance);
    return instance;
}

/**
 * Mirrors the server's trusted video link rules (TrustedVideoUrlService): HTTPS only, YouTube or Vimeo. Returns the
 * normalised link, the embed URL for the preview and a thumbnail (YouTube only), or null when the link is not accepted.
 * The server stays the authority; this only lets the tile answer at once.
 */
export function parseEmbedLink(input) {
    let url;
    try { url = new URL(String(input ?? '').trim()); } catch (error) { return null; }
    if (url.protocol !== 'https:') return null;
    const host = url.hostname.toLowerCase();
    const path = url.pathname.replace(/^\/+|\/+$/g, '');
    const youtube = id => (/^[A-Za-z0-9_-]{11}$/.test(id ?? '') ? {
        provider: 'youtube',
        id,
        url: `https://www.youtube.com/watch?v=${id}`,
        embedUrl: `https://www.youtube-nocookie.com/embed/${id}`,
        thumbnail: `https://i.ytimg.com/vi/${id}/hqdefault.jpg`,
    } : null);
    if (['youtube.com', 'www.youtube.com', 'm.youtube.com'].includes(host)) {
        const parts = path.split('/');
        if (path === 'watch') return youtube(url.searchParams.get('v'));
        return parts[0] === 'shorts' || parts[0] === 'embed' ? youtube(parts[1]) : null;
    }
    if (host === 'youtu.be') return youtube(path.split('/')[0]);
    if (['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'].includes(host)) {
        const id = path.split('/').filter(Boolean).at(-1);
        return /^\d+$/.test(id ?? '') ? { provider: 'vimeo', id, url: `https://vimeo.com/${id}`, embedUrl: `https://player.vimeo.com/video/${id}`, thumbnail: '' } : null;
    }
    return null;
}

/**
 * Embedded-video tile (markup: components/ui-embed-slot.blade.php), same look as a media slot: empty = "+ Nhúng",
 * click opens a small link editor; OK shows the video in the tile itself (thumbnail, View/Remove on hover). The
 * committed link lives in a hidden input. Choosing a video file elsewhere empties it through `ui-embed-clear`.
 */
export function initUiKitEmbedSlot(slot, deps = {}) {
    if (instances.has(slot)) return instances.get(slot);
    const part = name => slot.querySelector(`[data-ui-embed-${name}]`);
    const value = part('value');
    if (!value) return null;
    if (!claimOnce(slot, 'EmbedSlot')) return null;
    const filled = part('filled');
    const addButton = part('add');
    const image = part('image');
    const fallback = part('fallback');
    const viewButton = part('view');
    const removeButton = part('remove');
    const editor = part('editor');
    const input = part('input');
    const okButton = part('ok');
    const cancelButton = part('cancel');
    const errorNode = part('error');
    const statusNode = part('status');
    const createEvent = deps.createEvent ?? ((type, detail) => new CustomEvent(type, { bubbles: true, detail }));
    const doc = deps.document ?? globalThis.document;
    const savedUrl = value.value || '';
    const savedPreview = slot.getAttribute('data-saved-preview-url') || '';
    const savedImage = slot.getAttribute('data-saved-image') || '';
    const savedName = slot.getAttribute('data-saved-name') || '';
    const removedText = slot.getAttribute('data-status-removed') || '';
    let editing = false;

    // The saved link keeps what the server decided to show (thumbnail, preview); a link typed now is derived here.
    const parsed = () => (value.value && value.value !== savedUrl ? parseEmbedLink(value.value) : null);
    const announce = text => { if (statusNode) statusNode.textContent = text; };
    const setError = text => {
        errorNode.textContent = text;
        errorNode.hidden = text === '';
        input.setAttribute('aria-invalid', text === '' ? 'false' : 'true');
    };
    const render = () => {
        const isFilled = value.value !== '';
        const info = parsed();
        const thumb = info ? info.thumbnail : (value.value === savedUrl ? savedImage : '');
        slot.setAttribute('data-state', isFilled ? 'filled' : 'empty');
        filled.hidden = !isFilled;
        addButton.hidden = isFilled;
        addButton.setAttribute('aria-expanded', String(editing));
        if (isFilled && thumb) image.setAttribute('src', thumb); else image.removeAttribute('src');
        image.hidden = !(isFilled && thumb);
        fallback.hidden = isFilled && Boolean(thumb);
        const preview = info ? info.embedUrl : (value.value === savedUrl ? savedPreview : '');
        viewButton.hidden = !isFilled || preview === '';
        editor.hidden = !editing;
    };
    const emitChange = () => slot.dispatchEvent(createEvent(UI_EMBED_CHANGE_EVENT, { url: value.value }));
    const openEditor = () => {
        editing = true;
        input.value = value.value;
        setError('');
        render();
        input.focus?.();
    };
    const closeEditor = (refocus = true) => {
        editing = false;
        setError('');
        render();
        if (refocus) (value.value === '' ? addButton : viewButton.hidden ? removeButton : viewButton).focus?.();
    };
    const commit = () => {
        const link = parseEmbedLink(input.value);
        if (!link) {
            setError(slot.getAttribute('data-invalid-message') || 'Invalid link');
            input.focus?.();
            return;
        }
        value.value = link.url;
        editing = false;
        setError('');
        render();
        emitChange();
        viewButton.hidden ? removeButton.focus?.() : viewButton.focus?.();
    };
    const clear = () => {
        if (value.value === '') return false;
        value.value = '';
        announce(removedText);
        render();
        emitChange();
        return true;
    };
    const onRemove = () => { if (clear()) addButton.focus?.(); };
    const onView = () => {
        const info = parsed();
        const url = info ? info.embedUrl : (value.value === savedUrl ? savedPreview : '');
        if (!url) return;
        slot.dispatchEvent(createEvent(UI_MEDIA_PREVIEW_EVENT, {
            name: info ? (info.provider === 'youtube' ? 'YouTube' : 'Vimeo') : savedName,
            url,
            kind: 'embed',
            mimeType: 'text/html',
        }));
    };
    const onKeydown = event => {
        if (event.key === 'Enter') { event.preventDefault(); commit(); }
        else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeEditor(); }
    };
    const onOutside = event => {
        if (editing && !slot.contains(event.target)) closeEditor(false);
    };
    const onReset = () => {
        value.value = savedUrl;
        editing = false;
        render();
        emitChange();
    };
    const form = slot.closest?.('form') ?? null;
    addButton.addEventListener('click', openEditor);
    okButton.addEventListener('click', commit);
    cancelButton.addEventListener('click', () => closeEditor());
    removeButton.addEventListener('click', onRemove);
    viewButton.addEventListener('click', onView);
    editor.addEventListener('keydown', onKeydown);
    doc?.addEventListener?.('pointerdown', onOutside);
    form?.addEventListener('reset', onReset);
    slot.addEventListener?.(UI_EMBED_CLEAR_EVENT, clear);
    render();
    const instance = { destroy() {
        doc?.removeEventListener?.('pointerdown', onOutside);
        form?.removeEventListener('reset', onReset);
        slot.removeEventListener?.(UI_EMBED_CLEAR_EVENT, clear);
        addButton.removeEventListener('click', openEditor);
        okButton.removeEventListener('click', commit);
        removeButton.removeEventListener('click', onRemove);
        viewButton.removeEventListener('click', onView);
        editor.removeEventListener('keydown', onKeydown);
        releaseClaim(slot, 'EmbedSlot');
        instances.delete(slot);
    } };
    instances.set(slot, instance);
    return instance;
}

export function initUiKitEmbedSlots(scope = document) {
    return [...scope.querySelectorAll('[data-ui-embed-slot]')].map(slot => initUiKitEmbedSlot(slot)).filter(Boolean);
}

export function initUiKitMediaSlots(scope = document) {
    return [...scope.querySelectorAll('[data-ui-media-slot]')].map(slot => initUiKitMediaSlot(slot)).filter(Boolean);
}

const LIVE_FILTER_DELAY_MS = 350;

/**
 * Filters a list in place: the form's GET request is fetched and only the
 * [data-ui-live-region] blocks are swapped, so the search box keeps focus.
 * Only the latest request may touch the page; anything unexpected falls back
 * to a normal page load of the same URL. Without JavaScript the form submits.
 */
export function initUiKitLiveFilter(form, deps = {}) {
    if (instances.has(form)) return instances.get(form);
    const root = form.closest('.lf-admin-ui');
    if (!root) return null;
    if (!claimOnce(form, 'LiveFilter')) return null;
    const win = deps.window ?? window;
    const delay = deps.delay ?? LIVE_FILTER_DELAY_MS;
    const parse = deps.parse ?? (html => new win.DOMParser().parseFromString(html, 'text/html'));
    const params = deps.params ?? (() => new URLSearchParams(new FormData(form)));
    let seq = 0;
    let timer = null;
    let controller = null;
    let composing = false;
    let lastUrl = null;

    const buildUrl = () => {
        const url = new URL(form.getAttribute('action'), win.location.href);
        url.search = '';
        for (const [key, value] of params()) {
            if (String(value).trim() !== '') url.searchParams.append(key, value);
        }
        return url.toString();
    };
    const navigate = url => win.location.assign(url);
    const cancelTimer = () => {
        if (timer !== null) win.clearTimeout(timer);
        timer = null;
    };
    const run = async () => {
        cancelTimer();
        const mine = ++seq;
        const url = buildUrl();
        lastUrl = url;
        controller?.abort();
        controller = new win.AbortController();
        root.setAttribute('aria-busy', 'true');
        try {
            const response = await win.fetch(url, {
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (mine !== seq) return;
            if (!response.ok || response.redirected) {
                navigate(response.redirected ? response.url : url);
                return;
            }
            const html = await response.text();
            if (mine !== seq) return;
            const doc = parse(html);
            const current = [...root.querySelectorAll('[data-ui-live-region]')];
            const next = current.map(node => doc.querySelector(`[data-ui-live-region="${node.getAttribute('data-ui-live-region')}"]`));
            if (current.length === 0 || next.some(node => !node)) {
                navigate(url);
                return;
            }
            current.forEach((node, index) => node.replaceWith(win.document.importNode(next[index], true)));
            win.history.replaceState(win.history.state, '', url);
        } catch (error) {
            if (mine !== seq) return;
            navigate(url);
        } finally {
            if (mine === seq) root.removeAttribute('aria-busy');
        }
    };
    const schedule = () => {
        if (composing) return;
        cancelTimer();
        timer = win.setTimeout(() => {
            timer = null;
            if (buildUrl() !== lastUrl) run();
        }, delay);
    };
    const onSubmit = event => {
        event.preventDefault();
        run();
    };
    // The search box inside a multi-select panel only narrows that panel's list; it is not a filter.
    const inMultiSelectPanel = target => Boolean(target?.closest?.('[data-ui-multiselect]'));
    const onInput = event => {
        if (event.target?.type !== 'search' || event.isComposing || inMultiSelectPanel(event.target)) return;
        schedule();
    };
    // Choosing a value in a select (native or the UI Kit combobox built from one), or ticking an entry of a
    // multi-select, filters at once.
    const onChange = event => {
        if (event.target?.tagName === 'SELECT' || (event.target?.type === 'checkbox' && inMultiSelectPanel(event.target))) run();
    };
    const onCompositionStart = () => {
        composing = true;
        cancelTimer();
    };
    const onCompositionEnd = event => {
        composing = false;
        if (event.target?.type === 'search' && !inMultiSelectPanel(event.target)) schedule();
    };

    // A dialog form in the same scope saved something: the list may have changed, fetch it again.
    const onSaved = () => { run(); };
    lastUrl = buildUrl();
    root.addEventListener('ui-dialog-form-saved', onSaved);
    form.addEventListener('submit', onSubmit);
    form.addEventListener('input', onInput);
    form.addEventListener('change', onChange);
    form.addEventListener('compositionstart', onCompositionStart);
    form.addEventListener('compositionend', onCompositionEnd);
    const instance = { run, destroy() {
        cancelTimer();
        seq++;
        controller?.abort();
        releaseClaim(form, 'LiveFilter');
        root.removeEventListener('ui-dialog-form-saved', onSaved);
        form.removeEventListener('submit', onSubmit);
        form.removeEventListener('input', onInput);
        form.removeEventListener('change', onChange);
        form.removeEventListener('compositionstart', onCompositionStart);
        form.removeEventListener('compositionend', onCompositionEnd);
        instances.delete(form);
    } };
    instances.set(form, instance);
    return instance;
}

if (typeof document !== 'undefined') {
    const initialize = () => {
        document.querySelectorAll('.lf-admin-ui select[data-ui-kit-select]').forEach(initUiKitSelect);
        document.querySelectorAll('.lf-admin-ui form[data-ui-live-filter]').forEach(form => initUiKitLiveFilter(form));
        initUiKitDateInputs(document);
        initUiKitMediaSlots(document);
        initUiKitEmbedSlots(document);
        initUiKitToasts(document);
        initUiKitToastEvents(document);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}

/**
 * Go where the server says after a save or delete. When the target is the page already open (only the #hash or
 * nothing differs), assigning the URL would not reload it, so reload to show the new data and the flashed message.
 */
export function followRedirect(win, url) {
    let sameDocument = false;
    try {
        const target = new URL(String(url), win.location.href);
        sameDocument = target.origin === win.location.origin && target.pathname === win.location.pathname && target.search === win.location.search;
        win.location.assign(target.href);
    } catch (error) {
        win.location.assign(String(url));
        return;
    }
    if (sameDocument) win.location.reload?.();
}
