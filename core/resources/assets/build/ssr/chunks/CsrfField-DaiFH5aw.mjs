import { mergeProps, unref, useSSRContext } from "vue";
import { ssrRenderAttrs } from "vue/server-renderer";
import { usePage } from "@kestello/app";
// core/resources/js/components/CsrfField.vue
var _sfc_main = {
	__name: "CsrfField",
	__ssrInlineRender: true,
	setup(__props) {
		const page = usePage();
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<input${ssrRenderAttrs(mergeProps({
				type: "hidden",
				name: "_csrf_token",
				value: unref(page).props.csrfToken
			}, _attrs))}>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/CsrfField.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as t };
