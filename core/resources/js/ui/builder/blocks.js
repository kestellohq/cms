/**
 * What the editor knows about blocks, given the catalog (name → definition as
 * GET /blocks describes it). Pure functions: nothing here touches Vue or the page.
 */
import { newId, reid } from './tree.js';

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };
/** Text without its markup: tags dropped, entities read, spaces collapsed. */
export const plain = (html) =>
    String(html ?? '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (m, e) => {
            if (e[0] !== '#') return ENTITIES[e.toLowerCase()] ?? m;
            const code = e[1] === 'x' || e[1] === 'X' ? parseInt(e.slice(2), 16) : Number(e.slice(1));
            return code > 0 && code < 0x110000 ? String.fromCodePoint(code) : m;
        })
        .replace(/\s+/g, ' ')
        .trim();
const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

/**
 * A new block of this kind with its defaults. A required choice starts on its
 * first option; a block made of one kind of child (columns, tabs) starts with
 * some of them.
 */
export function createBlock(def, catalog) {
    const values = {};
    for (const f of def.fields) {
        if (f.default !== null && f.default !== undefined) values[f.name] = f.default;
        else if (f.required && f.type === 'select' && f.options?.choices?.length) values[f.name] = f.options.choices[0].value;
        else if (f.required && Array.isArray(f.options?.suggest) && f.options.suggest.length) values[f.name] = f.options.suggest[0].value;
    }
    const node = { type: def.name, id: newId(), props: values, children: [] };
    // what it starts with: its own starter (BlockDefinition::starter), else two of its only kind of child
    // (columns: as many as the layout has)
    if (Array.isArray(def.starter) && def.starter.length) {
        node.children = def.starter
            .filter((s) => catalog.has(s.type))
            .map((s) => {
                const child = createBlock(catalog.get(s.type), catalog);
                return { ...child, props: { ...child.props, ...(s.props ?? {}) } };
            });
        return node;
    }
    const only = Array.isArray(def.children) && def.children.length === 1 ? catalog.get(def.children[0]) : null;
    if (only) {
        const count = def.name === 'core/columns' ? String(values.layout ?? '1-1').split('-').length : 2;
        node.children = Array.from({ length: count }, () => createBlock(only, catalog));
    }
    return node;
}

/** A pattern as blocks: a synced pattern stays one reference; the others are copied (in a group when there are several). */
export function fromPattern(pattern, catalog) {
    if (pattern.synced) return { type: 'core/pattern', id: newId(), props: { pattern: pattern.key }, children: [] };
    const copies = pattern.blocks.filter((b) => catalog.has(b.type)).map(reid);
    if (copies.length === 1) return copies[0];
    return { ...createBlock(catalog.get('core/group'), catalog), children: copies };
}

/** The kind of block a pattern becomes, before it is made (dragging decides where it may go). */
export function patternDef(pattern, catalog) {
    if (pattern.synced) return catalog.get('core/pattern') ?? null;
    const usable = pattern.blocks.filter((b) => catalog.has(b.type));
    return usable.length === 1 ? catalog.get(usable[0].type) : (catalog.get('core/group') ?? null);
}

const bound = (v) => (v && typeof v === 'object' && typeof v.bind === 'string' ? v.bind : null);
const TEXT_TYPES = ['text', 'textarea', 'rich_text', 'url', 'link'];

/** One line about a block for the layers: its text, what data it shows, or its main choice. */
/** The words a block shows itself (text fields or bound data), or '' (null: no definition). */
function ownText(node, def, bindingLabel) {
    if (!def) return null;
    const p = node.props ?? {};
    for (const f of def.fields) {
        const v = p[f.name];
        if (bound(v)) return bindingLabel(bound(v));
        if (TEXT_TYPES.includes(f.type) && typeof v === 'string' && plain(v)) return plain(v).slice(0, 60);
    }
    return '';
}

const choiceLabel = (f, value) => f.options?.choices?.find((c) => c.value === value)?.label ?? '';

/**
 * A block in a few words, for the layers and lists: what a visitor would read in it, so it
 * matches the page. Its own text first; a field marked as the summary (options.summary,
 * such as the columns' layout); for a block holding others, the first words inside it;
 * for any other block, its first choice that is set (a space's size).
 * @param {(node: object) => object|null} [defOf]  needed to look inside a block holding others
 */
