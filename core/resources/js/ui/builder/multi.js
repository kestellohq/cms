/**
 * Several blocks selected at once (Shift+click for a run, Mod+click to add or take one
 * away). They are always siblings, side by side under one parent, as in most editors:
 * that keeps grouping, removing and duplicating them simple and predictable.
 * Pure: a tree and ids in, ids or a new tree out.
 */
import { locate, reid } from './tree.js';

const parentOf = (list, id) => {
    const at = locate(list, id);
    return at ? (at.parent?.id ?? null) : undefined;
};

/** The ids in page order, those not found (or not siblings of the first) left out. */
export function inOrder(list, ids) {
    const first = ids.map((id) => locate(list, id)).find(Boolean);
    if (!first) return [];
    const want = new Set(ids);
    return first.list.filter((n) => want.has(n.id)).map((n) => n.id);
}

/** Shift+click: every sibling from the anchor to the one clicked. Just the clicked one when they aren't siblings. */
export function rangeBetween(list, anchor, target) {
    const a = locate(list, anchor),
        t = locate(list, target);
    if (!t) return [];
    if (!a || a.list !== t.list) return [target];
    const [from, to] = a.index <= t.index ? [a.index, t.index] : [t.index, a.index];
    return a.list.slice(from, to + 1).map((n) => n.id);
}

/** Mod+click: the one clicked added or taken away; a block elsewhere starts afresh. */
export function toggled(list, picked, id) {
    if (picked.includes(id)) return picked.filter((x) => x !== id);
    const p = picked.length ? parentOf(list, picked[0]) : undefined;
    const q = parentOf(list, id);
    if (q === undefined) return picked;
    return p === q ? inOrder(list, [...picked, id]) : [id];
}

/** The siblings as copies (fresh ids), placed after the last of them. Returns the tree and the copies' ids. */
export function duplicateMany(list, ids, insert) {
    const order = inOrder(list, ids);
    if (!order.length) return { list, ids: [] };
    const last = locate(list, order[order.length - 1]);
    const copies = order.map((id) => reid(locate(list, id).node));
    let next = list;
    copies.forEach((c, i) => {
        next = insert(next, last.parent?.id ?? null, last.index + 1 + i, c);
    });
    return { list: next, ids: copies.map((c) => c.id) };
}

/** The siblings moved into a new group where the first of them was. */
export function groupMany(list, ids, group, { insert, remove }) {
    const order = inOrder(list, ids);
    if (!order.length) return list;
    const first = locate(list, order[0]);
    const nodes = order.map((id) => locate(list, id).node);
    let next = list;
    for (const id of order) next = remove(next, id);
    return insert(next, first.parent?.id ?? null, first.index, { ...group, children: nodes });
}
