import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHistory } from '../../core/resources/js/ui/builder/history.js';

test('undo and redo walk the snapshots', () => {
    const h = createHistory();
    h.reset([]);
    h.record([1]);
    h.record([1, 2]);
    assert.deepEqual(h.undo(), [1]);
    assert.deepEqual(h.undo(), []);
    assert.equal(h.undo(), null);
    assert.deepEqual(h.redo(), [1]);
    h.record([9]);
    assert.equal(h.canRedo(), false, 'a new change drops what was undone');
});

test('changes in one group close together are one step', () => {
    let t = 0;
    const h = createHistory(undefined, () => t);
    h.reset(['']);
    h.record(['a'], 'props:x');
    t += 300;
    h.record(['ab'], 'props:x');
    t += 300;
    h.record(['abc'], 'props:x');
    assert.deepEqual(h.undo(), ['']);
    h.redo();
    t += 5000;
    h.record(['abcd'], 'props:x');
    assert.deepEqual(h.undo(), ['abc'], 'after a pause, a new step');
});

test('isCurrent tells our tree from one replaced outside', () => {
    const h = createHistory();
    h.reset([{ a: 1 }]);
    assert.equal(h.isCurrent([{ a: 1 }]), true);
    assert.equal(h.isCurrent([{ a: 2 }]), false);
});

test('undoing a step brings back what was selected before it', () => {
    const h = createHistory();
    h.reset(['heading']);
    h.record(['heading', 'copy'], '', 'heading'); // duplicated while the heading was selected
    h.record(['heading', 'copy', 'text'], 'props:x', 'copy');
    assert.deepEqual(h.undoStep(), { tree: ['heading', 'copy'], selected: 'copy' });
    assert.deepEqual(h.undoStep(), { tree: ['heading'], selected: 'heading' });
    assert.equal(h.undoStep(), null);
    h.record(['grouped'], 'props:y', 'heading');
    h.record(['grouped!'], 'props:y', 'other');
    assert.deepEqual(h.undoStep(), { tree: ['heading'], selected: 'heading' }, 'a grouped step keeps the selection from its start');
});
