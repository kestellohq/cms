/**
 * The admin sidebar's rules, kept apart from its markup so they can be
 * tested: which entry is the current page, which entries it shows, and
 * which groups are folded.
 */

/** Groups folded until someone opens them: tools most people visit rarely. */
export const FOLDED_BY_DEFAULT = Object.freeze(['system']);

/** Entries the sidebar leaves out because its footer already links there (the finder still lists them). */
export const IN_FOOTER = Object.freeze(['core.profile']);

/** Where the folded groups are remembered, per browser. */
export const STORAGE_KEY = 'kestello.nav.folded';

/** Whether a menu entry covers this path ("/admin" only itself, others their whole section). */
export function covers(item, path) {
    const match = String(item.match ?? item.href).replace(/\/$/, '');
    return match === '/admin' ? path === '/admin' : path === match || path.startsWith(match + '/');
}

/**
 * The one entry this page belongs to, and its group: the longest match
 * wins (Categories over Content). Links to other sites never count.
 *
 * @returns {{ group: object, item: object } | null}
 */
export function currentEntry(groups, path) {
    let best = null;
    for (const group of groups) {
        for (const item of group.items) {
            if (item.href.startsWith('https://') || !covers(item, path)) continue;
            if (best === null || String(item.match ?? item.href).length > String(best.item.match ?? best.item.href).length) {
                best = { group, item };
            }
        }
    }
    return best;
}

/** The groups as the sidebar shows them: without footer entries, and without groups left empty. */
export function visibleGroups(groups) {
    return groups.map((g) => ({ ...g, items: g.items.filter((i) => !IN_FOOTER.includes(i.id)) })).filter((g) => g.items.length > 0);
}

/** The folded group ids from storage; the defaults when nothing (or nothing valid) was stored. */
export function readFolded(raw) {
    if (typeof raw !== 'string') return [...FOLDED_BY_DEFAULT];
    try {
        const list = JSON.parse(raw);
        return Array.isArray(list)
            ? [...new Set(list.filter((id) => typeof id === 'string' && /^[a-z0-9][a-z0-9_-]{0,49}$/.test(id)))]
            : [...FOLDED_BY_DEFAULT];
    } catch {
        return [...FOLDED_BY_DEFAULT];
    }
}

/** Fold an open group, or open a folded one. */
export function toggleFolded(folded, id) {
    return folded.includes(id) ? folded.filter((g) => g !== id) : [...folded, id];
}

/** Opening a page opens its group: you went there, so it stays in view. */
export function unfold(folded, id) {
    return id && folded.includes(id) ? folded.filter((g) => g !== id) : folded;
}
