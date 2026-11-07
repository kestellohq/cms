import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderSlot } from "vue/server-renderer";
// core/resources/js/pages/blocks/Columns.vue
var _sfc_main = {
	__name: "Columns",
	__ssrInlineRender: true,
	props: {
		layout: {
			type: String,
			default: "1-1"
		},
		gap: {
			type: String,
			default: "md"
		},
		width: {
			type: String,
			default: "content"
		}
	},
	setup(__props) {
		const props = __props;
		const layouts = [
			"1-1",
			"2-1",
			"1-2",
			"1-1-1",
			"1-1-1-1"
		];
		const layout = computed(() => layouts.includes(props.layout) ? props.layout : "1-1");
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				class: ["k-b-columns", [`k-b-columns--${__props.gap}`, `k-b-columns--l${layout.value}`]],
				"data-width": __props.width
			}, _attrs))} data-v-be3fc663>`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Columns.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Columns_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-be3fc663"]]);
export { Columns_default as default };
