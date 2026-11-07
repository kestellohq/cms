import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs } from "vue/server-renderer";
// core/resources/js/pages/blocks/Spacer.vue
var _sfc_main = {
	__name: "Spacer",
	__ssrInlineRender: true,
	props: { size: {
		type: String,
		default: "md"
	} },
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				class: ["k-b-spacer", `k-b-spacer--${__props.size}`],
				"aria-hidden": "true"
			}, _attrs))} data-v-512ac5b1></div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Spacer.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Spacer_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-512ac5b1"]]);
export { Spacer_default as default };
