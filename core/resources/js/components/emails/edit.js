/**
 * An email edit as the Emails editor changes it. Pure: no translations, no
 * Vue; labels are in the page. The server checks every edit (EmailEdit).
 *
 *   fromEdit({ subject, blocks })   blocks with a _key each, every field there for v-model
 *   toEdit(draft)                   what PUT /emails/{id} and the preview take
 *   sameEdit(a, b)                  whether two edits would save the same
 */

/** What an edit can add, in the order the editor offers it. */
export const ADDABLE = ['heading', 'paragraph', 'button', 'image', 'divider', 'note'];
/** Blocks whose words an edit writes. */
const TEXT = ['heading', 'paragraph', 'note'];

let next = 0;
const key = () => `b${++next}`;

/** A new block of one type, empty where the person writes. */
export function newBlock(type, extra = {}) {
    const fields = {
        heading: { text: '' },
        paragraph: { text: '' },
        note: { text: '' },
        button: { label: '', url: 'https://' },
        image: { media: null, alt: '' },
        divider: {},
        original: { name: '', text: '', label: '' },
    }[type];
    if (!fields) throw new Error(`Unknown email block type "${type}".`);
    return { _key: key(), type, ...fields, ...extra };
}

/** A saved edit (or the template) as the editor holds it. */
export function fromEdit(edit) {
    return {
        subject: edit?.subject ?? '',
        blocks: (edit?.blocks ?? []).map((b) => newBlock(b.type, Object.fromEntries(Object.entries(b).filter(([k]) => k !== 'type')))),
    };
}

/** The editor's draft as the API takes it: only each type's own fields; kept blocks carry new words only when there are some. */
export function toEdit(draft) {
    return {
        subject: draft.subject ?? '',
        blocks: draft.blocks.map((b) => {
            if (TEXT.includes(b.type)) return { type: b.type, text: b.text ?? '' };
            if (b.type === 'button') return { type: 'button', label: b.label ?? '', url: b.url ?? '' };
            if (b.type === 'image') return { type: 'image', media: b.media ?? null, alt: b.alt ?? '' };
            if (b.type === 'original') {
                const out = { type: 'original', name: b.name };
                if (String(b.text ?? '').trim() !== '') out.text = b.text;
                if (String(b.label ?? '').trim() !== '') out.label = b.label;
                return out;
            }
            return { type: b.type };
        }),
    };
}

/** Whether two edits (toEdit() shapes) save the same: the subject is kept trimmed. */
export function sameEdit(a, b) {
    return JSON.stringify({ ...a, subject: a.subject.trim() }) === JSON.stringify({ ...b, subject: b.subject.trim() });
}

/**
 * The previewed email's HTML as one mail app shows it: its dark styles on
 * for "dark", off for "light", whatever the admin's own colour scheme, and
 * its links opening in a new tab.
 */
export function previewDocument(html, scheme) {
    return String(html)
        .replace('@media (prefers-color-scheme: dark){', scheme === 'dark' ? '@media all{' : '@media not all{')
        .replace(/<head>/i, '<head><base target="_blank">');
}
