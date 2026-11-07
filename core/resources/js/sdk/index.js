/**
 * @kestello/sdk: what admin pages (core and plugins) build on.
 *
 *   import { useApi, useResource, useCan, notify, confirm, __ } from '@kestello/sdk';
 *
 *   const api = useApi();
 *   const users = useResource('/users', { query: { limit: 10 } });
 *   await api.put('/system/platform/cache', { driver: 'redis', options });  // asks for the password when needed
 *
 * The client talks to /api/v1 with the session cookie and the CSRF header;
 * tokens never touch localStorage. Errors are ApiError (RFC 9457 problems).
 * One shared instance for everyone, provided through the import map.
 */
import { computed, reactive, ref, shallowRef, watch, onBeforeUnmount, getCurrentInstance } from 'vue';
import { usePage, useRouter } from '@kestello/app';
import { createCatalogue } from './catalogue.js';
import { format } from './messageformat.js';
import { formatBytes as formatBytesFor } from './bytes.js';

export const SDK_VERSION = '0.1.0';

// ── admin context ─────────────────────────────────────────────────────

/** The admin boot data of the current page: { menu, capabilities, api, version, locale, dir, translations }. */
export function useAdmin() {
    const page = usePage();
    return computed(() => page.props.admin ?? { menu: [], capabilities: [], api: '/api/v1' });
}

/** const can = useCan(); can('system.manage') — cosmetic: the API enforces every check. */
export function useCan() {
    const admin = useAdmin();
    return (capability) => {
        const caps = admin.value.capabilities ?? [];
        return caps.includes('*') || caps.includes(capability);
    };
}

// ── extensions ────────────────────────────────────────────────────────

/** Does a slot's `when` filter ({ type: ['post', 'page'] }) match this context? */
export function matchesWhen(when, context = {}) {
    return Object.entries(when ?? {}).every(([key, values]) => values.includes(context?.[key]));
}

/**
 * What plugins registered at a point, already filtered by capability on the
 * server: useExtensions('widgets', 'dashboard'), useExtensions('columns', 'users.list'),
 * useExtensions('slots', 'users.edit.panels', () => ({ user })),
 * useExtensions('columns', 'entries.list', () => ({ type })). With a context,
 * entries registered with `when` show only where it matches.
 */
export function useExtensions(kind, point, context = null) {
    const admin = useAdmin();
    return computed(() => {
        const list = admin.value.extensions?.[kind]?.[point] ?? [];
        if (!context) return list;
        const ctx = typeof context === 'function' ? context() : context;
        return list.filter((e) => matchesWhen(e.when, ctx));
    });
}

/** The component name that replaces a core component, or null. */
export function useReplacement(name) {
    const admin = useAdmin();
    return computed(() => admin.value.extensions?.replace?.[name] ?? null);
}

/**
 * Optional sugar for extension components: declares the props every point
 * passes ({ context } for slots and widgets, { row, value } for cells).
 *
 *   export default defineAdminExtension({ setup(props) { ... } });
 */
export function defineAdminExtension(options) {
    return {
        ...options,
        props: { context: { type: Object, default: () => ({}) }, row: { type: Object, default: null }, value: { default: null }, ...(options.props ?? {}) },
    };
}

/** The signed-in user ({id, username, email, role}) or null. */
export function useUser() {
    const page = usePage();
    return computed(() => page.props.auth?.user ?? null);
}

// ── API client ────────────────────────────────────────────────────────

export class ApiError extends Error {
    constructor(status, problem = {}) {
        super(problem.detail || problem.title || `Request failed (${status})`);
        this.name = 'ApiError';
        this.status = status;
        this.type = problem.type ?? null;
        this.code = typeof problem.type === 'string' ? problem.type.split(':').pop() : null;
        this.title = problem.title ?? '';
        this.detail = problem.detail ?? '';
        this.errors = problem.errors ?? {};
        this.problem = problem;
    }

    /** First message per field: { 'options.host': 'This field is required.' } */
    fieldErrors() {
        const out = {};
        for (const [field, messages] of Object.entries(this.errors)) out[field] = Array.isArray(messages) ? messages[0] : String(messages);
        return out;
    }
}

