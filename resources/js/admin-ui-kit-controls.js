/** Opt-in adapters for real native selects. All presentation is shared CSS. */
const instances = new WeakMap();

export function initUiKitSelect(select) {
    if (instances.has(select)) return instances.get(select);
    if (select.multiple) return null;
    const root = select.closest('.lf-admin-ui');
    if (!root) return null;
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
        instances.delete(select);
    } };
    instances.set(select, instance);
    return instance;
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
    const onCompositionStart = () => {
        composing = true;
        cancelTimer();
    };
    const onCompositionEnd = event => {
        composing = false;
        if (event.target?.type === 'search') schedule();
    };

    lastUrl = buildUrl();
    form.addEventListener('submit', onSubmit);
    form.addEventListener('input', onInput);
    form.addEventListener('compositionstart', onCompositionStart);
    form.addEventListener('compositionend', onCompositionEnd);
    const instance = { run, destroy() {
        cancelTimer();
        seq++;
        controller?.abort();
        form.removeEventListener('submit', onSubmit);
        form.removeEventListener('input', onInput);
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
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}
