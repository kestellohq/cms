import { createVNode, mergeProps, renderSlot, resolveDynamicComponent, useSSRContext, withCtx } from "vue";
import { ssrRenderSlot, ssrRenderVNode } from "vue/server-renderer";
// core/resources/js/pages/blocks/TemplatePart.vue
var _sfc_main = {
	__name: "TemplatePart",
	__ssrInlineRender: true,
	props: {
		part: {
			type: String,
			default: ""
		},
		area: {
			type: String,
			default: "general"
		}
	},
	setup(__props) {
		const tags = {
			header: "header",
			footer: "footer",
			sidebar: "aside"
		};
		return (_ctx, _push, _parent, _attrs) => {
			ssrRenderVNode(_push, createVNode(resolveDynamicComponent(tags[__props.area] ?? "div"), mergeProps({
				class: ["k-b-part k-blocks", `k-b-part--${__props.area}`],
				"data-width": "full",
				"data-part": __props.part
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent, _scopeId);
					else return [renderSlot(_ctx.$slots, "default")];
				}),
				_: 3
			}), _parent);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/TemplatePart.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
