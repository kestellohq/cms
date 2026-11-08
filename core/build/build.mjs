#!/usr/bin/env node
/**
 * Build Vue pages for core, themes and plugins (dev-only tool).
 *
 *   node core/build/build.mjs                 # core + every package with resources/js
 *   node core/build/build.mjs core            # just core
 *   node core/build/build.mjs content/themes/aurora /path/to/my-plugin   # any folder, also outside this repo
 *
 * For each target:  resources/js/{pages,layouts}/**\/*.vue  →  assets/build/
 * (core: core/resources/js → core/resources/assets/build, plus the runtime).
 * `vue` and `@kestello/{app,sdk,ui}` stay external: the page shell's import map points
 * them at the single vendored runtime, so every package shares one Vue.
 * A second SSR build (assets/build/ssr/) feeds the optional Node renderer and,
 * with assets/build/prerender/ (prerender.mjs), saved page HTML.
 */
import { build } from 'vite';
import { existsSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { entries, kestello } from './preset.mjs';
import { buildPrerender } from './prerender.mjs';

const repo = path.resolve(import.meta.dirname, '../..');

function discover() {
    const targets = ['core'];
    for (const parent of ['content/themes', 'content/plugins']) {
        const abs = path.join(repo, parent);
        if (!existsSync(abs)) continue;
        for (const name of readdirSync(abs)) {
            if (existsSync(path.join(abs, name, 'resources/js'))) targets.push(path.join(parent, name));
        }
    }
    return targets;
}

function layout(target) {
    if (target === 'core') {
        return { dir: path.join(repo, 'core'), outDir: path.join(repo, 'core/resources/assets/build'), runtime: true };
    }
    const dir = path.resolve(repo, target);
    return { dir, outDir: path.join(dir, 'assets/build'), runtime: false };
}

/** The package's own vite.config.js (built on the preset) or the preset itself. */
async function configFor(spec, ssr) {
    const own = path.join(spec.dir, 'vite.config.js');
    if (spec.runtime || !existsSync(own)) {
        return kestello({ root: spec.dir, outDir: spec.outDir, ssr, runtime: spec.runtime });
    }
    process.env.KESTELLO_SSR = ssr ? '1' : '0';
    const mod = await import(`${pathToFileURL(own).href}?t=${Date.now()}`);
    const config = typeof mod.default === 'function' ? await mod.default({ command: 'build', mode: 'production', isSsrBuild: ssr }) : mod.default;
    return { ...config, configFile: false };
}

const targets = process.argv.slice(2).length ? process.argv.slice(2) : discover();
let failed = false;

for (const target of targets) {
    const spec = layout(target);
    const count = Object.keys(entries(spec.dir, { runtime: spec.runtime })).length;

    if (count === 0) {
        console.log(`- ${target}: no pages`);
        continue;
    }

    try {
        await build(await configFor(spec, false));
        await build(await configFor(spec, true));
        if (spec.runtime) await buildPrerender(repo);
        console.log(`✓ ${target}: ${count} entries → ${path.relative(repo, spec.outDir)}`);
    } catch (error) {
        failed = true;
        console.error(`✗ ${target}: ${error.message}`);
    }
}

process.exit(failed ? 1 : 0);
