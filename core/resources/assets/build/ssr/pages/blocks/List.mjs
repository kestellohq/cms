import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, createBlock, createVNode, mergeProps, openBlock, renderList, resolveDynamicComponent, toDisplayString, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderList, ssrRenderVNode } from "vue/server-renderer";
// core/resources/js/pages/blocks/List.vue
var _sfc_main = {
	__name: "List",
	__ssrInlineRender: true,
	props: {
		ordered: Boolean,
		items: {
			type: Array,
			default: () => []
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.items.length) {
				_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-b-list" }, _attrs))} data-v-30334004>`);
				ssrRenderVNode(_push, createVNode(resolveDynamicComponent(__props.ordered ? "ol" : "ul"), null, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<!--[-->`);
							ssrRenderList(__props.items, (item, i) => {
								_push(`<li${ssrRenderAttr("data-k-field", `items.${i}.text`)} data-placeholder="List item" data-v-30334004${_scopeId}>${ssrInterpolate(item?.text)}</li>`);
							});
							_push(`<!--]-->`);
						} else return [(openBlock(true), createBlock(Fragment, null, renderList(__props.items, (item, i) => {
							return openBlock(), createBlock("li", {
								key: i,
								"data-k-field": `items.${i}.text`,
								"data-placeholder": "List item"
							}, toDisplayString(item?.text), 9, ["data-k-field"]);
						}), 128))];
					}),
					_: 1
				}), _parent);
				_push(`</div>`);
			} else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/List.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var List_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-30334004"]]);
export { List_default as default };
