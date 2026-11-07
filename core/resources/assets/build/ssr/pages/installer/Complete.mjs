import { t as _sfc_main$1 } from "../../chunks/InstallerLayout-7a6f5LEx.mjs";
import { createVNode, mergeProps, unref, useSSRContext, withCtx } from "vue";
import { ssrRenderComponent } from "vue/server-renderer";
import { KIcon } from "@kestello/ui";
// core/resources/js/pages/installer/Complete.vue
var _sfc_main = {
	__name: "Complete",
	__ssrInlineRender: true,
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				step: 6,
				title: "Kestello is installed",
				description: "Sign in with the administrator account you just created."
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-install__done"${_scopeId}>`);
						_push(ssrRenderComponent(unref(KIcon), { name: "check-circle" }, null, _parent, _scopeId));
						_push(`<p${_scopeId}>Your site is ready. The admin walks you through the first steps.</p></div><div class="k-install__actions"${_scopeId}><span${_scopeId}></span><a href="/login" class="k-btn k-btn--primary k-btn--md"${_scopeId}>Sign in</a></div>`);
					} else return [createVNode("div", { class: "k-install__done" }, [createVNode(unref(KIcon), { name: "check-circle" }), createVNode("p", null, "Your site is ready. The admin walks you through the first steps.")]), createVNode("div", { class: "k-install__actions" }, [createVNode("span"), createVNode("a", {
						href: "/login",
						class: "k-btn k-btn--primary k-btn--md"
					}, "Sign in")])];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/installer/Complete.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
