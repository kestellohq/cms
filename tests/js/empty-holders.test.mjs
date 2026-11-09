import { test } from 'node:test';
import assert from 'node:assert/strict';
import { emptyHolderRule } from '../../core/resources/js/app/canvas/overlay.js';

test('empty blocks that hold others get room and the hint', () => {
    const css = emptyHolderRule(['core/column', 'core/group'], 'Empty. Click to select it, then add a block.');
    assert.match(css, /\[data-block="core\/column"\]:empty, \[data-block="core\/group"\]:empty/);
    assert.match(css, /min-height: 4\.5rem/);
    assert.match(css, /content: "Empty\. Click to select it, then add a block\."/);
});

test('nothing to hold: no rule', () => {
    assert.equal(emptyHolderRule([], 'x'), '');
    assert.equal(emptyHolderRule(undefined, 'x'), '');
});

test('names that could break the selector are left out', () => {
    const css = emptyHolderRule(['core/column', 'x"] , body { display:none } [a="', 'Core/Group', 'nogroup'], 'x');
    assert.match(css, /core\/column/);
    assert.doesNotMatch(css, /body|Core\/Group|nogroup/);
});

test('the hint cannot end the CSS string', () => {
    const css = emptyHolderRule(['core/column'], 'a"; } body { display: none } x { content: "\\\n');
    assert.match(css, /content: "a ; } body \{ display: none \} x \{ content: {4}"/);
    assert.equal(css.split('"').length - 1, 6, 'the selector in both rules, and the content');
});
