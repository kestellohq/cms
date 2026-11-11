/**
 * Exhibit's rooms, and what a label plate prints. Pure functions: the
 * layout and pages pass in the translate function (__), so these read the
 * same in every language and can be tested without Vue.
 */

/** The content types the theme knows, and the room each one hangs in. */
export const ROOMS = { projects: 'projects', team: 'studio', talks: 'talks', post: 'journal' };

/**
 * The room of a page: its wall colour. The entrance is the projects room;
 * an entry or a list follows its type; the studio page (its address in
 * this language is the shared prop exhibit.studio) is the studio;
 * categories, tags and authors are read in the journal.
 */
export function roomOf(component, props = {}) {
    if (component === 'Home') return 'projects';
    const type = props.type?.name;
    if (component === '@core/content/Entry' || component === '@core/content/Archive') {
        if (ROOMS[type]) return ROOMS[type];
        if (type === 'page') return props.exhibit?.studio && props.entry?.path === props.exhibit.studio ? 'studio' : 'hall';
        if (component === '@core/content/Archive') return 'journal';
    }
    return 'hall';
}

/** A room's name in the page's language, for a type the theme knows; else the type's own. */
export function roomName(typeName, fallback, __) {
    const names = { projects: __('Projects'), team: __('People'), talks: __('Talks'), post: __('Journal') };
    return names[typeName] ?? fallback;
}

/** The fields a project's plate prints, in order, with how each value reads. */
const PLATE = ['place', 'year', 'use', 'area', 'status'];

/** The longest text a plate prints; longer ones belong to the page. */
const PLATE_TEXT = 80;

/** "housing" → "Housing": a choice the theme has no words for, written readably. */
const readable = (key) =>
    String(key)
        .replace(/[_-]+/g, ' ')
        .replace(/^\w/, (c) => c.toUpperCase());

/**
 * The rows of a label plate from an entry's fields: [{ label, value }].
 * Known fields come first, in plate order, with the theme's words for them
 * and their choices; other short fields follow under a readable name.
 * Empty values, lists, objects and long texts (a plate is small) are left
 * out. "number" is the catalogue number, printed apart (plateNumber).
 */
export function plateRows(fields, __) {
    const f = fields ?? {};
    const labels = { place: __('Place'), year: __('Year'), use: __('Use'), area: __('Area'), status: __('Status') };
    const choices = {
        housing: __('Housing'),
        culture: __('Culture'),
        workplace: __('Workplace'),
        landscape: __('Landscape'),
        education: __('Education'),
        built: __('Built'),
        construction: __('Under construction'),
        study: __('Study'),
        competition: __('Competition'),
    };
    const show = (key, value) => {
        if (key === 'area') return __('{n} m²', { n: Number(value).toLocaleString() });
        if (typeof value === 'string' && choices[value]) return choices[value];
        if (typeof value === 'string' && /^[a-z_-]+$/.test(value)) return readable(value);
        return String(value);
    };
    const keys = [...PLATE.filter((k) => k in f), ...Object.keys(f).filter((k) => !PLATE.includes(k) && k !== 'number')];
    return keys
        .filter((k) => f[k] !== null && f[k] !== '' && typeof f[k] !== 'object' && !(typeof f[k] === 'string' && f[k].length > PLATE_TEXT))
        .map((k) => ({ key: k, label: labels[k] ?? readable(k), value: show(k, f[k]) }));
}

/** The catalogue number printed on a plate: "Cat. 07", or '' without one. */
export function plateNumber(fields, __) {
    const n = fields?.number;
    return n === undefined || n === null || n === '' ? '' : __('Cat. {n}', { n: String(n).padStart(2, '0') });
}

/** A photograph's credit as its label prints it: the media's caption, else nothing. */
export function credit(media) {
    return (media?.caption ?? '').trim();
}

/**
 * A talk's date as wall text, in the page's language: { day: "12 March", time: "18:30" }.
 * `past` is true once it has started.
 */
export function talkDate(iso, locale, now = new Date()) {
    if (!iso) return null;
    const at = new Date(iso);
    if (Number.isNaN(at.getTime())) return null;
    return {
        day: new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long' }).format(at),
        time: new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' }).format(at),
        year: at.getFullYear(),
        past: at <= now,
    };
}

/**
 * Talks as a programme reads them: the coming ones first, the soonest at
 * the top, then the ones held, the latest first.
 */
export function programme(talks, now = new Date()) {
    const at = (e) => new Date(e.fields?.starts ?? e.published_at ?? 0).getTime() || 0;
    const coming = talks.filter((e) => at(e) > now.getTime()).sort((a, b) => at(a) - at(b));
    const held = talks.filter((e) => at(e) <= now.getTime()).sort((a, b) => at(b) - at(a));
    return [...coming, ...held];
}

/** A room's line under its name, in the page's language, for the rooms the theme knows; else the type's own description. */
export function roomLede(typeName, fallback, __) {
    const ledes = {
        projects: __('Built work, work on site and studies, newest first.'),
        talks: __('Evenings at the studio and elsewhere, the next one first.'),
        team: __('The people who work at the studio.'),
        post: __('Notes from the studio: places we visited, drawings, and what we are reading.'),
    };
    return ledes[typeName] ?? fallback ?? '';
}

/** Whether a value is an image from the media library, as pages receive it. */
const isImage = (m) => !!m && typeof m === 'object' && typeof m.url === 'string' && m.kind === 'image';

/**
 * Every photograph an entry carries, in field order: single image fields
 * and galleries alike, each once. The first is the one the entry hangs by.
 */
export function photosOf(data) {
    const seen = new Set();
    const out = [];
    for (const [key, value] of Object.entries(data ?? {})) {
        if (key === 'seo') continue;
        for (const m of Array.isArray(value) ? value : [value]) {
            if (isImage(m) && !seen.has(m.id ?? m.url)) {
                seen.add(m.id ?? m.url);
                out.push(m);
            }
        }
    }
    return out;
}
