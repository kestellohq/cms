import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { mergeProps, ref, useId, useSSRContext } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs } from "vue/server-renderer";
import { useRouter } from "@kestello/app";
// core/resources/js/pages/blocks/SearchForm.vue
var _sfc_main = {
	__name: "SearchForm",
	__ssrInlineRender: true,
	props: {
		label: {
			type: String,
			default: "Search"
		},
		button: {
			type: String,
			default: "Search"
		},
		type: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		useRouter();
		const q = ref("");
		const id = `k-b-search-${useId()}`;
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<form${ssrRenderAttrs(mergeProps({
				class: "k-b-search",
				role: "search",
				action: "/search",
				method: "get"
			}, _attrs))} data-v-8300e937><label class="k-b-search__label"${ssrRenderAttr("for", id)} data-v-8300e937>${ssrInterpolate(__props.label)}</label><div class="k-b-search__bar" data-v-8300e937><input${ssrRenderAttr("id", id)}${ssrRenderAttr("value", q.value)} class="k-b-search__input" type="search" name="q" maxlength="200" autocomplete="off" enterkeyhint="search" data-v-8300e937>`);
			if (__props.type) _push(`<input type="hidden" name="type"${ssrRenderAttr("value", __props.type)} data-v-8300e937>`);
			else _push(`<!---->`);
			_push(`<button type="submit" class="k-b-search__button" data-v-8300e937>${ssrInterpolate(__props.button)}</button></div></form>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/SearchForm.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var SearchForm_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-8300e937"]]);
export { SearchForm_default as default };
