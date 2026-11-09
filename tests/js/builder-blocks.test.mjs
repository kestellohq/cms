import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    blockIcon,
    createBlock,
    documentStats,
    editableFields,
    errorFromMessage,
    errorTarget,
    fromPattern,
    isKind,
    linesOf,
    plain,
    summarize,
    turnInto,
} from '../../core/resources/js/ui/builder/blocks.js';
import { catalog, defOf, node } from './fixtures.mjs';

test('createBlock fills defaults and starts columns with their columns', () => {
    const img = createBlock(catalog.get('core/image'), catalog);
    assert.deepEqual(img.props, { scale: 100, align: 'center' });
    const cols = createBlock(catalog.get('core/columns'), catalog);
    assert.equal(cols.children.length, 2);
    assert.ok(cols.children.every((c) => c.type === 'core/column'));
});

test('createBlock starts a block with its starter: given props over defaults, unknown kinds left out', () => {
    const row = createBlock(catalog.get('core/buttons'), catalog);
    assert.deepEqual(
        row.children.map((c) => [c.type, c.props]),
        [['core/button', { variant: 'primary' }]],
        'one button, not two',
    );
    const cover = createBlock(catalog.get('core/cover'), catalog);
    assert.deepEqual(
        cover.children.map((c) => [c.type, c.props.level, c.props.align]),
        [['core/heading', '1', 'start']],
    );
    assert.notEqual(cover.children[0].id, row.children[0].id);
});

test('fromPattern: synced stays a reference, several blocks go in a group, unknown blocks are dropped', () => {
    const synced = fromPattern({ synced: true, key: 'cta' }, catalog);
    assert.deepEqual([synced.type, synced.props.pattern], ['core/pattern', 'cta']);
    const one = fromPattern({ blocks: [node('core/text', { text: '<p>a</p>' }), node('vendor/gone')] }, catalog);
    assert.equal(one.type, 'core/text');
    const many = fromPattern({ blocks: [node('core/text'), node('core/heading')] }, catalog);
    assert.equal(many.type, 'core/group');
    assert.equal(many.children.length, 2);
});

test('summarize: text first, nothing for an empty text block, a choice otherwise', () => {
    assert.equal(summarize(node('core/text', { text: '<p>Hello <b>you</b></p>', align: 'start' }), defOf({ type: 'core/text' })), 'Hello you');
    assert.equal(summarize(node('core/text', { text: '', align: 'start' }), defOf({ type: 'core/text' })), '', 'not "Left"');
    assert.equal(
        summarize(node('core/heading', { text: { bind: 'entry.title' } }), defOf({ type: 'core/heading' }), (p) => `bound ${p}`),
        'bound entry.title',
    );
    assert.equal(summarize(node('core/x'), null), null);
});

test('summarize: a block holding others says the first words inside it, as the page shows', () => {
    const cover = node('core/cover', { shade: 'dark' }, [
        node('core/group', {}, [node('core/heading', { text: 'Good work, done properly' })]),
        node('core/text', { text: '<p>Later</p>' }),
    ]);
    assert.equal(summarize(cover, defOf(cover), undefined, defOf), 'Good work, done properly', 'not its shade');
    assert.equal(summarize(node('core/group', {}, []), defOf({ type: 'core/group' }), undefined, defOf), '', 'nothing inside yet');
    assert.equal(summarize(cover, defOf(cover)), '', 'without defOf it cannot look inside');
});

test('summarize: a field marked as the summary wins over the words inside', () => {
    const cols = node('core/columns', { layout: '1-1-1' }, [node('core/column', {}, [node('core/heading', { text: 'Plan' })])]);
    const def = {
        ...defOf(cols),
        fields: [{ name: 'layout', type: 'select', options: { summary: true, choices: [{ value: '1-1-1', label: 'Three equal' }] } }],
    };
    assert.equal(summarize(cols, def, undefined, defOf), 'Three equal');
});

test("blockIcon: the icon for a field's value, else the block's own", () => {
    const def = {
        icon: 'text-h',
        fields: [{ name: 'level', default: '2' }],
        iconBy: { field: 'level', icons: { 1: 'text-h-one', 2: 'text-h-two', 3: 'text-h-three' } },
    };
    assert.equal(blockIcon({ props: { level: '3' } }, def), 'text-h-three');
    assert.equal(blockIcon({ props: {} }, def), 'text-h-two', "the field's default");
    assert.equal(blockIcon({ props: { level: '9' } }, def), 'text-h', 'no icon for that value');
    assert.equal(blockIcon({ props: {} }, { icon: 'paragraph', fields: [] }), 'paragraph');
    assert.equal(blockIcon({}, null), 'warning', 'unknown block');
});

