import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

// Core and the Base theme style their own markup. Utility classes in the manner of a CSS framework
// (.btn, .container, .py-5) would make every theme define them for core's pages to look right.
const FRAMEWORK = ['btn', 'btn-primary', 'btn-outline-primary', 'container', 'py-5', 'text-secondary', 'd-flex', 'align-items-center', 'gap-3', 'flex-wrap'];

const files = (dir, ext) =>
    readdirSync(dir).flatMap((f) => {
        const path = join(dir, f);
        if (statSync(path).isDirectory()) return f === 'build' || f === 'vendor' ? [] : files(path, ext);
        return ext.test(f) ? [path] : [];
    });

test('core and the Base theme use no framework utility classes', () => {
    const found = [];
    for (const file of [...files('core/resources/js', /\.(vue|js)$/), ...files('content/themes/base/resources/js', /\.(vue|js)$/)]) {
        for (const [, list] of readFileSync(file, 'utf8').matchAll(/\bclass="([^"]*)"/g)) {
            for (const name of list.split(/\s+/)) if (FRAMEWORK.includes(name)) found.push(`${file}: class "${name}"`);
        }
    }
    assert.deepEqual(found, []);
});

test('the Base theme defines no framework utility classes', () => {
    const css = readFileSync('content/themes/base/assets/css/theme.css', 'utf8');
    const defined = FRAMEWORK.filter((name) => new RegExp(`\\.${name}(?![\\w-])`).test(css));
    assert.deepEqual(defined, []);
});
