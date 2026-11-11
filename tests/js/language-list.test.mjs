import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    add,
    badge,
    code,
    codes,
    dropped,
    localPath,
    prefixes,
    remove,
    setText,
    slots,
    texts,
} from '../../core/resources/js/components/languages/language-list.js';

test('codes are written as in addresses', () => {
    assert.equal(code(' pt_BR '), 'pt-br');
    assert.equal(code('español'), '');
    assert.deepEqual(codes('es, PT_br,,es, not a code'), ['es', 'pt-br']);
    assert.deepEqual(codes(''), []);
    assert.deepEqual(codes(null), []);
});

test('languages are added once, never the main one', () => {
    assert.equal(add('', 'es'), 'es');
    assert.equal(add('es', 'de'), 'es,de');
    assert.equal(add('es', 'ES'), 'es');
    assert.equal(add('es', 'en', 'en'), 'es', 'the main language is not another one');
    assert.equal(add('es', 'not a code'), 'es');
});

test('languages are removed', () => {
    assert.equal(remove('es,de', 'es'), 'de');
    assert.equal(remove('es', 'es'), '');
    assert.equal(remove('es', 'fr'), 'es');
});

test('removing a language with entries is pointed out', () => {
    const languages = [
        { code: '', name: 'English', entries: 9 },
        { code: 'es', name: 'Español', entries: 4 },
        { code: 'de', name: 'Deutsch', entries: 0 },
    ];
    assert.deepEqual(dropped('es,de', 'de', languages), [languages[1]]);
    assert.deepEqual(dropped('es,de', 'es', languages), [], 'no entries in German: nothing to warn about');
    assert.deepEqual(dropped('es', 'es,fr', languages), []);
});

test("an entry's translations are listed per language", () => {
    const languages = [
        { code: '', name: 'English' },
        { code: 'es', name: 'Español' },
        { code: 'de', name: 'Deutsch' },
    ];
    const es = { locale: 'es', id: 7, status: 'draft', title: 'Sobre' };
    assert.deepEqual(slots({ locale: '', translations: [es] }, languages), [
        { code: 'es', name: 'Español', translation: es },
        { code: 'de', name: 'Deutsch', translation: null },
    ]);
    assert.deepEqual(
        slots({ locale: 'es', translations: [] }, languages).map((s) => s.code),
        ['', 'de'],
        'from a translation, the main language is one of the others',
    );
    assert.equal(badge('es'), 'ES');
    assert.equal(badge('', 'en'), 'EN');
});

test('the site name and tagline per language are one setting', () => {
    assert.deepEqual(texts(''), {});
    assert.deepEqual(texts('not json'), {});
    let value = setText('', 'es', 'site_name', 'Taller');
    assert.deepEqual(texts(value), { es: { site_name: 'Taller' } });
    value = setText(value, 'es', 'site_description', 'Arquitectura');
    value = setText(value, 'de', 'site_name', 'Werkstatt');
    assert.deepEqual(texts(value), { es: { site_name: 'Taller', site_description: 'Arquitectura' }, de: { site_name: 'Werkstatt' } });
    value = setText(value, 'de', 'site_name', '  ');
    assert.deepEqual(Object.keys(texts(value)), ['es'], 'an emptied language goes');
    assert.equal(setText(setText(value, 'es', 'site_name', ''), 'es', 'site_description', ''), '', 'nothing left: empty');
});

test('the prefixes a language can translate', () => {
    const types = [
        { name: 'page', plural: 'Pages', path: '' },
        { name: 'project', plural: 'Projects', path: '/projects' },
        { name: 'post', plural: 'Posts', path: '/blog' },
        { name: 'hidden', plural: 'Hidden', path: null },
    ];
    const taxonomies = [
        { name: 'category', plural: 'Categories', path: '/blog/category' },
        { name: 'dup', plural: 'Again', path: '/blog' },
    ];
    assert.deepEqual(prefixes(types, taxonomies), [
        { path: '/blog', label: 'Posts' },
        { path: '/projects', label: 'Projects' },
        { path: '/blog/category', label: 'Categories' },
    ]);
    assert.deepEqual(prefixes(null, undefined), []);
    assert.deepEqual(texts(setText('', 'es', '/projects', 'proyectos')), { es: { '/projects': 'proyectos' } }, 'paths are stored like texts');
});

test('a prefix in a language with its own word', () => {
    const paths = { '/blog': '/noticias', '/blog/category': '/categoria' };
    assert.equal(localPath('/blog/', paths), '/noticias/');
    assert.equal(localPath('/blog', paths), '/noticias');
    assert.equal(localPath('/blog/category/x', paths), '/categoria/x', 'the longest first');
    assert.equal(localPath('/blogroll/', paths), '/blogroll/', 'a whole segment only');
    assert.equal(localPath('/blog/', undefined), '/blog/');
});
