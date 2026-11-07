/**
 * Where a block may go, for dragging in the layers and on the page, and putting
 * blocks side by side. Pure: given the tree and two questions about blocks
 * (fits: may a block of this kind go into that parent; make: a new block of a
 * kind), it answers with targets and new trees.
 *
 * A target is { id, where: before | after | inside | left | right } or { end: true }.
 */
import * as T from './tree.js';

const COLUMN_LAYOUTS = { 2: '1-1', 3: '1-1-1', 4: '1-1-1-1' };
const MAX_COLUMNS = 4;

/**
 * @param {{ fits: (def: object, parent: object|null) => boolean, defOf: (node: object) => object|null, make: (name: string) => object|null }} rules
 */
export function createPlacement({ fits, defOf, make }) {
    /** Is the moving block this one, or around it? A block can't go into itself. */
    const within = (tree, movingId, id) => {
        if (!movingId) return false;
        if (movingId === id) return true;
        const moving = T.locate(tree, movingId)?.node;
        return !!moving && T.contains(moving, id);
    };

    /** Where a target puts a block: the parent's id (null: the top level) and the index in it. */
    function position(tree, target) {
        if (!target || target.end) return { parentId: null, index: tree.length };
        const at = T.locate(tree, target.id);
        if (!at) return null;
        if (target.where === 'inside') return { parentId: at.node.id, index: (at.node.children ?? []).length };
        return { parentId: at.parent?.id ?? null, index: at.index + (target.where === 'after' ? 1 : 0) };
    }

    /**
     * Over a row in the layers, y from 0 (top) to 1 (bottom): the middle of a block that
     * holds others drops inside it, the top and bottom halves before and after it.
     */
    function layerTarget(tree, id, y, def, movingId = null) {
        const at = T.locate(tree, id);
        if (!at || !def || within(tree, movingId, id)) return null;
        const holds = !!defOf(at.node)?.children && fits(def, at.node);
        if (holds && y > 0.25 && y < 0.75) return { id, where: 'inside' };
        if (fits(def, at.parent)) return { id, where: y < 0.5 ? 'before' : 'after' };
        return holds ? { id, where: 'inside' } : null;
    }

    const columnOf = (at) => (at.node.type === 'core/column' ? at.node : at.parent?.type === 'core/column' ? at.parent : null);

    /** Could a block of this kind go beside this one, in columns (a new set of them, or one more)? */
    function canBeside(tree, at, def) {
        const column = make('core/column');
        if (!column || !make('core/columns') || !fits(def, column)) return false;
        const col = columnOf(at);
        if (col) {
            const cols = T.locate(tree, col.id)?.parent;
            return !!cols && (cols.children ?? []).length < MAX_COLUMNS;
        }
        return at.node.type !== 'core/columns' && fits(defOf({ type: 'core/columns' }), at.parent);
    }

    /**
     * On the page: the blocks under the pointer, innermost first, each with where the pointer
     * is in it (xr, yr from 0 to 1) and how wide it is. The outer sixth of a wide block puts
     * the dragged one beside it; the middle of a block that holds others puts it inside;
     * otherwise before or after the first block it may sit next to.
     */
    function canvasTarget(tree, chain, def, movingId = null) {
        if (!def) return null;
        for (const c of chain) {
            const at = T.locate(tree, c.id);
            if (!at) continue; // a block of a part or the theme: look further out
            if (within(tree, movingId, c.id)) return null; // onto itself: nothing to do
            if (c.w > 240 && (c.xr < 0.16 || c.xr > 0.84) && canBeside(tree, at, def)) return { id: c.id, where: c.xr < 0.5 ? 'left' : 'right' };
            const holds = !!defOf(at.node)?.children && fits(def, at.node);
            const empty = !(at.node.children ?? []).length;
            if (holds && (empty || (c.yr > 0.2 && c.yr < 0.8 && at.node.type !== 'core/columns'))) return { id: c.id, where: 'inside' };
            if (fits(def, at.parent)) return { id: c.id, where: c.yr < 0.5 ? 'before' : 'after' };
        }
        return !chain.length && fits(def, null) ? { end: true } : null;
    }

    /**
     * Side by side: the block goes in a new column next to the target's column, or the two
     * are made into columns. The columns' layout follows how many there are.
     * @returns {object[]|null} the new tree
     */
    function placeBeside(tree, target, node, movingId = null) {
        let next = movingId ? T.remove(tree, movingId) : tree;
        const at = T.locate(next, target.id);
        if (!at) return null;
        const column = { ...make('core/column'), children: [node] };
        const col = columnOf(at);
        if (col) {
            const colAt = T.locate(next, col.id);
            const cols = colAt.parent;
            next = T.insert(next, cols.id, colAt.index + (target.where === 'right' ? 1 : 0), column);
            const count = (cols.children ?? []).length + 1;
            return T.update(next, cols.id, { props: { ...(cols.props ?? {}), layout: COLUMN_LAYOUTS[count] ?? COLUMN_LAYOUTS[MAX_COLUMNS] } });
        }
        const base = make('core/columns');
        const beside = { ...make('core/column'), children: [at.node] };
        const columns = {
            ...base,
            props: { ...base.props, layout: COLUMN_LAYOUTS[2] },
            children: target.where === 'left' ? [column, beside] : [beside, column],
        };
        return T.insert(T.remove(next, at.node.id), at.parent?.id ?? null, at.index, columns);
    }

    /** Put a new block next to a selection: inside it if it fits, else after it, else at the end. Null: nowhere. */
    function near(tree, selectedId, def) {
        const at = selectedId ? T.locate(tree, selectedId) : null;
        if (at && fits(def, at.node)) return { parentId: at.node.id, index: (at.node.children ?? []).length };
        if (at && fits(def, at.parent)) return { parentId: at.parent?.id ?? null, index: at.index + 1 };
        return fits(def, null) ? { parentId: null, index: tree.length } : null;
    }

    return { position, layerTarget, canvasTarget, canBeside, placeBeside, near };
}
