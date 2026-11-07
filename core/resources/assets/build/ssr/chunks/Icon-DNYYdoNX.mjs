import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttr, ssrRenderAttrs } from "vue/server-renderer";
// core/resources/js/shared/Icon.vue
var _sfc_main = {
	__name: "Icon",
	__ssrInlineRender: true,
	props: {
		d: {
			type: String,
			default: ""
		},
		label: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.d) _push(`<svg${ssrRenderAttrs(mergeProps({
				class: "k-icon-svg",
				viewBox: "0 0 256 256",
				width: "1em",
				height: "1em",
				fill: "currentColor",
				focusable: "false",
				role: __props.label ? "img" : void 0,
				"aria-label": __props.label || void 0,
				"aria-hidden": __props.label ? void 0 : "true"
			}, _attrs))}><path${ssrRenderAttr("d", __props.d)}></path></svg>`);
			else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/shared/Icon.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as t };
