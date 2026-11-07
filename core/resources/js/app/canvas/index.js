/**
 * The editors' canvas (Appearance → Templates, and the entry editor): the site,
 * in a frame of the admin. The admin sends the tree being edited, already
 * prepared by the server, and which block is hovered or selected; this frame
 * shows it, reports clicks, and lets text be typed straight into the blocks that
 * allow it. Loaded only on a canvas page (app/index.js), and it only listens to
 * the same-origin window around it. Links and forms do nothing here.
 *
 * The parts: editing.js (typing), resize.js (handles), drag.js (dropping blocks),
 * overlay.js (outlines and marks), tree.js (where a tree goes in the page).
 * What the messages carry: shared/canvas-protocol.js.
 */
import { nextTick } from 'vue';
import { REVEAL, safeField, safeId } from '../../shared/canvas-protocol.js';
import { createDrag } from './drag.js';
import { createEditing } from './editing.js';
import { createOverlay } from './overlay.js';
import { createResizer } from './resize.js';
import { freeRoom } from './room.js';
import { FALLBACK_ID, hasBody, placeTree, stamp } from './tree.js';

export function startCanvas(router, win = window) {
    const doc = win.document;
    const design = router.page.props.design;
    const send = (message) => win.parent.postMessage(message, win.location.origin);
    const marks = { hover: null, selected: null, also: [] };
    let pending = null; // a tree that arrived while typing: shown when typing stops
    let rev = 0;
    let bodyShown = null; // does the template show the body being edited (entry only)

    // ── finding things in the page
    const blockEl = (id) => (id ? doc.querySelector(`[data-block-id="${id}"]`) : null);
    const fieldEl = (id, field) => {
        const block = blockEl(id);
        if (!block) return null;
        if (block.getAttribute('data-k-field') === field) return block;
        return [...block.querySelectorAll(`[data-k-field="${field}"]`)].find((el) => el.closest('[data-block-id]') === block) ?? null;
    };
    const rectOf = (el) => {
        const r = el.getBoundingClientRect();
        return { top: r.top, left: r.left, width: r.width, height: r.height };
    };

    const overlay = createOverlay(doc);
    // typing stopped: a tree that waited is drawn now, unless it is older than what was typed (a newer one is on its way)
    const onIdle = () => {
        if (pending && editing.includesTyping(pending)) {
            const p = pending;
            pending = null;
            show(p);
        }
    };
    const editing = createEditing({ send, fieldEl, rectOf, onIdle }, doc);
    const resizer = createResizer(
        {
            selected: () => marks.selected,
            blockEl,
            send,
            onMove: (id, block) => send({ type: 'kestello:rect', id, rect: rectOf(block), room: room.id === id ? room.value : null }),
        },
        doc,
    );
    const drag = createDrag({ send, overlay, blockEl }, doc);

    // ── where the selected block is, for the editor's toolbar (once a frame at most), with the
    //    room around it (room.js, about 2-3 ms). While the page scrolls the block and what is
    //    around it move together, so the last probe is reused and taken again once it stops.
    let room = { id: null, value: null };
    function reportRect(probe = true) {
        const el = blockEl(marks.selected);
        const rect = el ? rectOf(el) : null;
        if (rect && (probe || room.id !== marks.selected)) {
            room = {
                id: marks.selected,
                value: freeRoom(rect, (x, y) => doc.elementsFromPoint(x, y), el, { viewport: { width: win.innerWidth, height: win.innerHeight } }),
            };
        }
        send({ type: 'kestello:rect', id: marks.selected, rect, room: rect ? room.value : null });
        resizer.place();
    }
    let rectAsked = false;
    let probeNext = false;
    const reportSoon = (probe = true) => {
        probeNext ||= probe !== false;
        if (rectAsked) return;
        rectAsked = true;
        win.requestAnimationFrame(() => {
            rectAsked = false;
            const p = probeNext;
            probeNext = false;
            reportRect(p);
        });
    };
    let scrollStopped = 0;
    win.addEventListener(
        'scroll',
        () => {
            reportSoon(false);
            win.clearTimeout(scrollStopped);
            scrollStopped = win.setTimeout(() => reportSoon(true), 140);
        },
        { passive: true },
    );
    win.addEventListener('resize', reportSoon);
    if ('ResizeObserver' in win) new win.ResizeObserver(reportSoon).observe(doc.body);
    // components that load late (or render again) get their fields marked once they are in the page
    if ('MutationObserver' in win) {
        let asked = false;
        new win.MutationObserver(() => {
            if (asked) return;
            asked = true;
            win.requestAnimationFrame(() => {
                asked = false;
                editing.mark();
                reportRect();
            });
        }).observe(doc.body, { childList: true, subtree: true });
    }

    // ── showing a tree
    function show(data) {
        rev++;
        const blocks = stamp(data.blocks, editing.takeEdited(), rev);
        const props = router.page.props;
        if (design.kind === 'entry' && props.template) {
            const shown = hasBody(
                (props.template.blocks ?? []).filter((n) => n?.id !== FALLBACK_ID),
                design.field,
            );
            if (shown !== bodyShown) {
                bodyShown = shown;
                send({ type: 'kestello:body', shown });
            }
        }
        router.page.props = placeTree(props, design, { ...data, blocks });
        editing.setEditable(data.editable);
        nextTick(() => {
            editing.mark();
            reportRect();
            if (data.focus) editing.focus(safeId(data.focus.id), safeField(data.focus.field), data.focus.at === 'start' ? 'start' : 'end');
        });
    }

    win.addEventListener('message', (event) => {
        if (event.origin !== win.location.origin || event.source !== win.parent) return;
        const data = event.data ?? {};
        switch (data.type) {
            case 'kestello:tree': {
                if (!Array.isArray(data.blocks)) return;
                const force = data.force || pending?.force;
                // older than what was typed here: it would put older text back, so wait for the next one
                if (!editing.includesTyping(data)) {
                    pending = { ...data, force };
                    return;
                }
                const now = editing.current();
                // typed text is already on the page: a tree that only echoes it waits until typing stops;
                // one changed from outside is drawn now, keeping the caret where it was if the field is still there
                if (now && !data.focus && !force) {
                    pending = data;
                    return;
                }
                pending = null;
                show(now && !data.focus ? { ...data, focus: { id: now.id, field: now.field, at: 'end' } } : data);
                break;
            }
            case 'kestello:mark': {
                marks.hover = safeId(data.hover);
                const selected = safeId(data.selected);
                const changed = selected !== marks.selected;
                marks.selected = selected;
                marks.also = Array.isArray(data.also) ? data.also.slice(0, 500).map(safeId).filter(Boolean) : [];
                overlay.outline(marks);
                // a block inside something closed (a tab not shown) asks it to open first
                if (changed && selected) blockEl(selected)?.dispatchEvent(new CustomEvent(REVEAL, { bubbles: true }));
                if (changed && selected && data.scroll) blockEl(selected)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                reportRect();
                break;
            }
            case 'kestello:format':
                editing.format(data.command, data.value);
                break;
            case 'kestello:focus':
                editing.focus(safeId(data.id), safeField(data.field), data.at === 'start' ? 'start' : 'end');
                break;
            case 'kestello:drag':
                drag.setActive(data.active);
                break;
            case 'kestello:drop-mark':
                drag.mark(data.mark);
                break;
            default:
        }
    });

    // ── clicks and hovering: the block and the blocks around it, innermost first. Blocks of a
    // template part or of the entry's body aren't in the tree being edited, so the editor picks
    // the nearest one that is.
    const blockAt = (target) => (target instanceof win.Element ? target.closest('[data-block-id]') : null);
    const chain = (el) => {
        const ids = [];
        for (let at = el; at; at = at.parentElement?.closest('[data-block-id]') ?? null) ids.push(at.getAttribute('data-block-id'));
        return ids;
    };
    doc.addEventListener(
        'click',
        (event) => {
            const typing = event.target instanceof win.Element && event.target.closest('[data-k-editable]');
            if (event.target instanceof win.Element && event.target.closest('a, button, input[type=submit], form')) event.preventDefault();
            if (event.target instanceof win.Element && event.target.closest('[data-k-title]')) {
                send({ type: 'kestello:title' });
                return;
            }
            const el = blockAt(event.target);
            if (!el) return;
            if (!typing) event.preventDefault();
            // Shift or Mod (not while placing the caret in text) picks several blocks
            const extend = typing ? null : event.shiftKey ? 'range' : event.metaKey || event.ctrlKey ? 'toggle' : null;
            send({ type: 'kestello:pick', ids: chain(el), extend });
        },
        true,
    );
    doc.addEventListener('submit', (event) => event.preventDefault(), true);
    let lastHover = null;
    doc.addEventListener('mouseover', (event) => {
        const el = blockAt(event.target);
        const id = el?.getAttribute('data-block-id') ?? null;
        if (id !== lastHover) {
            lastHover = id;
            send({ type: 'kestello:hover', ids: el ? chain(el) : [] });
        }
    });
    doc.addEventListener('mouseleave', () => {
        lastHover = null;
        send({ type: 'kestello:hover', ids: [] });
    });

    send({ type: 'kestello:canvas-ready' });
}
