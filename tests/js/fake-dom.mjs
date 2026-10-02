/*
 * A very small DOM, only as much as resources/js/ai-authoring.js touches, so the
 * script's behaviour (not just its source text) can be tested in plain Node.
 * It is not a browser: layout, focus rules and BFCache are out of its reach.
 */
import { webcrypto } from 'node:crypto';

export class FakeNode {
    constructor(tag, nodeType = 1) {
        this.tag = tag;
        this.nodeType = nodeType;
        this.children = [];
        this.parent = null;
        this.attrs = new Map();
        this.listeners = new Map();
        this.className = '';
        this.disabled = false;
        this.checked = false;
        this.value = '';
        this._text = '';
    }

    get hidden() {
        return this.attrs.has('hidden');
    }

    set hidden(on) {
        if (on) {
            this.attrs.set('hidden', '');
        } else {
            this.attrs.delete('hidden');
        }
    }

    // The script must never turn text into markup. A real browser would parse it; this fake refuses,
    // so any route to these members, spelled however it is spelled, fails the test that runs it.
    get innerHTML() {
        throw new Error('HTML sink used: innerHTML');
    }

    set innerHTML(value) {
        throw new Error('HTML sink used: innerHTML');
    }

    get outerHTML() {
        throw new Error('HTML sink used: outerHTML');
    }

    set outerHTML(value) {
        throw new Error('HTML sink used: outerHTML');
    }

    insertAdjacentHTML() {
        throw new Error('HTML sink used: insertAdjacentHTML');
    }

    get childElementCount() {
        return this.children.filter((child) => child.nodeType === 1).length;
    }

    get childNodes() {
        return this.children;
    }

    get textContent() {
        return this.nodeType === 3 ? this._text : `${this._text}${this.children.map((child) => child.textContent).join('')}`;
    }

    set textContent(text) {
        this.children.forEach((child) => { child.parent = null; });
        this.children = [];
        this._text = String(text);
    }

    setAttribute(name, value) {
        this.attrs.set(name, String(value));
    }

    getAttribute(name) {
        return this.attrs.has(name) ? this.attrs.get(name) : null;
    }

    hasAttribute(name) {
        return this.attrs.has(name);
    }

    removeAttribute(name) {
        this.attrs.delete(name);
    }

    append(...nodes) {
        for (const node of nodes) {
            const child = typeof node === 'string' ? Object.assign(new FakeNode('#text', 3), { _text: node }) : node;
            child.parent = this;
            this.children.push(child);
        }
    }

    replaceChildren(...nodes) {
        this.children.forEach((child) => { child.parent = null; });
        this.children = [];
        this._text = '';
        this.append(...nodes);
    }

    addEventListener(type, listener) {
        this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]);
    }

    async dispatch(type, event = {}) {
        for (const listener of this.listeners.get(type) ?? []) {
            await listener({ type, target: this, ...event });
        }
    }

    focus() {
        document.activeElement = this;
    }

    contains(node) {
        for (let current = node; current; current = current.parent) {
            if (current === this) {
                return true;
            }
        }

        return false;
    }

    all() {
        return this.children.flatMap((child) => [child, ...child.all()]);
    }

    querySelectorAll(selector) {
        const parts = selector.split(',').map((part) => part.trim());

        return this.all().filter((node) => node.nodeType === 1 && parts.some((part) => matches(node, part)));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }
}

function matches(node, selector) {
    if (selector === '*') {
        return true;
    }
    const notDisabled = selector.includes(':not(:disabled)');
    const parsed = selector.replace(':not(:disabled)', '').match(/^([a-z0-9]*)(?:\[([a-z-]+)\])?$/i);
    if (!parsed) {
        throw new Error(`Unsupported selector in the fake DOM: ${selector}`);
    }
    const [, tag, attribute] = parsed;

    return (!tag || node.tag === tag) && (!attribute || node.hasAttribute(attribute)) && !(notDisabled && node.disabled);
}

/** Everything a person could read or copy out of a subtree: its text and the values typed into it. */
export function everything(node) {
    return [node.textContent, ...node.all().filter((item) => item.value).map((item) => item.value)].join('\n');
}

export function install() {
    const windowListeners = new Map();
    globalThis.Node = FakeNode;
    globalThis.document = {
        readyState: 'loading', // so the module does not start itself on import
        activeElement: null,
        createElement: (tag) => new FakeNode(tag),
        createTextNode: (text) => Object.assign(new FakeNode('#text', 3), { _text: String(text) }),
        addEventListener() {},
        contains: (node) => Boolean(node) && (node === globalThis.document.body || globalThis.document.body.contains(node)),
        body: new FakeNode('body'),
    };
    globalThis.window = {
        LFConfirm: { answer: true, calls: 0, last: null, async open(options) { this.calls += 1; this.last = options; return this.answer; } },
        addEventListener(type, listener) {
            windowListeners.set(type, [...(windowListeners.get(type) ?? []), listener]);
        },
        reset() {
            windowListeners.clear();
        },
        async fire(type, event = {}) {
            // The caller's own object is the event, so it can read what the handlers set on it.
            Object.assign(event, { type, preventDefault() { event.prevented = true; } });
            for (const listener of windowListeners.get(type) ?? []) {
                await listener(event);
            }
        },
    };
    if (!globalThis.crypto) {
        Object.defineProperty(globalThis, 'crypto', { value: webcrypto });
    }
}
