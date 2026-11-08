import { computed, createTextVNode, createVNode, mergeProps, nextTick, onBeforeUnmount, onMounted, ref, toDisplayString, unref, useSSRContext, watch, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderSlot, ssrRenderStyle } from "vue/server-renderer";
import { Link, usePage } from "@kestello/app";
import { __, requestPassword, setReauthHandler, useAdmin, useApi, useCan, useUser } from "@kestello/sdk";
import { KAlert, KAvatar, KDialogs, KFinder, KIcon, KTime, KToasts } from "@kestello/ui";
// core/resources/js/components/demo/demo.js
/**
* A public demo (config app.demo) tells every page so through the "demo"
* page prop: the account to sign in with and when the site resets.
*/
/**
* Whole minutes until the next reset, at least 1; null when it is not known
* (before the first snapshot) or overdue (the reset runs with the next
* scheduled task).
*
* @param {?number} nextReset unix seconds
* @param {number} now unix milliseconds
*/
function minutesUntil(nextReset, now) {
	if (typeof nextReset !== "number" || !Number.isFinite(nextReset)) return null;
	const seconds = nextReset - Math.floor(now / 1e3);
	return seconds <= 0 ? null : Math.max(1, Math.ceil(seconds / 60));
}
// core/resources/js/components/demo/DemoBar.vue
/**
* The admin's demo bar: this is a demo, what it shares with other
* visitors, when it resets, and what is locked.
*/
var _sfc_main$2 = {
	__name: "DemoBar",
	__ssrInlineRender: true,
	props: { 
	/** The "demo" page prop: { next_reset, every }. */
demo: {
		type: Object,
		required: true
	} },
	setup(__props) {
		const props = __props;
		const now = ref(Date.now());
		const timer = setInterval(() => now.value = Date.now(), 3e4);
		onBeforeUnmount(() => clearInterval(timer));
		const minutes = computed(() => minutesUntil(props.demo.next_reset, now.value));
		const when = computed(() => minutes.value === null ? __("Everything goes back to how it was shortly.") : minutes.value === 1 ? __("Everything goes back to how it was in about a minute.") : __("Everything goes back to how it was in {n} minutes.", { n: minutes.value }));
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(unref(KAlert), mergeProps({
				tone: "info",
				title: unref(__)("This is a demo")
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`<p${_scopeId}>${ssrInterpolate(when.value)} ${ssrInterpolate(unref(__)("Anyone can sign in here, so others may see what you change, and you may see theirs. Nothing is emailed."))}</p><p class="k-small"${_scopeId}>${ssrInterpolate(unref(__)("Content, design, menus, forms and images can be changed. Your account, people, plugins, updates, backups and settings are locked."))}</p>`);
					else return [createVNode("p", null, toDisplayString(when.value) + " " + toDisplayString(unref(__)("Anyone can sign in here, so others may see what you change, and you may see theirs. Nothing is emailed.")), 1), createVNode("p", { class: "k-small" }, toDisplayString(unref(__)("Content, design, menus, forms and images can be changed. Your account, people, plugins, updates, backups and settings are locked.")), 1)];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup$2 = _sfc_main$2.setup;
_sfc_main$2.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/demo/DemoBar.vue");
	return _sfc_setup$2 ? _sfc_setup$2(props, ctx) : void 0;
};
// core/resources/js/components/admin/nav.js
/**
* The admin sidebar's rules, kept apart from its markup so they can be
* tested: which entry is the current page, which entries it shows, and
* which groups are folded.
*/
/** Groups folded until someone opens them: tools most people visit rarely. */
var FOLDED_BY_DEFAULT = Object.freeze(["system"]);
/** Entries the sidebar leaves out because its footer already links there (the finder still lists them). */
var IN_FOOTER = Object.freeze(["core.profile"]);
/** Where the folded groups are remembered, per browser. */
var STORAGE_KEY = "kestello.nav.folded";
/** Whether a menu entry covers this path ("/admin" only itself, others their whole section). */
function covers(item, path) {
	const match = String(item.match ?? item.href).replace(/\/$/, "");
	return match === "/admin" ? path === "/admin" : path === match || path.startsWith(match + "/");
}
/**
* The one entry this page belongs to, and its group: the longest match
* wins (Categories over Content). Links to other sites never count.
*
* @returns {{ group: object, item: object } | null}
*/
function currentEntry(groups, path) {
	let best = null;
	for (const group of groups) for (const item of group.items) {
		if (item.href.startsWith("https://") || !covers(item, path)) continue;
		if (best === null || String(item.match ?? item.href).length > String(best.item.match ?? best.item.href).length) best = {
			group,
			item
		};
	}
	return best;
}
/** The groups as the sidebar shows them: without footer entries, and without groups left empty. */
function visibleGroups(groups) {
	return groups.map((g) => ({
		...g,
		items: g.items.filter((i) => !IN_FOOTER.includes(i.id))
	})).filter((g) => g.items.length > 0);
}
/** The folded group ids from storage; the defaults when nothing (or nothing valid) was stored. */
function readFolded(raw) {
	if (typeof raw !== "string") return [...FOLDED_BY_DEFAULT];
	try {
		const list = JSON.parse(raw);
		return Array.isArray(list) ? [...new Set(list.filter((id) => typeof id === "string" && /^[a-z0-9][a-z0-9_-]{0,49}$/.test(id)))] : [...FOLDED_BY_DEFAULT];
	} catch {
		return [...FOLDED_BY_DEFAULT];
	}
}
/** Opening a page opens its group: you went there, so it stays in view. */
function unfold(folded, id) {
	return id && folded.includes(id) ? folded.filter((g) => g !== id) : folded;
}
// core/resources/js/components/admin/AdminNav.vue
/**
* The admin menu: groups that fold, each entry with its icon. The group of
* the page you are on is always open, and its entry scrolled into view.
* What is folded is remembered per browser.
*/
var _sfc_main$1 = {
	__name: "AdminNav",
	__ssrInlineRender: true,
	props: {
		/** The menu from the server, already limited to what this person may open. */
		groups: {
			type: Array,
			required: true
		},
		/** The current path, without query or trailing slash. */
		path: {
			type: String,
			required: true
		}
	},
	setup(__props) {
		const props = __props;
		const root = ref(null);
		const shown = computed(() => visibleGroups(props.groups));
		const current = computed(() => currentEntry(props.groups, props.path));
		const folded = ref(readFolded(null));
		function remember() {
			try {
				localStorage.setItem(STORAGE_KEY, JSON.stringify(folded.value));
			} catch {}
		}
		function isOpen(group) {
			return !group.label || !folded.value.includes(group.id);
		}
		watch(() => current.value?.group.id, (id) => {
			const next = unfold(folded.value, id);
			if (next !== folded.value) {
				folded.value = next;
				remember();
			}
		});
		onMounted(async () => {
			try {
				folded.value = unfold(readFolded(localStorage.getItem(STORAGE_KEY)), current.value?.group.id);
			} catch {
				folded.value = unfold(folded.value, current.value?.group.id);
			}
			await nextTick();
			root.value?.querySelector("[aria-current=\"page\"]")?.scrollIntoView({ block: "nearest" });
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<nav${ssrRenderAttrs(mergeProps({
				ref_key: "root",
				ref: root,
				class: "k-nav",
				"aria-label": unref(__)("Admin menu")
			}, _attrs))}><!--[-->`);
			ssrRenderList(shown.value, (group) => {
				_push(`<div class="${ssrRenderClass([{ "is-folded": !isOpen(group) }, "k-nav__group"])}">`);
				if (group.label) {
					_push(`<h2 class="k-nav__heading"><button type="button" class="${ssrRenderClass([{ "is-current": current.value?.group.id === group.id }, "k-nav__toggle"])}"${ssrRenderAttr("aria-expanded", isOpen(group) ? "true" : "false")}${ssrRenderAttr("aria-controls", `k-nav-${group.id}`)}><span>${ssrInterpolate(group.label)}</span>`);
					_push(ssrRenderComponent(unref(KIcon), {
						name: "caret-down",
						class: "k-nav__caret"
					}, null, _parent));
					_push(`</button></h2>`);
				} else _push(`<!---->`);
				_push(`<ul${ssrRenderAttr("id", `k-nav-${group.id}`)} style="${ssrRenderStyle(isOpen(group) ? null : { display: "none" })}"><!--[-->`);
				ssrRenderList(group.items, (item) => {
					_push(`<li>`);
					if (item.href.startsWith("https://")) {
						_push(`<a${ssrRenderAttr("href", item.href)} class="k-nav__link" target="_blank" rel="noopener noreferrer">`);
						_push(ssrRenderComponent(unref(KIcon), {
							name: item.icon,
							class: "k-nav__icon"
						}, null, _parent));
						_push(`<span class="k-nav__label">${ssrInterpolate(item.label)}</span>`);
						_push(ssrRenderComponent(unref(KIcon), {
							name: "arrow-square-out",
							class: "k-nav__external",
							label: unref(__)("opens in a new tab")
						}, null, _parent));
						_push(`</a>`);
					} else _push(ssrRenderComponent(unref(Link), {
						href: item.href,
						class: ["k-nav__link", { "is-active": item === current.value?.item }],
						"aria-current": item === current.value?.item ? "page" : void 0
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(ssrRenderComponent(unref(KIcon), {
									name: item.icon,
									class: "k-nav__icon"
								}, null, _parent, _scopeId));
								_push(`<span class="k-nav__label"${_scopeId}>${ssrInterpolate(item.label)}</span>`);
							} else return [createVNode(unref(KIcon), {
								name: item.icon,
								class: "k-nav__icon"
							}, null, 8, ["name"]), createVNode("span", { class: "k-nav__label" }, toDisplayString(item.label), 1)];
						}),
						_: 2
					}, _parent));
					_push(`</li>`);
				});
				_push(`<!--]--></ul></div>`);
			});
			_push(`<!--]--></nav>`);
		};
	}
};
var _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/admin/AdminNav.vue");
	return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
// core/resources/js/prerender/background.js
/**
* Saved page HTML, from the admin's side: while an administrator has the
* admin open, now and then ask whether pages are waiting for their HTML and,
* when some are, open the hidden frame (/system/prerender) that draws them.
* The frame says when it is done; it is removed then, or after a while.
*/
/** Wait this long after the admin opens, so the frame never slows the screen being opened. */
var FIRST_CHECK = 2e4;
/** Then check this often. */
var EVERY = 6e5;
/** Remove a frame that has not said it is done by then. */
var FRAME_TIMEOUT = 12e4;
/** Whether a check found work for the frame (GET /system/prerender). */
function hasWork(status) {
	return Boolean(status?.enabled) && Number(status?.waiting ?? 0) > 0;
}
/**
* @param {{ get: Function }} api the admin's API client
* @returns {() => void} stops checking and removes any frame
*/
function startPrerender(api) {
	let frame = null;
	let removeTimer = null;
	function close() {
		clearTimeout(removeTimer);
		frame?.remove();
		frame = null;
	}
	function onMessage(event) {
		if (event.origin === window.location.origin && event.source === frame?.contentWindow && event.data?.type === "kestello:prerender-done") close();
	}
	async function check() {
		if (frame || document.visibilityState !== "visible") return;
		let status;
		try {
			status = (await api.get("/system/prerender", { limit: 1 }))?.data;
		} catch {
			return;
		}
		if (!hasWork(status) || frame) return;
		frame = document.createElement("iframe");
		frame.src = "/system/prerender";
		frame.title = "Saving page HTML";
		frame.setAttribute("aria-hidden", "true");
		frame.tabIndex = -1;
		frame.style.cssText = "position:absolute;width:0;height:0;border:0;visibility:hidden";
		document.body.append(frame);
		removeTimer = setTimeout(close, FRAME_TIMEOUT);
	}
	window.addEventListener("message", onMessage);
	const first = setTimeout(check, FIRST_CHECK);
	const timer = setInterval(check, EVERY);
	return () => {
		clearTimeout(first);
		clearInterval(timer);
		window.removeEventListener("message", onMessage);
		close();
	};
}
// core/resources/js/layouts/Admin.vue
/**
* The admin app frame. Pages opt in with defineOptions({ layout: 'Admin' });
* it stays mounted while navigating between them, so the sidebar, the
* notification bell and open toasts survive page changes.
*/
var _sfc_main = {
	__name: "Admin",
	__ssrInlineRender: true,
	setup(__props) {
		const page = usePage();
		const admin = useAdmin();
		const user = useUser();
		const can = useCan();
		const api = useApi();
		const site = computed(() => page.props.site?.name ?? "Kestello");
		const flash = computed(() => page.props.flash ?? {});
		const path = computed(() => page.url.split("?")[0].replace(/\/$/, "") || "/admin");
		const sidebarOpen = ref(false);
		const finderOpen = ref(false);
		const isMac = typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform ?? "");
		function onFinderKey(event) {
			const typing = event.target instanceof HTMLElement && (event.target.isContentEditable || [
				"INPUT",
				"TEXTAREA",
				"SELECT"
			].includes(event.target.tagName));
			if ((event.key === "k" || event.key === "K") && (event.metaKey || event.ctrlKey) && !event.altKey) {
				event.preventDefault();
				finderOpen.value = !finderOpen.value;
			} else if (event.key === "/" && !typing && !finderOpen.value && !event.metaKey && !event.ctrlKey) {
				event.preventDefault();
				finderOpen.value = true;
			}
		}
		onMounted(() => window.addEventListener("keydown", onFinderKey));
		onBeforeUnmount(() => window.removeEventListener("keydown", onFinderKey));
		const menu = computed(() => admin.value.menu ?? []);
		watch(() => page.url, () => {
			sidebarOpen.value = false;
			bellOpen.value = false;
		});
		const theme = ref("system");
		function applyTheme(value) {
			theme.value = value;
			if (value === "system") document.documentElement.removeAttribute("data-theme");
			else document.documentElement.setAttribute("data-theme", value);
			try {
				localStorage.setItem("kestello.theme", value);
			} catch {}
		}
		const themeIcon = computed(() => ({
			system: "circle-half",
			light: "sun",
			dark: "moon-stars"
		})[theme.value]);
		const themeName = computed(() => ({
			system: __("as the system"),
			light: __("light"),
			dark: __("dark")
		})[theme.value]);
		const bellOpen = ref(false);
		const unread = ref(0);
		const recent = ref([]);
		let timer = null;
		let stopPrerender = null;
		async function loadNotifications() {
			if (!can("profile.manage")) return;
			try {
				const body = await api.get("/me/notifications", { limit: 8 });
				recent.value = body.data;
				unread.value = body.meta.unread;
			} catch {}
		}
		function onDocumentClick(event) {
			if (bellOpen.value && !event.target.closest?.(".k-bell")) bellOpen.value = false;
		}
		onMounted(() => {
			try {
				applyTheme(localStorage.getItem("kestello.theme") ?? "system");
			} catch {
				applyTheme("system");
			}
			setReauthHandler(requestPassword);
			loadNotifications();
			timer = setInterval(() => {
				if (document.visibilityState === "visible") loadNotifications();
			}, 6e4);
			document.addEventListener("click", onDocumentClick);
			if (can("system.manage")) stopPrerender = startPrerender(api);
		});
		onBeforeUnmount(() => {
			clearInterval(timer);
			stopPrerender?.();
			document.removeEventListener("click", onDocumentClick);
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: ["k-admin", { "is-nav-open": sidebarOpen.value }] }, _attrs))}><a class="k-skip" href="#k-main">${ssrInterpolate(unref(__)("Skip to content"))}</a><aside class="k-sidebar"${ssrRenderAttr("aria-label", unref(__)("Admin"))}><div class="k-sidebar__brand"><img${ssrRenderAttr("src", "/assets/core/brand/kestello-symbol.svg")} alt="" width="24" height="24" class="k-logo k-logo--light"><img${ssrRenderAttr("src", "/assets/core/brand/kestello-symbol-white.svg")} alt="" width="24" height="24" class="k-logo k-logo--dark"><div>`);
			_push(ssrRenderComponent(unref(Link), {
				href: "/admin",
				class: "k-sidebar__site"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(site.value)}`);
					else return [createTextVNode(toDisplayString(site.value), 1)];
				}),
				_: 1
			}, _parent));
			_push(`<span class="k-sidebar__product">Kestello ${ssrInterpolate(unref(admin).version)}`);
			if (unref(page).props.demo) _push(`<!--[--> · ${ssrInterpolate(unref(__)("Demo"))}<!--]-->`);
			else _push(`<!---->`);
			_push(`</span></div><a href="/" class="k-icon-btn"${ssrRenderAttr("title", unref(__)("View site"))}${ssrRenderAttr("aria-label", unref(__)("View site"))}>`);
			_push(ssrRenderComponent(unref(KIcon), { name: "arrow-square-out" }, null, _parent));
			_push(`</a></div><button type="button" class="k-finder-btn"${ssrRenderAttr("aria-label", unref(__)("Find ({keys})", { keys: unref(isMac) ? "Command K" : "Control K" }))}>`);
			_push(ssrRenderComponent(unref(KIcon), { name: "magnifying-glass" }, null, _parent));
			_push(`<span class="k-finder-btn__text">${ssrInterpolate(unref(__)("Find"))}</span><kbd class="k-finder-btn__keys" aria-hidden="true">${ssrInterpolate(unref(isMac) ? "⌘K" : "Ctrl K")}</kbd></button>`);
			_push(ssrRenderComponent(_sfc_main$1, {
				groups: menu.value,
				path: path.value
			}, null, _parent));
			_push(`<div class="k-sidebar__footer">`);
			_push(ssrRenderComponent(unref(Link), {
				href: "/admin/profile",
				class: "k-sidebar__me",
				title: unref(__)("Your profile")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KAvatar), { user: unref(user) }, null, _parent, _scopeId));
						_push(`<span class="k-sidebar__user"${_scopeId}><span${_scopeId}>${ssrInterpolate(unref(user)?.username)}</span><small${_scopeId}>${ssrInterpolate(unref(user)?.role)}</small></span>`);
					} else return [createVNode(unref(KAvatar), { user: unref(user) }, null, 8, ["user"]), createVNode("span", { class: "k-sidebar__user" }, [createVNode("span", null, toDisplayString(unref(user)?.username), 1), createVNode("small", null, toDisplayString(unref(user)?.role), 1)])];
				}),
				_: 1
			}, _parent));
			_push(`<div class="k-bell"><button type="button" class="k-icon-btn"${ssrRenderAttr("aria-expanded", bellOpen.value ? "true" : "false")} aria-haspopup="true"${ssrRenderAttr("aria-label", unread.value ? unref(__)("Notifications ({count} unread)", { count: unread.value }) : unref(__)("Notifications"))}${ssrRenderAttr("title", unref(__)("Notifications"))}>`);
			_push(ssrRenderComponent(unref(KIcon), { name: "bell" }, null, _parent));
			if (unread.value) _push(`<span class="k-bell__count">${ssrInterpolate(unread.value > 99 ? "99+" : unread.value)}</span>`);
			else _push(`<!---->`);
			_push(`</button>`);
			if (bellOpen.value) {
				_push(`<div class="k-popover k-bell__panel"><div class="k-popover__header"><strong>${ssrInterpolate(unref(__)("Notifications"))}</strong>`);
				if (unread.value) _push(`<button type="button" class="k-link-btn">${ssrInterpolate(unref(__)("Mark all as read"))}</button>`);
				else _push(`<!---->`);
				_push(`</div>`);
				if (recent.value.length) {
					_push(`<ul class="k-bell__list"><!--[-->`);
					ssrRenderList(recent.value, (item) => {
						_push(`<li><button type="button" class="${ssrRenderClass([{ "is-unread": !item.read_at }, "k-bell__item"])}"><span class="k-bell__title">${ssrInterpolate(item.data?.title ?? item.type)}</span>`);
						if (item.data?.body) _push(`<span class="k-bell__body">${ssrInterpolate(item.data.body)}</span>`);
						else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KTime), {
							value: item.created_at,
							class: "k-bell__time"
						}, null, _parent));
						_push(`</button></li>`);
					});
					_push(`<!--]--></ul>`);
				} else _push(`<p class="k-bell__empty">${ssrInterpolate(unref(__)("You are all caught up."))}</p>`);
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`</div><button type="button" class="k-icon-btn"${ssrRenderAttr("title", unref(__)("Theme: {mode}", { mode: themeName.value }))}${ssrRenderAttr("aria-label", unref(__)("Switch theme (now {mode})", { mode: themeName.value }))}>`);
			_push(ssrRenderComponent(unref(KIcon), { name: themeIcon.value }, null, _parent));
			_push(`</button>`);
			_push(ssrRenderComponent(unref(Link), {
				href: "/logout",
				method: "post",
				as: "button",
				class: "k-icon-btn",
				"aria-label": unref(__)("Sign out"),
				title: unref(__)("Sign out")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KIcon), { name: "sign-out" }, null, _parent, _scopeId));
					else return [createVNode(unref(KIcon), { name: "sign-out" })];
				}),
				_: 1
			}, _parent));
			_push(`</div></aside><div class="k-scrim"></div><div class="k-shell"><header class="k-topbar"><button type="button" class="k-icon-btn"${ssrRenderAttr("aria-expanded", sidebarOpen.value ? "true" : "false")}${ssrRenderAttr("aria-label", unref(__)("Menu"))}>`);
			_push(ssrRenderComponent(unref(KIcon), { name: "list" }, null, _parent));
			_push(`</button><span class="k-topbar__site">${ssrInterpolate(site.value)}</span><button type="button" class="k-icon-btn"${ssrRenderAttr("aria-label", unref(__)("Find"))}>`);
			_push(ssrRenderComponent(unref(KIcon), { name: "magnifying-glass" }, null, _parent));
			_push(`</button></header><main id="k-main" class="k-main" tabindex="-1"><div class="k-flash">`);
			if (unref(page).props.demo) _push(ssrRenderComponent(_sfc_main$2, { demo: unref(page).props.demo }, null, _parent));
			else _push(`<!---->`);
			if (unref(page).props.recoveryMode) _push(ssrRenderComponent(unref(KAlert), {
				tone: "warning",
				title: unref(__)("Recovery mode")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("All plugins are disabled and the default theme is active. It ends when the recovery link expires; to leave sooner, delete the kestello_recovery cookie for this site in your browser."))}`);
					else return [createTextVNode(toDisplayString(unref(__)("All plugins are disabled and the default theme is active. It ends when the recovery link expires; to leave sooner, delete the kestello_recovery cookie for this site in your browser.")), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (flash.value.success) _push(ssrRenderComponent(unref(KAlert), { tone: "success" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(flash.value.success)}`);
					else return [createTextVNode(toDisplayString(flash.value.success), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (flash.value.error) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(flash.value.error)}`);
					else return [createTextVNode(toDisplayString(flash.value.error), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`</div><div class="k-page">`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div></main></div>`);
			_push(ssrRenderComponent(unref(KToasts), null, null, _parent));
			_push(ssrRenderComponent(unref(KDialogs), null, null, _parent));
			_push(ssrRenderComponent(unref(KFinder), {
				open: finderOpen.value,
				"onUpdate:open": ($event) => finderOpen.value = $event
			}, null, _parent));
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/layouts/Admin.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
