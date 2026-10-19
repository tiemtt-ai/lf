import test from 'node:test';
import assert from 'node:assert/strict';
import { initUiKitDialogs } from '../../resources/js/admin-ui-kit-dialog.js';

class Element extends EventTarget {
    constructor(kind, id = '') {
        super(); this.kind = kind; this.id = id; this.children = [];
        this.dataset = {}; this.attributes = {}; this.isConnected = true;
    }
    append(child) { child.parent = this; this.children.push(child); return child; }
    contains(node) { return node === this || this.children.some(child => child.contains(node)); }
    querySelectorAll(selector) {
        const all = this.children.flatMap(child => [child, ...child.querySelectorAll('*')]);
        if (selector === '*') return all;
        if (selector === 'dialog[data-ui-dialog]') return all.filter(e => e.kind === 'dialog');
        if (selector === '[data-ui-dialog-open]') return all.filter(e => e.kind === 'opener');
        return [];
    }
    closest(selector) {
        const match = (selector === '[data-ui-dialog-open]' && this.kind === 'opener')
            || (selector === '[data-ui-dialog-close]' && this.kind === 'close')
            || (selector === 'dialog[data-ui-dialog]' && this.kind === 'dialog');
        return match ? this : this.parent?.closest(selector);
    }
    setAttribute(key, value) { this.attributes[key] = value; }
    focus() { this.focused = true; }
    showModal() { this.open = true; this.showCount = (this.showCount ?? 0) + 1; }
    close() { this.open = false; this.dispatchEvent(new Event('close')); }
}
function fixture() {
    const scope = new Element('scope');
    const dialog = scope.append(new Element('dialog', 'sample'));
    const trigger = scope.append(new Element('opener')); trigger.dataset.uiDialogOpen = 'sample';
    const close = dialog.append(new Element('close'));
    return { scope, dialog, trigger, close };
}
function click(scope, target) {
    const event = new Event('click'); Object.defineProperty(event, 'target', { value: target });
    scope.dispatchEvent(event);
}

test('dialog adapter is idempotent and restores opener after explicit close', () => {
    const f = fixture(); const instance = initUiKitDialogs(f.scope);
    assert.equal(initUiKitDialogs(f.scope), instance);
    click(f.scope, f.trigger); click(f.scope, f.trigger);
    assert.equal(f.dialog.showCount, 1); assert.equal(f.trigger.attributes['aria-expanded'], 'true');
    click(f.scope, f.close);
    assert.equal(f.dialog.open, false); assert.equal(f.trigger.focused, true);
    assert.equal(f.trigger.attributes['aria-expanded'], 'false');
    instance.destroy();
});

test('openers cannot open dialogs in a different scope', () => {
    const a = fixture(), b = fixture(); a.trigger.dataset.uiDialogOpen = 'foreign'; b.dialog.id = 'foreign';
    initUiKitDialogs(a.scope); initUiKitDialogs(b.scope);
    click(a.scope, a.trigger);
    assert.equal(b.dialog.open, undefined); assert.equal(a.dialog.open, undefined);
});

test('delegation supports remounted dialogs and disconnected opener cleanup', () => {
    const f = fixture(); const instance = initUiKitDialogs(f.scope);
    const late = f.scope.append(new Element('dialog', 'late'));
    f.trigger.dataset.uiDialogOpen = 'late'; click(f.scope, f.trigger);
    assert.equal(late.open, true);
    f.trigger.isConnected = false; late.close();
    assert.equal(f.trigger.focused, undefined);
    instance.destroy();
});

test('destroy removes delegates and allows a clean reinitialization', () => {
    const f = fixture(); const first = initUiKitDialogs(f.scope);
    click(f.scope, f.trigger); first.destroy();
    assert.equal(f.dialog.open, false);
    click(f.scope, f.trigger); assert.equal(f.dialog.open, false);
    const next = initUiKitDialogs(f.scope); assert.notEqual(first, next);
    click(f.scope, f.trigger); assert.equal(f.dialog.open, true); next.destroy();
});

test('a late close event cannot reset a reopened dialog trigger', () => {
    const f = fixture(); const instance = initUiKitDialogs(f.scope);
    click(f.scope, f.trigger); f.dialog.dispatchEvent(new Event('close'));
    assert.equal(f.trigger.attributes['aria-expanded'], 'true');
    instance.destroy();
});
