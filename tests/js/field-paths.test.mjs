import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cellOf, getAt, itemOf, neighbours, safeFieldPath, setAt } from '../../core/resources/js/shared/field-paths.js';

test('field paths: names and indexes joined by dots, starting with a name', () => {
    for (const ok of ['text', 'items.2.text', 'table.rows.0.1', 'a_b.10.c_d']) assert.equal(safeFieldPath(ok), ok);
    for (const bad of [
        '',
        '2.text',
        'items..text',
        'items.-1.text',
        'Items.0.text',
        'a.b.c.d.e.f',
        'items.0.text"]',
        'x'.repeat(201),
        null,
        3,
        'items.12345.text',
    ])
        assert.equal(safeFieldPath(bad), null, String(bad));
});

test('a path names an item, a cell or neither', () => {
    assert.deepEqual(itemOf('items.2.text'), { list: 'items', index: 2, sub: 'text' });
    assert.equal(itemOf('text'), null);
    assert.equal(itemOf('table.rows.0.1'), null);
    assert.deepEqual(cellOf('table.rows.0.1'), { field: 'table', row: 0, col: 1 });
    assert.equal(cellOf('items.2.text'), null);
    assert.equal(cellOf('table.head.0.1'), null);
});

test('setAt copies along the path and leaves the rest shared', () => {
    const props = { ordered: true, items: [{ text: 'a' }, { text: 'b' }], other: { x: 1 } };
    const out = setAt(props, 'items.1.text', 'B');
    assert.deepEqual(out.items, [{ text: 'a' }, { text: 'B' }]);
    assert.equal(props.items[1].text, 'b', 'the original is untouched');
    assert.equal(out.items[0], props.items[0], 'untouched items are shared');
    assert.equal(out.other, props.other);

    const table = {
        table: {
            head: true,
            rows: [
                ['Plan', 'Price'],
                ['Basic', '9'],
            ],
        },
    };
    assert.deepEqual(setAt(table, 'table.rows.1.1', '€9').table.rows, [
        ['Plan', 'Price'],
        ['Basic', '€9'],
    ]);
    assert.equal(getAt(table, 'table.rows.0.0'), 'Plan');

    assert.deepEqual(setAt({}, 'caption', 'New'), { caption: 'New' }, 'a top-level field may be new');
});

test('setAt refuses paths that lead nowhere', () => {
    const props = { items: [{ text: 'a' }], text: 'plain' };
    assert.equal(setAt(props, 'items.5.text', 'x'), null, 'past the end of the list');
    assert.equal(setAt(props, 'text.0.x', 'x'), null, 'through a string');
    assert.equal(setAt(props, 'missing.0.text', 'x'), null);
    assert.equal(setAt(props, 'items.0', 'x') !== null, true, 'an item itself is a place');
    assert.equal(setAt(props, 'bad path', 'x'), null);
    assert.equal(getAt(props, 'items.3.text'), undefined);
});

test('neighbours: items move up and down, cells along rows and columns', () => {
    assert.deepEqual(neighbours('items.2.text', 'up'), ['items.1.text']);
    assert.deepEqual(neighbours('items.0.text', 'up'), []);
    assert.deepEqual(neighbours('items.0.text', 'down'), ['items.1.text']);
    assert.deepEqual(neighbours('items.0.text', 'next'), [], 'Tab in a list is left alone');
    assert.deepEqual(neighbours('t.rows.1.2', 'up'), ['t.rows.0.2']);
    assert.deepEqual(neighbours('t.rows.0.2', 'up'), []);
    assert.deepEqual(neighbours('t.rows.1.2', 'down'), ['t.rows.2.2']);
    assert.deepEqual(neighbours('t.rows.1.2', 'next'), ['t.rows.1.3', 't.rows.2.0']);
    assert.deepEqual(neighbours('t.rows.1.2', 'prev'), ['t.rows.1.1']);
    assert.deepEqual(
        neighbours('t.rows.1.0', 'prev', () => 3),
        ['t.rows.0.2'],
        'back to the end of the row above',
    );
    assert.deepEqual(
        neighbours('t.rows.0.0', 'prev', () => 3),
        [],
    );
    assert.deepEqual(neighbours('caption', 'down'), []);
});
