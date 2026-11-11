import { test } from 'node:test';
import assert from 'node:assert/strict';
import { credit, photosOf, plateNumber, plateRows, roomName, roomOf, talkDate } from '../../content/themes/exhibit/resources/js/components/rooms.js';

// a translate function that marks what it translated and fills {placeholders}
const __ = (text, values = {}) => `«${text.replace(/\{(\w+)\}/g, (_, k) => values[k])}»`;

test('each page hangs in a room', () => {
    assert.equal(roomOf('Home'), 'projects');
    assert.equal(roomOf('@core/content/Entry', { type: { name: 'projects' } }), 'projects');
    assert.equal(roomOf('@core/content/Archive', { type: { name: 'talks' } }), 'talks');
    assert.equal(roomOf('@core/content/Entry', { type: { name: 'post' } }), 'journal');
    assert.equal(roomOf('@core/content/Entry', { type: { name: 'team' } }), 'studio');
    assert.equal(roomOf('@core/content/Entry', { type: { name: 'page' }, entry: { path: '/es/studio' } }), 'studio');
    assert.equal(roomOf('@core/content/Entry', { type: { name: 'page' }, entry: { path: '/contact' } }), 'hall');
    assert.equal(roomOf('@core/content/Archive', { type: { name: 'category' } }), 'journal', 'categories are read in the journal');
    assert.equal(roomOf('@core/auth/Login'), 'hall');
});

test('rooms the theme knows are named in the page language', () => {
    assert.equal(roomName('post', 'Posts', __), '«Journal»');
    assert.equal(roomName('events', 'Events', __), 'Events', 'a type the theme does not know keeps its own name');
});

test('a label plate prints the fields it knows first, then the others', () => {
    const rows = plateRows(
        { number: 7, year: 2024, place: 'Valencia', use: 'housing', area: 1240, status: 'study', client_kind: 'private', photos: [3], blank: '' },
        __,
    );
    assert.deepEqual(
        rows.map((r) => [r.label, r.value]),
        [
            ['«Place»', 'Valencia'],
            ['«Year»', '2024'],
            ['«Use»', '«Housing»'],
            ['«Area»', `«${(1240).toLocaleString()} m²»`],
            ['«Status»', '«Study»'],
            ['Client kind', 'Private'],
        ],
        'in plate order; lists, empty values and the number left out',
    );
    assert.deepEqual(plateRows(null, __), []);
    assert.deepEqual(
        plateRows({ summary: 'x'.repeat(81), client: 'Ruiz family' }, __).map((r) => r.key),
        ['client'],
        'long texts belong to the page',
    );
});

test('the catalogue number and the credit', () => {
    assert.equal(plateNumber({ number: 7 }, __), '«Cat. 07»');
    assert.equal(plateNumber({ number: '112' }, __), '«Cat. 112»');
    assert.equal(plateNumber({}, __), '');
    assert.equal(credit({ caption: ' Photo: Ana Ruiz, CC0 ' }), 'Photo: Ana Ruiz, CC0');
    assert.equal(credit(null), '');
});

test('a talk date reads as wall text, and knows when it is past', () => {
    const d = talkDate('2026-03-12T18:30:00Z', 'en-GB', new Date('2026-03-01T00:00:00Z'));
    assert.equal(d.day, '12 March');
    assert.equal(d.past, false);
    assert.equal(talkDate('2026-03-12T18:30:00Z', 'en-GB', new Date('2026-04-01T00:00:00Z')).past, true);
    assert.equal(talkDate('not a date', 'en'), null);
    assert.equal(talkDate(null, 'en'), null);
});

test('every photograph an entry carries, each once', () => {
    const a = { id: 1, url: '/a.webp', kind: 'image' };
    const b = { id: 2, url: '/b.webp', kind: 'image' };
    const pdf = { id: 3, url: '/c.pdf', kind: 'document' };
    assert.deepEqual(photosOf({ cover: a, photos: [a, b, pdf], seo: { image: b }, year: 2024 }), [a, b]);
    assert.deepEqual(photosOf(null), []);
});
