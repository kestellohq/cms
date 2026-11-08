/**
 * What a key typed on the page does to a list's items or a table's rows
 * (spec: theme-builder.md §15). The canvas reports Enter on a one-line field and
 * Backspace in an empty field as `kestello:key`; for items and cells the answer
 * depends on where the caret is, and that is decided here, as data, so the
 * editor (useCanvas) only carries it out. Moving between items and cells needs
 * only the page and happens in the frame (app/canvas/editing.js).
 *
 * Answers:
 *   { do: 'default' }                              what the key does for any block
 *   { do: 'none' }                                 nothing (the caret goes back where it was)
 *   { do: 'props', props, focus: { field, at } }   new props for the block, then typing goes on there
 *   { do: 'leave', props }                         the list loses its empty last item (props null: the
 *                                                  whole block goes) and a paragraph starts after it
 */
import { cellOf, cellPath, itemOf, itemPath } from '../../shared/field-paths.js';

/** The table's most rows (Fields\TableField: option max, 200 by default). */
const TABLE_ROWS = 200;

const isBlank = (v) => typeof v !== 'string' || v.trim() === '';

/**
 * @param {{ node: object, def: object, field: string, key: string, first: string|null }} at
 *   first: the block's first field typed on the page (blocks.js firstInlineOf)
 */
export function typingKey({ node, def, field, key, first }) {
    const props = node?.props ?? {};
    const item = itemOf(field);
    if (item) {
        const f = def?.fields?.find((x) => x.name === item.list && x.type === 'repeater');
        const items = Array.isArray(props[item.list]) ? props[item.list] : null;
        if (!f || !items || item.index >= items.length) return { do: 'none' };
        if (key === 'enter') return enterInItem(props, f, items, item);
        if (key === 'remove') return removeItem(props, items, item);
        return { do: 'default' };
    }
    const cell = cellOf(field);
    if (cell) {
        const f = def?.fields?.find((x) => x.name === cell.field && x.type === 'table');
        const rows = Array.isArray(props[cell.field]?.rows) ? props[cell.field].rows : null;
        if (!f || !rows) return { do: 'none' };
        // Enter on the last row adds one (the frame moves down from any other row itself);
        // no key typed in a cell takes the table apart
        if (key === 'enter' && cell.row === rows.length - 1) return addRow(props, f, rows, cell);
        return key === 'enter' || key === 'remove' ? { do: 'none' } : { do: 'default' };
    }
    // Backspace in an empty caption or source: only the block's first field removes the block
    if (key === 'remove' && first && field !== first) return { do: 'none' };
    return { do: 'default' };
}

function enterInItem(props, f, items, { list, index, sub }) {
    // an empty last item ends the list: it goes, and a paragraph starts after the list
    if (index === items.length - 1 && isBlank(items[index]?.[sub])) {
        if (items.length === 1) return { do: 'leave', props: null };
        return { do: 'leave', props: { ...props, [list]: items.slice(0, -1) } };
    }
    const max = Number(f.options?.max ?? 100);
    if (items.length >= max) return { do: 'none' };
    // the new item's text fields start empty; its other fields (an icon) carry over from this one
    const empty = Object.fromEntries((f.fields ?? []).filter((s) => ['text', 'textarea'].includes(s.type)).map((s) => [s.name, '']));
    const fresh = { ...(items[index] ?? {}), ...empty };
    const next = [...items.slice(0, index + 1), fresh, ...items.slice(index + 1)];
    return { do: 'props', props: { ...props, [list]: next }, focus: { field: itemPath(list, index + 1, sub), at: 'start' } };
}

function removeItem(props, items, { list, index, sub }) {
    if (items.length === 1) return { do: 'default' }; // the only item: the block goes, as for any block
    const next = items.filter((_, i) => i !== index);
    const focus = index > 0 ? { field: itemPath(list, index - 1, sub), at: 'end' } : { field: itemPath(list, 0, sub), at: 'start' };
    return { do: 'props', props: { ...props, [list]: next }, focus };
}

function addRow(props, f, rows, { field, col }) {
    const max = Number(f.options?.max ?? TABLE_ROWS);
    if (rows.length >= max) return { do: 'none' };
    const width = Math.max(1, ...rows.map((r) => (Array.isArray(r) ? r.length : 0)));
    const table = { ...props[field], rows: [...rows, Array.from({ length: width }, () => '')] };
    return { do: 'props', props: { ...props, [field]: table }, focus: { field: cellPath(field, rows.length, col), at: 'start' } };
}
