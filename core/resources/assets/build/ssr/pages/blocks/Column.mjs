import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderSlot } from "vue/server-renderer";
// core/resources/js/pages/blocks/Column.vue
var _sfc_main = {
	__name: "Column",
	__ssrInlineRender: true,
	props: { align: {
		type: String,
		default: "start"
	} },
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: ["k-b-column k-b-stack", `k-b-column--${[
				"start",
				"center",
				"end"
			].includes(__props.align) ? __props.align : "start"}`] }, _attrs))} data-v-5c191147>`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Column.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Column_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-5c191147"]]);
export { Column_default as default };
