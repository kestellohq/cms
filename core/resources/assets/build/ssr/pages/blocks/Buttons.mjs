import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderSlot } from "vue/server-renderer";
// core/resources/js/pages/blocks/Buttons.vue
var _sfc_main = {
	__name: "Buttons",
	__ssrInlineRender: true,
	props: { align: {
		type: String,
		default: "start"
	} },
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: ["k-b-buttons", `is-${[
				"start",
				"center",
				"end",
				"between"
			].includes(__props.align) ? __props.align : "start"}`] }, _attrs))} data-v-e4010ef8>`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Buttons.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Buttons_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-e4010ef8"]]);
export { Buttons_default as default };
