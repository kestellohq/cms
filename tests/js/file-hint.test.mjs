import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DEFAULT_MAX_FILES, DEFAULT_MAX_MB, fileHint, fill, MOST_FILES } from '../../core/resources/js/shared/file-hint.js';

const words = { one: (p) => fill('Up to {size} MB.', p), many: (p) => fill('Up to {count} files, {size} MB each.', p) };

test('a file field says how large a file may be, and how many when several are allowed', () => {
    assert.equal(fileHint({ max_size: 12 }, words), 'Up to 12 MB.');
    assert.equal(fileHint({ max_size: 12, multiple: true, max: 3 }, words), 'Up to 3 files, 12 MB each.');
});

test('a field not saved yet shows the limits the server will apply, never "undefined"', () => {
    assert.equal(fileHint({}, words), 'Up to 5 MB.');
    assert.equal(fileHint({ max_size: null, multiple: true, max: '' }, words), 'Up to 5 files, 5 MB each.');
});

test('several files allowed but only one at most reads as one file', () => {
    assert.equal(fileHint({ max_size: 2, multiple: true, max: 1 }, words), 'Up to 2 MB.');
});

test('fill puts values into a translated sentence and leaves unknown names alone', () => {
    assert.equal(fill('{a} and {b}', { a: 1 }), '1 and {b}');
});

test("the defaults are the server's (FormsTest::testFileFieldsWithoutLimitsGetTheDefaults)", () => {
    assert.deepEqual([DEFAULT_MAX_MB, DEFAULT_MAX_FILES, MOST_FILES], [5, 5, 10]);
});
