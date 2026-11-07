/**
 * ICU MessageFormat for the browser: the same messages PHP formats with intl
 * (Kestello\Support\Translator), so one catalogue serves both.
 *
 *   format('Hello, {name}!', { name: 'Ana' })
 *   format('{count, plural, =0 {No files} one {# file} other {# files}}', { count: 3 }, 'en')
 *   format('{role, select, admin {Administrator} other {Member}}', { role: 'admin' })
 *   format('{n, number, percent}', { n: 0.25 })   format('{d, date, medium}', { d: new Date() })
 *
 * Supported: {arg}, plural (with =N, offset: and #), selectordinal, select,
 * number (integer, percent), date and time (short, medium, long, full), and
 * apostrophe quoting ('{' is a literal brace, '' an apostrophe).
 * A message that doesn't parse is shown with only its {name} parts replaced,
 * like PHP does without intl, and the problem goes to the console once.
 */

const cache = new Map();
/** The # of a plural case: the number, formatted. */
const POUND = Object.freeze({ pound: true });
const warned = new Set();

/**
 * @param {string} message
 * @param {Record<string, unknown>} [params]
 * @param {string} [locale]  a BCP 47 tag ("sr-Latn"); underscores are accepted
 * @returns {string}
 */
export function format(message, params = {}, locale = 'en') {
    const text = String(message ?? '');
    if (!text.includes('{') && !text.includes("'")) return text;
    const tag = String(locale || 'en').replace(/_/g, '-');
    let ast = cache.get(text);
    if (ast === undefined) {
        try {
            ast = parse(text);
        } catch (error) {
            ast = null;
            if (!warned.has(text)) {
                warned.add(text);
                console.warn(`[kestello] message "${text}" is not valid ICU: ${error.message}`);
            }
        }
        cache.set(text, ast);
    }
    if (ast === null) return text.replace(/\{(\w+)\}/g, (m, key) => (key in params ? String(params[key]) : m));
    return render(ast, params ?? {}, tag, null);
}

// ── parsing ──────────────────────────────────────────────────────────

/**
 * The message as a list of parts: strings, POUND (#), and { name, type, style, options, offset }.
 * @param {string} text
 */
export function parse(text) {
    const state = { text, i: 0 };
    const parts = parseMessage(state, false);
    if (state.i < text.length) throw new Error(`unexpected "}" at ${state.i}`);
    return parts;
}

function parseMessage(state, inPlural) {
    const parts = [];
    let buffer = '';
    const { text } = state;
    while (state.i < text.length) {
        const c = text[state.i];
        if (c === "'") {
            const next = text[state.i + 1];
            if (next === "'") {
                buffer += "'";
                state.i += 2;
            } else if (next === '{' || next === '}' || (inPlural && next === '#')) {
                // quoted literal up to the next lone apostrophe ('' inside is one apostrophe)
                state.i += 1;
                while (state.i < text.length) {
                    if (text[state.i] === "'" && text[state.i + 1] === "'") {
                        buffer += "'";
                        state.i += 2;
                    } else if (text[state.i] === "'") {
                        state.i += 1;
                        break;
                    } else {
                        buffer += text[state.i++];
                    }
                }
            } else {
                buffer += "'";
                state.i += 1;
            }
        } else if (c === '{') {
            if (buffer) parts.push(buffer);
            buffer = '';
            state.i += 1;
            parts.push(parseArgument(state));
        } else if (c === '}') {
            break;
        } else if (c === '#' && inPlural) {
            if (buffer) parts.push(buffer);
            buffer = '';
            parts.push(POUND);
            state.i += 1;
        } else {
            buffer += c;
            state.i += 1;
        }
    }
    if (buffer) parts.push(buffer);
    return parts;
}

function skipSpace(state) {
    while (state.i < state.text.length && /\s/.test(state.text[state.i])) state.i += 1;
}

function readWord(state) {
    skipSpace(state);
    const m = /^[^\s,{}]+/.exec(state.text.slice(state.i));
    if (!m) throw new Error(`expected a name at ${state.i}`);
    state.i += m[0].length;
    skipSpace(state);
    return m[0];
}

