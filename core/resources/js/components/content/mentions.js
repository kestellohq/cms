/**
 * @mentions in review comments, the same rules as the server (Reviews::mentions):
 * a name of 3 to 50 letters, digits, _ or -, not inside a word or an email address.
 *
 *   mentionAt('Thanks @ma', 10)        → { start: 7, query: 'ma' }   (the caret is after "@ma")
 *   insertMention('Thanks @ma', 10, 'maria') → { text: 'Thanks @maria ', caret: 14 }
 *   splitMentions('Hi @maria!')        → [{ text: 'Hi ' }, { text: '@maria', mention: 'maria' }, { text: '!' }]
 */
const NAME = /(?<![A-Za-z0-9_.@-])@([A-Za-z0-9_-]{3,50})(?![A-Za-z0-9_-])/g;

/** The @name being typed just before the caret, or null. */
export function mentionAt(text, caret) {
    const m = /(?:^|[^A-Za-z0-9_.@-])@([A-Za-z0-9_-]{0,50})$/.exec(text.slice(0, caret));
    return m ? { start: caret - m[1].length - 1, query: m[1] } : null;
}

/** People whose name starts with the query (then contains it), at most `max`. */
export function matchPeople(people, query, max = 6) {
    const q = query.toLowerCase();
    const starts = people.filter((p) => p.username.toLowerCase().startsWith(q));
    const contains = people.filter((p) => !starts.includes(p) && p.username.toLowerCase().includes(q));
    return [...starts, ...contains].slice(0, max);
}

/** The text with the @name being typed replaced by a whole one and a space. */
export function insertMention(text, caret, username) {
    const at = mentionAt(text, caret);
    if (!at) return { text, caret };
    const before = `${text.slice(0, at.start)}@${username} `;
    const after = text.slice(caret).replace(/^ /, '');
    return { text: before + after, caret: before.length };
}

/** A comment cut into plain text and @mentions, for showing it. */
export function splitMentions(body) {
    const out = [];
    let last = 0;
    for (const m of body.matchAll(NAME)) {
        if (m.index > last) out.push({ text: body.slice(last, m.index) });
        out.push({ text: m[0], mention: m[1] });
        last = m.index + m[0].length;
    }
    if (last < body.length) out.push({ text: body.slice(last) });
    return out;
}
