/**
 * Where a tree from the editor goes in the page being shown: a template's own
 * blocks, or, for an entry, its body field (inside the template's entry-content
 * block, and in the entry's data). Pure: returns new page props.
 */

/** The template's blocks with the entry-content block for this field holding the given blocks. */
export function withBody(nodes, field, blocks) {
    return nodes.map((n) => {
        if (n?.type === 'core/entry-content' && (n.props?.field ?? '') === field) return { ...n, children: blocks };
        return Array.isArray(n?.children) && n.children.length ? { ...n, children: withBody(n.children, field, blocks) } : n;
    });
}

/** Does the template show this body field anywhere (its entry-content block)? */
export function hasBody(nodes, field) {
    return (nodes ?? []).some(
        (n) => (n?.type === 'core/entry-content' && (n.props?.field ?? '') === field) || (Array.isArray(n?.children) && hasBody(n.children, field)),
    );
}

/**
 * A template without a slot for the body would hide everything being edited. The canvas
 * then shows the body after the template, in a slot of its own, and says so to the editor.
 */
export const FALLBACK_ID = 'kbodyfallback';

/**
 * Blocks typed into since the last tree get a new revision, so they render afresh
 * (the browser has already changed their text while they were edited).
 */
export function stamp(nodes, edited, rev) {
    if (!edited.size) return nodes;
    return nodes.map((n) => ({
        ...n,
        ...(edited.has(n.id) ? { _rev: rev } : {}),
        children: Array.isArray(n.children) ? stamp(n.children, edited, rev) : n.children,
    }));
}

/**
 * @param {object} props   the page's props now
 * @param {{ kind: string, field?: string }} design  what the canvas shows (router.page.props.design)
 * @param {{ blocks: object[], title?: string }} data  the message from the editor
 */
export function placeTree(props, design, data) {
    // (the template's own blocks never include FALLBACK_ID; it is added here, each time)
    const blocks = data.blocks;
    if (design.kind !== 'entry') return { ...props, template: { ...(props.template ?? {}), blocks } };
    const field = design.field;
    const next = { ...props };
    if (props.template) {
        const own = (props.template.blocks ?? []).filter((n) => n?.id !== FALLBACK_ID);
        next.template = {
            ...props.template,
            blocks: hasBody(own, field)
                ? withBody(own, field, blocks)
                : [...own, { type: 'core/entry-content', id: FALLBACK_ID, props: { field }, children: blocks, component: '@core/blocks/EntryContent' }],
        };
    }
    if (props.entry) {
        next.entry = { ...props.entry, data: { ...(props.entry.data ?? {}), [field]: blocks } };
        if (typeof data.title === 'string') next.entry.title = data.title;
    }
    if (typeof data.title === 'string') next.title = data.title;
    return next;
}
