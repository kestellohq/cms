import { test } from 'node:test';
import assert from 'node:assert/strict';
import { documentTitle, formatTitle } from '../../core/resources/js/app/document-title.js';

const titles = { page: '{title} {sep} {site}', home: '{site}', sep: '-', tagline: '' };
const page = (props, url = '/blog/rye') => ({ url, props: { site: { name: 'Bakery', titles }, ...props } });

test('the same cases as SeoTitleTest on the server', () => {
    assert.equal(formatTitle('{title} {sep} {site}', 'Rye', 'Bakery'), 'Rye - Bakery');
    assert.equal(formatTitle('{title} {sep} {site}', 'Rye', 'Bakery', '|'), 'Rye | Bakery');
    assert.equal(formatTitle('{site} {sep} {tagline}', '', 'Bakery', '·', 'Bread, daily.'), 'Bakery · Bread, daily.');
    assert.equal(formatTitle('Read: {title}', 'Rye', 'Bakery'), 'Read: Rye');
    assert.equal(formatTitle('{site} {sep} {tagline}', '', 'Bakery', '-', ''), 'Bakery');
    assert.equal(formatTitle('{title} {sep} {site}', '', 'Bakery'), 'Bakery');
    assert.equal(formatTitle('{title} {sep} {tagline} {sep} {site}', 'Rye', 'Bakery'), 'Rye - Bakery');
    assert.equal(formatTitle('{sep}', 'Rye', 'Bakery'), 'Rye');
    assert.equal(formatTitle('{title} {sep} {site}', 'Why {site} matters', 'Bakery'), 'Why {site} matters - Bakery');
    assert.equal(formatTitle('{title} {sep} {site}', 'A-B', 'Bakery'), 'A-B - Bakery');
    assert.equal(formatTitle('{title} {sep} {site}', 'Rye', 'Bakery', '|'), 'Rye | Bakery', 'a separator that means something in a regular expression');
});

test("a page's title follows the site's pattern; the home page uses its own", () => {
    assert.equal(documentTitle('Rye', page({ title: 'Rye' })), 'Rye - Bakery');
    assert.equal(documentTitle('About', page({ title: 'About' }, '/')), 'Bakery', 'the home page');
    assert.equal(documentTitle('', page({}, '/search?q=x')), 'Bakery', 'no title');
    assert.equal(documentTitle('Rye', page({ title: 'Rye' }), true), 'Rye', 'raw');
    const custom = { url: '/blog/rye', props: { title: 'Rye', site: { name: 'Bakery', titles: { ...titles, page: '{title} {sep} {site}', sep: '·' } } } };
    assert.equal(documentTitle('Rye', custom), 'Rye · Bakery');
});

test("the page's own title gives way to its search title", () => {
    const p = page({ title: 'Rye', seo: { title: 'Rye bread in three days' } });
    assert.equal(documentTitle('Rye', p), 'Rye bread in three days - Bakery');
    assert.equal(documentTitle('About', p), 'About - Bakery', 'another title is kept as given');
    assert.equal(documentTitle('Rye', page({ title: 'Rye', seo: { title: '' } })), 'Rye - Bakery', 'an empty search title changes nothing');
});

test('the admin, and pages without the patterns, keep "Title - Site"', () => {
    assert.equal(documentTitle('Users', page({ title: 'Users', admin: {} }, '/admin/users')), 'Users - Bakery');
    assert.equal(documentTitle('Rye', { props: { title: 'Rye', site: { name: 'Bakery' } } }), 'Rye - Bakery');
    assert.equal(documentTitle('Rye', { props: {} }), 'Rye', 'no site name: the title alone');
});
