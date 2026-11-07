import { test } from 'node:test';
import assert from 'node:assert/strict';
import { documentTitle } from '../../core/resources/js/app/document-title.js';

const page = (props) => ({ props: { site: { name: 'Bakery' }, ...props } });

test('a title is followed by the site name, as the server writes it', () => {
    assert.equal(documentTitle('Rye', page({ title: 'Rye' })), 'Rye - Bakery');
    assert.equal(documentTitle('', page({})), 'Bakery');
    assert.equal(documentTitle('Rye', page({ title: 'Rye' }), true), 'Rye');
    assert.equal(documentTitle('Rye', { props: {} }), 'Rye', 'no site name: the title alone');
});

test("the page's own title gives way to its search title", () => {
    const p = page({ title: 'Rye', seo: { title: 'Rye bread in three days' } });
    assert.equal(documentTitle('Rye', p), 'Rye bread in three days - Bakery');
    assert.equal(documentTitle('About', p), 'About - Bakery', 'another title is kept as given');
    assert.equal(documentTitle('Rye', page({ title: 'Rye', seo: { title: '' } })), 'Rye - Bakery', 'an empty search title changes nothing');
});
