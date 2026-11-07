import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../../core/resources/js/pages/admin/', import.meta.url));
const files = (dir) => readdirSync(dir).flatMap((n) => (statSync(join(dir, n)).isDirectory() ? files(join(dir, n)) : n.endsWith('.vue') ? [join(dir, n)] : []));

// A table's empty title is short and bold, like every other empty state; what to do next goes in empty-text.
test('empty tables have a short title, with the explanation under it', () => {
    const long = [];
    for (const file of files(ROOT)) {
        const source = readFileSync(file, 'utf8');
        for (const [, attr] of source.matchAll(/\s:empty="([^"]*)"/g)) {
            for (const [, text] of attr.matchAll(/__\('((?:[^'\\]|\\.)*)'/g)) {
                if (/[.!?]\s+\S/.test(text)) long.push(`${file.slice(ROOT.length)}: ${text}`);
            }
        }
    }
    assert.deepEqual(long, []);
});
