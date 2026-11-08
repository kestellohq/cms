import { test } from 'node:test';
import assert from 'node:assert/strict';
import { nextPage, registryFor } from '../../core/resources/js/prerender/queue.js';
import { hasWork } from '../../core/resources/js/prerender/background.js';

test('the next page is the first not tried in this run', () => {
    const pages = [{ key: 'a' }, { key: 'b' }];
    assert.equal(nextPage(pages, new Set()).key, 'a');
    assert.equal(nextPage(pages, new Set(['a'])).key, 'b');
    assert.equal(nextPage(pages, new Set(['a', 'b'])), null, 'a page that failed is not tried again');
    assert.equal(nextPage(undefined, new Set()), null);
    assert.equal(nextPage([null, { key: 1 }, { key: 'c' }], new Set()).key, 'c');
});

test('the registry holds only the SSR modules the server lists', () => {
    const registry = registryFor({
        '@core/blocks/Buttons': '/system/prerender/modules/core/~1/build/ssr/pages/blocks/Buttons.mjs',
        evil: 'https://example.com/x.mjs',
        odd: 7,
    });
    assert.deepEqual(registry, { '@core/blocks/Buttons': { file: '/system/prerender/modules/core/~1/build/ssr/pages/blocks/Buttons.mjs', css: [] } });
    assert.deepEqual(registryFor(undefined), {});
});

test('the frame opens only when pages wait', () => {
    assert.equal(hasWork({ enabled: true, waiting: 3 }), true);
    assert.equal(hasWork({ enabled: true, waiting: 0 }), false);
    assert.equal(hasWork({ enabled: false, waiting: 3 }), false);
    assert.equal(hasWork(undefined), false);
});
