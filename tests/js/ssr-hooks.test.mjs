import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createResolve, version } from '../../core/ssr/hooks.mjs';

const CORE = 'file:///site/core/resources/assets/build/ssr/';
const stat = (times) => (path) => (path in times ? { mtimeMs: times[path] } : undefined);

test('a site module in a built SSR folder is keyed by its change time', () => {
    const url = 'file:///site/content/themes/t/assets/build/ssr/pages/Home.mjs';
    assert.equal(version(url, CORE, stat({ '/site/content/themes/t/assets/build/ssr/pages/Home.mjs': 1700.9 })), `${url}?v=1700`);
});

test('an existing query is replaced, not added to', () => {
    const file = '/site/content/plugins/p/assets/build/ssr/chunks/A.mjs';
    assert.equal(version(`file://${file}?v=1`, CORE, stat({ [file]: 2 })), `file://${file}?v=2`);
});

test("core's own build, other files and missing files keep their URL", () => {
    const s = stat({});
    assert.equal(version(`${CORE}app/index.mjs`, CORE, stat({ '/site/core/resources/assets/build/ssr/app/index.mjs': 5 })), `${CORE}app/index.mjs`);
    assert.equal(version('file:///site/content/themes/t/src/x.mjs', CORE, s), 'file:///site/content/themes/t/src/x.mjs');
    assert.equal(version('file:///site/content/themes/t/assets/build/ssr/x.js', CORE, s), 'file:///site/content/themes/t/assets/build/ssr/x.js');
    assert.equal(version('file:///site/content/themes/t/assets/build/ssr/gone.mjs', CORE, s), 'file:///site/content/themes/t/assets/build/ssr/gone.mjs');
    assert.equal(version('node:fs', CORE, s), 'node:fs');
});

test('shared specifiers resolve to their one instance without asking further', () => {
    const resolve = createResolve({ shared: { vue: 'file:///vue.mjs', '@kestello/ui': '' }, core: CORE, stat: stat({}) });
    assert.deepEqual(
        resolve('vue', {}, () => assert.fail('next called')),
        { url: 'file:///vue.mjs', shortCircuit: true },
    );
    // an empty mapping falls through to the default resolver
    assert.deepEqual(
        resolve('@kestello/ui', {}, () => ({ url: 'file:///elsewhere.mjs', format: 'module' })),
        { url: 'file:///elsewhere.mjs', format: 'module' },
    );
});

test('a module rebuilt in place renders its new code without a restart', () => {
    const dir = mkdtempSync(join(tmpdir(), 'kestello-ssr-'));
    try {
        const build = join(dir, 'content/themes/t/assets/build/ssr/pages');
        mkdirSync(build, { recursive: true });
        const page = join(build, 'Home.mjs');
        writeFileSync(join(build, 'part.mjs'), 'export default "part one";');
        writeFileSync(page, 'import part from "./part.mjs"; export default "page one, " + part;');
        const hooks = new URL('../../core/ssr/hooks.mjs', import.meta.url).href;
        const script = `
            import { registerHooks } from 'node:module';
            import { writeFileSync, utimesSync } from 'node:fs';
            import { createResolve } from ${JSON.stringify(hooks)};
            registerHooks({ resolve: createResolve({ shared: {}, core: 'file:///nowhere/' }) });
            const url = ${JSON.stringify(pathToFileURL(page).href)};
            const first = (await import(url)).default;
            writeFileSync(${JSON.stringify(join(build, 'part.mjs'))}, 'export default "part two";');
            writeFileSync(${JSON.stringify(page)}, 'import part from "./part.mjs"; export default "page two, " + part;');
            const later = new Date(Date.now() + 5000);
            utimesSync(${JSON.stringify(page)}, later, later);
            utimesSync(${JSON.stringify(join(build, 'part.mjs'))}, later, later);
            const second = (await import(url)).default;
            console.log(JSON.stringify([first, second]));
        `;
        const out = execFileSync(process.execPath, ['--input-type=module', '-e', script], { encoding: 'utf8' });
        assert.deepEqual(JSON.parse(out), ['page one, part one', 'page two, part two']);
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
});
