/**
 * The two files of saved page HTML's hidden frame (/system/prerender), built
 * into core/resources/assets/build/prerender/ after core's own builds:
 *
 *   vue.js    Vue's runtime and server renderer as one module (the import
 *             map points `vue` and `vue/server-renderer` at it)
 *   frame.js  core/resources/js/prerender/frame.js, which draws waiting
 *             pages; Vue and @kestello/* stay external, as in the SSR build
 */
import { build } from 'vite';
import path from 'node:path';
import { readFileSync } from 'node:fs';

export async function buildPrerender(repo) {
    const src = path.join(repo, 'core/resources/js/prerender');
    const outDir = path.join(repo, 'core/resources/assets/build/prerender');
    const modules = path.join(repo, 'node_modules');
    const common = { configFile: false, logLevel: 'warn', root: src };
    const vueVersion = JSON.parse(readFileSync(path.join(modules, 'vue/package.json'), 'utf8')).version;

    await build({
        ...common,
        resolve: {
            alias: {
                vue: path.join(modules, 'vue/dist/vue.runtime.esm-bundler.js'),
                '@vue/server-renderer': path.join(modules, '@vue/server-renderer/dist/server-renderer.esm-bundler.js'),
            },
        },
        define: {
            'process.env.NODE_ENV': '"production"',
            __VUE_OPTIONS_API__: 'true',
            __VUE_PROD_DEVTOOLS__: 'false',
            __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
        },
        build: {
            outDir,
            emptyOutDir: true,
            minify: true,
            lib: { entry: path.join(src, 'vue.js'), formats: ['es'], fileName: () => 'vue.js' },
            rollupOptions: {
                output: {
                    banner: `/*! Vue v${vueVersion} with @vue/server-renderer | (c) 2018-present Yuxi (Evan) You and Vue contributors | @license MIT */`,
                },
            },
        },
    });

    await build({
        ...common,
        build: {
            outDir,
            emptyOutDir: false,
            minify: true,
            lib: { entry: path.join(src, 'frame.js'), formats: ['es'], fileName: () => 'frame.js' },
            rollupOptions: { external: (id) => id === 'vue' || id.startsWith('vue/') || id.startsWith('@kestello/') },
        },
    });
}
