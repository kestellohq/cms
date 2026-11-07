import { Fragment, computed, createBlock, createTextVNode, mergeProps, openBlock, renderList, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList, ssrRenderSlot } from "vue/server-renderer";
import { Head, Link, usePage } from "@kestello/app";
import { __ } from "@kestello/sdk";
import { KAlert } from "@kestello/ui";
// core/resources/js/components/AuthCard.vue
var _sfc_main$1 = {
	__name: "AuthCard",
	__ssrInlineRender: true,
	props: {
		title: {
			type: String,
			required: true
		},
		description: {
			type: String,
			default: ""
		},
		code: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		const props = __props;
		const page = usePage();
		const site = computed(() => page.props.site?.name || "Kestello");
		const flash = computed(() => page.props.flash ?? {});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: __props.title }, null, _parent));
			_push(`<main class="k-auth"><div class="k-auth__sheet"><div class="k-auth__brand"><img${ssrRenderAttr("src", "/assets/core/brand/kestello-symbol.svg")} alt="" width="26" height="26" class="k-logo k-logo--light"><img${ssrRenderAttr("src", "/assets/core/brand/kestello-symbol-white.svg")} alt="" width="26" height="26" class="k-logo k-logo--dark"><div><span class="k-auth__site">${ssrInterpolate(site.value)}</span><span class="k-sidebar__product">Kestello</span></div></div><header class="k-auth__head"><h1 class="k-auth__title">${ssrInterpolate(props.title)}</h1>`);
			if (__props.description) _push(`<p class="k-auth__description">${ssrInterpolate(__props.description)}</p>`);
			else _push(`<!---->`);
			_push(`</header>`);
			if (flash.value.success) _push(ssrRenderComponent(unref(KAlert), { tone: "success" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(flash.value.success)}`);
					else return [createTextVNode(toDisplayString(flash.value.success), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (flash.value.error) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(flash.value.error)}`);
					else return [createTextVNode(toDisplayString(flash.value.error), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div><nav class="k-auth__foot"${ssrRenderAttr("aria-label", unref(__)("More"))}>`);
			ssrRenderSlot(_ctx.$slots, "footer", {}, () => {
				_push(ssrRenderComponent(unref(Link), {
					href: "/",
					class: "k-link"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Back to {site}", { site: site.value }))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Back to {site}", { site: site.value })), 1)];
					}),
					_: 1
				}, _parent));
			}, _push, _parent);
			_push(`</nav></main><!--]-->`);
		};
	}
};
var _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/AuthCard.vue");
	return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
// core/resources/js/components/FormErrors.vue
var _sfc_main = {
	__name: "FormErrors",
	__ssrInlineRender: true,
	props: {
		errors: {
			type: Object,
			default: () => ({})
		},
		fields: {
			type: Array,
			default: () => []
		}
	},
	setup(__props) {
		const props = __props;
		const messages = computed(() => Object.entries(props.errors).filter(([key, msg]) => msg && !props.fields.includes(key)).map(([, msg]) => msg));
		return (_ctx, _push, _parent, _attrs) => {
			if (messages.value.length) _push(ssrRenderComponent(unref(KAlert), mergeProps({ tone: "danger" }, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<!--[-->`);
						ssrRenderList(messages.value, (msg, i) => {
							_push(`<div${_scopeId}>${ssrInterpolate(msg)}</div>`);
						});
						_push(`<!--]-->`);
					} else return [(openBlock(true), createBlock(Fragment, null, renderList(messages.value, (msg, i) => {
						return openBlock(), createBlock("div", { key: i }, toDisplayString(msg), 1);
					}), 128))];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/FormErrors.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main$1 as n, _sfc_main as t };
