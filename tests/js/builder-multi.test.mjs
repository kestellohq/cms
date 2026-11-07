import { test } from 'node:test';
import assert from 'node:assert/strict';
import { duplicateMany, groupMany, inOrder, rangeBetween, toggled } from '../../core/resources/js/ui/builder/multi.js';
import * as T from '../../core/resources/js/ui/builder/tree.js';

const n = (id, children = []) => ({ type: 'core/text', id, props: {}, children });
const doc = () => [n('a'), n('b'), n('g', [n('g1'), n('g2'), n('g3')]), n('c')];

test('Shift+click picks the run of siblings between, either way round', () => {
    assert.deepEqual(rangeBetween(doc(), 'a', 'g'), ['a', 'b', 'g']);
    assert.deepEqual(rangeBetween(doc(), 'g3', 'g1'), ['g1', 'g2', 'g3']);
    assert.deepEqual(rangeBetween(doc(), 'a', 'g2'), ['g2'], 'not siblings: just the one clicked');
    assert.deepEqual(rangeBetween(doc(), 'a', 'nope'), []);
});

test('Mod+click adds or takes away a sibling; elsewhere starts afresh', () => {
    assert.deepEqual(toggled(doc(), ['c'], 'a'), ['a', 'c'], 'kept in page order');
    assert.deepEqual(toggled(doc(), ['a', 'c'], 'a'), ['c']);
    assert.deepEqual(toggled(doc(), ['a', 'c'], 'g1'), ['g1'], 'inside another block: a new selection');
    assert.deepEqual(toggled(doc(), ['a'], 'nope'), ['a']);
    assert.deepEqual(inOrder(doc(), ['c', 'g1', 'a']), ['a', 'c'], 'only siblings of the first found');
});

test('several blocks duplicate after the last of them, in order, with fresh ids', () => {
    const { list, ids } = duplicateMany(doc(), ['b', 'a'], T.insert);
    assert.deepEqual(list.map((x) => x.id).slice(0, 2), ['a', 'b']);
    assert.equal(list.length, 6);
    assert.deepEqual(ids, [list[2].id, list[3].id]);
    assert.notEqual(ids[0], 'a');
    const inner = duplicateMany(doc(), ['g2', 'g3'], T.insert).list;
    assert.equal(inner[2].children.length, 5, 'inside a block too');
});

test('several blocks go into one group, where the first was', () => {
    const list = groupMany(doc(), ['c', 'b'], { type: 'core/group', id: 'new', props: {}, children: [] }, T);
    assert.deepEqual(
        list.map((x) => x.id),
        ['a', 'new', 'g'],
    );
    assert.deepEqual(
        list[1].children.map((x) => x.id),
        ['b', 'c'],
        'kept in order, the same blocks (not copies)',
    );
});
