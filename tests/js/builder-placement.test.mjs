import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createPlacement } from '../../core/resources/js/ui/builder/placement.js';
import { createBlock } from '../../core/resources/js/ui/builder/blocks.js';
import { fits } from '../../core/resources/js/ui/builder/tree.js';
import { catalog, defOf, node } from './fixtures.mjs';

const rules = createPlacement({
    fits: (def, parent) => fits(def, parent ? defOf(parent) : null),
    defOf,
    make: (name) => (catalog.has(name) ? createBlock(catalog.get(name), catalog) : null),
});
const TEXT = catalog.get('core/text');
const COLUMN = catalog.get('core/column');

test('position: before, after, inside, end', () => {
    const a = node('core/text');
    const g = node('core/group', {}, [node('core/text')]);
    const tree = [a, g];
    assert.deepEqual(rules.position(tree, { id: a.id, where: 'before' }), { parentId: null, index: 0 });
    assert.deepEqual(rules.position(tree, { id: a.id, where: 'after' }), { parentId: null, index: 1 });
    assert.deepEqual(rules.position(tree, { id: g.id, where: 'inside' }), { parentId: g.id, index: 1 });
    assert.deepEqual(rules.position(tree, { end: true }), { parentId: null, index: 2 });
    assert.equal(rules.position(tree, { id: 'gone', where: 'after' }), null);
});

test('layerTarget: the middle of a container drops inside, halves before and after', () => {
    const a = node('core/text');
    const g = node('core/group');
    const tree = [a, g];
    assert.deepEqual(rules.layerTarget(tree, g.id, 0.5, TEXT), { id: g.id, where: 'inside' });
    assert.deepEqual(rules.layerTarget(tree, g.id, 0.1, TEXT), { id: g.id, where: 'before' });
    assert.deepEqual(rules.layerTarget(tree, a.id, 0.5, TEXT), { id: a.id, where: 'after' });
    assert.equal(rules.layerTarget(tree, g.id, 0.5, TEXT, g.id), null, 'not onto itself');
    assert.equal(rules.layerTarget(tree, a.id, 0.3, COLUMN), null, 'a column only goes into columns');
});

test('canvasTarget: edges of a wide block go beside, inner blocks win, foreign blocks are skipped', () => {
    const a = node('core/text');
    const tree = [a];
    assert.deepEqual(rules.canvasTarget(tree, [{ id: a.id, xr: 0.95, yr: 0.5, w: 600 }], TEXT), { id: a.id, where: 'right' });
    assert.deepEqual(rules.canvasTarget(tree, [{ id: a.id, xr: 0.05, yr: 0.5, w: 600 }], TEXT), { id: a.id, where: 'left' });
    assert.deepEqual(rules.canvasTarget(tree, [{ id: a.id, xr: 0.95, yr: 0.3, w: 200 }], TEXT), { id: a.id, where: 'before' }, 'too narrow to split');
    assert.deepEqual(
        rules.canvasTarget(
            tree,
            [
                { id: 'theme-part', xr: 0.5, yr: 0.9, w: 900 },
                { id: a.id, xr: 0.5, yr: 0.9, w: 600 },
            ],
            TEXT,
        ),
        { id: a.id, where: 'after' },
    );
    assert.deepEqual(rules.canvasTarget(tree, [], TEXT), { end: true });
    const g = node('core/group');
    assert.deepEqual(
        rules.canvasTarget([g], [{ id: g.id, xr: 0.5, yr: 0.1, w: 600 }], TEXT),
        { id: g.id, where: 'inside' },
        'an empty container takes it anywhere',
    );
});

test('placeBeside: two blocks become columns, then a third column joins with an even layout', () => {
    const a = node('core/text', { text: 'A' });
    const b = node('core/text', { text: 'B' });
    const c = node('core/text', { text: 'C' });
    let tree = [a, b, c];
    tree = rules.placeBeside(tree, { id: a.id, where: 'right' }, b, b.id);
    assert.equal(tree.length, 2);
    const cols = tree[0];
    assert.equal(cols.type, 'core/columns');
    assert.equal(cols.props.layout, '1-1');
    assert.deepEqual(
        cols.children.map((col) => col.children[0].props.text),
        ['A', 'B'],
    );
    tree = rules.placeBeside(tree, { id: b.id, where: 'right' }, c, c.id);
    assert.equal(tree.length, 1);
    assert.equal(tree[0].props.layout, '1-1-1');
    assert.deepEqual(
        tree[0].children.map((col) => col.children[0].props.text),
        ['A', 'B', 'C'],
    );
    const left = rules.placeBeside([a], { id: a.id, where: 'left' }, node('core/quote'));
    assert.equal(left[0].children[0].children[0].type, 'core/quote', 'left puts the new one first');
});

test('canBeside stops at four columns', () => {
    const cols = createBlock(catalog.get('core/columns'), catalog);
    cols.children = [0, 1, 2, 3].map(() => ({ ...createBlock(COLUMN, catalog), children: [node('core/text')] }));
    const inner = cols.children[0].children[0];
    const target = rules.canvasTarget([cols], [{ id: inner.id, xr: 0.95, yr: 0.5, w: 300 }], TEXT);
    assert.notEqual(target?.where, 'right');
});

test('near: inside the selection if it fits, after it, else at the end', () => {
    const a = node('core/text');
    const g = node('core/group');
    const tree = [a, g];
    assert.deepEqual(rules.near(tree, g.id, TEXT), { parentId: g.id, index: 0 });
    assert.deepEqual(rules.near(tree, a.id, TEXT), { parentId: null, index: 1 });
    assert.deepEqual(rules.near(tree, null, TEXT), { parentId: null, index: 2 });
    assert.equal(rules.near(tree, a.id, COLUMN), null);
});
