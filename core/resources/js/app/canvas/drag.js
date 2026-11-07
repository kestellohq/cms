/**
 * Dragging a block (from the toolbar, the layers or the library) over the page:
 * the canvas says which blocks are under the pointer and where in each; the
 * editor decides where the block may go, and the overlay shows it.
 */
import { safeId } from '../../shared/canvas-protocol.js';

const DEPTH = 24;

/** The blocks under a point, innermost first, with where the point is in each (0..1 across and down). */
export function chainAt(el, x, y) {
    const chain = [];
    for (let at = el; at && chain.length < DEPTH; at = at.parentElement?.closest('[data-block-id]') ?? null) {
        const id = safeId(at.getAttribute('data-block-id'));
        const r = at.getBoundingClientRect();
        if (id && r.width && r.height) chain.push({ id, xr: (x - r.left) / r.width, yr: (y - r.top) / r.height, w: r.width });
    }
    return chain;
}

/**
 * @param {{ send: Function, overlay: ReturnType<typeof import('./overlay.js').createOverlay>, blockEl: Function }} ctx
 */
export function createDrag({ send, overlay, blockEl }, doc = document) {
    const win = doc.defaultView;
    let active = false;
    let frame = 0;

    doc.addEventListener('dragover', (event) => {
        if (!active) return;
        event.preventDefault();
        const x = event.clientX;
        const y = event.clientY;
        const el = event.target instanceof win.Element ? event.target.closest('[data-block-id]') : null;
        win.cancelAnimationFrame(frame);
        frame = win.requestAnimationFrame(() => send({ type: 'kestello:dragover', chain: chainAt(el, x, y) }));
    });
    doc.addEventListener('drop', (event) => {
        if (!active) return;
        event.preventDefault();
        overlay.hideDrop();
        send({ type: 'kestello:drop' });
    });
    doc.addEventListener('dragleave', (event) => {
        if (active && !event.relatedTarget) overlay.hideDrop();
    });

    return {
        setActive(on) {
            active = on === true;
            if (!active) overlay.hideDrop();
        },
        /** Show where the editor says the block would land. */
        mark(target) {
            if (!active || !target || typeof target !== 'object') {
                overlay.hideDrop();
                return;
            }
            const block = target.id ? blockEl(safeId(target.id)) : null;
            const list = doc.querySelector('[data-block-id]')?.parentElement;
            const r = block?.getBoundingClientRect() ?? (target.end && list ? list.getBoundingClientRect() : null);
            overlay.showDrop(target, r);
        },
    };
}
