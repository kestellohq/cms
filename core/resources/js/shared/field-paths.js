/**
 * Where typed text goes in a block's props (spec: theme-builder.md §15).
 *
 *   text              a top-level field
 *   items.2.text      the "text" of the third item of the repeater "items"
 *   table.rows.0.1    the second cell of the first row of the table field "table"
 *
 * Both sides of the canvas frame use these: the frame to find and move between
 * the elements a block marks with data-k-field, the editor to know what a path
 * means and to write a value at it. Pure functions, no DOM.
 */

const NAME = /^[a-z][a-z0-9_]{0,63}$/;
const INDEX = /^\d{1,4}$/;
const MAX_SEGMENTS = 5;

/** A field path as blocks mark it, or null: names and indexes joined by dots, starting with a name. */
export function safeFieldPath(path) {
    if (typeof path !== 'string' || path.length > 200) return null;
    const parts = path.split('.');
    if (parts.length > MAX_SEGMENTS || !NAME.test(parts[0])) return null;
    return parts.every((p) => NAME.test(p) || INDEX.test(p)) ? path : null;
}

/** "items.2.text" → ['items', 2, 'text'] (indexes as numbers). */
export const segments = (path) => path.split('.').map((p) => (INDEX.test(p) ? Number(p) : p));

/** A repeater item's field: { list: 'items', index: 2, sub: 'text' }, or null. */
export function itemOf(path) {
    const s = safeFieldPath(path) ? segments(path) : [];
    return s.length === 3 && typeof s[1] === 'number' && typeof s[2] === 'string' ? { list: s[0], index: s[1], sub: s[2] } : null;
}

/** A table cell: { field: 'table', row: 0, col: 1 }, or null. */
export function cellOf(path) {
    const s = safeFieldPath(path) ? segments(path) : [];
    return s.length === 4 && s[1] === 'rows' && typeof s[2] === 'number' && typeof s[3] === 'number' ? { field: s[0], row: s[2], col: s[3] } : null;
}

export const itemPath = (list, index, sub) => `${list}.${index}.${sub}`;
export const cellPath = (field, row, col) => `${field}.rows.${row}.${col}`;

/** The value at a path, or undefined. */
export function getAt(props, path) {
    let at = props;
    for (const s of segments(path)) {
        if (at === null || typeof at !== 'object') return undefined;
        at = at[s];
    }
    return at;
}

/**
 * Props with `value` at `path`, copied along the path (the rest shared). Only
 * paths that already lead somewhere are written: an index past the end of a
 * list, or a step through something that isn't a list or an object, gives null.
 */
export function setAt(props, path, value) {
    const s = safeFieldPath(path) ? segments(path) : null;
    if (!s) return null;
    const write = (at, i) => {
        const key = s[i];
        const last = i === s.length - 1;
        if (typeof key === 'number') {
            if (!Array.isArray(at) || key >= at.length) return null;
        } else if (at === null || typeof at !== 'object' || Array.isArray(at)) return null;
        const next = last ? value : write(at[key], i + 1);
        if (next === null) return null;
        const copy = Array.isArray(at) ? [...at] : { ...at };
        copy[key] = next;
        return copy;
    };
    // a top-level field may be new (a prop not set yet); deeper steps must exist
    if (s.length === 1) return { ...(props ?? {}), [s[0]]: value };
    return write(props ?? {}, 0);
}

/**
 * The fields to try when the caret leaves an item or a cell, nearest first:
 * up / down move between items, or between cells in the same column; next /
 * previous (Tab) move along a row and then on to the next or previous row.
 * Whether a field exists is for the caller to check (the page knows).
 *
 * @param {'up'|'down'|'next'|'prev'} dir
 * @param {(row: number) => number} widthOf how many cells a table row has (for Shift+Tab from a row's first cell)
 * @returns {string[]}
 */
export function neighbours(path, dir, widthOf = () => 0) {
    const item = itemOf(path);
    if (item) {
        if (dir !== 'up' && dir !== 'down') return [];
        const i = item.index + (dir === 'up' ? -1 : 1);
        return i >= 0 ? [itemPath(item.list, i, item.sub)] : [];
    }
    const cell = cellOf(path);
    if (!cell) return [];
    const { field, row, col } = cell;
    if (dir === 'up') return row > 0 ? [cellPath(field, row - 1, col)] : [];
    if (dir === 'down') return [cellPath(field, row + 1, col)];
    if (dir === 'next') return [cellPath(field, row, col + 1), cellPath(field, row + 1, 0)];
    if (col > 0) return [cellPath(field, row, col - 1)];
    const width = row > 0 ? widthOf(row - 1) : 0;
    return width > 0 ? [cellPath(field, row - 1, width - 1)] : [];
}
