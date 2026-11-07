import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createTextVNode, mergeProps, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Link, usePage } from "@kestello/app";
// core/resources/js/pages/blocks/Menu.vue
var _sfc_main = {
	__name: "Menu",
	__ssrInlineRender: true,
	props: {
		location: {
			type: String,
			default: ""
		},
		orientation: {
			type: String,
			default: "horizontal"
		}
	},
	setup(__props) {
		const props = __props;
		const page = usePage();
		const items = computed(() => page.props.menus?.[props.location] ?? []);
		const internal = (item) => item.url.startsWith("/") && !item.url.startsWith("//") && !item.target;
		return (_ctx, _push, _parent, _attrs) => {
			if (items.value.length) {
				_push(`<nav${ssrRenderAttrs(mergeProps({
					class: ["k-b-menu", `k-b-menu--${__props.orientation}`],
					"aria-label": __props.location
				}, _attrs))} data-v-65393291><ul data-v-65393291><!--[-->`);
				ssrRenderList(items.value, (item) => {
					_push(`<li data-v-65393291>`);
					if (internal(item)) _push(ssrRenderComponent(unref(Link), { href: item.url }, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(item.label)}`);
							else return [createTextVNode(toDisplayString(item.label), 1)];
						}),
						_: 2
					}, _parent));
					else _push(`<a${ssrRenderAttr("href", item.url)}${ssrRenderAttr("target", item.target ?? void 0)}${ssrRenderAttr("rel", item.target ? "noopener noreferrer" : void 0)} data-v-65393291>${ssrInterpolate(item.label)}</a>`);
					_push(`</li>`);
				});
				_push(`<!--]--></ul></nav>`);
			} else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Menu.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Menu_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-65393291"]]);
export { Menu_default as default };
