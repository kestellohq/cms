/**
 * The text of a page's <title>, the same as the server writes it
 * (PageRenderer): "Title - Site". The page's own title gives way to its
 * search title from Search and sharing; another title (a theme page's
 * "About") is kept as given. raw skips the site name.
 *
 * @param {string} title what <Head title> was given
 * @param {{ props?: { title?: string, site?: { name?: string }, seo?: { title?: string } } } | null} page
 * @param {boolean} [raw]
 * @returns {string}
 */
export function documentTitle(title, page, raw = false) {
    const site = page?.props?.site?.name ?? '';
    const own = page?.props?.seo?.title;
    const shown = own && title === page?.props?.title ? own : title;
    if (raw || !site) return shown || site;
    return shown ? `${shown} - ${site}` : site;
}