export function summarize(node, def, bindingLabel = (p) => p, defOf = null) {
    const own = ownText(node, def, bindingLabel);
    if (own !== '') return own;
    const p = node.props ?? {};
    const marked = def.fields.find((f) => f.options?.summary && p[f.name] !== undefined && p[f.name] !== '');
    if (marked) return marked.type === 'select' ? choiceLabel(marked, p[marked.name]) : String(p[marked.name]);
    // a block that holds text but has none yet says nothing, rather than naming its alignment
    if (def.fields.some((f) => ['text', 'textarea', 'rich_text'].includes(f.type))) return '';
    if (def.children) return defOf ? firstWords(node.children ?? [], defOf, bindingLabel) : '';
    const choice = def.fields.find((f) => f.type === 'select' && p[f.name] !== undefined && p[f.name] !== '');
    return choice ? choiceLabel(choice, p[choice.name]) : '';
}

/** The first words found inside these blocks, depth first ('' when none). */
function firstWords(nodes, defOf, bindingLabel) {
    for (const n of nodes) {
        const own = ownText(n, defOf(n), bindingLabel);
        if (own) return own;
        const inner = firstWords(n.children ?? [], defOf, bindingLabel);
        if (inner) return inner;
    }
    return '';
}

/** The icon a block shows: the one for its field's value (iconBy, a heading's level), else its own. */
export function blockIcon(node, def) {
    if (!def) return 'warning';
    const by = def.iconBy;
    if (by?.field) {
        const field = def.fields?.find((f) => f.name === by.field);
        const value = node?.props?.[by.field] ?? field?.default;
        const icon = value === undefined || value === null ? null : by.icons?.[String(value)];
        if (icon) return icon;
    }
    return def.icon ?? 'square';
}

/** How each kind of text field is typed on the page. */
export const INLINE = { text: 'plain', textarea: 'multi', rich_text: 'rich' };

/** For each block, the fields that may be typed straight into the page: text that holds text (not bound to data, not locked). */
export function editableFields(tree, defOf, { disabled = false, locks = true } = {}) {
    const out = {};
    if (disabled) return out;
    const visit = (nodes) => {
        for (const n of nodes) {
            const def = defOf(n);
            if (def && !(locks && (n.lock ?? []).includes('edit'))) {
                for (const f of def.fields) {
                    const v = n.props?.[f.name];
                    if (INLINE[f.type] && (v === undefined || v === null || typeof v === 'string')) (out[n.id] ??= {})[f.name] = INLINE[f.type];
                }
            }
            visit(n.children ?? []);
        }
    };
    visit(tree);
    return out;
}

/** The first field of a block that is typed on the page. */
export const firstInline = (def) => def?.fields.find((f) => INLINE[f.type])?.name ?? null;

// ── turning a block into another ───────────────────────────────────────
export const TEXTUAL = ['core/text', 'core/heading', 'core/quote', 'core/list', 'core/code', 'core/details'];
const ALIGNABLE = ['core/text', 'core/heading', 'core/quote'];

/** A text block's content as lines. */
export function linesOf(node) {
    const p = node.props ?? {};
    if (node.type === 'core/text' || node.type === 'core/details') {
        const parts = String(p.text ?? '')
            .split(/<\/p>|<br\s*\/?>|<\/li>|<\/h[1-6]>/i)
            .map(plain)
            .filter(Boolean);
        return node.type === 'core/details' && p.summary ? [p.summary, ...parts] : parts;
    }
    if (node.type === 'core/list') return (p.items ?? []).map((i) => String(i?.text ?? '').trim()).filter(Boolean);
    if (node.type === 'core/code') return String(p.code ?? '').split('\n');
    return String(p.text ?? '')
        .split('\n')
        .map((l) => l.trim())
        .filter(Boolean);
}

/** Is the block already this kind (and variant)? */
export function isKind(node, type, variant) {
    if (node.type !== type) return false;
    if (type === 'core/heading') return String(node.props?.level ?? '2') === String(variant ?? '2');
    if (type === 'core/list') return !!node.props?.ordered === (variant === 'ordered');
    return true;
}

