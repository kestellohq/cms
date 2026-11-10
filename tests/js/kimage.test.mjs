import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pictureSources } from '../../core/resources/js/app/index.js';

const image = (name, formats = ['avif', 'webp', 'jpeg']) => ({
    url: `/media/${name}.png`,
    sizes: { large: { src: `/media/${name}-l.jpeg`, srcset: Object.fromEntries(formats.map((f) => [f, `/media/${name}-l.${f} 1280w`])) } },
});
const keys = (sources) => sources.map((s) => s.key);

test('a light image offers AVIF and WebP; its JPEG is the img', () => {
    const sources = pictureSources(image('shot'), null, 'large', '100vw');

    assert.deepEqual(keys(sources), ['avif', 'webp']);
    assert.deepEqual(sources[0].attrs, { type: 'image/avif', srcset: '/media/shot-l.avif 1280w', sizes: '100vw' });
});

test('the dark image comes first, every format behind the dark media query', () => {
    const sources = pictureSources(image('shot'), image('shot-dark'), 'large', '50vw');

    assert.deepEqual(keys(sources), ['dark-avif', 'dark-webp', 'dark-jpeg', 'avif', 'webp']);
    assert.deepEqual(sources[2].attrs, { media: '(prefers-color-scheme: dark)', type: 'image/jpeg', srcset: '/media/shot-dark-l.jpeg 1280w', sizes: '50vw' });
    assert.equal(sources[3].attrs.media, undefined, 'the light sources match any scheme');
});

test('a dark image without variants is offered as it is', () => {
    const sources = pictureSources(image('shot'), { url: '/media/dark.gif' }, 'large', '100vw');

    assert.deepEqual(sources[0], { key: 'dark', attrs: { media: '(prefers-color-scheme: dark)', srcset: '/media/dark.gif' } });
});

test('without variants or a dark image there is nothing to add', () => {
    assert.deepEqual(pictureSources({ url: '/media/a.gif' }, null, 'large', '100vw'), []);
    assert.deepEqual(pictureSources(image('shot'), { url: '' }, 'large', '100vw').length, 2, 'an empty dark image is ignored');
});
