/**
 * The pattern browser's logic (BuilderPatterns.vue): patterns grouped by category and
 * found by a search, and a whole-page layout turned into blocks. Patterns arrive from
 * GET /design/patterns, fitted to this site already.
 */
import { reid } from './tree.js';

/** Core's categories, in the order people look for them; a theme's own come after, A to Z. */
export const CATEGORY_ORDER = [
    'Pages',
    'Headers',
    'Features',
    'Text',
    'Calls to action',
    'Testimonials',
    'Pricing',
    'Questions',
    'About',
    'Contact',
    'Lists and tables',
];

const words = (q) =>
    String(q ?? '')
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean);

/** Does a pattern match every word searched for (in its title, description or category)? */
export function matchesPattern(p, query) {
    const text = `${p.title} ${p.description ?? ''} ${p.category ?? ''}`.toLowerCase();
    return words(query).every((w) => text.includes(w));
}

/**
 * The patterns to show, in their categories, in order.
 * @param {object[]} patterns
 * @param {{ query?: string, layouts?: boolean }} options  layouts: only whole pages
 * @returns {{ name: string, patterns: object[] }[]}
 */
export function groupPatterns(patterns, { query = '', layouts = false } = {}) {
    const shown = patterns.filter((p) => (!layouts || p.layout) && matchesPattern(p, query));
    const names = [...new Set(shown.map((p) => p.category || 'General'))];
    const rank = (n) => {
        const i = CATEGORY_ORDER.indexOf(n);
        return i === -1 ? CATEGORY_ORDER.length : i;
    };
    names.sort((a, b) => rank(a) - rank(b) || a.localeCompare(b));
    return names.map((name) => ({ name, patterns: shown.filter((p) => (p.category || 'General') === name) }));
}

/** A whole-page layout as fresh blocks for the page itself (not wrapped in a group, as a section is). */
export function layoutBlocks(pattern, catalog) {
    return (pattern.blocks ?? []).filter((b) => catalog.has(b.type)).map(reid);
}

/** Does choosing this pattern need a choice first: a whole-page layout onto a page that has blocks? */
export const needsLayoutChoice = (pattern, list) => !!pattern?.layout && !pattern.synced && list.length > 0;

/**
 * The page after a layout goes onto it.
 * @param {object[]} list    the page's blocks now
 * @param {object[]} blocks  the layout's (layoutBlocks)
 * @param {'replace'|'append'} how
 */
export function withLayout(list, blocks, how) {
    if (how !== 'replace' && how !== 'append') throw new Error(`withLayout: how must be "replace" or "append", got ${JSON.stringify(how)}`);
    return how === 'replace' ? [...blocks] : [...list, ...blocks];
}
