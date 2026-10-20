/**
 * UI Kit multi-select for a filter form: a trigger that summarises the choice, and a panel with a search box and a
 * list of checkboxes (`name` given by data-ms-name). The server renders the list for the current choice of a
 * controlling select (`data-ms-depends-on` = its name); when that select changes the list is rebuilt from
 * `data-ms-options` ({ controllingValue: [{ value, label }] }), the previous choice is dropped, and the field is
 * disabled while the controlling select is empty. Every change of a checkbox bubbles as a normal `change` event,
 * so the live filter reacts as it does to any other field. Without JavaScript the server-rendered list is still
 * a plain group of checkboxes inside the form.
 */
import { claimOnce, releaseClaim } from './admin-ui-kit-controls.js';

const instances = new WeakMap();

export function initUiKitMultiSelect(root, deps = {}) {
    if (instances.has(root)) return instances.get(root);
    if (!claimOnce(root, 'MultiSelect')) return null;
    const doc = deps.document ?? document;
    const trigger = root.querySelector('[data-ms-trigger]');
    const panel = root.querySelector('[data-ms-panel]');
    const search = root.querySelector('[data-ms-search]');
    const list = root.querySelector('[data-ms-list]');
    const summary = root.querySelector('[data-ms-summary]');
    if (!trigger || !panel || !list || !summary) return null;
    const form = root.closest('form');
    const controlling = form?.querySelector(`[name="${root.getAttribute('data-ms-depends-on')}"]`) ?? null;
    const name = root.getAttribute('data-ms-name') || '';
    let optionsByValue = {};
    try { optionsByValue = JSON.parse(root.getAttribute('data-ms-options') || '{}'); } catch (error) { optionsByValue = {}; }
    const text = key => root.getAttribute(`data-text-${key}`) || '';
    const boxes = () => [...list.querySelectorAll('input[type="checkbox"]')];
    let open = false;

    const available = () => controlling === null || String(controlling.value ?? '') !== '';
    const refresh = () => {
        const enabled = available();
        trigger.disabled = !enabled;
        trigger.setAttribute('aria-disabled', String(!enabled));
        const count = boxes().filter(box => box.checked).length;
        summary.textContent = !enabled ? text('pick-first') : count === 0 ? text('all') : text('selected').replace(':count', String(count));
    };
    const setOpen = next => {
        open = next && available();
        panel.hidden = !open;
        trigger.setAttribute('aria-expanded', String(open));
        if (open) search?.focus?.();
    };
    const filter = () => {
        const query = String(search?.value ?? '').toLocaleLowerCase();
        for (const label of list.querySelectorAll('label')) {
            label.hidden = query !== '' && !String(label.textContent).toLocaleLowerCase().includes(query);
        }
    };
    const rebuild = () => {
        const items = optionsByValue[String(controlling?.value ?? '')] ?? [];
        list.replaceChildren(...items.map(item => {
            const label = doc.createElement('label');
            label.className = 'ui-check';
            const box = doc.createElement('input');
            box.type = 'checkbox';
            box.name = name;
            box.value = item.value;
            const labelText = doc.createElement('span');
            labelText.textContent = item.label;
            label.append(box, labelText);
            return label;
        }));
        if (search) search.value = '';
        setOpen(false);
        refresh();
    };

    const onTrigger = () => setOpen(!open);
    const onList = () => refresh();
    const onSearch = () => filter();
    const onKeydown = event => {
        if (event.key === 'Escape' && open) {
            event.stopPropagation?.();
            event.preventDefault?.();
            setOpen(false);
            trigger.focus?.();
        }
    };
    const onOutside = event => { if (open && !root.contains(event.target)) setOpen(false); };
    const onControlling = () => rebuild();

    trigger.addEventListener('click', onTrigger);
    list.addEventListener('change', onList);
    search?.addEventListener('input', onSearch);
    root.addEventListener('keydown', onKeydown);
    doc.addEventListener('click', onOutside);
    controlling?.addEventListener('change', onControlling);
    refresh();

    const instance = { destroy() {
        trigger.removeEventListener('click', onTrigger);
        list.removeEventListener('change', onList);
        search?.removeEventListener('input', onSearch);
        root.removeEventListener('keydown', onKeydown);
        doc.removeEventListener('click', onOutside);
        controlling?.removeEventListener('change', onControlling);
        releaseClaim(root, 'MultiSelect');
        instances.delete(root);
    } };
    instances.set(root, instance);
    return instance;
}

if (typeof document !== 'undefined') {
    const initialize = () => document.querySelectorAll('[data-ui-multiselect]').forEach(root => initUiKitMultiSelect(root));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}
