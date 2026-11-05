/**
 * Order of one group on the template content tab (the lessons of a group, the activities of a lesson, the sections under
 * one parent): `[data-reorder-url]` holds rows `[data-order-id]` and names the ids field in `data-reorder-field`.
 * A row moves by dragging its handle (x-sort on the list calls dropped()) or with the arrow keys on the handle (move()).
 * The rows move at once and the whole group is then saved as the complete list of ids.
 *
 * Invariants (tests/js/course-template-order.test.mjs):
 *  1. One request at a time. Moves made while one is on its way are not lost: the latest order is sent when it returns.
 *  2. A refused or failed save puts the rows back in the last order the server accepted, and says why.
 *     Moves made while that request was on its way go too: they were built on the refused order.
 *  3. The numbers on the rows, and the section outline, always follow the rows.
 *  4. A move changes the page of a paged list only when the moved row leaves it; the handle keeps the focus.
 *  5. Nothing is sent when the order did not change.
 *  6. The arrow keys pass over rows hidden by a phase tab: one press always moves the row past one row the user can see.
 *  7. A row released outside its own list, on any side, goes back to where the drag began, says why, and saves nothing:
 *     a row never changes group, and the position it last passed on the way out is not a choice the user made.
 *  8. A drag called off over the list (Esc, or a touch the system took back) goes back the same way, saves nothing
 *     and says nothing: the user asked for exactly that.
 *  9. A list marked `data-reorder-reload` shows what the server derives from the order (the class's dates): the page
 *     reloads once the last move is saved.
 */
import { showUiToast, hideUiToast } from './admin-ui-kit-controls.js';

const orders = new WeakMap();

// The gap between a list and what follows it still counts as the list: a drop just past the last row means the last place.
const RELEASE_TOLERANCE = 12;

