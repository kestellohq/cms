/**
 * The line under a form's file field: how large a file may be and, when several
 * are allowed, how many. Shared by the site's Form block and the form builder's
 * canvas. A field not saved yet has no limits of its own, so the server's
 * defaults (Forms::cleanOptions: 5 MB, 5 files) are shown, never "undefined".
 * The words come from the caller, already in its language.
 */
export const DEFAULT_MAX_MB = 5;
export const DEFAULT_MAX_FILES = 5;
/** The most files one field takes, whatever it asks for. */
export const MOST_FILES = 10;

const whole = (v, fallback) => (Number.isFinite(Number(v)) && Number(v) >= 1 && v !== '' && v !== null ? Math.floor(Number(v)) : fallback);

/**
 * @param {{max_size?: unknown, multiple?: unknown, max?: unknown}} options
 * @param {{one: (p: {size: number}) => string, many: (p: {size: number, count: number}) => string}} words
 */
export function fileHint(options, words) {
    const size = whole(options?.max_size, DEFAULT_MAX_MB);
    const count = options?.multiple ? whole(options?.max, DEFAULT_MAX_FILES) : 1;

    return count > 1 ? words.many({ size, count }) : words.one({ size });
}

/** Put {name} values into a sentence translated elsewhere; unknown names stay as written. */
export function fill(sentence, params = {}) {
    return String(sentence).replace(/\{(\w+)\}/g, (all, name) => (name in params ? String(params[name]) : all));
}
