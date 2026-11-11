/**
 * The Kestello Vite preset (dev-only). Every package builds with it: core,
 * themes and plugins. A plugin's own vite.config.js (from
 * `bin/cms make:plugin <slug> --admin`) is just:
 *
 *   import { defineConfig } from 'vite';
 *   import { kestello } from '../../../core/build/preset.mjs';
 *   export default defineConfig(kestello({ root: import.meta.dirname }));
 *
 * and is picked up by `npm run build content/plugins/<slug>`. Add Vite
 * plugins or aliases by extending the returned object.
 *
 * What it guarantees:
 *   - entries are resources/js/{pages,layouts}/**\/*.vue (pages/admin/… for
 *     admin screens and extension components)
 *   - `vue` and `@kestello/{app,sdk,ui}` stay external, resolved by the page's
 *     import map, so every package shares one Vue and one SDK
 *   - output goes to assets/build/ with a Vite manifest the CMS reads (and
 *     assets/build/ssr/ for the optional Node renderer when ssr is true)
 *   - `import '@kestello/blocks.css'` brings core's block layout and design
 *     styles, for a page of a theme or plugin that draws a <BlockTree>
 *     (core's own Entry page imports them; a theme's replacement must too)
 */
import vue from '@vitejs/plugin-vue';
import { globSync } from 'node:fs';
import path from 'node:path';

export const EXTERNAL = [/^vue(\/.*)?$/, '@kestello/app', '@kestello/sdk', '@kestello/ui'];

/** Core's block styles, under one name for every package (see the header). */
export const BLOCKS_CSS = path.join(import.meta.dirname, '../resources/js/shared/block-layout.css');

/** name => file for every page and layout (plus core's shared modules). */
export function entries(root, { runtime = false } = {}) {
    const js = path.join(root, 'resources/js');
    const input = {};
    for (const file of globSync('{pages,layouts}/**/*.vue', { cwd: js })) {
        input[file.replace(/\.vue$/, '')] = path.join(js, file);
    }
    if (runtime) {
        // Shared modules: one copy for core, plugins and themes (import map).
        for (const name of ['app', 'sdk', 'ui']) input[`${name}/index`] = path.join(js, `${name}/index.js`);
    }
    return input;
}

/**
 * @param {{ root: string, outDir?: string, ssr?: boolean, runtime?: boolean, input?: Record<string, string> }} options
 */
export function kestello({ root, outDir, ssr = process.env.KESTELLO_SSR === '1', runtime = false, input } = {}) {
    if (!root) throw new Error('kestello(): pass { root: import.meta.dirname }');
    const out = outDir ?? path.join(root, 'assets/build');
    return {
        configFile: false,
        root,
        base: './',
        logLevel: 'warn',
        plugins: [vue()],
        resolve: { alias: { '@kestello/blocks.css': BLOCKS_CSS } },
        build: {
            outDir: ssr ? path.join(out, 'ssr') : out,
            emptyOutDir: true,
            manifest: true,
            ssr: ssr ? true : undefined,
            target: 'es2022',
            cssCodeSplit: true,
            rollupOptions: {
                input: input ?? entries(root, { runtime }),
                external: EXTERNAL,
                preserveEntrySignatures: 'exports-only',
                output: {
                    format: 'es',
                    entryFileNames: ssr ? '[name].mjs' : '[name]-[hash].js',
                    chunkFileNames: ssr ? 'chunks/[name]-[hash].mjs' : 'chunks/[name]-[hash].js',
                    assetFileNames: 'assets/[name]-[hash][extname]',
                },
            },
        },
    };
}
