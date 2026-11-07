/**
 * A table value (Fields\TableField): { head: bool, rows: string[][] }, every row the same
 * length. These return a new value and never change the one given, so the editor
 * can undo and compare. Limits match the server's defaults.
 */
export const MAX_COLUMNS = 12;
export const MAX_ROWS = 200;

/** A new table: a heading row and two rows, three columns, all empty. */
export const blankTable = (columns = 3, rows = 3) => ({ head: true, rows: Array.from({ length: rows }, () => Array(columns).fill('')) });

/** Any value as a well-formed table: missing cells filled, long rows cut, at least one cell. */
export function tidy(value) {
    const rows = Array.isArray(value?.rows) ? value.rows.filter(Array.isArray) : [];
    if (!rows.length) return blankTable();
    const width = Math.min(MAX_COLUMNS, Math.max(1, ...rows.map((r) => r.length)));
    return { head: !!value.head, rows: rows.slice(0, MAX_ROWS).map((r) => Array.from({ length: width }, (_, i) => String(r[i] ?? ''))) };
}

export const width = (t) => t.rows[0]?.length ?? 0;

export function setCell(t, r, c, text) {
    return { ...t, rows: t.rows.map((row, i) => (i === r ? row.map((cell, j) => (j === c ? text : cell)) : row)) };
}

/** A row inserted at `at` (default: the end). */
export function addRow(t, at = t.rows.length) {
    if (t.rows.length >= MAX_ROWS) return t;
    const rows = [...t.rows];
    rows.splice(at, 0, Array(width(t)).fill(''));
    return { ...t, rows };
}

/** A column inserted at `at` (default: the end). */
export function addColumn(t, at = width(t)) {
    if (width(t) >= MAX_COLUMNS) return t;
    return {
        ...t,
        rows: t.rows.map((row) => {
            const r = [...row];
            r.splice(at, 0, '');
            return r;
        }),
    };
}

/** The last row or column stays: a table always has a cell. */
export const removeRow = (t, r) => (t.rows.length <= 1 ? t : { ...t, rows: t.rows.filter((_, i) => i !== r) });
export const removeColumn = (t, c) => (width(t) <= 1 ? t : { ...t, rows: t.rows.map((row) => row.filter((_, j) => j !== c)) });

export function moveRow(t, r, delta) {
    const to = r + delta;
    if (to < 0 || to >= t.rows.length) return t;
    const rows = [...t.rows];
    [rows[r], rows[to]] = [rows[to], rows[r]];
    return { ...t, rows };
}

/**
 * Text copied from a spreadsheet: rows on lines, cells split by tabs, a cell holding a tab,
 * a line break or a quote comes "quoted" (with "" for a quote). Null for plain text (no tab, one line).
 */
export function parseGrid(text) {
    const src = String(text ?? '')
        .replace(/\r\n?/g, '\n')
        .replace(/\n$/, '');
    if (!src.includes('\t') && !src.includes('\n')) return null;
    const rows = [[]];
    let cell = '';
    let quoted = false;
    for (let i = 0; i < src.length; i++) {
        const ch = src[i];
        if (quoted) {
            if (ch === '"' && src[i + 1] === '"') {
                cell += '"';
                i++;
            } else if (ch === '"') quoted = false;
            else cell += ch;
        } else if (ch === '"' && cell === '') quoted = true;
        else if (ch === '\t') {
            rows.at(-1).push(cell);
            cell = '';
        } else if (ch === '\n') {
            rows.at(-1).push(cell);
            cell = '';
            rows.push([]);
        } else cell += ch;
    }
    rows.at(-1).push(cell);
    return rows;
}

/** A pasted grid written in from cell (r, c), growing the table as far as the limits allow. */
export function pasteGrid(t, r, c, grid) {
    let next = t;
    while (next.rows.length < Math.min(MAX_ROWS, r + grid.length)) next = addRow(next);
    const widest = Math.max(...grid.map((g) => g.length));
    while (width(next) < Math.min(MAX_COLUMNS, c + widest)) next = addColumn(next);
    grid.forEach((cells, i) =>
        cells.forEach((text, j) => {
            if (r + i < next.rows.length && c + j < width(next)) next = setCell(next, r + i, c + j, text);
        }),
    );
    return next;
}
