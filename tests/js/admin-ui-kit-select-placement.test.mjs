import test from 'node:test';
import assert from 'node:assert/strict';
import { choosePlacement } from '../../resources/js/admin-ui-kit-controls.js';

// Invariant: an open select list is never placed where it would be cut while the other side has more room.

test('the list opens below when it fits there', () => {
    assert.equal(choosePlacement({ spaceBelow: 300, spaceAbove: 600, listHeight: 220 }), 'bottom');
    assert.equal(choosePlacement({ spaceBelow: 220, spaceAbove: 0, listHeight: 220 }), 'bottom');
});

test('the list opens above when it does not fit below and there is more room above', () => {
    assert.equal(choosePlacement({ spaceBelow: 90, spaceAbove: 500, listHeight: 160 }), 'top');
});

test('the list stays below when neither side fits and below is the larger side', () => {
    assert.equal(choosePlacement({ spaceBelow: 120, spaceAbove: 80, listHeight: 220 }), 'bottom');
});
