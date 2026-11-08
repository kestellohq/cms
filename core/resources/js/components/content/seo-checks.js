/**
 * Search checks on an entry as it is being edited (unsaved values), shown
 * with the page checks in the editor's Checks tab. Concrete advice, not a
 * score: each issue says what search engines will do and where to change it.
 * Returns issues in the checks' shape: { tone, id: null, message }.
 */

/** About this many characters of a title fit in search results. */
export const TITLE_LENGTH = 60;
/** And this many of a description. */
export const DESCRIPTION_LENGTH = 160;

/**
 * @param {{ title?: string, excerpt?: string|null, seo?: { title?: string, description?: string, noindex?: boolean, canonical?: string } | null, hasSeo?: boolean }} entry
 * @param {Function} t the translation function
 */
export function checkSeo(entry, t) {
    if (!entry?.hasSeo) return [];
    const seo = entry.seo ?? {};
    const out = [];
    const warn = (message) => out.push({ tone: 'warning', id: null, message });
    const length = (s) => [...String(s ?? '').trim()].length;

    if (seo.noindex) warn(t('Hidden from search engines: this page will not appear in search results.'));

    const searchTitle = String(seo.title ?? '').trim();
    const title = searchTitle || String(entry.title ?? '').trim();
    if (length(title) > TITLE_LENGTH)
        warn(
            searchTitle
                ? t('The search title is {n} characters long. Search results show about {max}; the rest is cut off.', { n: length(title), max: TITLE_LENGTH })
                : t('The title is {n} characters long. Search results show about {max}: add a shorter search title under Search and sharing.', {
                      n: length(title),
                      max: TITLE_LENGTH,
                  }),
        );

    const description = String(seo.description ?? '').trim();
    if (!seo.noindex && !description && !String(entry.excerpt ?? '').trim())
        warn(t('No search description. Search engines will pick lines from the page; write one under Search and sharing.'));
    if (length(description) > DESCRIPTION_LENGTH)
        warn(t('The search description is {n} characters long. Search results show about {max}.', { n: length(description), max: DESCRIPTION_LENGTH }));

    const canonical = String(seo.canonical ?? '').trim();
    if (canonical) {
        let host = canonical;
        try {
            host = new URL(canonical).host;
        } catch {
            // shown as typed: the server refuses it on save
        }
        warn(t('The canonical address points to {host}: search engines will list that page instead of this one.', { host }));
    }
    return out;
}
