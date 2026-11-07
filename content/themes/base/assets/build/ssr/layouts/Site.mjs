import { computed, createBlock, createTextVNode, createVNode, mergeProps, openBlock, ref, toDisplayString, unref, useSSRContext, watch, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderSlot } from "vue/server-renderer";
import { Link, usePage } from "@kestello/app";
import { useI18n } from "@kestello/sdk";
// content/themes/base/resources/js/layouts/Site.vue
var _sfc_main = {
	__name: "Site",
	__ssrInlineRender: true,
	setup(__props) {
		const { __ } = useI18n("base");
		const page = usePage();
		const site = computed(() => page.props.site?.name ?? "Kestello");
		const user = computed(() => page.props.auth?.user ?? null);
		const path = computed(() => page.url.split("?")[0]);
		const open = ref(false);
		const year = (/* @__PURE__ */ new Date()).getFullYear();
		watch(() => page.url, () => {
			open.value = false;
		});
		const fallback = [{
			url: "/",
			label: __("Home"),
			target: null,
			children: []
		}, {
			url: "/about",
			label: __("About"),
			target: null,
			children: []
		}];
		const primary = computed(() => page.props.menus?.primary?.length ? page.props.menus.primary : fallback);
		const footer = computed(() => page.props.menus?.footer ?? []);
		const internal = (item) => item.url.startsWith("/") && !item.url.startsWith("//") && !item.target;
		const current = (item) => path.value === item.url || item.children.some(current) ? "page" : void 0;
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "site" }, _attrs))}><a class="site-skip" href="#main">${ssrInterpolate(unref(__)("Skip to content"))}</a>`);
			if (unref(page).props.recoveryMode) _push(`<div class="site-notice" role="status">${ssrInterpolate(unref(__)("Recovery mode: plugins are disabled."))}</div>`);
			else _push(`<!---->`);
			_push(`<header class="site-header"><div class="site-wrap">`);
			_push(ssrRenderComponent(unref(Link), {
				class: "site-brand",
				href: "/"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(site.value)}`);
					else return [createTextVNode(toDisplayString(site.value), 1)];
				}),
				_: 1
			}, _parent));
			if (unref(page).component === "Home") _push(`<span class="site-sample"${ssrRenderAttr("title", unref(__)("This front page shows sample content. Replace it with your own."))}>${ssrInterpolate(unref(__)("Sample content"))}</span>`);
			else _push(`<!---->`);
			_push(`<button type="button" class="site-menu-btn"${ssrRenderAttr("aria-expanded", open.value ? "true" : "false")} aria-controls="site-nav">${ssrInterpolate(unref(__)("Menu"))}</button><nav id="site-nav" class="${ssrRenderClass([{ "is-open": open.value }, "site-nav"])}"${ssrRenderAttr("aria-label", unref(__)("Main"))}><!--[-->`);
			ssrRenderList(primary.value, (item) => {
				_push(`<div class="${ssrRenderClass([{ "has-children": item.children.length }, "site-nav__item"])}">`);
				if (internal(item)) _push(ssrRenderComponent(unref(Link), {
					href: item.url,
					"aria-current": current(item)
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(item.label)}`);
						else return [createTextVNode(toDisplayString(item.label), 1)];
					}),
					_: 2
				}, _parent));
				else _push(`<a${ssrRenderAttr("href", item.url)}${ssrRenderAttr("target", item.target ?? void 0)}${ssrRenderAttr("rel", item.target ? "noopener" : void 0)}>${ssrInterpolate(item.label)}</a>`);
				if (item.children.length) {
					_push(`<ul class="site-nav__sub"><!--[-->`);
					ssrRenderList(item.children, (child) => {
						_push(`<li>`);
						if (internal(child)) _push(ssrRenderComponent(unref(Link), {
							href: child.url,
							"aria-current": path.value === child.url ? "page" : void 0
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(child.label)}`);
								else return [createTextVNode(toDisplayString(child.label), 1)];
							}),
							_: 2
						}, _parent));
						else _push(`<a${ssrRenderAttr("href", child.url)}${ssrRenderAttr("target", child.target ?? void 0)}${ssrRenderAttr("rel", child.target ? "noopener" : void 0)}>${ssrInterpolate(child.label)}</a>`);
						_push(`</li>`);
					});
					_push(`<!--]--></ul>`);
				} else _push(`<!---->`);
				_push(`</div>`);
			});
			_push(`<!--]-->`);
			_push(ssrRenderComponent(unref(Link), {
				href: "/search",
				class: "site-nav__search",
				"aria-current": path.value === "/search" ? "page" : void 0
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"${_scopeId}><circle cx="8.5" cy="8.5" r="5.75" fill="none" stroke="currentColor" stroke-width="2"${_scopeId}></circle><path d="M13 13l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"${_scopeId}></path></svg><span${_scopeId}>${ssrInterpolate(unref(__)("Search"))}</span>`);
					else return [(openBlock(), createBlock("svg", {
						viewBox: "0 0 20 20",
						width: "18",
						height: "18",
						"aria-hidden": "true"
					}, [createVNode("circle", {
						cx: "8.5",
						cy: "8.5",
						r: "5.75",
						fill: "none",
						stroke: "currentColor",
						"stroke-width": "2"
					}), createVNode("path", {
						d: "M13 13l4.5 4.5",
						stroke: "currentColor",
						"stroke-width": "2",
						"stroke-linecap": "round"
					})])), createVNode("span", null, toDisplayString(unref(__)("Search")), 1)];
				}),
				_: 1
			}, _parent));
			if (user.value) {
				_push(`<!--[-->`);
				if (user.value.admin) _push(ssrRenderComponent(unref(Link), {
					href: "/admin",
					class: "site-nav__account"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Admin"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Admin")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(ssrRenderComponent(unref(Link), {
					href: "/logout",
					method: "post",
					as: "button",
					class: "site-nav__account"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Sign out"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Sign out")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`<!--]-->`);
			} else _push(ssrRenderComponent(unref(Link), {
				href: "/login",
				class: "site-nav__account"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("Sign in"))}`);
					else return [createTextVNode(toDisplayString(unref(__)("Sign in")), 1)];
				}),
				_: 1
			}, _parent));
			_push(`</nav></div></header><main id="main" class="site-main" tabindex="-1">`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</main><footer class="site-footer"><div class="site-wrap"><p>© ${ssrInterpolate(unref(year))} ${ssrInterpolate(site.value)}</p>`);
			if (footer.value.length) {
				_push(`<nav class="site-footer__nav"${ssrRenderAttr("aria-label", unref(__)("Footer"))}><!--[-->`);
				ssrRenderList(footer.value, (item) => {
					_push(`<!--[-->`);
					if (internal(item)) _push(ssrRenderComponent(unref(Link), { href: item.url }, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(item.label)}`);
							else return [createTextVNode(toDisplayString(item.label), 1)];
						}),
						_: 2
					}, _parent));
					else _push(`<a${ssrRenderAttr("href", item.url)}${ssrRenderAttr("target", item.target ?? void 0)}${ssrRenderAttr("rel", item.target ? "noopener" : void 0)}>${ssrInterpolate(item.label)}</a>`);
					_push(`<!--]-->`);
				});
				_push(`<!--]--></nav>`);
			} else _push(`<!---->`);
			_push(`<p>${ssrInterpolate(unref(__)("Built with"))} <a href="https://kestello.dev" rel="noopener">Kestello</a></p></div></footer></div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/layouts/Site.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
