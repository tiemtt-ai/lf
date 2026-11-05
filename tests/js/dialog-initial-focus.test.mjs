import test from 'node:test';
import assert from 'node:assert/strict';
import { installDialogInitialFocus } from '../../resources/js/dialog-initial-focus.js';

const button = className => ({
    matches: selector => selector.split(',').includes(`.${className}`),
});

function dialogOpeningOn(first) {
    const doc = { activeElement: null };

    class Dialog {
        style = {};
        contains(node) { return node === first; }
        focus(options) { doc.activeElement = this; this.focusOptions = options; }
        showModal() { doc.activeElement = first; }
    }
    installDialogInitialFocus(Dialog, doc);

    return { dialog: new Dialog(), doc };
}

for (const className of ['ui-modal__close', 'admin-close', 'admin-password-modal-close', 'student-password-close', 'course-enrollment-lifecycle-modal__close']) {
    test(`a popup does not open with focus on its × (${className})`, () => {
        const { dialog, doc } = dialogOpeningOn(button(className));
        dialog.showModal();

        assert.equal(doc.activeElement, dialog);
        assert.equal(dialog.tabIndex, -1);
        assert.equal(dialog.style.outline, 'none');
        assert.deepEqual(dialog.focusOptions, { preventScroll: true });
    });
}

test('a popup keeps focus on anything else it opens on', () => {
    const field = button('ui-input');
    const { dialog, doc } = dialogOpeningOn(field);
    dialog.showModal();

    assert.equal(doc.activeElement, field);
    assert.equal(dialog.tabIndex, undefined);
});

test('pages without dialog support are left alone', () => {
    assert.doesNotThrow(() => installDialogInitialFocus(undefined));
});
