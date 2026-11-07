import { test } from 'node:test';
import assert from 'node:assert/strict';
import { groupPatterns, layoutBlocks, matchesPattern, needsLayoutChoice, withLayout } from '../../core/resources/js/ui/builder/patterns.js';
import { catalog, node } from './fixtures.mjs';

const P = (key, category, extra = {}) => ({
    key,
    title: key.replace(/-/g, ' '),
    description: '',
    category,
    synced: false,
    layout: false,
    blocks: [],
    ...extra,
});
const all = [
    P('faq', 'Questions'),
    P('hero', 'Headers'),
    P('promo', 'Seasonal'),
    P('page-home', 'Pages', { layout: true }),
    P('steps', 'Features', { description: 'Three numbered steps' }),
    P('awards', 'Awards'),
];

test("patterns come in core's category order, then the theme's own A to Z", () => {
    assert.deepEqual(
        groupPatterns(all).map((g) => g.name),
        ['Pages', 'Headers', 'Features', 'Questions', 'Awards', 'Seasonal'],
    );
    assert.deepEqual(
        groupPatterns(all, { layouts: true }).map((g) => g.patterns.map((p) => p.key)),
        [['page-home']],
        'an empty page is offered whole pages only',
    );
});

test('a search matches every word, in the title, description or category', () => {
    assert.equal(matchesPattern(all[4], 'numbered three'), true);
    assert.equal(matchesPattern(all[4], 'numbered four'), false);
    assert.equal(matchesPattern(all[0], 'QUESTIONS'), true);
    assert.deepEqual(
        groupPatterns(all, { query: 'hero' }).flatMap((g) => g.patterns.map((p) => p.key)),
        ['hero'],
    );
});

test("a layout becomes the page's own blocks, fresh ids, unknown kinds left out", () => {
    const layout = P('page-x', 'Pages', {
        layout: true,
        blocks: [node('core/heading', { text: 'Hi' }), node('vendor/gone'), node('core/group', {}, [node('core/text', { text: '<p>x</p>' })])],
    });
    const blocks = layoutBlocks(layout, catalog);
    assert.deepEqual(
        blocks.map((b) => b.type),
        ['core/heading', 'core/group'],
    );
    assert.notEqual(blocks[0].id, layout.blocks[0].id);
    assert.notEqual(blocks[1].children[0].id, layout.blocks[2].children[0].id);
});

test('a layout asks first only on a page that has blocks', () => {
    assert.equal(needsLayoutChoice({ layout: true }, []), false, 'an empty page just takes it');
    assert.equal(needsLayoutChoice({ layout: true }, [{ id: 'a' }]), true);
    assert.equal(needsLayoutChoice({ layout: true, synced: true }, [{ id: 'a' }]), false, 'a synced one is placed as a reference');
    assert.equal(needsLayoutChoice({ layout: false }, [{ id: 'a' }]), false, 'a section is placed like a block');
});

test('a layout replaces the page or goes after it', () => {
    const page = [{ id: 'a' }],
        layout = [{ id: 'x' }, { id: 'y' }];
    assert.deepEqual(
        withLayout(page, layout, 'replace').map((n) => n.id),
        ['x', 'y'],
    );
    assert.deepEqual(
        withLayout(page, layout, 'append').map((n) => n.id),
        ['a', 'x', 'y'],
    );
    assert.throws(() => withLayout(page, layout, 'merge'), /how must be "replace" or "append"/);
});
