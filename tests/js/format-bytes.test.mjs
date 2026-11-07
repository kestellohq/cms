import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatBytes } from '../../core/resources/js/sdk/bytes.js';

test('small files say their size in bytes, not a rounded-up kilobyte', () => {
    assert.equal(formatBytes(0), '0 B');
    assert.equal(formatBytes(20), '20 B');
    assert.equal(formatBytes(1023), '1,023 B');
});

test('larger sizes keep one decimal while it matters', () => {
    assert.equal(formatBytes(1536), '1.5 KB');
    assert.equal(formatBytes(1024), '1 KB');
    assert.equal(formatBytes(12.4 * 1024 * 1024), '12 MB');
    assert.equal(formatBytes(3 * 1024 ** 3), '3 GB');
});

test('numbers follow the admin language', () => {
    assert.equal(formatBytes(1536, 'de'), '1,5 KB');
    assert.equal(formatBytes(5 * 1024 ** 5, 'en'), '5,120 TB');
});

test('a size sent as a numeric string (database drivers do) still reads as a size', () => {
    assert.equal(formatBytes('2048'), '2 KB');
});

test('nothing sensible to show is a dash', () => {
    for (const v of [undefined, null, NaN, -1, 'big', '']) assert.equal(formatBytes(v), '—');
});
