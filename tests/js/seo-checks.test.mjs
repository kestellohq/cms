import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkSeo } from '../../core/resources/js/components/content/seo-checks.js';

const t = (s, p = {}) => s.replace(/\{(\w+)\}/g, (_, k) => String(p[k]));
const messages = (entry) => checkSeo({ hasSeo: true, ...entry }, t).map((i) => i.message);

test('a type without search fields gets no search checks', () => {
    assert.deepEqual(checkSeo({ title: 'x'.repeat(90), hasSeo: false }, t), []);
});

test('a short title with a description passes', () => {
    assert.deepEqual(messages({ title: 'Rye', seo: { description: 'Three days, one loaf.' } }), []);
    assert.deepEqual(messages({ title: 'Rye', excerpt: 'Three days, one loaf.' }), [], 'the excerpt stands in for a description');
});

test('long titles: the advice depends on whether a search title is set', () => {
    const long = 'A'.repeat(61);
    assert.match(messages({ title: long, excerpt: 'x' })[0], /The title is 61 characters long.*add a shorter search title/);
    assert.match(messages({ title: long, seo: { title: 'B'.repeat(70), description: 'x' } })[0], /The search title is 70 characters long/);
    assert.deepEqual(messages({ title: long, seo: { title: 'Rye', description: 'x' } }), [], 'a short search title fixes a long title');
    assert.deepEqual(messages({ title: '😀'.repeat(60), excerpt: 'x' }), [], 'characters, not bytes or UTF-16 units');
});

test('descriptions: missing or too long', () => {
    assert.match(messages({ title: 'Rye' })[0], /No search description/);
    assert.match(messages({ title: 'Rye', seo: { description: 'd'.repeat(161) } })[0], /161 characters long. Search results show about 160/);
    assert.deepEqual(messages({ title: 'Rye', seo: { noindex: true } }).length, 1, 'a hidden page is not asked for a description');
});

test('hidden pages and canonical addresses elsewhere are pointed out', () => {
    assert.match(messages({ title: 'Rye', seo: { noindex: true } })[0], /Hidden from search engines/);
    assert.match(messages({ title: 'Rye', excerpt: 'x', seo: { canonical: 'https://medium.com/@ana/rye' } })[0], /points to medium.com/);
    assert.match(messages({ title: 'Rye', excerpt: 'x', seo: { canonical: 'not an address' } })[0], /points to not an address/, 'shown as typed');
});

test('every issue is a warning that points at no block', () => {
    for (const issue of checkSeo({ hasSeo: true, title: 'A'.repeat(80), seo: { noindex: true, canonical: 'https://x.test/' } }, t))
        assert.deepEqual([issue.tone, issue.id], ['warning', null]);
});
