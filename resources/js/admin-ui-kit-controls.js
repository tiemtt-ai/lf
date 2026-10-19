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

if (typeof document !== 'undefined') {
    const initialize = () => document.querySelectorAll('.lf-admin-ui select[data-ui-kit-select]').forEach(initUiKitSelect);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}