function expect(state, char) {
    skipSpace(state);
    if (state.text[state.i] !== char) throw new Error(`expected "${char}" at ${state.i}`);
    state.i += 1;
}

function parseArgument(state) {
    const name = readWord(state);
    if (state.text[state.i] === '}') {
        state.i += 1;
        return { name, type: null };
    }
    expect(state, ',');
    const type = readWord(state);
    if (type === 'plural' || type === 'selectordinal' || type === 'select') {
        expect(state, ',');
        return { name, type, ...parseOptions(state, type !== 'select') };
    }
    let style = null;
    if (state.text[state.i] === ',') {
        state.i += 1;
        const end = state.text.indexOf('}', state.i);
        if (end < 0) throw new Error('unclosed argument');
        style = state.text.slice(state.i, end).trim();
        state.i = end;
    }
    expect(state, '}');
    return { name, type, style };
}

function parseOptions(state, plural) {
    const options = {};
    let offset = 0;
    for (;;) {
        skipSpace(state);
        if (state.text[state.i] === '}') {
            state.i += 1;
            break;
        }
        if (state.i >= state.text.length) throw new Error('unclosed argument');
        const selector = readWord(state);
        if (plural && selector.startsWith('offset:')) {
            offset = Number(selector.slice(7));
            if (!Number.isFinite(offset)) throw new Error(`bad offset "${selector}"`);
            continue;
        }
        expect(state, '{');
        options[selector] = parseMessage(state, plural);
        expect(state, '}');
    }
    if (!('other' in options)) throw new Error('a plural or select needs an "other" case');
    return { options, offset };
}

// ── formatting ───────────────────────────────────────────────────────

function render(parts, params, locale, number) {
    let out = '';
    for (const part of parts) {
        if (part === POUND) out += number === null ? '#' : formatNumber(number, locale, null);
        else if (typeof part === 'string') out += part;
        else out += renderArgument(part, params, locale);
    }
    return out;
}

function renderArgument(arg, params, locale) {
    if (!(arg.name in params)) return `{${arg.name}}`;
    const value = params[arg.name];
    switch (arg.type) {
        case null:
            return value instanceof Date ? formatDate(value, locale, 'medium', 'date') : String(value);
        case 'number':
            return formatNumber(Number(value), locale, arg.style);
        case 'date':
        case 'time':
            return formatDate(toDate(value), locale, arg.style ?? 'medium', arg.type);
        case 'select':
            return render(arg.options[String(value)] ?? arg.options.other, params, locale, null);
        case 'plural':
        case 'selectordinal': {
            const n = Number(value);
            const exact = arg.options[`=${n}`];
            if (exact) return render(exact, params, locale, n - arg.offset);
            const category = pluralRules(locale, arg.type === 'selectordinal' ? 'ordinal' : 'cardinal').select(n - arg.offset);
            return render(arg.options[category] ?? arg.options.other, params, locale, n - arg.offset);
        }
        default:
            return String(value);
    }
}

const rulesCache = new Map();
function pluralRules(locale, type) {
    const key = `${locale}|${type}`;
    if (!rulesCache.has(key)) {
        let rules;
        try {
            rules = new Intl.PluralRules(locale, { type });
        } catch {
            rules = new Intl.PluralRules('en', { type });
        }
        rulesCache.set(key, rules);
    }
    return rulesCache.get(key);
}

function formatNumber(n, locale, style) {
    const options = style === 'integer' ? { maximumFractionDigits: 0 } : style === 'percent' ? { style: 'percent' } : {};
    try {
        return new Intl.NumberFormat(locale, options).format(n);
    } catch {
        return String(n);
    }
}

function toDate(value) {
    if (value instanceof Date) return value;
    if (typeof value === 'number') return new Date(value < 1e12 ? value * 1000 : value); // unix seconds or milliseconds
    return new Date(String(value));
}

function formatDate(date, locale, style, type) {
    const known = ['short', 'medium', 'long', 'full'];
    const s = known.includes(style) ? style : 'medium';
    const options = type === 'time' ? { timeStyle: s === 'full' || s === 'long' ? 'medium' : 'short' } : { dateStyle: s };
    try {
        return new Intl.DateTimeFormat(locale, options).format(date);
    } catch {
        return date.toISOString();
    }
}
