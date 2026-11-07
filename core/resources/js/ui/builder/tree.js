/**
 * Pure operations on a block tree ({ type, id, props, children, visibility?, lock? }[]),
 * used by the theme builder. Every operation returns a new tree; nodes that
 * didn't change are shared, so undo snapshots stay cheap.
 */

export const newId = () => Array.from(crypto.getRandomValues(new Uint8Array(4)), (b) => b.toString(16).padStart(2, '0')).join('');

/** A deep copy with fresh ids (duplicate, paste, patterns). */
export function reid(node) {
    return { ...JSON.parse(JSON.stringify(node)), id: newId(), children: (node.children ?? []).map(reid) };
}

/** Where a node is: { node, parent (node | null), list, index, path (ancestors, outermost first) }. */
export function locate(tree, id, parent = null, path = []) {
    for (let i = 0; i < tree.length; i++) {
        const node = tree[i];
        if (node.id === id) return { node, parent, list: tree, index: i, path };
        const found = locate(node.children ?? [], id, node, [...path, node]);
        if (found) return found;
    }
    return null;
}

export function contains(node, id) {
    return (node.children ?? []).some((c) => c.id === id || contains(c, id));
}

function mapList(tree, parentId, fn) {
    if (parentId === null) return fn(tree);
    return tree.map((n) => {
        if (n.id === parentId) return { ...n, children: fn(n.children ?? []) };
        const children = n.children ?? [];
        const next = mapList(children, parentId, fn);
        return next === children ? n : { ...n, children: next };
    });
}

export function update(tree, id, patch) {
    return tree.map((n) => {
        if (n.id === id) {
            const next = { ...n, ...patch };
            for (const k of Object.keys(patch)) if (patch[k] === undefined) delete next[k];
            return next;
        }
        const children = n.children ?? [];
        if (!children.length) return n;
        const next = update(children, id, patch);
        return next === children ? n : { ...n, children: next };
    });
}

export function remove(tree, id) {
    const at = locate(tree, id);
    if (!at) return tree;
    return mapList(tree, at.parent?.id ?? null, (list) => list.filter((n) => n.id !== id));
}

export function insert(tree, parentId, index, node) {
    return mapList(tree, parentId, (list) => {
        const next = [...list];
        next.splice(Math.max(0, Math.min(index, next.length)), 0, node);
        return next;
    });
}

/** Move a node to another place; refuses to put a node inside itself. */
export function move(tree, id, parentId, index) {
    const at = locate(tree, id);
    if (!at || (parentId !== null && (parentId === id || contains(at.node, parentId)))) return tree;
    // moving down within the same list: the index shifts once the node is taken out
    const sameList = (at.parent?.id ?? null) === parentId;
    const target = sameList && index > at.index ? index - 1 : index;
    return insert(remove(tree, id), parentId, target, at.node);
}

/** Every node, depth first, with its depth (the layers list). */
export function flatten(tree, collapsed = new Set(), depth = 0, out = []) {
    for (const node of tree) {
        out.push({ node, depth });
        if (!collapsed.has(node.id)) flatten(node.children ?? [], collapsed, depth + 1, out);
    }
    return out;
}

/** May a block of type def go into parentDef (null: the top level, limited by allowed)? */
export function fits(def, parentDef, allowed = null, hidden = []) {
    if (!def || hidden.includes(def.name)) return false;
    if (parentDef === null) return allowed ? allowed.includes(def.name) : def.parents === null;
    const inside = parentDef.children === true || (Array.isArray(parentDef.children) && parentDef.children.includes(def.name));
    return inside && (def.parents === null || def.parents.includes(parentDef.name));
}
