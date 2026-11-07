import { test } from 'node:test';
import assert from 'node:assert/strict';
import { whenRow } from '../../core/resources/js/components/content/timetable.js';

const t = (s) => s;
const format = (iso) => `at ${iso}`;

test('a draft goes live when it is published', () => {
    assert.deepEqual(whenRow({ live: false, future: false, publishedAt: null }, t, format), {
        label: 'Goes live',
        value: 'When you publish',
        scheduled: false,
    });
    assert.equal(
        whenRow({ live: false, future: false, publishedAt: '2020-01-01T10:00:00Z' }, t, format).value,
        'When you publish',
        'a time already past publishes now',
    );
});

test('a time still to come is shown, marked as scheduled', () => {
    assert.deepEqual(whenRow({ live: false, future: true, publishedAt: '2030-05-01T09:00:00Z' }, t, format), {
        label: 'Goes live',
        value: 'at 2030-05-01T09:00:00Z',
        scheduled: true,
    });
});

test('a live page says since when', () => {
    assert.deepEqual(whenRow({ live: true, future: false, publishedAt: '2026-11-01T08:00:00Z' }, t, format), {
        label: 'Live since',
        value: 'at 2026-11-01T08:00:00Z',
        scheduled: false,
    });
    assert.equal(whenRow({ live: true, future: false, publishedAt: null }, t, format).value, 'Now');
});
