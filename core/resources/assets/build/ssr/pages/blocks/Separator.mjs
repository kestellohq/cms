import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs } from "vue/server-renderer";
// core/resources/js/pages/blocks/Separator.vue
var _sfc_main = {
	__name: "Separator",
	__ssrInlineRender: true,
	props: { look: {
		type: String,
		default: "line"
	} },
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<hr${ssrRenderAttrs(mergeProps({ class: ["k-b-separator", `k-b-separator--${__props.look}`] }, _attrs))} data-v-0546aff8>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Separator.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Separator_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-0546aff8"]]);
export { Separator_default as default };
