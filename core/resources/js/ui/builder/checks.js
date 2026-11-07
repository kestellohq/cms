/**
 * Accessibility checks on what the canvas shows: the page as visitors get it,
 * theme included. Returns issues: { tone: danger | warning, id, message }, where
 * id is the block of the tree the problem is in (null when it is elsewhere).
 */
const VAGUE = ['click here', 'here', 'read more', 'more', 'link', 'this'];

/**
 * @param {Document} doc        the canvas document
 * @param {{ kind: string, owns: (id: string) => boolean, t: Function }} options
 *        owns: is this block in the tree being edited; t: the translation function
 */
export function checkPage(doc, { kind, owns, t }) {
    const scope = doc?.querySelector('.k-template');
    if (!doc || !scope) return [];
    const out = [];
    const blockOf = (el) => {
        for (let x = el.closest('[data-block-id]'); x; x = x.parentElement?.closest('[data-block-id]') ?? null) {
            const id = x.getAttribute('data-block-id');
            if (owns(id)) return id;
        }
        return null;
    };
    const issue = (tone, el, message) => out.push({ tone, id: el ? blockOf(el) : null, message });
    const text = (el) => (el.textContent ?? '').replace(/\s+/g, ' ').trim();
    const named = (el) =>
        text(el) || el.getAttribute('aria-label') || el.getAttribute('title') || [...el.querySelectorAll('img[alt]')].some((i) => i.alt.trim());

    let prev = 0;
    for (const h of doc.querySelectorAll('h1, h2, h3, h4, h5, h6')) {
        const level = Number(h.tagName[1]);
        if (!text(h)) issue('danger', h, t('An empty heading. Screen reader users jump between headings.'));
        if (prev && level > prev + 1 && scope.contains(h))
            issue('warning', h, t('Heading level jumps from {a} to {b}. Use the levels in order.', { a: `h${prev}`, b: `h${level}` }));
        prev = level;
    }
    const h1s = doc.querySelectorAll('h1');
    if (['template', 'entry'].includes(kind) && !h1s.length) issue('warning', null, t('The page has no main heading (h1). Show the entry title as Heading 1.'));
    if (h1s.length > 1) issue('warning', h1s[1], t('More than one main heading (h1).'));
    for (const img of scope.querySelectorAll('img:not([alt])')) issue('danger', img, t('An image without alt text.'));
    for (const a of scope.querySelectorAll('a[href]')) {
        if (!named(a)) issue('danger', a, t('A link with no text: nobody can tell where it goes.'));
        else if (VAGUE.includes(text(a).toLowerCase())) issue('warning', a, t("Link text “{t}” doesn't say where it goes.", { t: text(a) }));
    }
    for (const b of scope.querySelectorAll('button')) if (!named(b)) issue('danger', b, t('A button with no text.'));
    for (const f of scope.querySelectorAll('iframe:not([title])')) issue('warning', f, t('An embedded frame without a title.'));
    for (const input of scope.querySelectorAll('input:not([type=hidden]):not([type=submit]), select, textarea')) {
        const labelled =
            input.getAttribute('aria-label') ||
            input.getAttribute('aria-labelledby') ||
            (input.id && doc.querySelector(`label[for="${CSS.escape(input.id)}"]`)) ||
            input.closest('label');
        if (!labelled) issue('danger', input, t('A form field without a label.'));
    }
    return out;
}
