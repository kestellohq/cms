import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createBlock, editableFields, firstInlineOf } from '../../core/resources/js/ui/builder/blocks.js';
import { typingKey } from '../../core/resources/js/ui/builder/typing-keys.js';
import { catalog, defOf, node } from './fixtures.mjs';

const key = (n, field, k) => typingKey({ node: n, def: defOf(n), field, key: k, first: firstInlineOf(n, defOf(n)) });

test('editableFields: each item of a list, each cell of a table, and their captions', () => {
    const list = node('core/list', { items: [{ text: 'Rye' }, { text: 'Spelt' }] });
    const icons = node('core/icon-list', { items: [{ icon: 'check', text: 'Fresh' }] });
    const table = node('core/table', {
        table: {
            head: true,
            rows: [
                ['Plan', 'Price'],
                ['Basic', '9'],
            ],
        },
        caption: '',
    });
    const bound = node('core/list', { items: [{ text: { bind: 'entry.title' } }] });
    const map = editableFields([list, icons, table, bound], defOf);
    assert.deepEqual(map[list.id], { 'items.0.text': 'plain', 'items.1.text': 'plain' });
    assert.deepEqual(map[icons.id], { 'items.0.text': 'plain' }, 'the icon is chosen in the panel');
    assert.deepEqual(map[table.id], {
        'table.rows.0.0': 'plain',
        'table.rows.0.1': 'plain',
        'table.rows.1.0': 'plain',
        'table.rows.1.1': 'plain',
        caption: 'plain',
    });
    assert.equal(map[bound.id], undefined, 'bound to data');
    assert.equal(firstInlineOf(list, defOf(list)), 'items.0.text');
});

test('a new list starts with one empty item to type into', () => {
    assert.deepEqual(createBlock(catalog.get('core/list'), catalog).props.items, [{ text: '' }]);
});

test('Enter in an item adds one after it; an icon carries over', () => {
    const list = node('core/list', { ordered: true, items: [{ text: 'Rye' }, { text: 'Spelt' }] });
    const a = key(list, 'items.0.text', 'enter');
    assert.equal(a.do, 'props');
    assert.deepEqual(a.props.items, [{ text: 'Rye' }, { text: '' }, { text: 'Spelt' }]);
    assert.equal(a.props.ordered, true);
    assert.deepEqual(a.focus, { field: 'items.1.text', at: 'start' });

    const icons = node('core/icon-list', { items: [{ icon: 'star', text: 'Fresh' }] });
    assert.deepEqual(key(icons, 'items.0.text', 'enter').props.items[1], { icon: 'star', text: '' });
});

test('Enter on an empty last item leaves the list', () => {
    const list = node('core/list', { items: [{ text: 'Rye' }, { text: ' ' }] });
    assert.deepEqual(key(list, 'items.1.text', 'enter'), { do: 'leave', props: { items: [{ text: 'Rye' }] } });
    assert.deepEqual(key(node('core/list', { items: [{ text: '' }] }), 'items.0.text', 'enter'), { do: 'leave', props: null }, 'the only item: the list goes');
    assert.equal(key(node('core/list', { items: [{ text: '' }, { text: 'b' }] }), 'items.0.text', 'enter').do, 'props', 'an empty item in the middle adds one');
});

test('a list stops growing at its most items', () => {
    const full = node('core/list', { items: [{ text: 'a' }, { text: 'b' }, { text: 'c' }] });
    assert.deepEqual(key(full, 'items.1.text', 'enter'), { do: 'none' });
});

test('Backspace in an empty item removes it; in the only item the block goes', () => {
    const list = node('core/list', { items: [{ text: 'Rye' }, { text: '' }, { text: 'Spelt' }] });
    const a = key(list, 'items.1.text', 'remove');
    assert.deepEqual(a.props.items, [{ text: 'Rye' }, { text: 'Spelt' }]);
    assert.deepEqual(a.focus, { field: 'items.0.text', at: 'end' });
    const first = key(node('core/list', { items: [{ text: '' }, { text: 'b' }] }), 'items.0.text', 'remove');
    assert.deepEqual(first.focus, { field: 'items.0.text', at: 'start' }, 'the first item: the caret goes to the next one');
    assert.deepEqual(key(node('core/list', { items: [{ text: '' }] }), 'items.0.text', 'remove'), { do: 'default' });
});

test('Enter on the last row of a table adds a row; nothing typed takes the table apart', () => {
    const table = node('core/table', {
        table: {
            head: true,
            rows: [
                ['Plan', 'Price'],
                ['Basic', '9'],
            ],
        },
    });
    const a = key(table, 'table.rows.1.1', 'enter');
    assert.deepEqual(a.props.table, {
        head: true,
        rows: [
            ['Plan', 'Price'],
            ['Basic', '9'],
            ['', ''],
        ],
    });
    assert.deepEqual(a.focus, { field: 'table.rows.2.1', at: 'start' });
    assert.deepEqual(key(table, 'table.rows.0.0', 'enter'), { do: 'none' }, 'other rows: the frame moves down itself');
    assert.deepEqual(key(table, 'table.rows.1.0', 'remove'), { do: 'none' }, 'Backspace in an empty cell keeps the table');
});

test('Backspace in an empty caption keeps the block; in its first field the block goes', () => {
    const table = node('core/table', { table: { rows: [['a']] }, caption: '' });
    assert.deepEqual(key(table, 'caption', 'remove'), { do: 'none' });
    const quote = node('core/quote', { text: '', cite: '' });
    assert.deepEqual(key(quote, 'cite', 'remove'), { do: 'none' }, 'before: an empty source took the quote with it');
    assert.deepEqual(key(quote, 'text', 'remove'), { do: 'default' });
});

test('a path to an item that is not there does nothing', () => {
    assert.deepEqual(key(node('core/list', { items: [{ text: 'a' }] }), 'items.4.text', 'enter'), { do: 'none' });
});
