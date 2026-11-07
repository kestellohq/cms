import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { createTextVNode, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { Head, Link } from "@kestello/app";
// core/resources/js/pages/errors/Error.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Site" }, {
	__name: "Error",
	__ssrInlineRender: true,
	props: {
		status: {
			type: Number,
			default: 500
		},
		heading: {
			type: String,
			default: "Something went wrong"
		},
		message: {
			type: String,
			default: ""
		},
		home: {
			type: String,
			default: "Go to the home page"
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: __props.heading }, null, _parent));
			_push(`<section class="kestello-error" data-v-9867327b><p class="kestello-error-code" data-v-9867327b>${ssrInterpolate(__props.status)}</p><h1 data-v-9867327b>${ssrInterpolate(__props.heading)}</h1>`);
			if (__props.message) _push(`<p data-v-9867327b>${ssrInterpolate(__props.message)}</p>`);
			else _push(`<!---->`);
			_push(ssrRenderComponent(unref(Link), {
				href: "/",
				class: "kestello-error-home"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(__props.home)}`);
					else return [createTextVNode(toDisplayString(__props.home), 1)];
				}),
				_: 1
			}, _parent));
			_push(`</section><!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/errors/Error.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Error_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-9867327b"]]);
export { Error_default as default };
