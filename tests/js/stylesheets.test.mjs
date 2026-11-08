import { test } from 'node:test';
import assert from 'node:assert/strict';
import { staleStylesheets, stylesFor } from '../../core/resources/js/app/stylesheets.js';

test('moving to another page drops the old page stylesheets but keeps those of blocks', () => {
    const links = [
        { href: '/a/Entry.css', keep: false },
        { href: '/a/Login.css', keep: false },
        { href: '/a/Cover.css', keep: true },
        { href: '/a/Button.css', keep: true },
    ];
    // the Home page (Cover) to About (Button only): Cover stays, or it is gone when Home comes back
    assert.deepEqual(staleStylesheets(links, ['/a/Entry.css']), ['/a/Login.css']);
});

test('the same page again drops nothing', () => {
    const links = [
        { href: '/a/Entry.css', keep: false },
        { href: '/a/Cover.css', keep: true },
    ];
    assert.deepEqual(staleStylesheets(links, ['/a/Entry.css']), []);
});

test('a stylesheet a page needs is never dropped', () => {
    assert.deepEqual(staleStylesheets([{ href: '/a/Shared.css', keep: false }], ['/a/Shared.css']), []);
    assert.deepEqual(staleStylesheets([], ['/a/Entry.css']), []);
});

test('stylesFor lists a page and its layout without repeats', () => {
    const registry = { page: { css: ['/a/x.css', '/a/y.css'] }, layout: { css: ['/a/y.css', '/a/z.css'] }, bare: {} };
    assert.deepEqual(stylesFor(registry, 'page', 'layout', 'bare', 'missing'), ['/a/x.css', '/a/y.css', '/a/z.css']);
});