const hooks = { reauth: null };

/** @internal The admin layout registers the password prompt here. */
export function setReauthHandler(handler) {
    hooks.reauth = handler;
}

export function createApi({ base = '/api/v1', csrf = () => '' } = {}) {
    async function request(method, path, { query, body, raw, form, signal, blob = false, retried = false } = {}) {
        const url = new URL(base.replace(/\/$/, '') + '/' + String(path).replace(/^\//, ''), window.location.origin);
        for (const [key, value] of Object.entries(query ?? {})) {
            if (value !== undefined && value !== null && value !== '')
                url.searchParams.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
        }
        const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        const init = { method, headers, credentials: 'same-origin', signal };
        if (method !== 'GET' && method !== 'HEAD') {
            headers['X-CSRF-TOKEN'] = csrf() ?? '';
            if (form !== undefined) {
                init.body = form; // FormData: the browser sets the multipart boundary
            } else if (raw !== undefined) {
                headers['Content-Type'] = 'application/octet-stream';
                init.body = raw;
            } else if (body !== undefined) {
                headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(body);
            }
        }

        let response;
        try {
            response = await fetch(url, init);
        } catch (error) {
            if (error.name === 'AbortError') throw error;
            throw new ApiError(0, { title: 'Network error', detail: 'The server could not be reached. Check your connection and try again.' });
        }

        if (response.status === 204) return null;
        if (blob && response.ok) return response.blob();
        const isJson = (response.headers.get('Content-Type') ?? '').includes('json');
        const data = isJson ? await response.json().catch(() => null) : null;
        if (response.ok) return data;

        const error = new ApiError(response.status, data ?? { title: response.statusText });
        if (error.code === 'reauth_required' && !retried && hooks.reauth) {
            if (await hooks.reauth()) return request(method, path, { query, body, raw, form, signal, blob, retried: true });
        }
        if (error.code === 'unauthenticated' && typeof window !== 'undefined') {
            window.location.href = '/login';
        }
        throw error;
    }

    return {
        request,
        get: (path, query, options = {}) => request('GET', path, { ...options, query }),
        post: (path, body, options = {}) => request('POST', path, { ...options, body }),
        put: (path, body, options = {}) => request('PUT', path, { ...options, body }),
        patch: (path, body, options = {}) => request('PATCH', path, { ...options, body }),
        delete: (path, options = {}) => request('DELETE', path, options),
        /** POST multipart form data (file uploads): api.upload('/themes/import', formData) */
        upload: (path, form, options = {}) => request('POST', path, { ...options, form }),
        /** GET a file and save it: api.download('/backups/x.zip/download', 'x.zip'). Asks for the password again when the endpoint needs it. */
        download: async (path, filename, options = {}) => {
            const file = await request('GET', path, { ...options, blob: true });
            const href = URL.createObjectURL(file);
            Object.assign(document.createElement('a'), { href, download: filename }).click();
            setTimeout(() => URL.revokeObjectURL(href), 1000);
        },
    };
}

/** API client bound to the current page's CSRF token and API base. Call in setup(). */
export function useApi() {
    const page = usePage();
    return createApi({ base: page.props.admin?.api ?? '/api/v1', csrf: () => page.props.csrfToken });
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function postToBucket(direct, file, onProgress, signal) {
    return new Promise((resolve, reject) => {
        const form = new FormData();
        for (const [key, value] of Object.entries(direct.fields)) form.append(key, value);
        form.append('file', file);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', direct.url);
        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) onProgress(e.loaded / e.total);
        };
        xhr.onload = () =>
            xhr.status >= 200 && xhr.status < 300
                ? resolve()
                : reject(new ApiError(xhr.status, { title: 'Upload refused', detail: 'The storage service refused the file.' }));
        xhr.onerror = () =>
            reject(
                new ApiError(0, {
                    title: 'Network error',
                    detail: "The file could not be sent to storage. Check your connection, or ask an administrator to allow this site in the bucket's CORS rules.",
                }),
            );
        signal?.addEventListener('abort', () => {
            xhr.abort();
            reject(new DOMException('Aborted', 'AbortError'));
        });
        xhr.send(form);
    });
}

/**
 * Upload a File to the media library and resolve with the new media item.
 * Large files go in chunks (retried on network errors) or straight to the
 * bucket when the site's storage takes direct uploads.
 *
 *   const media = await uploadMedia(useApi(), file, { onProgress: (f) => (progress.value = f) });
 */
export async function uploadMedia(api, file, { onProgress = () => {}, signal, details = {} } = {}) {
    const upload = (await api.post('/media/uploads', { filename: file.name, size: file.size }, { signal })).data;
    try {
        if (upload.mode === 'direct') {
            await postToBucket(upload.direct, file, onProgress, signal);
        } else {
            for (let i = upload.received; i < upload.chunks; i++) {
                const chunk = file.slice(i * upload.chunk_size, Math.min(file.size, (i + 1) * upload.chunk_size));
                for (let attempt = 1; ; attempt++) {
                    try {
                        await api.request('PUT', `/media/uploads/${upload.id}/chunks/${i}`, { raw: chunk, signal });
                        break;
                    } catch (error) {
                        const retryable = error.name !== 'AbortError' && (error.status === 0 || error.status >= 500 || error.status === 429);
                        if (!retryable || attempt >= 4) throw error;
                        await sleep(800 * attempt);
                    }
                }
                onProgress(Math.min(1, ((i + 1) * upload.chunk_size) / file.size));
            }
        }
        return (await api.post(`/media/uploads/${upload.id}/complete`, details, { signal })).data;
    } catch (error) {
        api.delete(`/media/uploads/${upload.id}`).catch(() => {}); // a refused file is already gone (404)
        throw error;
    }
}

/**
 * Load a resource and keep it fresh:
 *   const { data, meta, loading, error, reload } = useResource('/system/queue', { poll: 10 });
 * query may be a ref/getter; the request repeats when it changes.
 */
export function useResource(path, { query = {}, immediate = true, poll = 0, transform = (body) => body } = {}) {
    const api = useApi();
    const data = shallowRef(null);
    const meta = shallowRef(null);
    const error = shallowRef(null);
    const loading = ref(false);
    let controller = null;
    let timer = null;

    const resolveQuery = () => (typeof query === 'function' ? query() : (query?.value ?? query));
    const resolvePath = () => (typeof path === 'function' ? path() : (path?.value ?? path));

    async function reload({ silent = false } = {}) {
        controller?.abort();
        controller = new AbortController();
        if (!silent) loading.value = true;
        try {
            const body = transform(await api.get(resolvePath(), resolveQuery(), { signal: controller.signal }));
            data.value = body && typeof body === 'object' && 'data' in body ? body.data : body;
            meta.value = body?.meta ?? null;
            error.value = null;
        } catch (e) {
            if (e.name !== 'AbortError') error.value = e;
        } finally {
            if (!silent) loading.value = false;
        }
    }

    if (typeof query === 'function' || query?.value !== undefined || typeof path === 'function') {
        watch([resolvePath, () => JSON.stringify(resolveQuery())], () => reload());
    }
    if (immediate && typeof window !== 'undefined') reload();
    if (poll > 0 && typeof window !== 'undefined') {
        timer = setInterval(() => {
            if (document.visibilityState === 'visible') reload({ silent: true });
        }, poll * 1000);
    }
    if (getCurrentInstance()) {
        onBeforeUnmount(() => {
            controller?.abort();
            clearInterval(timer);
        });
    }

    return { data, meta, error, loading, reload };
}

// ── toasts and dialogs (rendered by the admin layout) ────────────────

export const toasts = reactive([]);
let toastId = 0;

/**
 * @param {{action?: {label: string, run: () => void}, timeout?: number}} [options]
 *   action: one button on the toast (such as "Undo"); choosing it runs it and closes the toast
 */
function push(kind, message, timeout, { action = null, timeout: own } = {}) {
    const id = ++toastId;
    const act = action && typeof action.run === 'function' && action.label ? { label: String(action.label), run: action.run } : null;
    toasts.push({ id, kind, message: message instanceof Error ? message.message : String(message), action: act });
    const ms = own ?? timeout;
    if (ms > 0) setTimeout(() => dismissToast(id), ms);
    return id;
}

/** Runs a toast's action and closes it. */
export function runToastAction(id) {
    const toast = toasts.find((t) => t.id === id);
    dismissToast(id);
    toast?.action?.run();
}

export function dismissToast(id) {
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

/** Toasts; each returns its id (for dismissToast). Options: { action: { label, run }, timeout }. */
export const notify = {
    success: (message, options) => push('success', message, 4000, options),
    info: (message, options) => push('info', message, 5000, options),
    warning: (message, options) => push('warning', message, 8000, options),
    error: (message, options) => push('danger', message, 10000, options),
};

export const dialogs = reactive([]);

/**
 * if (await confirm({ title: 'Delete 3 jobs?', message: 'This cannot be undone.', confirmLabel: 'Delete', danger: true })) …
 */
export function confirm(options) {
    const opts = typeof options === 'string' ? { message: options } : options;
    return new Promise((resolve) => {
        dialogs.push({
            kind: 'confirm',
            title: opts.title ?? __('Are you sure?'),
            message: opts.message ?? '',
            confirmLabel: opts.confirmLabel ?? __('Confirm'),
            danger: !!opts.danger,
            resolve,
        });
    });
}

/**
 * useLeaveGuard(dirty): while dirty (a ref, computed or function) is true, leaving
 * the page asks first: a link or the menu with a dialog, reload or close with the
 * browser's own question. Removed when the component unmounts.
 */
export function useLeaveGuard(dirty) {
    const router = useRouter();
    const holding = () => !!(typeof dirty === 'function' ? dirty() : dirty?.value);
    router.confirmLeave ??= () =>
        confirm({ title: __('Leave without saving?'), message: __('Your changes on this page are not saved.'), confirmLabel: __('Leave'), danger: true });
    const remove = router.leaveGuards.add(holding);
    const beforeUnload = (event) => {
        if (!holding()) return;
        event.preventDefault();
        event.returnValue = '';
    };
    if (typeof window !== 'undefined') window.addEventListener('beforeunload', beforeUnload);
    onBeforeUnmount(() => {
        remove();
        if (typeof window !== 'undefined') window.removeEventListener('beforeunload', beforeUnload);
    });
}

/** @internal Ask for the password (recent authentication). Resolves true when confirmed. */
export function requestPassword() {
    return new Promise((resolve) => dialogs.push({ kind: 'password', title: __('Confirm your password'), resolve }));
}

// ── i18n and formatting ──────────────────────────────────────────────

const catalogue = createCatalogue(reactive({}));
const i18n = reactive({ locale: 'en' });

/**
 * Add translations for a domain ("core", or a plugin's or theme's slug):
 * addMessages({ Save: 'Sačuvaj' }, 'acme-shop'). The admin loads every active
 * domain's messages itself (see loadTranslations); plugins rarely need this.
 */
export function addMessages(dictionary, domain = 'core') {
    catalogue.add(dictionary, domain);
}

/**
 * @internal Server-side rendering: the page's language and messages, replacing the last
 * page's (the SSR server renders one page after another in the same process).
 */
export function useMessagesFor(locale, messages) {
    setLocale(locale);
    catalogue.replace(messages);
}

/** @internal The locale messages are formatted for (plural rules, numbers, dates). */
export function setLocale(locale) {
    i18n.locale = String(locale || 'en').replace(/_/g, '-');
}

/**
 * The translation of a source text, formatted as ICU MessageFormat:
 *   __('Hello, {name}!', { name })
 *   __('{count, plural, one {# file} other {# files}}', { count })
 *   __('Greeting', {}, 'acme-shop')   a plugin's own text (falls back to core's)
 * Untranslated text is the English source itself.
 */
export function __(text, params = {}, domain = 'core') {
    return format(catalogue.lookup(text, domain), params, i18n.locale);
}

/**
 * const { __, locale } = useI18n('acme-shop'): __ bound to a plugin's domain,
 * and the current locale ("sr-Latn").
 */
export function useI18n(domain = 'core') {
    return { __: (text, params = {}) => __(text, params, domain), locale: computed(() => i18n.locale) };
}

/**
 * @internal Load the admin's translations (boot data: { locale, translations }) before
 * the first render. A failed download leaves the admin in English, and says so.
 */
export async function loadTranslations(admin, fetcher = globalThis.fetch) {
    setLocale(admin?.locale ?? 'en');
    if (!admin?.translations) return false;
    try {
        await catalogue.load(admin.translations, fetcher);
        return true;
    } catch (error) {
        console.error('[kestello] translations could not load; showing English', error);
        return false;
    }
}

const units = [
    ['year', 31536000],
    ['month', 2592000],
    ['week', 604800],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
    ['second', 1],
];

/** unix seconds, an ISO 8601 string or a Date. */
function toDate(value) {
    if (value instanceof Date) return value;
    if (typeof value === 'string' && !/^\d+(\.\d+)?$/.test(value)) return new Date(value);
    return new Date(Number(value) * 1000);
}

/** formatRelative(1793806676) → "5 minutes ago" / "in 2 hours" (unix seconds, ISO string or Date). */
export function formatRelative(value, now = Date.now()) {
    if (value === null || value === undefined) return '—';
    const ts = toDate(value).getTime();
    if (Number.isNaN(ts)) return '—';
    const diff = Math.round((ts - now) / 1000);
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    for (const [unit, seconds] of units) {
        if (Math.abs(diff) >= seconds || unit === 'second') return rtf.format(Math.round(diff / seconds), unit);
    }
    return '';
}

export function formatDateTime(value) {
    if (value === null || value === undefined) return '—';
    const date = toDate(value);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

/**
 * A deep copy of JSON data. Use it instead of structuredClone, which
 * throws on Vue's reactive proxies ("could not be cloned").
 */
export function clone(value) {
    return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
}

/** formatBytes(1536) → "1.5 KB", in the admin language's number format (bytes.js). */
export function formatBytes(bytes) {
    return formatBytesFor(bytes, i18n.locale);
}

export function formatNumber(value) {
    return new Intl.NumberFormat().format(value ?? 0);
}

/**
 * "Show this field when…" rules, the same as the server's
 * Kestello\Cms\Content\Conditions: one pass from the top, a hidden field
 * counts as empty for the fields below it.
 *
 * @param {Array<{name: string, conditions?: {match: string, rules: Array<{field: string, op: string, value: string}>}|null}>} fields
 * @param {Record<string, unknown>} data
 * @returns {Record<string, boolean>} name => visible
 */
export function visibleFields(fields, data) {
    const text = (v) => (v === true ? '1' : v === false || v === null || v === undefined ? '' : ['string', 'number'].includes(typeof v) ? String(v) : '');
    const test = (value, op, expected) => {
        const empty = value === null || value === undefined || value === '' || value === false || (Array.isArray(value) && !value.length);
        const strings = Array.isArray(value) ? value.map(text) : [text(value)];
        switch (op) {
            case 'filled':
                return !empty;
            case 'empty':
                return empty;
            case 'is':
                return !empty && strings.includes(expected);
            case 'is_not':
                return empty || !strings.includes(expected);
            case 'contains':
                return !empty && expected !== '' && strings.some((s) => s.toLowerCase().includes(expected.toLowerCase()));
            default:
                return false;
        }
    };
    const visible = {};
    for (const f of fields) {
        const c = f.conditions;
        if (!c || !c.rules?.length) {
            visible[f.name] = true;
            continue;
        }
        const results = c.rules.map((r) => test(visible[r.field] ? data?.[r.field] : null, r.op, String(r.value ?? '')));
        visible[f.name] = c.match === 'any' ? results.includes(true) : !results.includes(false);
    }
    return visible;
}
