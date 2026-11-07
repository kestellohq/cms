import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { mergeProps, unref, useSSRContext } from "vue";
import { ssrRenderAttrs } from "vue/server-renderer";
import { useCanvas } from "@kestello/app";
// core/resources/js/pages/blocks/Text.vue
var _sfc_main = {
	__name: "Text",
	__ssrInlineRender: true,
	props: {
		text: {
			type: String,
			default: ""
		},
		align: {
			type: String,
			default: "start"
		}
	},
	setup(__props) {
		const canvas = useCanvas();
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.text || unref(canvas)) _push(`<div${ssrRenderAttrs(mergeProps({
				class: [
					"prose",
					"k-b-text",
					`k-b-align--${__props.align}`
				],
				"data-k-field": "text",
				"data-placeholder": "Write, or type / to add a block"
			}, _attrs))} data-v-183bfc00>${__props.text ?? ""}</div>`);
			else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Text.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Text_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-183bfc00"]]);
export { Text_default as default };
