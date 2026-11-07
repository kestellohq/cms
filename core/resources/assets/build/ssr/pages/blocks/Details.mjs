import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { mergeProps, unref, useSSRContext } from "vue";
import { ssrInterpolate, ssrRenderAttrs } from "vue/server-renderer";
import { useCanvas } from "@kestello/app";
// core/resources/js/pages/blocks/Details.vue
var _sfc_main = {
	__name: "Details",
	__ssrInlineRender: true,
	props: {
		summary: {
			type: String,
			default: ""
		},
		text: {
			type: String,
			default: ""
		},
		open: Boolean
	},
	setup(__props) {
		const canvas = useCanvas();
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.summary || unref(canvas)) _push(`<details${ssrRenderAttrs(mergeProps({
				class: "k-b-details",
				open: __props.open || !!unref(canvas)
			}, _attrs))} data-v-7ff6a33e><summary data-k-field="summary" data-placeholder="Question or summary" data-v-7ff6a33e>${ssrInterpolate(__props.summary)}</summary><div class="prose k-b-details__body" data-k-field="text" data-placeholder="What visitors read when they open it" data-v-7ff6a33e>${__props.text ?? ""}</div></details>`);
			else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Details.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Details_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-7ff6a33e"]]);
export { Details_default as default };
