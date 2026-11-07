import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FALLBACK_ID, hasBody, placeTree, stamp, withBody } from '../../core/resources/js/app/canvas/tree.js';
import { layoutOf, resizedPercent } from '../../core/resources/js/app/canvas/resize.js';

test("an entry's blocks go into its body field and the template around it", () => {
    const template = { blocks: [{ type: 'core/group', children: [{ type: 'core/entry-content', props: { field: 'body' }, children: [] }] }] };
    const props = { template, entry: { title: 'Old', data: { body: [], other: 1 } } };
    const blocks = [{ id: 'a', type: 'core/text' }];
    const next = placeTree(props, { kind: 'entry', field: 'body' }, { blocks, title: 'New' });
    assert.equal(next.template.blocks[0].children[0].children, blocks);
    assert.deepEqual(next.entry.data, { body: blocks, other: 1 });
    assert.equal(next.entry.title, 'New');
    assert.equal(props.entry.title, 'Old', 'the old props are left alone');
    assert.deepEqual(withBody(template.blocks, 'intro', blocks), template.blocks, 'another field: untouched');
});

test("a template's tree replaces its blocks", () => {
    const next = placeTree({ template: { name: 'x', blocks: [] } }, { kind: 'template' }, { blocks: [{ id: 'a' }] });
    assert.deepEqual(next.template, { name: 'x', blocks: [{ id: 'a' }] });
});

test('blocks typed into get a new revision so they draw afresh', () => {
    const tree = [
        { id: 'a', children: [{ id: 'b', children: [] }] },
        { id: 'c', children: [] },
    ];
    assert.equal(stamp(tree, new Set(), 3), tree);
    const out = stamp(tree, new Set(['b']), 3);
    assert.equal(out[0].children[0]._rev, 3);
    assert.equal(out[0]._rev, undefined);
});

test('resizing: a share of the column, within 10 and 100', () => {
    assert.equal(resizedPercent({ startWidth: 700, column: 700, dx: -245, factor: 2 }), 30, 'centred: both sides move');
    assert.equal(resizedPercent({ startWidth: 350, column: 700, dx: -100, factor: -1 }), 64, 'from the right: dragging left grows');
    assert.equal(resizedPercent({ startWidth: 100, column: 700, dx: -500, factor: 1 }), 10);
    assert.equal(resizedPercent({ startWidth: 700, column: 700, dx: 300, factor: 1 }), 100);
    assert.deepEqual(layoutOf('k-b-image is-wrap-end', 'right'), { floated: true, fromEnd: true, centred: false });
    assert.deepEqual(layoutOf('k-b-image is-center', 'none'), { floated: false, fromEnd: false, centred: true });
});

test('a template with no place for the body still shows it, after the template, once', () => {
    const template = { blocks: [{ id: 't1', type: 'core/heading', children: [] }] };
    assert.equal(hasBody(template.blocks, 'body'), false);
    const blocks = [{ id: 'a', type: 'core/text' }];
    const once = placeTree({ template }, { kind: 'entry', field: 'body' }, { blocks });
    assert.deepEqual(
        once.template.blocks.map((n) => n.id),
        ['t1', FALLBACK_ID],
    );
    assert.equal(once.template.blocks[1].children, blocks);
    const twice = placeTree(once, { kind: 'entry', field: 'body' }, { blocks: [] });
    assert.deepEqual(
        twice.template.blocks.map((n) => n.id),
        ['t1', FALLBACK_ID],
        'not added again',
    );
    const nested = { blocks: [{ id: 'g', type: 'core/group', children: [{ id: 's', type: 'core/entry-content', props: { field: 'body' }, children: [] }] }] };
    assert.equal(hasBody(nested.blocks, 'body'), true);
    assert.equal(hasBody(nested.blocks, 'intro'), false);
    assert.deepEqual(
        placeTree({ template: nested }, { kind: 'entry', field: 'body' }, { blocks }).template.blocks.map((n) => n.id),
        ['g'],
    );
});