export function courseTemplateOrder(list, deps = {}) {
    if (orders.has(list)) return orders.get(list);

    const win = deps.window ?? window;
    const scope = list.closest('[data-csrf]');
    const createEvent = deps.createEvent ?? ((type, detail) => new CustomEvent(type, { bubbles: true, detail }));
    const rows = () => [...list.children].filter(node => node.hasAttribute('data-order-id'));
    const ids = () => rows().map(node => Number(node.getAttribute('data-order-id')));
    const sameOrder = (a, b) => a.length === b.length && a.every((id, index) => id === b[index]);
    const toast = kind => scope.querySelector(`[data-ui-dialog-form-${kind}]`);

    let confirmed = ids();
    let saving = false;
    let beforeDrag = null;

    const renumber = () => {
        const selector = list.getAttribute('data-order-number');
        if (!selector) return;
        rows().forEach((row, index) => {
            row.querySelector(selector).textContent = String(index + 1).padStart(2, '0');
        });
    };
    const changed = (item = null) => {
        renumber();
        list.dispatchEvent(createEvent('course-template-order-changed', { item }));
    };

    function arrange(order) {
        const active = win.document.activeElement;
        const byId = new Map(rows().map(row => [Number(row.getAttribute('data-order-id')), row]));
        order.forEach(id => list.appendChild(byId.get(id)));
        changed();
        if (active && list.contains(active)) active.focus();
    }

    function restore() {
        arrange(confirmed);
        // A refusal that lands during a drag also takes away the order that drag began from.
        if (beforeDrag) beforeDrag = confirmed;
    }

    function howReleased(event) {
        const point = event.changedTouches?.[0] ?? event;
        const { top, bottom, left, right } = list.getBoundingClientRect();
        const beyond = margin => point.clientY < top - margin || point.clientY > bottom + margin
            || point.clientX < left - margin || point.clientX > right + margin;
        if (beyond(RELEASE_TOLERANCE)) return 'outside';
        // A real release over the list is a `drop`, which ends the drag before `dragend` can. In the gap around the list
        // no `drop` comes, so only a `dragend` over the list itself means the drag was called off.
        if (event.type === 'touchcancel' || (event.type === 'dragend' && !beyond(0))) return 'cancelled';
        return 'dropped';
    }

    function refuse(message) {
        restore();
        hideUiToast(toast('flash'), win);
        showUiToast(toast('page-error'), message, win);
    }

    async function save() {
        if (saving) return;
        const order = ids();
        if (sameOrder(order, confirmed)) return;

        saving = true;
        let response;
        let payload = null;
        try {
            response = await win.fetch(list.getAttribute('data-reorder-url'), {
                method: 'PATCH',
                body: JSON.stringify({ [list.getAttribute('data-reorder-field')]: order }),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': scope.getAttribute('data-csrf'),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            payload = await response.json().catch(() => null);
        } catch (error) {
            saving = false;
            refuse(list.getAttribute('data-msg-error'));
            return;
        }
        saving = false;

        if (!response.ok) {
            const expired = response.status === 419 || response.status === 401;
            const explained = response.status === 409 || response.status === 422;
            refuse(expired
                ? scope.getAttribute('data-msg-session-expired')
                : (explained && payload?.message) || list.getAttribute('data-msg-error'));
            return;
        }

        confirmed = order;
        if (!sameOrder(ids(), confirmed)) {
            save();
            return;
        }
        if (list.hasAttribute('data-reorder-reload')) {
            win.location.reload();
            return;
        }
        hideUiToast(toast('page-error'), win);
        showUiToast(toast('flash'), list.getAttribute('data-msg-saved'), win);
    }

    const instance = {
        // Options for x-sort:config. Sortable calls onUnchoose before it reports the sort, so dropped() then finds nothing to do.
        sortable: {
            onChoose() {
                beforeDrag = ids();
            },
            onUnchoose(event) {
                const order = beforeDrag;
                beforeDrag = null;
                if (!order) return;
                const release = howReleased(event.originalEvent);
                if (release === 'dropped') return;
                arrange(order);
                if (release === 'cancelled') return;
                hideUiToast(toast('flash'), win);
                showUiToast(toast('page-error'), list.getAttribute('data-msg-outside'), win);
            },
        },
        dropped() {
            changed();
            return save();
        },
        move(handle, step) {
            const visible = rows().filter(row => row.style?.display !== 'none');
            const item = handle.closest('[data-order-id]');
            const target = visible[visible.indexOf(item) + step];
            if (!target) return undefined;
            list.insertBefore(item, step < 0 ? target : target.nextSibling);
            changed(item);
            // Moving a node drops its focus.
            handle.focus();
            handle.scrollIntoView?.({ block: 'nearest' });
            return save();
        },
    };
    orders.set(list, instance);

    return instance;
}

/**
 * The section outline after sections moved: the numbers (1, 1.2, 1.2.1) of every section under `root`, and the table of
 * contents, which lists all the sections flat and depth first. Sibling sections carry their branch with them.
 */
export function courseTemplateRefreshOutline(root, toc) {
    const sections = [];
    const walk = (container, prefix) => {
        [...container.children]
            .filter(node => node.hasAttribute('data-section-id'))
            .forEach((section, index) => {
                const number = `${prefix}${index + 1}`;
                section.querySelector(':scope > header > .course-template-outline-section-number').textContent = number;
                sections.push({ id: section.getAttribute('data-section-id'), number });
                const children = section.querySelector(':scope > .course-template-section-branch > .course-template-outline-children');
                if (children) walk(children, `${number}.`);
            });
    };
    walk(root, '');

    const list = toc?.querySelector('ol');
    if (!list) return;
    const entries = new Map([...list.children].map(entry => [entry.getAttribute('data-toc-section-id'), entry]));
    sections.forEach(({ id, number }) => {
        const entry = entries.get(id);
        entry.querySelector('.course-template-content-toc-number').textContent = number;
        list.appendChild(entry);
    });
}
