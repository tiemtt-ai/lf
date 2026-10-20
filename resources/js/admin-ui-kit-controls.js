/** Opt-in adapters for real native selects. All presentation is shared CSS. */
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

export function initUiKitSelect(select) {
    if (instances.has(select)) return instances.get(select);
    if (select.multiple) return null;
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
    function render() {
        const query = searching ? input.value.toLocaleLowerCase() : '';
        matches = [...select.options].filter(option => !option.disabled && option.textContent.toLocaleLowerCase().includes(query));
        clear.hidden = select.required || select.value === '' || ![...select.options].some(option => option.value === '');
        list.replaceChildren();
        for (const [index, option] of matches.entries()) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'ux-option cursor-interaction';
            button.id = `${select.id}-ui-kit-option-${index}`;
            button.tabIndex = -1;
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', String(select.value === option.value));
            button.textContent = option.textContent;
            if (select.value === option.value) {
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
    function close() {
        list.hidden = true;
        searching = false;
        active = -1;
        input.value = select.value === '' ? '' : selectedLabel();
        input.placeholder = selectedLabel();
        input.removeAttribute('aria-activedescendant');
        input.setAttribute('aria-expanded', 'false');
    }
    function open() {
        if (select.disabled) return;
        render();
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }
    function choose(option) {
        if (!option) return;
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
    const onOutside = event => { if (!wrapper.contains(event.target)) close(); };
    const onBlur = event => { if (!wrapper.contains(event.relatedTarget)) close(); };
    const onClear = () => choose([...select.options].find(option => option.value === ''));
    const onChange = () => { close(); render(); };
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

export const UI_MEDIA_PREVIEW_EVENT = 'ui-media-preview';

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
    const onRemove = () => {
        if (pending) {
            discardPending();
            input.value = '';
            // Anything that mirrors "a file is chosen" (e.g. a form's own state) hears that it is not any more.
            input.dispatchEvent?.(new Event('change', { bubbles: true }));
        } else if (saved && !removed && flag) {
            removed = true;
            flag.value = '1';
        } else {
            return;
        }
        announce(removedText);
        render();
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
    syncFromInput();
    render();
    const instance = { destroy() {
        discardPending();
        input.removeEventListener('change', onChange);
        removeButton.removeEventListener('click', onRemove);
        viewButton.removeEventListener('click', onView);
        image.removeEventListener('error', onImageError);
        form?.removeEventListener('reset', onReset);
        releaseClaim(slot, 'MediaSlot');
        instances.delete(slot);
    } };
    instances.set(slot, instance);
    return instance;
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
    const onInput = event => {
        if (event.target?.type !== 'search' || event.isComposing) return;
        schedule();
    };
    // Choosing a value in a select (native or the UI Kit combobox built from one) filters at once.
    const onChange = event => {
        if (event.target?.tagName === 'SELECT') run();
    };
    const onCompositionStart = () => {
        composing = true;
        cancelTimer();
    };
    const onCompositionEnd = event => {
        composing = false;
        if (event.target?.type === 'search') schedule();
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
        initUiKitMediaSlots(document);
        initUiKitToasts(document);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}
