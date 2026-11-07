import { t as _plugin_vue_export_helper_default } from "../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createTextVNode, mergeProps, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttrs, ssrRenderComponent, ssrRenderSlot } from "vue/server-renderer";
import { Link, usePage } from "@kestello/app";
// core/resources/js/layouts/Site.vue
var _sfc_main = {
	__name: "Site",
	__ssrInlineRender: true,
	setup(__props) {
		const page = usePage();
		const site = computed(() => page.props.site?.name ?? "Kestello");
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "kestello-fallback" }, _attrs))} data-v-5959ad7d><header data-v-5959ad7d>`);
			_push(ssrRenderComponent(unref(Link), { href: "/" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(site.value)}`);
					else return [createTextVNode(toDisplayString(site.value), 1)];
				}),
				_: 1
			}, _parent));
			_push(`</header><main data-v-5959ad7d>`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</main></div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/layouts/Site.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Site_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-5959ad7d"]]);
export { Site_default as default };
