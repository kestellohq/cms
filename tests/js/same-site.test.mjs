import { test } from 'node:test';
import assert from 'node:assert/strict';
import { endedElsewhere } from '../../core/resources/js/app/same-site.js';

test('a navigation redirected to another site is not read as a page here', () => {
    const origin = 'https://bakery.test';
    assert.equal(endedElsewhere({ redirected: true, url: 'https://shop.example/cart' }, origin), true);
    assert.equal(endedElsewhere({ redirected: true, url: 'http://bakery.test/about' }, origin), true, 'another scheme is another origin');
    assert.equal(endedElsewhere({ redirected: true, url: 'https://bakery.test:8443/about' }, origin), true, 'another port too');
    assert.equal(endedElsewhere({ redirected: true, url: 'https://bakery.test/new-address' }, origin), false, 'a redirect on this site');
    assert.equal(endedElsewhere({ redirected: false, url: 'https://bakery.test/about' }, origin), false);
    assert.equal(endedElsewhere({ redirected: true, url: '' }, origin), false);
    assert.equal(endedElsewhere({ redirected: true, url: 'not a url' }, origin), true, 'unreadable: treated as elsewhere');
});
