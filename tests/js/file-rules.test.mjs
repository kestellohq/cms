import { test } from 'node:test';
import assert from 'node:assert/strict';
import { acceptedKinds, acceptedLabel, fileProblem } from '../../core/resources/js/ui/file-rules.js';

const file = (name, size = 10, type = '') => ({ name, size, type });

test('acceptedKinds and acceptedLabel read an accept value the way the file chooser does', () => {
    assert.deepEqual(acceptedKinds(' .ZIP, application/zip ,'), ['.zip', 'application/zip']);
    assert.equal(acceptedLabel('.csv,text/csv,.txt,.CSV'), 'CSV, TXT');
    assert.equal(acceptedLabel('image/*'), '', 'only media types: nothing short to show');
    assert.deepEqual(acceptedKinds(''), []);
});

test('fileProblem accepts a matching extension or media type, in any case', () => {
    assert.equal(fileProblem(file('Export.XML'), '.xml,text/xml'), null);
    assert.equal(fileProblem(file('export', 10, 'text/xml'), '.xml,text/xml'), null, 'no extension, but the type matches');
    assert.equal(fileProblem(file('anything.bin'), ''), null, 'no accept: anything goes');
});

test('fileProblem refuses the wrong type before checking the size', () => {
    assert.deepEqual(fileProblem(file('notes.txt', 999), '.xml', 10), { reason: 'type' });
    assert.deepEqual(fileProblem(file('.xml'), '.xml'), { reason: 'type' }, 'a dotfile has no extension');
    assert.deepEqual(fileProblem(file('backup.zip.txt'), '.zip'), { reason: 'type' }, 'the last extension counts');
});

test('fileProblem refuses a file over the limit, and 0 means no limit', () => {
    assert.deepEqual(fileProblem(file('a.zip', 11), '.zip', 10), { reason: 'size', size: 11, max: 10 });
    assert.equal(fileProblem(file('a.zip', 10), '.zip', 10), null, 'exactly the limit is fine');
    assert.equal(fileProblem(file('a.zip', 1e12), '.zip', 0), null);
});