/** The same block as another kind: same id and place, its text carried over. */
export function turnInto(node, type, variant = null) {
    const lines = linesOf(node);
    const paras = (ls) => ls.map((l) => `<p>${esc(l)}</p>`).join('');
    const props = {
        'core/text': () => ({ text: paras(lines) }),
        'core/heading': () => ({ text: lines.join(' '), level: variant ?? '2' }),
        'core/list': () => ({ items: (lines.length ? lines : ['']).map((t) => ({ text: t.slice(0, 500) })), ordered: variant === 'ordered' }),
        'core/quote': () => ({ text: lines.join('\n'), cite: '' }),
        'core/code': () => ({ code: lines.join('\n'), language: '' }),
        'core/details': () => ({ summary: lines[0] ?? '', text: paras(lines.slice(1)), open: false }),
    }[type]?.();
    if (!props) return node;
    const align = node.props?.align;
    if (align && ALIGNABLE.includes(type) && ALIGNABLE.includes(node.type)) props.align = align;
    return { ...node, type, props, children: [] };
}

// ── counting ───────────────────────────────────────────────────────────
/** Words, characters, paragraphs, blocks and the headings (the outline) of a tree. */
export function documentStats(tree, defOf) {
    let words = 0,
        chars = 0,
        paragraphs = 0,
        blocks = 0;
    const headings = [];
    const count = (v) => {
        const t = plain(v);
        if (t) {
            words += t.split(' ').length;
            chars += t.length;
        }
        return t;
    };
    const visit = (nodes) => {
        for (const n of nodes) {
            blocks++;
            for (const f of defOf(n)?.fields ?? []) {
                const v = n.props?.[f.name];
                if (['text', 'textarea', 'rich_text'].includes(f.type) && typeof v === 'string') {
                    const t = count(v);
                    if (f.type === 'rich_text') paragraphs += (v.match(/<p[\s>]/gi) ?? []).length || (t ? 1 : 0);
                } else if (f.type === 'repeater' && Array.isArray(v)) {
                    for (const item of v) for (const x of Object.values(item ?? {})) if (typeof x === 'string') count(x);
                }
            }
            if (n.type === 'core/heading') headings.push({ id: n.id, level: Number(n.props?.level ?? 2), text: plain(n.props?.text) });
            visit(n.children ?? []);
        }
    };
    visit(tree);
    return { words, chars, paragraphs, blocks, headings, minutes: Math.max(1, Math.round(words / 220)) };
}

/**
 * The block a server error is about: "blocks.2.children.0.props.text" → the third block's first
 * child, and the field. Null when the path no longer leads to a block.
 */
export function errorTarget(tree, key) {
    const parts = String(key).split('.');
    if (parts.shift() !== 'blocks') return null;
    let list = tree;
    let node = null;
    while (parts.length && /^\d+$/.test(parts[0])) {
        node = Array.isArray(list) ? list[Number(parts.shift())] : null;
        if (!node) return null;
        if (parts[0] !== 'children') break;
        parts.shift();
        list = node.children;
    }
    if (!node) return null;
    return { id: node.id, field: parts[0] === 'props' ? (parts[1] ?? null) : null };
}

/**
 * The block a server message is about. Messages start with where the block is, as
 * BlockRegistry writes it: "Block 2 (Group) › Block 1 (Text), Text: This field is required."
 * Returns the block's id and the message without that trail, or null.
 */
export function errorFromMessage(tree, message) {
    const m = /^((?:Block \d+(?: \([^)]*\))?(?: › )?)+)(?:, |: )?/.exec(String(message));
    if (!m) return null;
    let list = tree;
    let node = null;
    for (const [, n] of m[1].matchAll(/Block (\d+)/g)) {
        node = Array.isArray(list) ? list[Number(n) - 1] : null;
        if (!node) return null;
        list = node.children;
    }
    if (!node) return null;
    // "Text, Text: …" names the field after a block of the same name: once is enough
    const label = /\(([^)]*)\)\s*$/.exec(m[1].trim())?.[1];
    let rest = String(message).slice(m[0].length);
    if (label && rest.startsWith(`${label}: `)) rest = rest.slice(label.length + 2);
    return { id: node.id, rest };
}
