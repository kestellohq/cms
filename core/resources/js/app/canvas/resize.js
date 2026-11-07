/**
 * Resizing on the page. A block marks the element that can be resized with
 * data-k-resize="<prop>" (a number field from 10 to 100: a percentage of the
 * column). The selected block gets a handle at that element's outer bottom
 * corner; dragging it sets --k-size (a percentage) and --k-size-f (a fraction)
 * on the block while the pointer moves, which the block's CSS reads, and sends
 * the new value to the editor when the pointer lets go.
 */
import { safeField } from '../../shared/canvas-protocol.js';

/** The percentage of the column a drag gives: the width it started at plus the distance moved. */
export function resizedPercent({ startWidth, column, dx, factor }) {
    const w = startWidth + dx * factor;
    return Math.round(Math.min(100, Math.max(10, (w / Math.max(1, column)) * 100)));
}

/** How a block is laid out, for measuring: floated, centred, and from which side it grows. */
export function layoutOf(className, float) {
    const floated = float === 'left' || float === 'right';
    return {
        floated,
        fromEnd: /\bis-(end|wrap-end)\b/.test(className),
        centred: !floated && /\bis-center\b/.test(className),
    };
}

/**
 * @param {{ selected: () => string|null, blockEl: (id: string) => Element|null, send: Function, onMove: Function }} ctx
 */
export function createResizer({ selected, blockEl, send, onMove }, doc = document) {
    const win = doc.defaultView;
    let handle = null;
    let active = null;

    const own = (block) => (el) => el.closest('[data-block-id]') === block;
    function target() {
        const id = selected();
        const block = id ? blockEl(id) : null;
        if (!block) return null;
        const el = block.hasAttribute('data-k-resize') ? block : [...block.querySelectorAll('[data-k-resize]')].find(own(block));
        const prop = el ? safeField(el.getAttribute('data-k-resize')) : null;
        return prop ? { id, block, el, prop } : null;
    }

    function handleEl() {
        if (handle) return handle;
        handle = doc.createElement('div');
        handle.setAttribute('aria-hidden', 'true');
        handle.dataset.kHandle = 'resize';
        handle.style.cssText =
            'position:fixed;z-index:2147483646;width:14px;height:14px;margin:-7px 0 0 -7px;border:2px solid #fff;border-radius:50%;background:#2563eb;box-shadow:0 0 0 1px #2563eb;cursor:ew-resize;touch-action:none;';
        handle.addEventListener('pointerdown', start);
        doc.body.appendChild(handle);
        return handle;
    }

    function placeAt(t) {
        const r = t.el.getBoundingClientRect();
        const h = handleEl();
        h.hidden = false;
        h.style.top = `${r.bottom}px`;
        h.style.left = `${layoutOf(t.block.className, 'none').fromEnd ? r.left : r.right}px`;
    }

    /** Called whenever the selection or the layout may have changed. */
    function place() {
        if (active) return;
        const t = target();
        if (t) placeAt(t);
        else if (handle) handle.hidden = true;
    }

    /** The width a percentage is of: the text column, without the block's own padding (or a float's gutters). */
    function columnOf(block, style, layout) {
        if (layout.floated) {
            const gutter = parseFloat(style.float === 'right' ? style.marginRight : style.marginLeft) || 0;
            return (block.parentElement?.clientWidth ?? 0) - gutter * 2;
        }
        return block.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0);
    }

    function start(event) {
        const t = target();
        if (!t) return;
        event.preventDefault();
        event.stopPropagation();
        const style = win.getComputedStyle(t.block);
        const layout = layoutOf(t.block.className, style.float);
        active = {
            ...t,
            x: event.clientX,
            startWidth: (layout.floated ? t.block : t.el).getBoundingClientRect().width,
            column: columnOf(t.block, style, layout) || 1,
            // a centred element grows on both sides; one on the right grows to the left
            factor: (layout.centred ? 2 : 1) * (layout.fromEnd ? -1 : 1),
            value: null,
        };
        handle.setPointerCapture?.(event.pointerId);
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', end, { once: true });
        handle.addEventListener('pointercancel', end, { once: true });
    }

    function move(event) {
        if (!active) return;
        const value = resizedPercent({ startWidth: active.startWidth, column: active.column, dx: event.clientX - active.x, factor: active.factor });
        active.value = value;
        active.block.style.setProperty('--k-size', `${value}%`);
        active.block.style.setProperty('--k-size-f', String(value / 100));
        placeAt(active);
        handle.title = `${value}%`;
        onMove(active.id, active.block);
    }

    function end() {
        handle.removeEventListener('pointermove', move);
        const done = active;
        active = null;
        // a click without a move changes nothing
        if (done && done.value !== null) send({ type: 'kestello:resize', id: done.id, prop: done.prop, value: done.value });
        place();
    }

    return { place, busy: () => active !== null };
}
