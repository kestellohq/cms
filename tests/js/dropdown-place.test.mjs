import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dropdownPlace } from '../../core/resources/js/ui/dropdown.js';

const view = { width: 1200, height: 800 };
const box = (top, height = 30) => ({ top, bottom: top + height, left: 100, width: 300 });

test('it opens under its box, as wide as the box', () => {
    assert.deepEqual(dropdownPlace(box(100), view), { top: 134, left: 100, width: 300, maxHeight: 280, up: false });
});

test('near the bottom of the screen it opens upwards when there is more room there', () => {
    const at = dropdownPlace(box(700), view);
    assert.equal(at.up, true);
    assert.equal(at.bottom, 104, 'it sits just above the box');
    assert.equal(at.maxHeight, 280);
    assert.equal('top' in at, false);
});

test('it is never taller than the room it has', () => {
    assert.equal(dropdownPlace(box(560), view).maxHeight, 198, 'under the box, 210 px less a gap and a margin');
    assert.equal(dropdownPlace(box(300, 30), { width: 1200, height: 500 }).maxHeight, 280, 'above, where 288 px are free, all of it');
    assert.equal(dropdownPlace(box(150, 30), { width: 1200, height: 300 }).maxHeight, 138, 'above, where only 138 px are free');
    assert.equal(dropdownPlace(box(790), view).maxHeight >= 0, true);
});

test('a short list keeps opening downwards while it fits', () => {
    assert.equal(dropdownPlace(box(560), view, { maxHeight: 120 }).up, false);
});
