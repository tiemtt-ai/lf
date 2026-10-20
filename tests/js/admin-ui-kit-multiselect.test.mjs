import test from 'node:test';
import assert from 'node:assert/strict';
import { initUiKitMultiSelect } from '../../resources/js/admin-ui-kit-multiselect.js';

// Invariants:
// 1. The summary says: pick the controlling choice first (disabled), all (nothing checked) or "N selected".
// 2. Changing the controlling select drops the previous choice, rebuilds the list for the new value and closes the panel.
// 3. The panel opens only when the field is available; Escape and a click outside close it.
// 4. The search hides non-matching entries without removing them (their checked state survives).

class El {
    constructor(tag = 'div') {
        this.tag = tag; this.attrs = new Map(); this.listeners = new Map(); this.children = [];
        this.hidden = false; this.disabled = false; this.checked = false; this.value = ''; this.textContent = ''; this.className = '';
        this.focused = 0;
    }
    getAttribute(n) { return this.attrs.has(n) ? this.attrs.get(n) : null; }
    setAttribute(n, v) { this.attrs.set(n, String(v)); }
    addEventListener(t, f) { this.listeners.set(t, f); }
    removeEventListener(t) { this.listeners.delete(t); }
    focus() { this.focused += 1; }
    append(...nodes) { this.children.push(...nodes); nodes.forEach(n => { n.parent = this; }); }
    replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
    contains(node) { return node === this || this.children.some(c => c.contains?.(node)); }
    querySelectorAll(selector) {
        const out = [];
        const walk = node => node.children.forEach(child => {
            if ((selector === 'input[type="checkbox"]' && child.tag === 'input') || (selector === 'label' && child.tag === 'label')) out.push(child);
            walk(child);
        });
        walk(this);
        return out;
    }
}

function setup({ ownerValue = '', selected = [] } = {}) {
    const options = JSON.stringify({ template: [{ value: 'template:1', label: 'Alpha' }, { value: 'template:2', label: 'Beta' }], activity: [{ value: 'activity:9', label: 'Gamma' }] });
    const owner = new El('select'); owner.value = ownerValue;
    const root = new El(); const trigger = new El('button'); const panel = new El(); panel.hidden = true; const search = new El('input'); const list = new El(); const summary = new El('span');
    root.attrs = new Map(Object.entries({ 'data-ms-depends-on': 'owner_type', 'data-ms-name': 'owner_refs[]', 'data-ms-options': options, 'data-text-pick-first': 'PICK', 'data-text-all': 'ALL', 'data-text-selected': 'SEL :count' }));
    const make = (value, label) => { const l = new El('label'); l.textContent = label; const b = new El('input'); b.value = value; b.checked = selected.includes(value); l.append(b); return l; };
    if (ownerValue === 'template') list.append(make('template:1', 'Alpha'), make('template:2', 'Beta'));
    root.append(trigger, panel); panel.append(search, list); trigger.append(summary);
    root.querySelector = s => ({ '[data-ms-trigger]': trigger, '[data-ms-panel]': panel, '[data-ms-search]': search, '[data-ms-list]': list, '[data-ms-summary]': summary }[s] ?? null);
    root.closest = () => ({ querySelector: () => owner });
    const doc = { createElement: tag => new El(tag), listeners: new Map(), addEventListener(t, f) { this.listeners.set(t, f); }, removeEventListener(t) { this.listeners.delete(t); } };
    const instance = initUiKitMultiSelect(root, { document: doc });
    return { root, owner, trigger, panel, search, list, summary, doc, instance };
}

test('summary: pick the controlling value first, all, or N selected', () => {
    const none = setup();
    assert.equal(none.summary.textContent, 'PICK');
    assert.equal(none.trigger.disabled, true);
    const all = setup({ ownerValue: 'template' });
    assert.equal(all.summary.textContent, 'ALL');
    assert.equal(all.trigger.disabled, false);
    const some = setup({ ownerValue: 'template', selected: ['template:1', 'template:2'] });
    assert.equal(some.summary.textContent, 'SEL 2');
});

test('the controlling change rebuilds the list for the new value, drops the old choice and closes the panel', () => {
    const t = setup({ ownerValue: 'template', selected: ['template:1'] });
    t.trigger.listeners.get('click')();
    assert.equal(t.panel.hidden, false);
    t.owner.value = 'activity';
    t.owner.listeners.get('change')();
    const boxes = t.list.querySelectorAll('input[type="checkbox"]');
    assert.deepEqual(boxes.map(b => [b.name, b.value, b.checked]), [['owner_refs[]', 'activity:9', false]]);
    assert.equal(t.panel.hidden, true);
    assert.equal(t.summary.textContent, 'ALL');
    t.owner.value = '';
    t.owner.listeners.get('change')();
    assert.equal(t.list.children.length, 0);
    assert.equal(t.trigger.disabled, true);
    assert.equal(t.summary.textContent, 'PICK');
});

test('opens only when available; Escape and an outside click close it', () => {
    const off = setup();
    off.trigger.listeners.get('click')();
    assert.equal(off.panel.hidden, true, 'a disabled field does not open');
    const t = setup({ ownerValue: 'template' });
    t.trigger.listeners.get('click')();
    assert.equal(t.panel.hidden, false);
    assert.equal(t.search.focused, 1);
    t.root.listeners.get('keydown')({ key: 'Escape' });
    assert.equal(t.panel.hidden, true);
    assert.equal(t.trigger.focused, 1);
    t.trigger.listeners.get('click')();
    t.doc.listeners.get('click')({ target: new El() });
    assert.equal(t.panel.hidden, true);
    t.trigger.listeners.get('click')();
    t.doc.listeners.get('click')({ target: t.search });
    assert.equal(t.panel.hidden, false, 'a click inside keeps it open');
});

test('search hides non-matching entries but keeps them (and their checked state); a checkbox change updates the summary', () => {
    const t = setup({ ownerValue: 'template', selected: ['template:2'] });
    t.search.value = 'alp';
    t.search.listeners.get('input')();
    assert.deepEqual(t.list.querySelectorAll('label').map(l => l.hidden), [false, true]);
    assert.equal(t.list.querySelectorAll('input[type="checkbox"]')[1].checked, true);
    t.search.value = '';
    t.search.listeners.get('input')();
    assert.deepEqual(t.list.querySelectorAll('label').map(l => l.hidden), [false, false]);
    t.list.querySelectorAll('input[type="checkbox"]')[0].checked = true;
    t.list.listeners.get('change')();
    assert.equal(t.summary.textContent, 'SEL 2');
});

test('destroy removes every listener', () => {
    const t = setup({ ownerValue: 'template' });
    t.instance.destroy();
    assert.equal(t.trigger.listeners.size + t.owner.listeners.size + t.doc.listeners.size + t.root.listeners.size + t.list.listeners.size, 0);
});
