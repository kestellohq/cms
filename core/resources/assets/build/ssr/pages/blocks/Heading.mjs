import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createTextVNode, createVNode, mergeProps, resolveDynamicComponent, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderVNode } from "vue/server-renderer";
import { useCanvas } from "@kestello/app";
// core/resources/js/pages/blocks/Heading.vue
var _sfc_main = {
	__name: "Heading",
	__ssrInlineRender: true,
	props: {
		text: {
			type: String,
			default: ""
		},
		level: {
			type: [String, Number],
			default: "2"
		},
		align: {
			type: String,
			default: "start"
		}
	},
	setup(__props) {
		const props = __props;
		const canvas = useCanvas();
		const tag = computed(() => `h${Math.min(6, Math.max(1, Number(props.level) || 2))}`);
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.text || unref(canvas)) ssrRenderVNode(_push, createVNode(resolveDynamicComponent(tag.value), mergeProps({
				class: ["k-b-heading", `k-b-align--${__props.align}`],
				"data-k-field": "text",
				"data-placeholder": "Heading"
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(__props.text)}`);
					else return [createTextVNode(toDisplayString(__props.text), 1)];
				}),
				_: 1
			}), _parent);
			else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Heading.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Heading_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-8f0d727d"]]);
export { Heading_default as default };
