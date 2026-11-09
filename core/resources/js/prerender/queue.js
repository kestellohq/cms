/**
 * The pure parts of drawing waiting pages to HTML (saved page HTML): which
 * page to draw next, and the registry the renderer gets for it. No DOM, no
 * network, so tests/js runs them as they are.
 */

/** At most this many pages per run, so one admin visit never works for long. */
export const MAX_PER_RUN = 25;

/**
 * The next page to draw: the first one not tried in this run (a page that
 * fails stays waiting, and must not be tried again and again).
 *
 * @param {{ key: string }[]} pages as GET /system/prerender lists them
 * @param {Set<string>} tried keys drawn or failed in this run
 */
export function nextPage(pages, tried) {
    return (pages ?? []).find((page) => page && typeof page.key === 'string' && !tried.has(page.key)) ?? null;
}

/**
 * The renderer's registry for a page: every component's SSR module.
 * Stylesheets are left out: the renderer draws HTML, it shows nothing.
 *
 * @param {Record<string, string>} modules name => address of its SSR build
 * @returns {Record<string, { file: string, css: string[] }>}
 */
export function registryFor(modules) {
    const out = {};
    for (const [name, file] of Object.entries(modules ?? {})) {
        if (typeof file === 'string' && file.startsWith('/system/prerender/modules/')) out[name] = { file, css: [] };
    }
    return out;
}
