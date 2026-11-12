// Module resolution for SSR bundles, as synchronous in-thread hooks
// (node:module registerHooks).
//
// 1. Built pages import the bare specifiers "vue", "@kestello/app",
//    "@kestello/sdk" and "@kestello/ui" (externals). Each maps to one shared
//    instance, so every page, layout and the runtime use the same Vue,
//    injection keys and translations.
// 2. Node caches an imported module for the life of the process, by URL. A
//    theme or plugin rebuilt in place (assets/build/ssr/pages/Home.mjs) would
//    keep rendering its old code, and the browser would hydrate different
//    markup. Each of those modules is therefore keyed by its file's change
//    time: a rebuilt file gets a new URL and is loaded fresh. Core's own build
//    is left alone: the runtime must stay one instance, and a core update
//    arrives in a new release directory anyway.
import { statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const SSR_BUILD = '/assets/build/ssr/';

/**
 * @param {{ shared: Record<string, string>, core: string, stat?: (path: string) => { mtimeMs: number } | undefined }} options
 *   shared: bare specifier → file URL; core: file URL prefix of core's own SSR build
 * @returns {(specifier: string, context: object, next: Function) => { url: string, shortCircuit?: boolean }}
 */
export function createResolve({ shared, core, stat = (path) => statSync(path, { throwIfNoEntry: false }) }) {
    return function resolve(specifier, context, next) {
        if (Object.hasOwn(shared, specifier) && shared[specifier]) {
            return { url: shared[specifier], shortCircuit: true };
        }
        const result = next(specifier, context);
        const versioned = version(result.url, core, stat);
        return versioned === result.url ? result : { ...result, url: versioned };
    };
}

/** The URL with ?v=<change time> for a site's built SSR module; any other URL unchanged. */
export function version(url, core, stat) {
    if (!url.startsWith('file:') || url.startsWith(core)) return url;
    const bare = url.split(/[?#]/, 1)[0];
    if (!bare.endsWith('.mjs') || !bare.includes(SSR_BUILD)) return url;
    const info = stat(fileURLToPath(bare));
    return info ? `${bare}?v=${Math.trunc(info.mtimeMs)}` : url;
}
