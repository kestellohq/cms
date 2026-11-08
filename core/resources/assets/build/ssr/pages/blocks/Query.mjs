import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createTextVNode, mergeProps, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttrs, ssrRenderComponent, ssrRenderSlot } from "vue/server-renderer";
import { Link, usePage } from "@kestello/app";
// core/resources/js/pages/blocks/Query.vue
var _sfc_main = {
	__name: "Query",
	__ssrInlineRender: true,
	props: {
		type: {
			type: String,
			default: ""
		},
		order: {
			type: String,
			default: "newest"
		},
		limit: {
			type: Number,
			default: 6
		},
		exclude_current: Boolean,
		width: {
			type: String,
			default: "wide"
		},
		empty: {
			type: String,
			default: ""
		},
		count: {
			type: Number,
			default: 0
		},
		pagination: {
			type: Object,
			default: null
		}
	},
	setup(__props) {
		const props = __props;
		const page = usePage();
		const href = (n) => {
			const [path, query = ""] = page.url.split("?");
			const params = new URLSearchParams(query);
			n > 1 ? params.set("page", String(n)) : params.delete("page");
			const qs = params.toString();
			return qs ? `${path}?${qs}` : path;
		};
		const pages = computed(() => props.pagination ?? {
			page: 1,
			last: 1
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				class: "k-b-query",
				"data-width": __props.width
			}, _attrs))} data-v-9111eef4>`);
			if (__props.count) {
				_push(`<ul class="k-b-query__items" data-v-9111eef4>`);
				ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
				_push(`</ul>`);
			} else if (__props.empty) _push(`<p class="k-b-query__empty" data-k-field="empty" data-v-9111eef4>${ssrInterpolate(__props.empty)}</p>`);
			else _push(`<!---->`);
			if (pages.value.last > 1) {
				_push(`<nav class="k-b-query__pages" aria-label="Pages" data-v-9111eef4>`);
				if (pages.value.page > 1) _push(ssrRenderComponent(unref(Link), {
					href: href(pages.value.page - 1),
					rel: "prev"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`Newer`);
						else return [createTextVNode("Newer")];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`<span data-v-9111eef4>Page ${ssrInterpolate(pages.value.page)} of ${ssrInterpolate(pages.value.last)}</span>`);
				if (pages.value.page < pages.value.last) _push(ssrRenderComponent(unref(Link), {
					href: href(pages.value.page + 1),
					rel: "next"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`Older`);
						else return [createTextVNode("Older")];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`</nav>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Query.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Query_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-9111eef4"]]);
export { Query_default as default };