test('editableFields: text fields holding text, not bound, not locked', () => {
    const t = node('core/text', { text: '' });
    const h = node('core/heading', { text: { bind: 'entry.title' } });
    const q = node('core/quote', {}, []);
    q.lock = ['edit'];
    const map = editableFields([t, h, q], defOf);
    assert.deepEqual(map[t.id], { text: 'rich' });
    assert.equal(map[h.id], undefined, 'bound to data');
    assert.equal(map[q.id], undefined, 'locked');
    assert.deepEqual(editableFields([q], defOf, { locks: false })[q.id], { text: 'multi', cite: 'plain' }, 'designers may type into locked blocks');
    assert.deepEqual(editableFields([t], defOf, { disabled: true }), {});
});

test('turnInto carries the text over, keeps id and alignment where it applies', () => {
    const t = node('core/text', { text: '<p>One</p><p>Two &amp; more</p>', align: 'center' });
    assert.deepEqual(linesOf(t), ['One', 'Two & more']);
    const list = turnInto(t, 'core/list', 'ordered');
    assert.equal(list.id, t.id);
    assert.deepEqual(list.props, { items: [{ text: 'One' }, { text: 'Two & more' }], ordered: true });
    const back = turnInto(list, 'core/text');
    assert.equal(back.props.text, '<p>One</p><p>Two &amp; more</p>', 'text is escaped again');
    assert.equal(turnInto(t, 'core/heading', '3').props.align, 'center');
    assert.equal(turnInto(t, 'core/code').props.align, undefined, 'code has no alignment');
    const details = turnInto(node('core/quote', { text: 'Why?\nBecause.' }), 'core/details');
    assert.deepEqual([details.props.summary, details.props.text], ['Why?', '<p>Because.</p>']);
    assert.equal(turnInto(t, 'core/unknown'), t);
});

test('isKind tells heading levels and list kinds apart', () => {
    assert.equal(isKind(node('core/heading', { level: '3' }), 'core/heading', '3'), true);
    assert.equal(isKind(node('core/heading', {}), 'core/heading', '3'), false);
    assert.equal(isKind(node('core/list', { ordered: true }), 'core/list', 'ordered'), true);
    assert.equal(isKind(node('core/list', {}), 'core/list', 'ordered'), false);
});

test('documentStats counts words, paragraphs and the outline', () => {
    const tree = [
        node('core/heading', { text: 'Fresh bread', level: '2' }),
        node('core/group', {}, [node('core/text', { text: '<p>Baked every day.</p><p>Come early.</p>' })]),
        node('core/list', { items: [{ text: 'Rye' }, { text: 'Spelt loaf' }] }),
    ];
    tree[2].type = 'core/list';
    const s = documentStats(tree, (n) => (n.type === 'core/list' ? { fields: [{ name: 'items', type: 'repeater' }] } : defOf(n)));
    assert.equal(s.words, 2 + 5 + 3);
    assert.equal(s.paragraphs, 2);
    assert.equal(s.blocks, 4);
    assert.deepEqual(
        s.headings.map((h) => [h.level, h.text]),
        [[2, 'Fresh bread']],
    );
    assert.equal(s.minutes, 1);
});

test('plain strips tags and entities for spaces', () => {
    assert.equal(plain('<p>a&nbsp;b</p>\n<p>c</p>'), 'a b c');
});

test('plain reads entities once', () => {
    assert.equal(plain('Fish &amp; chips &lt;3 &#8212; &#x2014; &copy;'), 'Fish & chips <3 — — &copy;');
});

test('errorTarget follows a server error path to its block and field', () => {
    const inner = node('core/text');
    const tree = [node('core/heading'), node('core/group', {}, [node('core/quote'), inner])];
    assert.deepEqual(errorTarget(tree, 'blocks.1.children.1.props.text'), { id: inner.id, field: 'text' });
    assert.deepEqual(errorTarget(tree, 'blocks.0'), { id: tree[0].id, field: null });
    assert.equal(errorTarget(tree, 'blocks.7.props.text'), null);
    assert.equal(errorTarget(tree, 'blocks'), null);
    assert.equal(errorTarget(tree, 'title'), null);
});

test('errorFromMessage reads the block trail the server writes', () => {
    const inner = node('core/text');
    const tree = [node('core/heading'), node('core/group', {}, [node('core/quote'), inner])];
    assert.deepEqual(errorFromMessage(tree, 'Block 2 (Group) › Block 2 (Text), Text: This field is required.'), {
        id: inner.id,
        rest: 'This field is required.',
    });
    assert.deepEqual(errorFromMessage(tree, 'Block 1 (Heading), Text: This field is required.'), { id: tree[0].id, rest: 'Text: This field is required.' });
    assert.deepEqual(errorFromMessage(tree, "Block 1 (Heading): can't be placed here."), { id: tree[0].id, rest: "can't be placed here." });
    assert.equal(errorFromMessage(tree, 'Block 9 (Text): x'), null);
    assert.deepEqual(
        errorFromMessage([inner], 'Block 1 (Text), Text: This field is required.'),
        { id: inner.id, rest: 'This field is required.' },
        'not "Text: Text:"',
    );
    assert.equal(errorFromMessage(tree, 'A tree can hold at most 500 blocks.'), null);
});
