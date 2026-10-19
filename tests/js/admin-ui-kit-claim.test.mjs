import test from 'node:test';
import assert from 'node:assert/strict';
import { claimOnce, releaseClaim, initUiKitMediaSlot, initUiKitToasts } from '../../resources/js/admin-ui-kit-controls.js';

// A second copy of a module (Vite dev can serve one with ?t=) must not initialise an element that is already set up:
// the guard lives on the element, not in module state.

test('claimOnce hands out one claim per element and name; release gives it back', () => {
    const node = { dataset: {} };
    assert.equal(claimOnce(node, 'Select'), true);
    assert.equal(claimOnce(node, 'Select'), false);
    assert.equal(claimOnce(node, 'LiveFilter'), true, 'other adapters are independent');
    releaseClaim(node, 'Select');
    assert.equal(claimOnce(node, 'Select'), true);
});

test('elements without a dataset are never blocked (test fakes, detached nodes)', () => {
    assert.equal(claimOnce({}, 'Select'), true);
    assert.equal(claimOnce({}, 'Select'), true);
});

test('an element already claimed by another copy is skipped by the initialisers', () => {
    const closeListeners = [];
    const node = { dataset: { uiKitReadyToast: '1' }, hidden: true, querySelector: () => ({ addEventListener: (...a) => closeListeners.push(a) }) };
    initUiKitToasts({ querySelectorAll: () => [node] });
    assert.equal(closeListeners.length, 0, 'no second set of listeners');

    const slot = { dataset: { uiKitReadyMediaSlot: '1' }, querySelector: () => ({}) };
    assert.equal(initUiKitMediaSlot(slot), null);
});
