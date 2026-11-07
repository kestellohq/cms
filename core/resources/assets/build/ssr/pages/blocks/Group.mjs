import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { createVNode, mergeProps, renderSlot, resolveDynamicComponent, useSSRContext, withCtx } from "vue";
import { ssrRenderSlot, ssrRenderVNode } from "vue/server-renderer";
// core/resources/js/pages/blocks/Group.vue
var _sfc_main = {
	__name: "Group",
	__ssrInlineRender: true,
	props: {
		tag: {
			type: String,
			default: "div"
		},
		width: {
			type: String,
			default: "content"
		},
		tone: {
			type: String,
			default: "plain"
		}
	},
	setup(__props) {
		const tags = [
			"div",
			"section",
			"aside",
			"header",
			"footer"
		];
		return (_ctx, _push, _parent, _attrs) => {
			ssrRenderVNode(_push, createVNode(resolveDynamicComponent(tags.includes(__props.tag) ? __props.tag : "div"), mergeProps({
				class: ["k-b-group k-b-stack", `k-b-group--${__props.tone}`],
				"data-width": __props.width
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent, _scopeId);
					else return [renderSlot(_ctx.$slots, "default", {}, void 0, true)];
				}),
				_: 3
			}), _parent);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Group.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Group_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-4f81a50f"]]);
export { Group_default as default };
