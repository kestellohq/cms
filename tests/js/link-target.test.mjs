import { test } from 'node:test';
import assert from 'node:assert/strict';
import { looksLikeAddress, wantsSuggestions, linkOptions } from '../../core/resources/js/ui/link-target.js';

test('addresses are left alone', () => {
    for (const a of [
        '/about',
        '#top',
        '?page=2',
        'https://example.com',
        'HTTP://x.y',
        'mailto:hi@example.com',
        'tel:+3611234567',
        'example.com',
        'www.example.org/menu',
    ]) {
        assert.equal(looksLikeAddress(a), true, a);
        assert.equal(wantsSuggestions(a), false, a);
    }
});

test('words look pages up', () => {
    for (const w of ['contact', 'About us', 'Our story', 'opening hours 2.0 info']) {
        assert.equal(looksLikeAddress(w), false, w);
        assert.equal(wantsSuggestions(w), true, w);
    }
    assert.equal(wantsSuggestions('a'), false, 'one letter is too little');
    assert.equal(wantsSuggestions('   '), false);
    assert.equal(wantsSuggestions(null), false);
});

test('lookup rows become options by address', () => {
    const rows = [
        { id: 1, title: 'Contact', type_label: 'Page', path: '/contact' },
        { id: 2, title: 'A note', type_label: 'Note', path: null },
        { id: 3, title: '', type_label: 'Post', path: '/blog/x' },
    ];
    assert.deepEqual(linkOptions(rows), [
        { id: '/contact', label: 'Contact', meta: 'Page · /contact' },
        { id: '/blog/x', label: '/blog/x', meta: 'Post · /blog/x' },
    ]);
    assert.deepEqual(linkOptions(undefined), []);
});
