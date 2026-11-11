/**
 * The "Other languages" setting as the admin edits it: a string of codes
 * written as in addresses ("es,pt-br"). Pure, so it is tested on its own;
 * the server checks the codes again when the setting is saved.
 */

/** "pt_BR" → "pt-br"; '' when it isn't a language code (the same rule as ContentLanguages::code). */
export function code(value) {
    const c = String(value ?? '')
        .trim()
        .toLowerCase()
        .replaceAll('_', '-');
    return /^[a-z]{2,3}(-[a-z0-9]{2,8}){0,2}$/.test(c) ? c : '';
}

/** The codes in a stored value, cleaned up, in order, without repeats. */
export function codes(value) {
    const out = [];
    for (const part of String(value ?? '').split(',')) {
        const c = code(part);
        if (c && !out.includes(c)) out.push(c);
    }
    return out;
}

/** The value with $added at the end (unchanged when it is malformed, already there, or the main language). */
export function add(value, added, main = '') {
    const c = code(added);
    const list = codes(value);
    return c && c !== code(main) && !list.includes(c) ? [...list, c].join(',') : list.join(',');
}

/** The value without $removed. */
export function remove(value, removed) {
    return codes(value)
        .filter((c) => c !== code(removed))
        .join(',');
}

/**
 * Languages that had entries and are left out of the new value: their
 * entries stay, but are no longer public. For the warning before saving.
 *
 * @param {string} saved the stored value
 * @param {string} draft the value being edited
 * @param {Array<{code: string, name: string, entries: number}>} languages from GET /content/languages
 */
export function dropped(saved, draft, languages) {
    const kept = codes(draft);
    return codes(saved)
        .filter((c) => !kept.includes(c))
        .map((c) => languages.find((l) => l.code === c))
        .filter((l) => l && l.entries > 0);
}

/**
 * An entry's place in each of the site's other languages: its translation
 * there, or null where it has none yet. In the order the site lists them.
 *
 * @param {{locale: string, translations: Array<{locale: string, id: number, status: string, title: string}>}} entry
 * @param {Array<{code: string, name: string}>} languages from GET /content/languages
 * @returns {Array<{code: string, name: string, translation: object|null}>}
 */
export function slots(entry, languages) {
    return languages
        .filter((l) => l.code !== (entry?.locale ?? ''))
        .map((l) => ({ code: l.code, name: l.name, translation: (entry?.translations ?? []).find((t) => t.locale === l.code) ?? null }));
}

/** The short label of a language in lists: its code in capitals, "EN" for the main one given its own code. */
export function badge(code, main = '') {
    return (code || main || '').toUpperCase();
}

/** The site's texts in other languages, from the setting's JSON: { es: { site_name, site_description } }. */
export function texts(value) {
    try {
        const parsed = JSON.parse(value || '{}');
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
        return {};
    }
}

/** The setting with one text changed; empty texts and languages left out ('' when nothing is left). */
export function setText(value, code, key, text) {
    const all = texts(value);
    const own = { ...(all[code] ?? {}), [key]: text };
    if (!String(text ?? '').trim()) delete own[key];
    if (Object.keys(own).length) all[code] = own;
    else delete all[code];
    return Object.keys(all).length ? JSON.stringify(all) : '';
}

/**
 * The prefixes a language can translate: each content type's and taxonomy's
 * own (not top-level pages, which have none), once, the shortest first.
 */
export function prefixes(types, taxonomies) {
    const seen = new Map();
    for (const owner of [...(types ?? []), ...(taxonomies ?? [])]) {
        if (owner?.path && !seen.has(owner.path)) seen.set(owner.path, { path: owner.path, label: owner.plural ?? owner.name });
    }
    return [...seen.values()].sort((a, b) => a.path.length - b.path.length || a.path.localeCompare(b.path));
}

/** A type's or taxonomy's prefix in a language with its own word for it: ('/blog/x', { '/blog': '/noticias' }) → '/noticias/x'. */
export function localPath(path, paths) {
    const found = Object.keys(paths ?? {})
        .sort((a, b) => b.length - a.length)
        .find((from) => path === from || path.startsWith(`${from.replace(/\/$/, '')}/`));
    return found ? paths[found] + path.slice(found.length) : path;
}

/**
 * An entry's fields with a note on those its translations share
 * (translatable: false), once it has a translation: saving one changes it
 * in every language.
 */
export function noteShared(fields, hasTranslations, note) {
    if (!hasTranslations) return fields;
    return (fields ?? []).map((f) => (f.translatable === false ? { ...f, help: f.help ? `${f.help} ${note}` : note } : f));
}
