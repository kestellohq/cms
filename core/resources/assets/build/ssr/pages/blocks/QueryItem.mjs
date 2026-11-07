import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderSlot } from "vue/server-renderer";
// core/resources/js/pages/blocks/QueryItem.vue
var _sfc_main = {
	__name: "QueryItem",
	__ssrInlineRender: true,
	props: { url: {
		type: String,
		default: ""
	} },
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<li${ssrRenderAttrs(mergeProps({ class: "k-b-query-item k-b-stack" }, _attrs))} data-v-8281fd0d>`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</li>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/QueryItem.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var QueryItem_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-8281fd0d"]]);
export { QueryItem_default as default };
