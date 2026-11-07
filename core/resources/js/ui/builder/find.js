/**
 * Find and replace across a document's blocks (BuilderFind.vue). Only the words people
 * read are searched: text, long text, rich text (its text, never its tags or links),
 * table cells and the text inside repeating items (a list's items). Settings, keys and
 * links are left alone, and so is a field marked option('findable', false).
 * Everything here is pure: a tree in, matches or a new tree out.
 */

const TEXT_TYPES = new Set(['text', 'textarea', 'rich_text', 'table']);
const MAX_QUERY = 200;

const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** A search as a RegExp (global), or null when there is nothing to look for. */
export function searchPattern(query, { matchCase = false } = {}) {
    const q = String(query ?? '');
    if (q.trim() === '' || q.length > MAX_QUERY) return null;
    return new RegExp(escapeRe(q), matchCase ? 'g' : 'gi');
}

/** The fields of a block that hold words: [{ name, type, fields? }] (fields: a repeater's own). */
export function textFields(fields = []) {
    return fields.flatMap((f) => {
        if (f.options?.findable === false) return [];
        if (f.type === 'repeater') {
            const inner = textFields(f.fields ?? []);
            return inner.length ? [{ name: f.name, type: 'repeater', fields: inner }] : [];
        }
        return TEXT_TYPES.has(f.type) ? [{ name: f.name, type: f.type }] : [];
    });
}

// ── rich text: replace in the text between tags, with entities read as the characters they are

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", '#39': "'", nbsp: ' ' };
const decode = (s) =>
    s.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+\d*);/gi, (m, e) => {
        const k = e.toLowerCase();
        if (k in ENTITIES) return ENTITIES[k];
        if (k.startsWith('#x')) return String.fromCodePoint(parseInt(k.slice(2), 16));
        if (k.startsWith('#')) return String.fromCodePoint(Number(k.slice(1)));
        return m;
    });
const encode = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** Replace (or, with replacement null, count) in plain text. */
function inPlain(text, re, replacement) {
    re.lastIndex = 0;
    const count = (text.match(re) ?? []).length;
    if (!count || replacement === null) return { text, count };
    return { text: text.replace(re, () => replacement), count };
}

/** Replace (or count) in rich text: only text between tags changes; a segment without a match is kept as it was. */
function inHtml(html, re, replacement) {
    let count = 0;
    const out = html
        .split(/(<[^>]*>)/)
        .map((part, i) => {
            if (i % 2 === 1 || part === '') return part; // a tag
            const r = inPlain(decode(part), re, replacement);
            count += r.count;
            return r.count && replacement !== null ? encode(r.text) : part;
        })
        .join('');
    return { text: out, count };
}

/** One field's value: counted, or replaced. Values that aren't text (bound to data, for one) are left as they are. */
function inValue(value, field, re, replacement) {
    if (field.type === 'repeater') {
        if (!Array.isArray(value)) return { value, count: 0 };
        let count = 0;
        const items = value.map((item) => {
            if (!item || typeof item !== 'object') return item;
            const r = inProps(item, field.fields, re, replacement);
            count += r.count;
            return r.count ? r.props : item;
        });
        return { value: count ? items : value, count };
    }
    if (field.type === 'table') {
        if (!value || !Array.isArray(value.rows)) return { value, count: 0 };
        let count = 0;
        const rows = value.rows.map((row) =>
            Array.isArray(row)
                ? row.map((cell) => {
                      if (typeof cell !== 'string') return cell;
                      const r = inPlain(cell, re, replacement);
                      count += r.count;
                      return r.text;
                  })
                : row,
        );
        return { value: count ? { ...value, rows } : value, count };
    }
    if (typeof value !== 'string') return { value, count: 0 };
    const r = field.type === 'rich_text' ? inHtml(value, re, replacement) : inPlain(value, re, replacement);
    return { value: r.text, count: r.count };
}

