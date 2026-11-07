import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { mergeProps, unref, useSSRContext } from "vue";
import { ssrInterpolate, ssrRenderAttrs } from "vue/server-renderer";
import { useCanvas } from "@kestello/app";
// core/resources/js/pages/blocks/Code.vue
var _sfc_main = {
	__name: "Code",
	__ssrInlineRender: true,
	props: {
		code: {
			type: String,
			default: ""
		},
		language: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		const canvas = useCanvas();
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.code || unref(canvas)) {
				_push(`<figure${ssrRenderAttrs(mergeProps({ class: "k-b-code" }, _attrs))} data-v-37fdbeb9>`);
				if (__props.language) _push(`<figcaption class="k-b-code__lang" data-v-37fdbeb9>${ssrInterpolate(__props.language)}</figcaption>`);
				else _push(`<!---->`);
				_push(`<pre data-v-37fdbeb9><code data-k-field="code" data-placeholder="Code" data-v-37fdbeb9>${ssrInterpolate(__props.code)}</code></pre></figure>`);
			} else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Code.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Code_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-37fdbeb9"]]);
export { Code_default as default };
