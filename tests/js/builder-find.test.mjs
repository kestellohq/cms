import { test } from 'node:test';
import assert from 'node:assert/strict';
import { findMatches, highlight, replaceMatches, searchPattern, snippet, textFields } from '../../core/resources/js/ui/builder/find.js';

const f = (name, type, extra = {}) => ({ name, type, options: {}, ...extra });
const DEFS = new Map([
    ['core/heading', { fields: [f('text', 'text'), f('level', 'select')] }],
    ['core/text', { fields: [f('text', 'rich_text')] }],
    ['core/list', { fields: [f('items', 'repeater', { fields: [f('text', 'text')] })] }],
    ['core/table', { fields: [f('table', 'table'), f('caption', 'text')] }],
    ['core/code', { fields: [f('code', 'textarea'), f('language', 'text', { options: { findable: false } })] }],
    ['core/button', { fields: [f('text', 'text'), f('link', 'link')] }],
    ['core/group', { fields: [] }],
]);
const defOf = (n) => DEFS.get(n.type) ?? null;
const doc = () => [
    { id: 'h', type: 'core/heading', props: { text: 'Bread and Butter', level: '2' }, children: [] },
    {
        id: 'g',
        type: 'core/group',
        props: {},
        children: [
            { id: 't', type: 'core/text', props: { text: '<p>Fresh <a href="/bread">bread</a> &amp; butter, <b>bread</b>!</p>' }, children: [] },
            { id: 'l', type: 'core/list', props: { items: [{ text: 'Rye bread' }, { text: 'Cake' }] }, children: [] },
        ],
    },
    {
        id: 'tb',
        type: 'core/table',
        props: {
            table: {
                head: true,
                rows: [
                    ['Item', 'Price'],
                    ['Bread', '£3'],
                ],
            },
            caption: '',
        },
        children: [],
    },
    { id: 'c', type: 'core/code', props: { code: 'bread()', language: 'bread' }, children: [] },
    { id: 'b', type: 'core/button', props: { text: { bind: 'entry.title' }, link: '/bread' }, children: [] },
];

test('only the fields people read are searched', () => {
    assert.deepEqual(
        textFields(DEFS.get('core/code').fields).map((x) => x.name),
        ['code'],
        'a field can opt out',
    );
    assert.deepEqual(
        textFields(DEFS.get('core/button').fields).map((x) => x.name),
        ['text'],
        'links are not words',
    );
    assert.deepEqual(textFields(DEFS.get('core/list').fields), [{ name: 'items', type: 'repeater', fields: [{ name: 'text', type: 'text' }] }]);
    assert.equal(searchPattern('   '), null);
    assert.equal(searchPattern('x'.repeat(201)), null, 'a search has a sensible length');
});

test('matches come in page order, counted per block, in rich text only between the tags', () => {
    const m = findMatches(doc(), defOf, 'bread');
    assert.deepEqual(
        m.map((x) => [x.id, x.count]),
        [
            ['h', 1],
            ['t', 2],
            ['l', 1],
            ['tb', 1],
            ['c', 1],
        ],
        'the link address and a bound value are not matched',
    );
    assert.deepEqual(
        findMatches(doc(), defOf, 'Bread', { matchCase: true }).map((x) => x.id),
        ['h', 'tb'],
    );
    assert.deepEqual(
        findMatches(doc(), defOf, '& butter').map((x) => x.id),
        ['t'],
        'an entity is the character it stands for',
    );
    assert.deepEqual(
        findMatches(doc(), defOf, 'amp').map((x) => x.id),
        [],
        "never the entity's spelling",
    );
    assert.deepEqual(
        findMatches(doc(), defOf, 'a.b').map((x) => x.id),
        [],
        'a search is literal text, not a pattern',
    );
});

test('replacing keeps tags, links and entities, and touches only what was asked', () => {
    const before = doc();
    const { list, count } = replaceMatches(before, defOf, 'bread', 'loaf');
    assert.equal(count, 6);
    assert.equal(list[0].props.text, 'loaf and Butter');
    assert.equal(list[1].children[0].props.text, '<p>Fresh <a href="/bread">loaf</a> &amp; butter, <b>loaf</b>!</p>');
    assert.deepEqual(list[1].children[1].props.items, [{ text: 'Rye loaf' }, { text: 'Cake' }]);
    assert.deepEqual(list[2].props.table.rows, [
        ['Item', 'Price'],
        ['loaf', '£3'],
    ]);
    assert.equal(list[3].props.language, 'bread');
    assert.deepEqual(list[4], before[4], 'a block without a match is the same object');
    assert.equal(before[0].props.text, 'Bread and Butter', 'the old tree is not changed');

    const one = replaceMatches(doc(), defOf, 'bread', 'loaf', { ids: ['t'] });
    assert.equal(one.count, 2);
    assert.equal(one.list[0].props.text, 'Bread and Butter');

    const kept = replaceMatches(doc(), defOf, 'bread', 'loaf', { skip: (n) => n.id === 'h' });
    assert.equal(kept.list[0].props.text, 'Bread and Butter', 'a locked block is left alone');

    const html = replaceMatches(doc(), defOf, 'butter', '<script>x</script> & co', { ids: ['t'] }).list[1].children[0].props.text;
    assert.equal(
        html,
        '<p>Fresh <a href="/bread">bread</a> &amp; &lt;script&gt;x&lt;/script&gt; &amp; co, <b>bread</b>!</p>',
        'replacement text is text, never markup',
    );
    assert.equal(replaceMatches(doc(), defOf, 'cake', '$& $1').list[1].children[1].props.items[1].text, '$& $1', 'replacement is taken literally');
});

test('a snippet shows the words around the first match', () => {
    const re = searchPattern('butter');
    assert.equal(snippet('<p>Fresh <b>bread</b> &amp; butter</p>', { type: 'rich_text' }, re), 'Fresh bread & butter');
    assert.equal(snippet('a '.repeat(40) + 'butter' + ' z'.repeat(40), { type: 'text' }, re, 6), '…a a a butter z z z…');
});

test('the matches in a snippet are marked, as text', () => {
    assert.deepEqual(highlight('Bread and bread', 'bread'), [
        { text: 'Bread', hit: true },
        { text: ' and ', hit: false },
        { text: 'bread', hit: true },
    ]);
    assert.deepEqual(highlight('Bread and bread', 'bread', { matchCase: true }), [
        { text: 'Bread and ', hit: false },
        { text: 'bread', hit: true },
    ]);
    assert.deepEqual(highlight('plain', ''), [{ text: 'plain', hit: false }]);
});
