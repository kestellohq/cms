import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createUndoNotice } from '../../core/resources/js/ui/builder/undo-notice.js';

function setup() {
    const shown = [];
    const dismissed = [];
    let undone = 0;
    const notice = createUndoNotice({
        show: (message, action) => {
            shown.push({ message, action });
            return shown.length;
        },
        dismiss: (id) => dismissed.push(id),
        label: 'Undo',
    });
    return {
        notice,
        shown,
        dismissed,
        undo: () => {
            undone++;
        },
        undone: () => undone,
    };
}

test('offers Undo for the step, and choosing it undoes once', () => {
    const s = setup();
    s.notice.offer('3 blocks removed.', 7, s.undo);
    assert.equal(s.shown[0].message, '3 blocks removed.');
    assert.equal(s.shown[0].action.label, 'Undo');
    s.shown[0].action.run();
    assert.equal(s.undone(), 1);
    s.shown[0].action.run();
    assert.equal(s.undone(), 1, 'a second click does nothing');
    assert.equal(s.notice.open, false);
});

test('any later change takes the offer away, so Undo never undoes something else', () => {
    const s = setup();
    s.notice.offer('Heading removed.', 4, s.undo);
    s.notice.changed(4);
    assert.equal(s.notice.open, true, 'the same version: still offered');
    s.notice.changed(5);
    assert.deepEqual(s.dismissed, [1]);
    s.shown[0].action.run();
    assert.equal(s.undone(), 0, 'a stale toast does nothing');
});

test('a new offer replaces the old one', () => {
    const s = setup();
    s.notice.offer('Heading removed.', 4, s.undo);
    s.notice.offer('Text removed.', 5, s.undo);
    assert.deepEqual(s.dismissed, [1]);
    s.shown[0].action.run();
    assert.equal(s.undone(), 0, 'the first is gone');
    s.shown[1].action.run();
    assert.equal(s.undone(), 1);
});

test('clear takes it away (leaving the editor)', () => {
    const s = setup();
    s.notice.offer('Home page added: 29 blocks.', 2, s.undo);
    s.notice.clear();
    assert.deepEqual(s.dismissed, [1]);
    assert.equal(s.notice.open, false);
});
