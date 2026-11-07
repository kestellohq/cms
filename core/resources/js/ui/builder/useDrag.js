/**
 * Dragging blocks: rows in the layers, tiles in the library, the grip on the
 * toolbar; dropped in the layers or on the page. The rules for where a block
 * may land are in placement.js; this keeps what is being dragged and where it
 * would go, and tells the canvas so it can show it.
 */
import { ref } from 'vue';
import { __ } from '@kestello/sdk';
import * as T from './tree.js';

export function useDrag(b, { props }) {
    const drag = ref(null); // { id } a block moving | { def } a new block | { pattern }
    const drop = ref(null); // a target (placement.js)

    function start(event, payload, effect) {
        drag.value = payload;
        event.dataTransfer.effectAllowed = effect;
        event.dataTransfer.setData('text/plain', payload.id ?? payload.def?.name ?? payload.pattern?.key ?? '');
        b.post({ type: 'kestello:drag', active: true });
    }
    const dragNode = (event, id) => {
        if (!props.disabled) start(event, { id }, 'move');
    };
    const dragNew = (event, payload) => {
        if (!props.disabled) start(event, payload, 'copy');
    };

    /** The kind of block being dragged (a pattern: what it becomes). */
    function dragDef() {
        const d = drag.value;
        if (!d) return null;
        if (d.id) return b.defOf(T.locate(b.list.value, d.id)?.node);
        return d.def ?? b.defOfPattern(d.pattern);
    }

    function clearDrag() {
        drag.value = null;
        drop.value = null;
        b.post({ type: 'kestello:drag', active: false });
    }
    // a drop on the page arrives as a message, which can come just after the drag ends here
    const endDrag = () => setTimeout(clearDrag, 120);

    // ── in the layers
    function overRow(event, row) {
        if (!drag.value) return;
        const r = event.currentTarget.getBoundingClientRect();
        const t = b.placement.layerTarget(b.list.value, row.node.id, (event.clientY - r.top) / r.height, dragDef(), drag.value.id ?? null);
        drop.value = t;
        if (t) {
            event.preventDefault();
            event.dataTransfer.dropEffect = drag.value.id ? 'move' : 'copy';
        }
    }
    function overEnd(event) {
        if (!drag.value || !b.fitsIn(dragDef(), null)) return;
        event.preventDefault();
        drop.value = { end: true };
    }
    function leave(match) {
        if (match(drop.value)) drop.value = null;
    }
    function finish(event) {
        event.preventDefault();
        dropHere();
    }

    // ── on the page: the canvas reports the blocks under the pointer
    function onCanvasDrag(data) {
        if (!drag.value) return;
        const chain = Array.isArray(data.chain) ? data.chain.filter((c) => typeof c?.id === 'string').slice(0, 24) : [];
        const target = b.placement.canvasTarget(b.list.value, chain, dragDef(), drag.value.id ?? null);
        drop.value = target;
        b.post({ type: 'kestello:drop-mark', mark: target }); // the plain object: a reactive one can't be posted
    }

    /** Put what is being dragged where it would go. */
    function dropHere() {
        const t = drop.value;
        const d = drag.value;
        clearDrag();
        if (!t || !d) return;
        if (t.where === 'left' || t.where === 'right') {
            beside(t, d);
            return;
        }
        const where = b.placement.position(b.list.value, t);
        if (!where) return;
        if (t.where === 'inside') b.collapsed.delete(t.id);
        if (d.id) {
            b.moveNode(d.id, where.parentId, where.index, { focus: false });
            b.select(d.id);
            return;
        }
        const node = d.def ? b.create(d.def) : b.makePattern(d.pattern);
        b.insertAt(node, where.parentId, where.index);
        b.select(node.id);
    }

    function beside(t, d) {
        const node = d.id ? T.locate(b.list.value, d.id)?.node : d.def ? b.create(d.def) : b.makePattern(d.pattern);
        const other = T.locate(b.list.value, t.id)?.node;
        const next = node ? b.placement.placeBeside(b.list.value, t, node, d.id ?? null) : null;
        if (!next) return;
        b.commit(next);
        b.announce(__('{block} placed beside {other}.', { block: b.nameOf(node), other: b.nameOf(other) }));
        b.select(node.id);
    }

    return { drag, drop, dragNode, dragNew, endDrag, overRow, overEnd, leave, finish, onCanvasDrag, dropHere };
}
