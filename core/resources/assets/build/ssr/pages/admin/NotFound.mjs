import { createTextVNode, createVNode, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { __ } from "@kestello/sdk";
import { KButton, KCard, KEmpty, KPageHeader } from "@kestello/ui";
// core/resources/js/pages/admin/NotFound.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "NotFound",
	__ssrInlineRender: true,
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), { title: unref(__)("Page not found") }, null, _parent));
			_push(ssrRenderComponent(unref(KCard), null, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KEmpty), {
						icon: "compass",
						title: unref(__)("There is no admin page at this address")
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<p${_scopeId}>${ssrInterpolate(unref(__)("The link may be mistyped, or the plugin that added the page may be turned off."))}</p>`);
								_push(ssrRenderComponent(unref(KButton), {
									href: "/admin",
									variant: "primary"
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Go to the overview"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Go to the overview")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
							} else return [createVNode("p", null, toDisplayString(unref(__)("The link may be mistyped, or the plugin that added the page may be turned off.")), 1), createVNode(unref(KButton), {
								href: "/admin",
								variant: "primary"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Go to the overview")), 1)]),
								_: 1
							})];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KEmpty), {
						icon: "compass",
						title: unref(__)("There is no admin page at this address")
					}, {
						default: withCtx(() => [createVNode("p", null, toDisplayString(unref(__)("The link may be mistyped, or the plugin that added the page may be turned off.")), 1), createVNode(unref(KButton), {
							href: "/admin",
							variant: "primary"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Go to the overview")), 1)]),
							_: 1
						})]),
						_: 1
					}, 8, ["title"])];
				}),
				_: 1
			}, _parent));
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/NotFound.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
