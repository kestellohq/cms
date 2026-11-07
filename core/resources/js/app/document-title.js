/**
 * The text of a page's <title>, the same as the server writes it
 * (Seo::documentTitle, SeoTitle::format). The site's title pattern
 * (Settings → Search and sharing) is shared as site.titles; the home page
 * and a page without a title use its home pattern. The page's own title
 * gives way to its search title; another title (a theme page's "About")
 * is kept as given. raw skips the pattern. The admin always shows
 * "Title - Site".
 *
 * @param {string} title what <Head title> was given
 * @param {{ url?: string, props?: Record<string, any> } | null} page
 * @param {boolean} [raw]
 * @returns {string}
 */
export function documentTitle(title, page, raw = false) {
    const props = page?.props ?? {};
    const site = props.site?.name ?? '';
    const own = props.seo?.title;
    const shown = own && title === props.title ? own : title;
    if (raw || !site) return shown || site;
    const titles = props.site?.titles;
    if (props.admin || !titles) return shown ? `${shown} - ${site}` : site;
    const path = String(page?.url ?? '/').split(/[?#]/)[0];
    const home = path === '/' || !shown;
    return formatTitle(home ? titles.home : titles.page, shown, site, titles.sep, titles.tagline);
}

/** SeoTitle::format: placeholders in one pass; an empty one takes its separator with it. */
export function formatTitle(pattern, title, site, sep = '-', tagline = '') {
    const values = { title, site, sep, tagline: tagline ?? '' };
    let out = String(pattern ?? '')
        .replace(/\{(title|site|sep|tagline)\}/g, (_, key) => values[key])
        .replace(/\s+/g, ' ')
        .trim();
    if (sep) {
        const q = sep.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
        let before;
        do {
            before = out;
            out = out
                .replace(new RegExp(`^${q}\\s*`), '')
                .replace(new RegExp(`\\s*${q}$`), '')
                .replace(new RegExp(`${q}\\s*${q}`, 'g'), sep)
                .trim();
        } while (out !== before);
    }
    return out || title || site;
}
