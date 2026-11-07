/**
 * What the canvas draws over the page: outlines for the hovered and selected
 * block, and the mark showing where a dragged block would land. Nothing here
 * changes the page's own elements; outlines are one adopted stylesheet and the
 * drop mark is a fixed element of its own.
 */
const ACCENT = '#2563eb';

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

    /** Ids are checked by the caller (safeId), so they can go into a selector as they are. */
    function outline({ hover, selected, also = [] }) {
        if (!sheet) return;
        const rules = [];
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
    return { outline, showDrop, hideDrop };
}
