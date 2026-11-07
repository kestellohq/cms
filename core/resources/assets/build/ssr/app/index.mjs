import { r as designAttrs } from "../chunks/block-design-BwxGdv-2.mjs";
import { createApp, createSSRApp, defineAsyncComponent, defineComponent, h, inject, markRaw, onBeforeUnmount, onMounted, onUpdated, reactive, ref, shallowRef, watch } from "vue";
// core/resources/js/app/leave-guards.js
/**
* Unsaved work holds a page: the router asks before a visit that would leave
* it (a link, the menu, Back), and the page asks the browser on reload or close.
* Pages add a guard with useLeaveGuard (@kestello/sdk); the router only checks.
*/
/** A set of guards: each answers whether its page has unsaved work right now. */
function createLeaveGuards() {
	const guards = /* @__PURE__ */ new Set();
	return {
		/** @param {() => unknown} guard @returns {() => void} removes it */
		add(guard) {
			guards.add(guard);
			return () => guards.delete(guard);
		},
		/** True while any guard reports unsaved work. A guard that throws counts as none. */
		holding() {
			for (const guard of guards) try {
				if (guard()) return true;
			} catch {}
			return false;
		}
	};
}
/**
* Whether a visit replaces the screen: a GET to another path. The same path with
* another query or hash, a refresh that keeps state, and sent forms stay.
*/
function leavesPage(from, to, { method = "get", preserveState = false } = {}) {
	return String(method).toLowerCase() === "get" && !preserveState && (from.origin !== to.origin || from.pathname !== to.pathname);
}
// core/resources/js/app/index.js
/**
* @kestello/app: the client runtime for Vue-rendered pages.
*
* The server answers every route with a page object
*   { component: 'Blog/Post', props: {...}, url: '/blog/hello', version: 'abc' }
* embedded in the HTML on the first visit, or as JSON when this runtime
* navigates (X-Kestello: true). Components come from a registry the server
* provides; nothing outside it can be imported.
*
* Works in the browser and in the optional Node SSR server (no window access
* at import time).
*/
var ROUTER = Symbol("kestello.router");
var PAGE = Symbol("kestello.page");
var HEAD = Symbol("kestello.head");
var isBrowser = typeof window !== "undefined";
/** The current page: { component, props, url, version } (reactive). */
function usePage() {
	return inject(PAGE);
}
/**
* True on the editors' canvas (the page builder and the entry editor show
* the real site in a frame). Blocks use it to keep an empty field visible
* there, so it can be typed into, while the site leaves it out.
*/
function useCanvas() {
	return inject(ROUTER, null)?.page?.props?.design?.canvas === true;
}
function useRouter() {
	return inject(ROUTER);
}
/** const load = useComponentLoader(); const Widget = await load('@acme/admin/Widget'); */
function useComponentLoader() {
	const router = inject(ROUTER);
	return (name) => router.load(name);
}
var Router = class {
	constructor(page, registry, importer) {
		this.registry = registry;
		this.importer = importer;
		this.page = reactive({ ...page });
		this.current = shallowRef(null);
		this.counter = 0;
		this.controller = null;
		this.leaveGuards = createLeaveGuards();
		this.confirmLeave = null;
	}
	async init() {
		this.current.value = await this.resolvePage(this.page.component);
		this.current.value.key = this.counter;
		if (typeof document !== "undefined") await syncStylesheets(this.registry, this.page.component, this.current.value.layoutName);
	}
	async resolvePage(name) {
		const component = await this.resolve(name);
		let layout = component.layout ?? null;
		let layoutName = null;
		if (typeof layout === "string") {
			layoutName = this.registry[`layouts/${layout}`] ? `layouts/${layout}` : null;
			layout = layoutName ? await this.resolve(layoutName) : null;
		}
		return {
			component: markRaw(component),
			layout: layout ? markRaw(layout) : null,
			layoutName,
			key: 0
		};
	}
	/**
	* Load a registered component outside of navigation (admin extensions:
	* widgets, slots, cells). Its stylesheets are added and kept.
	*/
	async load(name) {
		const entry = this.registry[name];
		if (!entry) throw new Error(`[kestello] component "${name}" is not registered`);
		if (typeof document !== "undefined") addStylesheets(entry.css ?? []);
		return markRaw(await this.resolve(name));
	}
	async resolve(name) {
		const entry = this.registry[name];
		if (!entry) throw new Error(`[kestello] page component "${name}" is not registered`);
		const module = await this.importer(entry, name);
		return module.default ?? module;
	}
	get(url, data = {}, options = {}) {
		return this.visit(url, {
			...options,
			method: "get",
			data
		});
	}
	post(url, data = {}, options = {}) {
		return this.visit(url, {
			...options,
			method: "post",
			data
		});
	}
	put(url, data = {}, options = {}) {
		return this.visit(url, {
			...options,
			method: "put",
			data
		});
	}
	patch(url, data = {}, options = {}) {
		return this.visit(url, {
			...options,
			method: "patch",
			data
		});
	}
	delete(url, options = {}) {
		return this.visit(url, {
			...options,
			method: "delete"
		});
	}
	reload(options = {}) {
		return this.visit(window.location.href, {
			...options,
			preserveScroll: true,
			preserveState: true,
			replace: true
		});
	}
	/** Whether to go on when unsaved work holds the page: true when nothing holds it, or the person chose to leave. */
	async mayLeave() {
		return !this.leaveGuards.holding() || !this.confirmLeave || !!await this.confirmLeave();
	}
	/** @param {{discardUnsaved?: boolean}} options discardUnsaved: the page is done with its work (it was deleted), go without asking */
	async visit(href, { method = "get", data = {}, replace = false, preserveScroll = false, preserveState = false, discardUnsaved = false, onFinish } = {}) {
		method = method.toLowerCase();
		const url = new URL(href, window.location.href);
		if (!discardUnsaved && leavesPage(new URL(window.location.href), url, {
			method,
			preserveState
		}) && !await this.mayLeave()) {
			onFinish?.();
			return;
		}
		if (url.origin !== window.location.origin) {
			window.location.href = url.href;
			return;
		}
		const headers = {
			"X-Kestello": "true",
			"X-Kestello-Version": this.page.version,
			"X-Requested-With": "XMLHttpRequest",
			Accept: "text/html, application/json"
		};
		const init = {
			method: method.toUpperCase(),
			headers,
			credentials: "same-origin",
			redirect: "follow"
		};
		if (method === "get") for (const [key, value] of Object.entries(data ?? {})) if (value === null || value === void 0 || value === "") url.searchParams.delete(key);
		else url.searchParams.set(key, String(value));
		else {
			headers["Content-Type"] = "application/json";
			headers["X-CSRF-TOKEN"] = this.page.props.csrfToken ?? "";
			init.body = JSON.stringify(data ?? {});
		}
		this.controller?.abort();
		this.controller = new AbortController();
		init.signal = this.controller.signal;
		document.documentElement.setAttribute("aria-busy", "true");
		try {
			const response = await fetch(url, init);
			if (response.status === 409 && response.headers.get("X-Kestello-Location")) {
				window.location.href = response.headers.get("X-Kestello-Location");
				return;
			}
			if (!response.headers.get("X-Kestello")) {
				if (method === "get") window.location.href = response.url || url.href;
				else showRawResponse(await response.text());
				return;
			}
			const page = await response.json();
			await this.swap(page, {
				replace: replace || page.url === window.location.pathname + window.location.search,
				preserveScroll,
				preserveState
			});
		} catch (error) {
			if (error.name !== "AbortError") {
				console.error("[kestello] navigation failed", error);
				if (method === "get") window.location.href = url.href;
			}
		} finally {
			document.documentElement.removeAttribute("aria-busy");
			onFinish?.();
		}
	}
	async swap(page, { replace = false, preserveScroll = false, preserveState = false, history = true } = {}) {
		const next = await this.resolvePage(page.component);
		if (!preserveState || page.component !== this.page.component) this.counter++;
		next.key = this.counter;
		await syncStylesheets(this.registry, page.component, next.layoutName);
		Object.assign(this.page, page);
		this.current.value = next;
		if (history) window.history[replace ? "replaceState" : "pushState"]({ kestello: JSON.parse(JSON.stringify(page)) }, "", page.url);
		if (!preserveScroll) window.scrollTo(0, 0);
	}
	listen() {
		window.history.replaceState({ kestello: JSON.parse(JSON.stringify(this.page)) }, "", window.location.pathname + window.location.search + window.location.hash);
		window.addEventListener("popstate", async (event) => {
			const page = event.state?.kestello;
			if (this.leaveGuards.holding() && this.confirmLeave) {
				const here = JSON.parse(JSON.stringify(this.page));
				window.history.pushState({ kestello: here }, "", here.url);
				if (!await this.confirmLeave()) return;
				if (page) this.swap(page, {
					replace: true,
					preserveScroll: true
				});
				else window.location.reload();
				return;
			}
			if (page) this.swap(page, {
				history: false,
				preserveScroll: true
			});
			else window.location.reload();
		});
	}
};
/** Render the page component (with its layout) for the router's current state. */
var Root = defineComponent({
	name: "KestelloRoot",
	setup() {
		const router = inject(ROUTER);
		const page = inject(PAGE);
		return () => {
			const current = router.current.value;
			if (!current) return null;
			const props = pickProps(current.component, page.props);
			const vnode = h(current.component, {
				...props,
				key: current.key
			});
			return current.layout ? h(current.layout, null, { default: () => vnode }) : vnode;
		};
	}
});
/** Pass only the props a component declares; shared data is read with usePage(). */
function pickProps(component, all) {
	const declared = component.props;
	if (!declared) return {};
	const names = Array.isArray(declared) ? declared : Object.keys(declared);
	const out = {};
	for (const name of names) if (Object.prototype.hasOwnProperty.call(all, name)) out[name] = all[name];
	return out;
}
/**
* <Link href="/about">About</Link>
* <Link href="/logout" method="post" as="button">Log out</Link>
*/
var Link = defineComponent({
	name: "Link",
	props: {
		href: {
			type: String,
			required: true
		},
		method: {
			type: String,
			default: "get"
		},
		data: {
			type: Object,
			default: () => ({})
		},
		as: {
			type: String,
			default: null
		},
		replace: Boolean,
		preserveScroll: Boolean,
		preserveState: Boolean
	},
	setup(props, { slots, attrs }) {
		const router = inject(ROUTER);
		return () => {
			const isGet = props.method.toLowerCase() === "get";
			const tag = props.as ?? (isGet ? "a" : "button");
			const extra = tag === "a" ? { href: props.href } : { type: "button" };
			return h(tag, {
				...attrs,
				...extra,
				onClick: (event) => {
					attrs.onClick?.(event);
					if (event.defaultPrevented) return;
					if (tag === "a" && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || attrs.target === "_blank")) return;
					event.preventDefault();
					router.visit(props.href, {
						method: props.method,
						data: props.data,
						replace: props.replace,
						preserveScroll: props.preserveScroll,
						preserveState: props.preserveState
					});
				}
			}, slots.default?.());
		};
	}
});
/**
* A responsive image from the media library (the objects pages receive for
* media fields: { url, alt, width, height, color, placeholder, focus, sizes }).
*
*   <KImage :media="entry.data.image" size="large" sizes="(min-width: 60rem) 44rem, 100vw" priority />
*
* Renders <picture> with AVIF and WebP sources and a JPEG/PNG fallback, the
* size's width and height (no layout shift), lazy loading unless `priority`
* (the page's main image: eager, fetchpriority=high), and the average colour
* plus a tiny blurred preview behind it while it loads. Attributes (class,
* style) go on the <img>. Files without variants (animations, documents'
* previews, no image engine) render a plain <img> of the original.
*/
var KImage = defineComponent({
	name: "KImage",
	inheritAttrs: false,
	props: {
		media: {
			type: Object,
			default: null
		},
		size: {
			type: String,
			default: "large"
		},
		sizes: {
			type: String,
			default: "100vw"
		},
		alt: {
			type: String,
			default: null
		},
		priority: Boolean
	},
	setup(props, { attrs }) {
		return () => {
			const m = props.media;
			if (!m || !m.url) return null;
			const v = m.sizes?.[props.size] ?? null;
			const fallback = v?.srcset?.jpeg ?? v?.srcset?.png;
			const style = {};
			if (Array.isArray(m.focus)) style.objectPosition = `${Math.round(m.focus[0] * 100)}% ${Math.round(m.focus[1] * 100)}%`;
			if (!m.alpha && (m.placeholder || m.color)) {
				if (m.color) style.backgroundColor = m.color;
				if (m.placeholder) Object.assign(style, {
					backgroundImage: `url("${m.placeholder}")`,
					backgroundSize: "cover",
					backgroundPosition: style.objectPosition ?? "center"
				});
			}
			const img = h("img", {
				...attrs,
				src: v?.src ?? m.url,
				srcset: fallback,
				sizes: fallback ? props.sizes : void 0,
				width: v?.width ?? m.width ?? void 0,
				height: v?.height ?? m.height ?? void 0,
				alt: props.alt ?? m.alt ?? "",
				loading: props.priority ? "eager" : "lazy",
				decoding: props.priority ? "auto" : "async",
				fetchpriority: props.priority ? "high" : void 0,
				style: [style, attrs.style]
			});
			if (!v) return img;
			const sources = ["avif", "webp"].filter((f) => v.srcset?.[f]).map((f) => h("source", {
				key: f,
				type: `image/${f}`,
				srcset: v.srcset[f],
				sizes: props.sizes
			}));
			return h("picture", { class: "k-image" }, [...sources, img]);
		};
	}
});
/**
* Render a block tree the server prepared (blocks fields arrive this way:
* validated again, visibility applied, bindings and media resolved):
*
*   <BlockTree :blocks="entry.data.body" />
*
* Each node is { type, id, component, props, children }. The component is
* looked up in the registry (a theme overrides "@core/blocks/Heading" by
* shipping its own); its props are the block's props, and its default slot
* renders the children. A component that can't load renders nothing.
*/
var blockComponents = /* @__PURE__ */ new WeakMap();
function blockComponent(router, name) {
	let cache = blockComponents.get(router);
	if (!cache) blockComponents.set(router, cache = /* @__PURE__ */ new Map());
	if (!cache.has(name)) cache.set(name, router.registry[name] ? markRaw(defineAsyncComponent({
		loader: () => router.load(name),
		onError: (error, retry, fail) => {
			console.error(`[kestello] block component "${name}" failed to load`, error);
			fail();
		}
	})) : null);
	return cache.get(name);
}
/**
* On the canvas, a block that shows nothing yet (an image not chosen, an empty list)
* still needs something to click, select and drag: a placeholder takes its place.
*/
var CanvasBlock = defineComponent({
	name: "CanvasBlock",
	props: {
		component: {
			type: [Object, Function],
			required: true
		},
		attrs: {
			type: Object,
			required: true
		},
		type: {
			type: String,
			default: ""
		}
	},
	setup(props, { slots }) {
		const inner = ref(null);
		const empty = ref(false);
		const check = () => {
			const el = inner.value?.$el;
			const now = !el || el.nodeType !== 1;
			if (now !== empty.value) empty.value = now;
		};
		let timers = [];
		const soon = () => {
			check();
			timers.forEach(clearTimeout);
			timers = [
				80,
				400,
				1500
			].map((ms) => setTimeout(check, ms));
		};
		onMounted(soon);
		onUpdated(soon);
		onBeforeUnmount(() => timers.forEach(clearTimeout));
		const name = () => {
			const n = (props.type.split("/")[1] ?? props.type).replace(/-/g, " ");
			return n.charAt(0).toUpperCase() + n.slice(1);
		};
		return () => [h(props.component, {
			...props.attrs,
			ref: inner
		}, slots), empty.value ? h("div", {
			"data-block-id": props.attrs["data-block-id"],
			"data-block": props.type,
			"data-k-placeholder": "",
			style: "padding:1.25rem 1rem;border:1px dashed currentColor;border-radius:6px;opacity:.55;font:500 14px/1.4 system-ui,sans-serif;text-align:center;"
		}, `${name()}: nothing to show yet. Select it to set it up.`) : null];
	}
});
var BlockTree = defineComponent({
	name: "BlockTree",
	props: { blocks: {
		type: Array,
		default: () => []
	} },
	setup(props) {
		const router = inject(ROUTER);
		const canvas = () => router?.page?.props?.design?.canvas === true;
		const render = (nodes) => nodes.map((node) => {
			const component = node?.component ? blockComponent(router, node.component) : null;
			if (!component) return null;
			const children = Array.isArray(node.children) ? node.children : [];
			const attrs = {
				key: `${node.id}:${node.type}${node._rev ? `:${node._rev}` : ""}`,
				...node.props ?? {},
				"data-block": node.type
			};
			if (Array.isArray(node.hide) && node.hide.length) attrs.class = node.hide.map((d) => `k-hide-${d}`);
			const design = designAttrs(node.design);
			if (design) Object.assign(attrs, design);
			if (!canvas()) return h(component, attrs, { default: () => render(children) });
			attrs["data-block-id"] = node.id;
			return h(CanvasBlock, {
				key: attrs.key,
				component,
				attrs,
				type: node.type
			}, { default: () => render(children) });
		});
		return () => render(props.blocks ?? []);
	}
});
/**
* Form state with server-side validation errors:
*   const form = useForm({ email: '', password: '' });
*   form.post('/login')  →  form.errors.email, form.processing
*/
function useForm(initial = {}) {
	const router = inject(ROUTER);
	const page = inject(PAGE);
	const defaults = JSON.parse(JSON.stringify(initial));
	const form = reactive({
		...JSON.parse(JSON.stringify(initial)),
		errors: {},
		processing: false,
		data() {
			const out = {};
			for (const key of Object.keys(defaults)) out[key] = form[key];
			return out;
		},
		submit(method, url, options = {}) {
			form.processing = true;
			return router.visit(url, {
				preserveScroll: true,
				preserveState: true,
				...options,
				method,
				data: method === "delete" ? {} : form.data(),
				onFinish: () => {
					form.processing = false;
					form.errors = firstMessages(page.props.errors);
					options.onFinish?.();
				}
			});
		},
		get(url, options) {
			return form.submit("get", url, options);
		},
		post(url, options) {
			return form.submit("post", url, options);
		},
		put(url, options) {
			return form.submit("put", url, options);
		},
		patch(url, options) {
			return form.submit("patch", url, options);
		},
		delete(url, options) {
			return form.submit("delete", url, options);
		},
		reset(...fields) {
			for (const key of fields.length ? fields : Object.keys(defaults)) form[key] = JSON.parse(JSON.stringify(defaults[key]));
		},
		clearErrors() {
			form.errors = {};
		}
	});
	form.errors = firstMessages(page.props.errors);
	return form;
}
function firstMessages(errors) {
	const out = {};
	for (const [field, value] of Object.entries(errors ?? {})) out[field] = Array.isArray(value) ? value[0] : String(value);
	return out;
}
/** <Head title="Users" />: sets the document title (and the SSR <title>). */
var Head = defineComponent({
	name: "Head",
	props: {
		title: {
			type: String,
			default: ""
		},
		raw: Boolean
	},
	setup(props) {
		const head = inject(HEAD, null);
		const page = inject(PAGE, null);
		const full = () => {
			const site = page?.props?.site?.name ?? "";
			if (props.raw || !site) return props.title || site;
			return props.title ? `${props.title} - ${site}` : site;
		};
		if (head) head.title = full();
		if (isBrowser) {
			onMounted(() => {
				document.title = full();
			});
			watch(full, (title) => {
				document.title = title;
			});
		}
		return () => null;
	}
});
function stylesFor(registry, ...names) {
	const urls = [];
	for (const name of names) for (const url of registry[name]?.css ?? []) if (!urls.includes(url)) urls.push(url);
	return urls;
}
/** Add stylesheets without waiting (extension components). */
function addStylesheets(urls) {
	const have = new Set([...document.querySelectorAll("link[rel=\"stylesheet\"]")].map((l) => l.getAttribute("href")));
	for (const url of urls) {
		if (have.has(url)) continue;
		const link = document.createElement("link");
		link.rel = "stylesheet";
		link.href = url;
		link.dataset.kestelloCss = "";
		document.head.appendChild(link);
	}
}
/** Load the stylesheets the next page needs, then drop the ones it doesn't. */
async function syncStylesheets(registry, component, layout) {
	const wanted = stylesFor(registry, component, ...layout ? [layout] : []);
	const existing = new Map([...document.querySelectorAll("link[data-kestello-css]")].map((l) => [l.getAttribute("href"), l]));
	await Promise.all(wanted.filter((url) => !existing.has(url)).map((url) => new Promise((resolve) => {
		const link = document.createElement("link");
		link.rel = "stylesheet";
		link.href = url;
		link.dataset.kestelloCss = "";
		link.onload = link.onerror = resolve;
		setTimeout(resolve, 3e3);
		document.head.appendChild(link);
	})));
	for (const [url, link] of existing) if (!wanted.includes(url)) link.remove();
}
function showRawResponse(html) {
	document.open();
	document.write(html);
	document.close();
}
/**
* @param {{ page: object, registry: Record<string, {file: string, css?: string[]}>, ssr?: boolean, importer?: Function }} options
*/
async function createKestelloApp({ page, registry, ssr = false, importer, messages = null }) {
	if (ssr) {
		const { useMessagesFor } = await import("@kestello/sdk");
		useMessagesFor(page.props?.admin?.locale ?? page.props?.i18n?.locale ?? "en", messages ?? {});
	}
	const router = new Router(page, registry, importer ?? ((entry) => import(entry.file)));
	const head = { title: "" };
	await router.init();
	const app = (ssr ? createSSRApp : createApp)(Root);
	app.provide(ROUTER, router);
	app.provide(PAGE, router.page);
	app.provide(HEAD, head);
	app.component("Link", Link);
	app.component("Head", Head);
	app.component("BlockTree", BlockTree);
	app.config.globalProperties.$page = router.page;
	return {
		app,
		router,
		head
	};
}
/**
* Live preview for Appearance → Styles: when this page runs in a frame of
* the same site, the admin may send custom-property values and CSS to try
* on. Messages from any other origin or window are ignored, and nothing is
* stored: it only changes what this frame shows.
*/
function listenForStylePreview() {
	if (typeof window === "undefined" || window.parent === window) return;
	let sheet = null;
	const root = document.documentElement;
	let applied = [];
	window.addEventListener("message", (event) => {
		if (event.origin !== window.location.origin || event.source !== window.parent) return;
		const data = event.data;
		if (!data || data.type !== "kestello:styles") return;
		for (const name of applied) root.style.removeProperty(name);
		applied = [];
		for (const [name, value] of Object.entries(data.vars ?? {})) if (/^--[a-z][a-z0-9-]{0,40}$/.test(name) && typeof value === "string" && value.length < 400) {
			root.style.setProperty(name, value);
			applied.push(name);
		}
		if (typeof data.css === "string" && "adoptedStyleSheets" in document) {
			sheet ??= new CSSStyleSheet();
			try {
				sheet.replaceSync(data.css);
			} catch {}
			if (!document.adoptedStyleSheets.includes(sheet)) document.adoptedStyleSheets = [...document.adoptedStyleSheets, sheet];
		}
	});
	window.parent.postMessage({
		type: "kestello:preview-ready",
		path: window.location.pathname + window.location.search
	}, window.location.origin);
}
/** Browser entry, called by the nonce'd script in the page shell. */
async function boot({ registry }) {
	const el = document.getElementById("app");
	const page = JSON.parse(document.getElementById("kestello-page").textContent);
	const i18n = page.props?.admin ?? page.props?.i18n;
	if (i18n?.translations || i18n?.locale && !String(i18n.locale).startsWith("en")) {
		const { loadTranslations } = await import("@kestello/sdk");
		await loadTranslations(i18n);
	}
	const { app, router } = await createKestelloApp({
		page,
		registry,
		ssr: el.hasAttribute("data-ssr")
	});
	app.config.errorHandler = (error, instance, info) => console.error("[kestello]", info, error);
	app.mount(el);
	router.listen();
	listenForStylePreview();
	if (window.parent !== window && router.page.props?.design?.canvas === true) import("../chunks/canvas-CDPa7MXn.mjs").then(({ startCanvas }) => startCanvas(router)).catch((error) => console.error("[kestello] the canvas could not start", error));
	return app;
}
export { BlockTree, Head, KImage, Link, boot, createKestelloApp, useCanvas, useComponentLoader, useForm, usePage, useRouter };
