import { computed, getCurrentInstance, onBeforeUnmount, reactive, ref, shallowRef, watch } from "vue";
import { usePage, useRouter } from "@kestello/app";
// core/resources/js/sdk/catalogue.js
/**
* Translations per domain ("core", a plugin's or theme's slug), as the SDK keeps
* them. Pure: the store is handed in (the SDK passes a reactive object, so
* pages re-render when messages arrive).
*/
/** @param {Record<string, Record<string, string>>} [store] */
function createCatalogue(store = {}) {
	return {
		/** Merge messages into a domain. */
		add(dictionary, domain = "core") {
			const clean = {};
			for (const [key, value] of Object.entries(dictionary ?? {})) if (typeof value === "string" && value !== "") clean[key] = value;
			store[domain] = {
				...store[domain] ?? {},
				...clean
			};
		},
		/** Keep only these messages ({ domain: { text: translation } }), dropping all others. */
		replace(data) {
			for (const domain of Object.keys(store)) delete store[domain];
			for (const [domain, dictionary] of Object.entries(data ?? {})) if (dictionary && typeof dictionary === "object") this.add(dictionary, domain);
		},
		/** The domain's translation, else core's, else the source text itself. */
		lookup(text, domain = "core") {
			return store[domain]?.[text] ?? (domain !== "core" ? store.core?.[text] : void 0) ?? text;
		},
		/**
		* Fetch a catalogue ({ domain: { text: translation } }) and add it.
		* Throws with the reason when it can't (the caller decides what to say).
		*/
		async load(url, fetcher) {
			const response = await fetcher(url, { credentials: "same-origin" });
			if (!response.ok) throw new Error(`HTTP ${response.status} for ${url}`);
			const data = await response.json();
			if (!data || typeof data !== "object" || Array.isArray(data)) throw new Error(`${url} is not a catalogue`);
			for (const [domain, dictionary] of Object.entries(data)) if (dictionary && typeof dictionary === "object") this.add(dictionary, domain);
		}
	};
}
// core/resources/js/sdk/messageformat.js
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
var cache = /* @__PURE__ */ new Map();
/** The # of a plural case: the number, formatted. */
var POUND = Object.freeze({ pound: true });
var warned = /* @__PURE__ */ new Set();
/**
* @param {string} message
* @param {Record<string, unknown>} [params]
* @param {string} [locale]  a BCP 47 tag ("sr-Latn"); underscores are accepted
* @returns {string}
*/
function format(message, params = {}, locale = "en") {
	const text = String(message ?? "");
	if (!text.includes("{") && !text.includes("'")) return text;
	const tag = String(locale || "en").replace(/_/g, "-");
	let ast = cache.get(text);
	if (ast === void 0) {
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
	if (ast === null) return text.replace(/\{(\w+)\}/g, (m, key) => key in params ? String(params[key]) : m);
	return render(ast, params ?? {}, tag, null);
}
/**
* The message as a list of parts: strings, POUND (#), and { name, type, style, options, offset }.
* @param {string} text
*/
function parse(text) {
	const state = {
		text,
		i: 0
	};
	const parts = parseMessage(state, false);
	if (state.i < text.length) throw new Error(`unexpected "}" at ${state.i}`);
	return parts;
}
function parseMessage(state, inPlural) {
	const parts = [];
	let buffer = "";
	const { text } = state;
	while (state.i < text.length) {
		const c = text[state.i];
		if (c === "'") {
			const next = text[state.i + 1];
			if (next === "'") {
				buffer += "'";
				state.i += 2;
			} else if (next === "{" || next === "}" || inPlural && next === "#") {
				state.i += 1;
				while (state.i < text.length) if (text[state.i] === "'" && text[state.i + 1] === "'") {
					buffer += "'";
					state.i += 2;
				} else if (text[state.i] === "'") {
					state.i += 1;
					break;
				} else buffer += text[state.i++];
			} else {
				buffer += "'";
				state.i += 1;
			}
		} else if (c === "{") {
			if (buffer) parts.push(buffer);
			buffer = "";
			state.i += 1;
			parts.push(parseArgument(state));
		} else if (c === "}") break;
		else if (c === "#" && inPlural) {
			if (buffer) parts.push(buffer);
			buffer = "";
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
	if (state.text[state.i] === "}") {
		state.i += 1;
		return {
			name,
			type: null
		};
	}
	expect(state, ",");
	const type = readWord(state);
	if (type === "plural" || type === "selectordinal" || type === "select") {
		expect(state, ",");
		return {
			name,
			type,
			...parseOptions(state, type !== "select")
		};
	}
	let style = null;
	if (state.text[state.i] === ",") {
		state.i += 1;
		const end = state.text.indexOf("}", state.i);
		if (end < 0) throw new Error("unclosed argument");
		style = state.text.slice(state.i, end).trim();
		state.i = end;
	}
	expect(state, "}");
	return {
		name,
		type,
		style
	};
}
function parseOptions(state, plural) {
	const options = {};
	let offset = 0;
	for (;;) {
		skipSpace(state);
		if (state.text[state.i] === "}") {
			state.i += 1;
			break;
		}
		if (state.i >= state.text.length) throw new Error("unclosed argument");
		const selector = readWord(state);
		if (plural && selector.startsWith("offset:")) {
			offset = Number(selector.slice(7));
			if (!Number.isFinite(offset)) throw new Error(`bad offset "${selector}"`);
			continue;
		}
		expect(state, "{");
		options[selector] = parseMessage(state, plural);
		expect(state, "}");
	}
	if (!("other" in options)) throw new Error("a plural or select needs an \"other\" case");
	return {
		options,
		offset
	};
}
function render(parts, params, locale, number) {
	let out = "";
	for (const part of parts) if (part === POUND) out += number === null ? "#" : formatNumber$1(number, locale, null);
	else if (typeof part === "string") out += part;
	else out += renderArgument(part, params, locale);
	return out;
}
function renderArgument(arg, params, locale) {
	if (!(arg.name in params)) return `{${arg.name}}`;
	const value = params[arg.name];
	switch (arg.type) {
		case null: return value instanceof Date ? formatDate(value, locale, "medium", "date") : String(value);
		case "number": return formatNumber$1(Number(value), locale, arg.style);
		case "date":
		case "time": return formatDate(toDate$1(value), locale, arg.style ?? "medium", arg.type);
		case "select": return render(arg.options[String(value)] ?? arg.options.other, params, locale, null);
		case "plural":
		case "selectordinal": {
			const n = Number(value);
			const exact = arg.options[`=${n}`];
			if (exact) return render(exact, params, locale, n - arg.offset);
			const category = pluralRules(locale, arg.type === "selectordinal" ? "ordinal" : "cardinal").select(n - arg.offset);
			return render(arg.options[category] ?? arg.options.other, params, locale, n - arg.offset);
		}
		default: return String(value);
	}
}
var rulesCache = /* @__PURE__ */ new Map();
function pluralRules(locale, type) {
	const key = `${locale}|${type}`;
	if (!rulesCache.has(key)) {
		let rules;
		try {
			rules = new Intl.PluralRules(locale, { type });
		} catch {
			rules = new Intl.PluralRules("en", { type });
		}
		rulesCache.set(key, rules);
	}
	return rulesCache.get(key);
}
function formatNumber$1(n, locale, style) {
	const options = style === "integer" ? { maximumFractionDigits: 0 } : style === "percent" ? { style: "percent" } : {};
	try {
		return new Intl.NumberFormat(locale, options).format(n);
	} catch {
		return String(n);
	}
}
function toDate$1(value) {
	if (value instanceof Date) return value;
	if (typeof value === "number") return new Date(value < 0xe8d4a51000 ? value * 1e3 : value);
	return new Date(String(value));
}
function formatDate(date, locale, style, type) {
	const s = [
		"short",
		"medium",
		"long",
		"full"
	].includes(style) ? style : "medium";
	const options = type === "time" ? { timeStyle: s === "full" || s === "long" ? "medium" : "short" } : { dateStyle: s };
	try {
		return new Intl.DateTimeFormat(locale, options).format(date);
	} catch {
		return date.toISOString();
	}
}
// core/resources/js/sdk/bytes.js
/**
* A size for people: "20 B", "1.5 KB", "12 MB", in the admin language's number
* format. One decimal while the number is under 10, none after. Anything that is
* not a size (missing, negative) shows a dash.
*/
var UNITS = [
	"B",
	"KB",
	"MB",
	"GB",
	"TB"
];
function formatBytes$1(bytes, locale = "en") {
	const size = typeof bytes === "string" && bytes.trim() !== "" ? Number(bytes) : bytes;
	if (typeof size !== "number" || !Number.isFinite(size) || size < 0) return "—";
	let n = size;
	let i = 0;
	while (n >= 1024 && i < UNITS.length - 1) {
		n /= 1024;
		i++;
	}
	const digits = i === 0 || n >= 10 ? 0 : 1;
	return `${new Intl.NumberFormat(locale, { maximumFractionDigits: digits }).format(n)} ${UNITS[i]}`;
}
// core/resources/js/sdk/index.js
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
var SDK_VERSION = "0.1.0";
/** The admin boot data of the current page: { menu, capabilities, api, version, locale, dir, translations }. */
function useAdmin() {
	const page = usePage();
	return computed(() => page.props.admin ?? {
		menu: [],
		capabilities: [],
		api: "/api/v1"
	});
}
/** const can = useCan(); can('system.manage') — cosmetic: the API enforces every check. */
function useCan() {
	const admin = useAdmin();
	return (capability) => {
		const caps = admin.value.capabilities ?? [];
		return caps.includes("*") || caps.includes(capability);
	};
}
/** Does a slot's `when` filter ({ type: ['post', 'page'] }) match this context? */
function matchesWhen(when, context = {}) {
	return Object.entries(when ?? {}).every(([key, values]) => values.includes(context?.[key]));
}
/**
* What plugins registered at a point, already filtered by capability on the
* server: useExtensions('widgets', 'dashboard'), useExtensions('columns', 'users.list'),
* useExtensions('slots', 'users.edit.panels', () => ({ user })),
* useExtensions('columns', 'entries.list', () => ({ type })). With a context,
* entries registered with `when` show only where it matches.
*/
function useExtensions(kind, point, context = null) {
	const admin = useAdmin();
	return computed(() => {
		const list = admin.value.extensions?.[kind]?.[point] ?? [];
		if (!context) return list;
		const ctx = typeof context === "function" ? context() : context;
		return list.filter((e) => matchesWhen(e.when, ctx));
	});
}
/** The component name that replaces a core component, or null. */
function useReplacement(name) {
	const admin = useAdmin();
	return computed(() => admin.value.extensions?.replace?.[name] ?? null);
}
/**
* Optional sugar for extension components: declares the props every point
* passes ({ context } for slots and widgets, { row, value } for cells).
*
*   export default defineAdminExtension({ setup(props) { ... } });
*/
function defineAdminExtension(options) {
	return {
		...options,
		props: {
			context: {
				type: Object,
				default: () => ({})
			},
			row: {
				type: Object,
				default: null
			},
			value: { default: null },
			...options.props ?? {}
		}
	};
}
/** The signed-in user ({id, username, email, role}) or null. */
function useUser() {
	const page = usePage();
	return computed(() => page.props.auth?.user ?? null);
}
var ApiError = class extends Error {
	constructor(status, problem = {}) {
		super(problem.detail || problem.title || `Request failed (${status})`);
		this.name = "ApiError";
		this.status = status;
		this.type = problem.type ?? null;
		this.code = typeof problem.type === "string" ? problem.type.split(":").pop() : null;
		this.title = problem.title ?? "";
		this.detail = problem.detail ?? "";
		this.errors = problem.errors ?? {};
		this.problem = problem;
	}
	/** First message per field: { 'options.host': 'This field is required.' } */
	fieldErrors() {
		const out = {};
		for (const [field, messages] of Object.entries(this.errors)) out[field] = Array.isArray(messages) ? messages[0] : String(messages);
		return out;
	}
};
var hooks = { reauth: null };
/** @internal The admin layout registers the password prompt here. */
function setReauthHandler(handler) {
	hooks.reauth = handler;
}
function createApi({ base = "/api/v1", csrf = () => "" } = {}) {
	async function request(method, path, { query, body, raw, form, signal, blob = false, retried = false } = {}) {
		const url = new URL(base.replace(/\/$/, "") + "/" + String(path).replace(/^\//, ""), window.location.origin);
		for (const [key, value] of Object.entries(query ?? {})) if (value !== void 0 && value !== null && value !== "") url.searchParams.set(key, typeof value === "boolean" ? value ? "1" : "0" : String(value));
		const headers = {
			Accept: "application/json",
			"X-Requested-With": "XMLHttpRequest"
		};
		const init = {
			method,
			headers,
			credentials: "same-origin",
			signal
		};
		if (method !== "GET" && method !== "HEAD") {
			headers["X-CSRF-TOKEN"] = csrf() ?? "";
			if (form !== void 0) init.body = form;
			else if (raw !== void 0) {
				headers["Content-Type"] = "application/octet-stream";
				init.body = raw;
			} else if (body !== void 0) {
				headers["Content-Type"] = "application/json";
				init.body = JSON.stringify(body);
			}
		}
		let response;
		try {
			response = await fetch(url, init);
		} catch (error) {
			if (error.name === "AbortError") throw error;
			throw new ApiError(0, {
				title: "Network error",
				detail: "The server could not be reached. Check your connection and try again."
			});
		}
		if (response.status === 204) return null;
		if (blob && response.ok) return response.blob();
		const data = (response.headers.get("Content-Type") ?? "").includes("json") ? await response.json().catch(() => null) : null;
		if (response.ok) return data;
		const error = new ApiError(response.status, data ?? { title: response.statusText });
		if (error.code === "reauth_required" && !retried && hooks.reauth) {
			if (await hooks.reauth()) return request(method, path, {
				query,
				body,
				raw,
				form,
				signal,
				blob,
				retried: true
			});
		}
		if (error.code === "unauthenticated" && typeof window !== "undefined") window.location.href = "/login";
		throw error;
	}
	return {
		request,
		get: (path, query, options = {}) => request("GET", path, {
			...options,
			query
		}),
		post: (path, body, options = {}) => request("POST", path, {
			...options,
			body
		}),
		put: (path, body, options = {}) => request("PUT", path, {
			...options,
			body
		}),
		patch: (path, body, options = {}) => request("PATCH", path, {
			...options,
			body
		}),
		delete: (path, options = {}) => request("DELETE", path, options),
		/** POST multipart form data (file uploads): api.upload('/themes/import', formData) */
		upload: (path, form, options = {}) => request("POST", path, {
			...options,
			form
		}),
		/** GET a file and save it: api.download('/backups/x.zip/download', 'x.zip'). Asks for the password again when the endpoint needs it. */
		download: async (path, filename, options = {}) => {
			const file = await request("GET", path, {
				...options,
				blob: true
			});
			const href = URL.createObjectURL(file);
			Object.assign(document.createElement("a"), {
				href,
				download: filename
			}).click();
			setTimeout(() => URL.revokeObjectURL(href), 1e3);
		}
	};
}
/** API client bound to the current page's CSRF token and API base. Call in setup(). */
function useApi() {
	const page = usePage();
	return createApi({
		base: page.props.admin?.api ?? "/api/v1",
		csrf: () => page.props.csrfToken
	});
}
var sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
function postToBucket(direct, file, onProgress, signal) {
	return new Promise((resolve, reject) => {
		const form = new FormData();
		for (const [key, value] of Object.entries(direct.fields)) form.append(key, value);
		form.append("file", file);
		const xhr = new XMLHttpRequest();
		xhr.open("POST", direct.url);
		xhr.upload.onprogress = (e) => {
			if (e.lengthComputable) onProgress(e.loaded / e.total);
		};
		xhr.onload = () => xhr.status >= 200 && xhr.status < 300 ? resolve() : reject(new ApiError(xhr.status, {
			title: "Upload refused",
			detail: "The storage service refused the file."
		}));
		xhr.onerror = () => reject(new ApiError(0, {
			title: "Network error",
			detail: "The file could not be sent to storage. Check your connection, or ask an administrator to allow this site in the bucket's CORS rules."
		}));
		signal?.addEventListener("abort", () => {
			xhr.abort();
			reject(new DOMException("Aborted", "AbortError"));
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
async function uploadMedia(api, file, { onProgress = () => {}, signal, details = {} } = {}) {
	const upload = (await api.post("/media/uploads", {
		filename: file.name,
		size: file.size
	}, { signal })).data;
	try {
		if (upload.mode === "direct") await postToBucket(upload.direct, file, onProgress, signal);
		else for (let i = upload.received; i < upload.chunks; i++) {
			const chunk = file.slice(i * upload.chunk_size, Math.min(file.size, (i + 1) * upload.chunk_size));
			for (let attempt = 1;; attempt++) try {
				await api.request("PUT", `/media/uploads/${upload.id}/chunks/${i}`, {
					raw: chunk,
					signal
				});
				break;
			} catch (error) {
				if (!(error.name !== "AbortError" && (error.status === 0 || error.status >= 500 || error.status === 429)) || attempt >= 4) throw error;
				await sleep(800 * attempt);
			}
			onProgress(Math.min(1, (i + 1) * upload.chunk_size / file.size));
		}
		return (await api.post(`/media/uploads/${upload.id}/complete`, details, { signal })).data;
	} catch (error) {
		api.delete(`/media/uploads/${upload.id}`).catch(() => {});
		throw error;
	}
}
/**
* Load a resource and keep it fresh:
*   const { data, meta, loading, error, reload } = useResource('/system/queue', { poll: 10 });
* query may be a ref/getter; the request repeats when it changes.
*/
function useResource(path, { query = {}, immediate = true, poll = 0, transform = (body) => body } = {}) {
	const api = useApi();
	const data = shallowRef(null);
	const meta = shallowRef(null);
	const error = shallowRef(null);
	const loading = ref(false);
	let controller = null;
	let timer = null;
	const resolveQuery = () => typeof query === "function" ? query() : query?.value ?? query;
	const resolvePath = () => typeof path === "function" ? path() : path?.value ?? path;
	async function reload({ silent = false } = {}) {
		controller?.abort();
		controller = new AbortController();
		if (!silent) loading.value = true;
		try {
			const body = transform(await api.get(resolvePath(), resolveQuery(), { signal: controller.signal }));
			data.value = body && typeof body === "object" && "data" in body ? body.data : body;
			meta.value = body?.meta ?? null;
			error.value = null;
		} catch (e) {
			if (e.name !== "AbortError") error.value = e;
		} finally {
			if (!silent) loading.value = false;
		}
	}
	if (typeof query === "function" || query?.value !== void 0 || typeof path === "function") watch([resolvePath, () => JSON.stringify(resolveQuery())], () => reload());
	if (immediate && typeof window !== "undefined") reload();
	if (poll > 0 && typeof window !== "undefined") timer = setInterval(() => {
		if (document.visibilityState === "visible") reload({ silent: true });
	}, poll * 1e3);
	if (getCurrentInstance()) onBeforeUnmount(() => {
		controller?.abort();
		clearInterval(timer);
	});
	return {
		data,
		meta,
		error,
		loading,
		reload
	};
}
var toasts = reactive([]);
var toastId = 0;
/**
* @param {{action?: {label: string, run: () => void}, timeout?: number}} [options]
*   action: one button on the toast (such as "Undo"); choosing it runs it and closes the toast
*/
function push(kind, message, timeout, { action = null, timeout: own } = {}) {
	const id = ++toastId;
	const act = action && typeof action.run === "function" && action.label ? {
		label: String(action.label),
		run: action.run
	} : null;
	toasts.push({
		id,
		kind,
		message: message instanceof Error ? message.message : String(message),
		action: act
	});
	const ms = own ?? timeout;
	if (ms > 0) setTimeout(() => dismissToast(id), ms);
	return id;
}
/** Runs a toast's action and closes it. */
function runToastAction(id) {
	const toast = toasts.find((t) => t.id === id);
	dismissToast(id);
	toast?.action?.run();
}
function dismissToast(id) {
	const index = toasts.findIndex((t) => t.id === id);
	if (index !== -1) toasts.splice(index, 1);
}
/** Toasts; each returns its id (for dismissToast). Options: { action: { label, run }, timeout }. */
var notify = {
	success: (message, options) => push("success", message, 4e3, options),
	info: (message, options) => push("info", message, 5e3, options),
	warning: (message, options) => push("warning", message, 8e3, options),
	error: (message, options) => push("danger", message, 1e4, options)
};
var dialogs = reactive([]);
/**
* if (await confirm({ title: 'Delete 3 jobs?', message: 'This cannot be undone.', confirmLabel: 'Delete', danger: true })) …
*/
function confirm(options) {
	const opts = typeof options === "string" ? { message: options } : options;
	return new Promise((resolve) => {
		dialogs.push({
			kind: "confirm",
			title: opts.title ?? __("Are you sure?"),
			message: opts.message ?? "",
			confirmLabel: opts.confirmLabel ?? __("Confirm"),
			danger: !!opts.danger,
			resolve
		});
	});
}
/**
* useLeaveGuard(dirty): while dirty (a ref, computed or function) is true, leaving
* the page asks first: a link or the menu with a dialog, reload or close with the
* browser's own question. Removed when the component unmounts.
*/
function useLeaveGuard(dirty) {
	const router = useRouter();
	const holding = () => !!(typeof dirty === "function" ? dirty() : dirty?.value);
	router.confirmLeave ??= () => confirm({
		title: __("Leave without saving?"),
		message: __("Your changes on this page are not saved."),
		confirmLabel: __("Leave"),
		danger: true
	});
	const remove = router.leaveGuards.add(holding);
	const beforeUnload = (event) => {
		if (!holding()) return;
		event.preventDefault();
		event.returnValue = "";
	};
	if (typeof window !== "undefined") window.addEventListener("beforeunload", beforeUnload);
	onBeforeUnmount(() => {
		remove();
		if (typeof window !== "undefined") window.removeEventListener("beforeunload", beforeUnload);
	});
}
/** @internal Ask for the password (recent authentication). Resolves true when confirmed. */
function requestPassword() {
	return new Promise((resolve) => dialogs.push({
		kind: "password",
		title: __("Confirm your password"),
		resolve
	}));
}
var catalogue = createCatalogue(reactive({}));
var i18n = reactive({ locale: "en" });
/**
* Add translations for a domain ("core", or a plugin's or theme's slug):
* addMessages({ Save: 'Sačuvaj' }, 'acme-shop'). The admin loads every active
* domain's messages itself (see loadTranslations); plugins rarely need this.
*/
function addMessages(dictionary, domain = "core") {
	catalogue.add(dictionary, domain);
}
/**
* @internal Server-side rendering: the page's language and messages, replacing the last
* page's (the SSR server renders one page after another in the same process).
*/
function useMessagesFor(locale, messages) {
	setLocale(locale);
	catalogue.replace(messages);
}
/** @internal The locale messages are formatted for (plural rules, numbers, dates). */
function setLocale(locale) {
	i18n.locale = String(locale || "en").replace(/_/g, "-");
}
/**
* The translation of a source text, formatted as ICU MessageFormat:
*   __('Hello, {name}!', { name })
*   __('{count, plural, one {# file} other {# files}}', { count })
*   __('Greeting', {}, 'acme-shop')   a plugin's own text (falls back to core's)
* Untranslated text is the English source itself.
*/
function __(text, params = {}, domain = "core") {
	return format(catalogue.lookup(text, domain), params, i18n.locale);
}
/**
* const { __, locale } = useI18n('acme-shop'): __ bound to a plugin's domain,
* and the current locale ("sr-Latn").
*/
function useI18n(domain = "core") {
	return {
		__: (text, params = {}) => __(text, params, domain),
		locale: computed(() => i18n.locale)
	};
}
/**
* @internal Load the admin's translations (boot data: { locale, translations }) before
* the first render. A failed download leaves the admin in English, and says so.
*/
async function loadTranslations(admin, fetcher = globalThis.fetch) {
	setLocale(admin?.locale ?? "en");
	if (!admin?.translations) return false;
	try {
		await catalogue.load(admin.translations, fetcher);
		return true;
	} catch (error) {
		console.error("[kestello] translations could not load; showing English", error);
		return false;
	}
}
var units = [
	["year", 31536e3],
	["month", 2592e3],
	["week", 604800],
	["day", 86400],
	["hour", 3600],
	["minute", 60],
	["second", 1]
];
/** unix seconds, an ISO 8601 string or a Date. */
function toDate(value) {
	if (value instanceof Date) return value;
	if (typeof value === "string" && !/^\d+(\.\d+)?$/.test(value)) return new Date(value);
	return /* @__PURE__ */ new Date(Number(value) * 1e3);
}
/** formatRelative(1793806676) → "5 minutes ago" / "in 2 hours" (unix seconds, ISO string or Date). */
function formatRelative(value, now = Date.now()) {
	if (value === null || value === void 0) return "—";
	const ts = toDate(value).getTime();
	if (Number.isNaN(ts)) return "—";
	const diff = Math.round((ts - now) / 1e3);
	const rtf = new Intl.RelativeTimeFormat(void 0, { numeric: "auto" });
	for (const [unit, seconds] of units) if (Math.abs(diff) >= seconds || unit === "second") return rtf.format(Math.round(diff / seconds), unit);
	return "";
}
function formatDateTime(value) {
	if (value === null || value === void 0) return "—";
	const date = toDate(value);
	if (Number.isNaN(date.getTime())) return "—";
	return new Intl.DateTimeFormat(void 0, {
		dateStyle: "medium",
		timeStyle: "short"
	}).format(date);
}
/**
* A deep copy of JSON data. Use it instead of structuredClone, which
* throws on Vue's reactive proxies ("could not be cloned").
*/
function clone(value) {
	return value === void 0 ? void 0 : JSON.parse(JSON.stringify(value));
}
/** formatBytes(1536) → "1.5 KB", in the admin language's number format (bytes.js). */
function formatBytes(bytes) {
	return formatBytes$1(bytes, i18n.locale);
}
function formatNumber(value) {
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
function visibleFields(fields, data) {
	const text = (v) => v === true ? "1" : v === false || v === null || v === void 0 ? "" : ["string", "number"].includes(typeof v) ? String(v) : "";
	const test = (value, op, expected) => {
		const empty = value === null || value === void 0 || value === "" || value === false || Array.isArray(value) && !value.length;
		const strings = Array.isArray(value) ? value.map(text) : [text(value)];
		switch (op) {
			case "filled": return !empty;
			case "empty": return empty;
			case "is": return !empty && strings.includes(expected);
			case "is_not": return empty || !strings.includes(expected);
			case "contains": return !empty && expected !== "" && strings.some((s) => s.toLowerCase().includes(expected.toLowerCase()));
			default: return false;
		}
	};
	const visible = {};
	for (const f of fields) {
		const c = f.conditions;
		if (!c || !c.rules?.length) {
			visible[f.name] = true;
			continue;
		}
		const results = c.rules.map((r) => test(visible[r.field] ? data?.[r.field] : null, r.op, String(r.value ?? "")));
		visible[f.name] = c.match === "any" ? results.includes(true) : !results.includes(false);
	}
	return visible;
}
export { ApiError, SDK_VERSION, __, addMessages, clone, confirm, createApi, defineAdminExtension, dialogs, dismissToast, formatBytes, formatDateTime, formatNumber, formatRelative, loadTranslations, matchesWhen, notify, requestPassword, runToastAction, setLocale, setReauthHandler, toasts, uploadMedia, useAdmin, useApi, useCan, useExtensions, useI18n, useLeaveGuard, useMessagesFor, useReplacement, useResource, useUser, visibleFields };
