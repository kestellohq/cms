import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as T from '../../core/resources/js/ui/builder/tree.js';
import { node } from './fixtures.mjs';

const sample = () => {
    const a = node('core/text');
    const b = node('core/heading');
    const c = node('core/text');
    const g = node('core/group', {}, [b, c]);
    return { tree: [a, g], a, b, c, g };
};

test('locate finds nodes with their parent, index and path', () => {
    const { tree, c, g } = sample();
    const at = T.locate(tree, c.id);
    assert.equal(at.node, c);
    assert.equal(at.parent, g);
    assert.equal(at.index, 1);
    assert.deepEqual(
        at.path.map((p) => p.id),
        [g.id],
    );
    assert.equal(T.locate(tree, 'nope'), null);
});

test('update shares unchanged nodes and removes undefined keys', () => {
    const { tree, a, b, g } = sample();
    const next = T.update(tree, b.id, { props: { text: 'Hi' }, lock: undefined });
    assert.notEqual(next, tree);
    assert.equal(next[0], a, 'untouched sibling is the same object');
    assert.notEqual(next[1], g);
    assert.equal(next[1].children[0].props.text, 'Hi');
    assert.ok(!('lock' in next[1].children[0]));
});

test('insert and remove at any depth', () => {
    const { tree, g, b } = sample();
    const x = node('core/quote');
    const inserted = T.insert(tree, g.id, 1, x);
    assert.deepEqual(
        inserted[1].children.map((n) => n.id),
        [b.id, x.id, g.children[1].id],
    );
    const removed = T.remove(inserted, b.id);
    assert.deepEqual(
        removed[1].children.map((n) => n.id),
        [x.id, g.children[1].id],
    );
    assert.equal(T.insert(tree, null, 99, x).at(-1), x, 'index past the end appends');
});

test('move: down in the same list accounts for the gap, and never into itself', () => {
    const { tree, a, g } = sample();
    const down = T.move(tree, a.id, null, 2);
    assert.deepEqual(
        down.map((n) => n.id),
        [g.id, a.id],
    );
    assert.equal(T.move(tree, g.id, g.children[0].id, 0), tree, 'into its own child: unchanged');
    assert.equal(T.move(tree, g.id, g.id, 0), tree, 'into itself: unchanged');
});

test('reid gives fresh ids all the way down', () => {
    const { g } = sample();
    const copy = T.reid(g);
    assert.notEqual(copy.id, g.id);
    assert.notEqual(copy.children[0].id, g.children[0].id);
    assert.match(copy.id, /^[a-f0-9]{8}$/);
});

test('flatten skips collapsed children', () => {
    const { tree, g } = sample();
    assert.equal(T.flatten(tree).length, 4);
    assert.deepEqual(
        T.flatten(tree, new Set([g.id])).map((r) => r.depth),
        [0, 0],
    );
});

test('fits: top level, containers and parent rules', () => {
    const col = { name: 'core/column', children: true, parents: ['core/columns'] };
    const cols = { name: 'core/columns', children: ['core/column'], parents: null };
    const text = { name: 'core/text', children: null, parents: null };
    assert.equal(T.fits(text, null), true);
    assert.equal(T.fits(col, null), false, 'a column only goes in columns');
    assert.equal(T.fits(col, cols), true);
    assert.equal(T.fits(text, cols), false, 'columns hold only columns');
    assert.equal(T.fits(text, null, ['core/heading']), false, 'allowed limits the top level');
    assert.equal(T.fits(text, null, null, ['core/text']), false, 'hidden blocks never fit');
});
