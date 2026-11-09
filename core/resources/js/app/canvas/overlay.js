/**
 * What the canvas draws over the page: outlines for the hovered and selected
 * block, and the mark showing where a dragged block would land. Nothing here
 * changes the page's own elements; outlines are one adopted stylesheet and the
 * drop mark is a fixed element of its own.
 */
const ACCENT = '#2563eb';

/** Block names as the catalog writes them (core/column): safe to put in a selector. */
const BLOCK_NAME = /^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/;

/**
 * Blocks that hold others render nothing of their own while empty (a new
 * Column, Group or Tab), so they would have no size to click. In the canvas
 * only, an empty one gets room, a dashed edge and a hint.
 *
 * @param {unknown} names  the kinds of block that hold others
 * @param {unknown} label  the hint, already translated
 */
export function emptyHolderRule(names, label) {
    const kinds = Array.isArray(names) ? names.filter((n) => typeof n === 'string' && BLOCK_NAME.test(n)).slice(0, 200) : [];
    if (!kinds.length) return '';
    const sel = kinds.map((n) => `[data-block="${n}"]:empty`).join(', ');
    const text = String(label ?? '')
        .replace(/[\\"\n\r]/g, ' ')
        .slice(0, 200);
    return [
        `:is(${sel}) { min-height: 4.5rem; display: flex; align-items: center; justify-content: center; outline: 1px dashed ${ACCENT}80; outline-offset: -1px; border-radius: 4px; }`,
        `:is(${sel})::before { content: "${text}"; font: 13px/1.4 system-ui, sans-serif; color: ${ACCENT}; opacity: .8; padding: 0 1rem; text-align: center; pointer-events: none; }`,
    ].join('\n');
}

export function createOverlay(doc = document) {
    const sheet = 'adoptedStyleSheets' in doc ? new CSSStyleSheet() : null;
    if (sheet) doc.adoptedStyleSheets = [...doc.adoptedStyleSheets, sheet];

    const BASE = [
        '[data-block-id] { cursor: default; }',
        '[data-k-editable], [data-k-title] { cursor: text; }',
        '[data-k-editable]:focus { outline: none; }',
        '[data-k-field]:empty::before { content: attr(data-placeholder); opacity: .45; pointer-events: none; }',
        '[data-k-editable="rich"]:not(:focus):has(> p:only-child > br:only-child)::before { content: attr(data-placeholder); opacity: .45; pointer-events: none; position: absolute; }',
        '[data-k-editable="rich"]:has(> p:only-child > br:only-child) { position: relative; }',
        '[data-k-field]:empty { min-height: 1.4em; min-width: 2ch; }',
    ];

    let holders = '';
    let last = {};
    /** The kinds of block that hold others, for the empty ones' room and hint. */
    function setHolders(names, label) {
        const next = emptyHolderRule(names, label);
        if (next === holders) return;
        holders = next;
        outline(last);
    }

    /** Ids are checked by the caller (safeId), so they can go into a selector as they are. */
    function outline({ hover, selected, also = [] } = {}) {
        last = { hover, selected, also };
        if (!sheet) return;
        const rules = holders ? [holders] : [];
        if (hover && hover !== selected) rules.push(`[data-block-id="${hover}"] { outline: 1px dashed ${ACCENT} !important; outline-offset: 2px; }`);
        if (selected) rules.push(`[data-block-id="${selected}"] { outline: 2px solid ${ACCENT} !important; outline-offset: 2px; }`);
        // the others picked with it: the same outline (never a background: that would hide the block's own)
        if (also.length)
            rules.push(`${also.map((id) => `[data-block-id="${id}"]`).join(', ')} { outline: 2px solid ${ACCENT} !important; outline-offset: 2px; }`);
        sheet.replaceSync([...rules, ...BASE].join('\n'));
    }

    let mark = null;
    const markEl = () => {
        if (!mark) {
            mark = doc.createElement('div');
            mark.setAttribute('aria-hidden', 'true');
            mark.style.cssText = `position:fixed;z-index:2147483645;pointer-events:none;background:${ACCENT};border-radius:2px;transition:top 60ms,left 60ms,width 60ms,height 60ms;`;
            doc.body.appendChild(mark);
        }
        return mark;
    };

    /**
     * A line before, after, left or right of the block, or a box over it (inside).
     * @param {{ where?: string, end?: boolean }} where  the editor's drop target
     * @param {DOMRect|null} r  the block's box (or, for the end, the list's)
     */
    function showDrop(where, r) {
        const el = markEl();
        if (!r) {
            el.hidden = true;
            return;
        }
        el.hidden = false;
        const box = (top, left, width, height, fill = true) =>
            Object.assign(el.style, {
                top: `${top}px`,
                left: `${left}px`,
                width: `${width}px`,
                height: `${height}px`,
                background: fill ? ACCENT : 'rgb(37 99 235 / .08)',
                outline: fill ? 'none' : `2px solid ${ACCENT}`,
            });
        const at = where.end ? 'after' : where.where;
        if (at === 'before') box(r.top - 4, r.left, r.width, 3);
        else if (at === 'after') box(r.bottom + 1, r.left, r.width, 3);
        else if (at === 'left') box(r.top, r.left - 4, 3, r.height);
        else if (at === 'right') box(r.top, r.right + 1, 3, r.height);
        else box(r.top, r.left, r.width, r.height, false);
    }
    const hideDrop = () => {
        if (mark) mark.hidden = true;
    };

    outline({ hover: null, selected: null });
    return { outline, setHolders, showDrop, hideDrop };
}
