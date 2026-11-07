import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { mergeProps, unref, useSSRContext } from "vue";
import { ssrInterpolate, ssrRenderAttrs } from "vue/server-renderer";
import { useCanvas } from "@kestello/app";
// core/resources/js/pages/blocks/Quote.vue
var _sfc_main = {
	__name: "Quote",
	__ssrInlineRender: true,
	props: {
		text: {
			type: String,
			default: ""
		},
		cite: {
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
			if (__props.text || unref(canvas)) {
				_push(`<figure${ssrRenderAttrs(mergeProps({ class: ["k-b-quote", `k-b-align--${__props.align}`] }, _attrs))} data-v-878908fc><blockquote data-k-field="text" data-placeholder="Quote" data-v-878908fc>${ssrInterpolate(__props.text)}</blockquote>`);
				if (__props.cite || unref(canvas)) _push(`<figcaption data-k-field="cite" data-placeholder="Who said it" data-v-878908fc>${ssrInterpolate(__props.cite)}</figcaption>`);
				else _push(`<!---->`);
				_push(`</figure>`);
			} else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Quote.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Quote_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-878908fc"]]);
export { Quote_default as default };
