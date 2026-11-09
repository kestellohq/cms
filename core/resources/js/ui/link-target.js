/**
 * Link fields take an address (/about, https://…, mailto:, tel:, #top) or,
 * when someone types words instead, offer the site's pages by title. These
 * decide which: pure, so they are tested on their own.
 */

/** Text that is already an address, so there is nothing to look up. */
export function looksLikeAddress(text) {
    const t = String(text ?? '').trim();
    if (t === '') return false;
    if (/^[/#?]/.test(t)) return true;
    if (/^(mailto|tel):/i.test(t) || /^[a-z][a-z0-9+.-]*:\/\//i.test(t)) return true;
    // example.com, www.example.org/page: a dot, no spaces
    return !/\s/.test(t) && /^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}(\/|$)/i.test(t);
}

/** Look pages up for this text? Words of two letters or more that aren't an address. */
export function wantsSuggestions(text) {
    const t = String(text ?? '').trim();
    return t.length >= 2 && !looksLikeAddress(t);
}

/** Lookup rows as options: only entries with an address, labelled by title. */
export function linkOptions(rows) {
    return (Array.isArray(rows) ? rows : [])
        .filter((r) => r && typeof r.path === 'string' && r.path !== '')
        .map((r) => ({ id: r.path, label: r.title || r.path, meta: [r.type_label, r.path].filter(Boolean).join(' · ') }));
}
