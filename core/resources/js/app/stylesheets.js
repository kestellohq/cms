/**
 * Which stylesheets a page keeps when the app moves to another page.
 *
 * Every stylesheet Kestello adds is a <link data-kestello-css>. Two kinds:
 *
 * - a page's and its layout's, which belong to that page and are swapped on
 *   navigation;
 * - a block's or an extension's (data-kestello-keep), added once when the
 *   component first loads. Components are loaded once and cached, so their
 *   stylesheet would never come back if it were dropped: these stay.
 *
 * A stylesheet both kinds use is kept.
 */

/** The stylesheets of registered components, in order, without repeats. */
export function stylesFor(registry, ...names) {
    const urls = [];
    for (const name of names) {
        for (const url of registry?.[name]?.css ?? []) {
            if (!urls.includes(url)) urls.push(url);
        }
    }
    return urls;
}

/**
 * The links to drop when the next page needs `wanted`.
 *
 * @param {{ href: string, keep: boolean }[]} links the data-kestello-css links on the page
 * @param {string[]} wanted the next page's and layout's stylesheets
 * @returns {string[]} their hrefs
 */
export function staleStylesheets(links, wanted) {
    return links.filter((link) => !link.keep && !wanted.includes(link.href)).map((link) => link.href);
}
