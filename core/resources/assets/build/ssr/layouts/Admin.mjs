import { computed, createTextVNode, createVNode, mergeProps, onBeforeUnmount, onMounted, ref, toDisplayString, unref, useSSRContext, watch, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderSlot } from "vue/server-renderer";
import { Link, usePage } from "@kestello/app";
import { __, requestPassword, setReauthHandler, useAdmin, useApi, useCan, useUser } from "@kestello/sdk";
import { KAlert, KAvatar, KDialogs, KFinder, KIcon, KTime, KToasts } from "@kestello/ui";
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
		const sheets = computed(() => admin.value.menu ?? []);
		const current = computed(() => sheets.value.flatMap((g) => g.items).filter((item) => !item.href.startsWith("https://") && isActive(item)).sort((a, b) => b.match.length - a.match.length)[0] ?? null);
		function isActive(item) {
			const match = item.match.replace(/\/$/, "");
			return match === "/admin" ? path.value === "/admin" : path.value === match || path.value.startsWith(match + "/");
		}
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
		});
		onBeforeUnmount(() => {
			clearInterval(timer);
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
			_push(`<span class="k-sidebar__product">Kestello ${ssrInterpolate(unref(admin).version)}</span></div><a href="/" class="k-icon-btn"${ssrRenderAttr("title", unref(__)("View site"))}${ssrRenderAttr("aria-label", unref(__)("View site"))}>`);
			_push(ssrRenderComponent(unref(KIcon), { name: "arrow-square-out" }, null, _parent));
			_push(`</a></div><button type="button" class="k-finder-btn"${ssrRenderAttr("aria-label", unref(__)("Find ({keys})", { keys: unref(isMac) ? "Command K" : "Control K" }))}>`);
			_push(ssrRenderComponent(unref(KIcon), { name: "magnifying-glass" }, null, _parent));
			_push(`<span class="k-finder-btn__text">${ssrInterpolate(unref(__)("Find"))}</span><kbd class="k-finder-btn__keys" aria-hidden="true">${ssrInterpolate(unref(isMac) ? "⌘K" : "Ctrl K")}</kbd></button><nav class="k-nav"><!--[-->`);
			ssrRenderList(sheets.value, (group) => {
				_push(`<div class="k-nav__group">`);
				if (group.label) _push(`<p class="k-nav__heading">${ssrInterpolate(group.label)}</p>`);
				else _push(`<!---->`);
				_push(`<ul><!--[-->`);
				ssrRenderList(group.items, (item) => {
					_push(`<li>`);
					if (item.href.startsWith("https://")) {
						_push(`<a${ssrRenderAttr("href", item.href)} class="k-nav__link" target="_blank" rel="noopener noreferrer"><span>${ssrInterpolate(item.label)}</span>`);
						_push(ssrRenderComponent(unref(KIcon), {
							name: "arrow-square-out",
							class: "k-nav__external"
						}, null, _parent));
						_push(`</a>`);
					} else _push(ssrRenderComponent(unref(Link), {
						href: item.href,
						class: ["k-nav__link", { "is-active": item === current.value }],
						"aria-current": item === current.value ? "page" : void 0
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`<span${_scopeId}>${ssrInterpolate(item.label)}</span>`);
							else return [createVNode("span", null, toDisplayString(item.label), 1)];
						}),
						_: 2
					}, _parent));
					_push(`</li>`);
				});
				_push(`<!--]--></ul></div>`);
			});
			_push(`<!--]--></nav><div class="k-sidebar__footer">`);
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
