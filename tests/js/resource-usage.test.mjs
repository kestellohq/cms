import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

// useResource() already unwraps the answer: .data.value is the list, .meta.value the meta.
// Reading .data.value.data (or .meta) is always undefined: the Forms and Content types
// screens once showed nothing this way.
const files = (dir) =>
    readdirSync(dir).flatMap((f) => (statSync(join(dir, f)).isDirectory() ? files(join(dir, f)) : /\.(vue|js)$/.test(f) ? [join(dir, f)] : []));

test('no page reads data or meta inside an unwrapped resource', () => {
    const bad = files('core/resources/js').flatMap((f) =>
        readFileSync(f, 'utf8')
            .split('\n')
            .map((line, i) => (/\.data\.value\??\.(data|meta)\b/.test(line) ? `${f}:${i + 1}` : null))
            .filter(Boolean),
    );
    assert.deepEqual(bad, []);
});