function inProps(props, fields, re, replacement) {
    let count = 0;
    const next = { ...props };
    const where = [];
    for (const f of fields) {
        if (!(f.name in (props ?? {}))) continue;
        const r = inValue(props[f.name], f, re, replacement);
        if (r.count) {
            count += r.count;
            where.push(f.name);
            next[f.name] = r.value;
        }
    }
    return { props: count ? next : props, count, where };
}

/**
 * Every block with a match, in the order of the page.
 * @param {object[]} list            the document
 * @param {(node: object) => object|null} defOf  a block's definition
 * @returns {{ id: string, count: number, fields: string[], snippet: string }[]}
 */
export function findMatches(list, defOf, query, options = {}) {
    const re = searchPattern(query, options);
    if (!re) return [];
    const out = [];
    const walk = (nodes) => {
        for (const node of nodes ?? []) {
            const fields = textFields(defOf(node)?.fields);
            const r = fields.length ? inProps(node.props ?? {}, fields, re, null) : { count: 0 };
            if (r.count)
                out.push({
                    id: node.id,
                    count: r.count,
                    fields: r.where,
                    snippet: snippet(
                        node.props[r.where[0]],
                        fields.find((f) => f.name === r.where[0]),
                        re,
                    ),
                });
            walk(node.children);
        }
    };
    walk(list);
    return out;
}

/**
 * The document with every match replaced (in the blocks named, or all), and how many were.
 * @param {(node: object) => boolean} [skip]  blocks to leave alone (locked by the layout)
 */
export function replaceMatches(list, defOf, query, replacement, { ids = null, skip = () => false, ...options } = {}) {
    const re = searchPattern(query, options);
    if (!re) return { list, count: 0 };
    let count = 0;
    const walk = (nodes) => {
        let changed = false;
        const next = nodes.map((node) => {
            let n = node;
            if ((!ids || ids.includes(node.id)) && !skip(node)) {
                const fields = textFields(defOf(node)?.fields);
                const r = fields.length ? inProps(node.props ?? {}, fields, re, String(replacement ?? '')) : { count: 0 };
                if (r.count) {
                    count += r.count;
                    n = { ...node, props: r.props };
                }
            }
            if (node.children?.length) {
                const kids = walk(node.children);
                if (kids !== node.children) n = { ...n, children: kids };
            }
            if (n !== node) changed = true;
            return n;
        });
        return changed ? next : nodes;
    };
    const next = walk(list);
    return { list: next, count };
}

/** A few words around the first match, as plain text, for the results list. */
export function snippet(value, field, re, room = 32) {
    let text = '';
    if (field?.type === 'repeater' && Array.isArray(value))
        text = value.map((item) => field.fields.map((f) => (typeof item?.[f.name] === 'string' ? item[f.name] : '')).join(' ')).join(' · ');
    else if (field?.type === 'table')
        text = (value?.rows ?? [])
            .flat()
            .filter((c) => typeof c === 'string')
            .join(' · ');
    else if (typeof value === 'string') text = field?.type === 'rich_text' ? decode(value.replace(/<[^>]*>/g, ' ')) : value;
    text = text.replace(/\s+/g, ' ').trim();
    re.lastIndex = 0;
    const m = re.exec(text);
    if (!m) return text.slice(0, room * 2);
    const start = Math.max(0, m.index - room);
    const end = Math.min(text.length, m.index + m[0].length + room);
    return `${start > 0 ? '…' : ''}${text.slice(start, end)}${end < text.length ? '…' : ''}`;
}

/** A snippet in parts, the matches marked: [{ text, hit }] (drawn as text, never as HTML). */
export function highlight(text, query, options = {}) {
    const re = searchPattern(query, options);
    if (!re || !text) return [{ text: text ?? '', hit: false }];
    const parts = [];
    let at = 0;
    for (const m of text.matchAll(re)) {
        if (m.index > at) parts.push({ text: text.slice(at, m.index), hit: false });
        parts.push({ text: m[0], hit: true });
        at = m.index + m[0].length;
    }
    if (at < text.length) parts.push({ text: text.slice(at), hit: false });
    return parts;
}
