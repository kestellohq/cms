import { n as panelId, r as tabId, t as TABS } from "../../chunks/tabs-D4NgJFOQ.mjs";
import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { mergeProps, provide, ref, unref, useSSRContext } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderList, ssrRenderSlot } from "vue/server-renderer";
// core/resources/js/pages/blocks/Tabs.vue
var _sfc_main = {
	__name: "Tabs",
	__ssrInlineRender: true,
	props: {
		tabs: {
			type: Array,
			default: () => []
		},
		group: {
			type: String,
			default: ""
		},
		look: {
			type: String,
			default: "line"
		},
		align: {
			type: String,
			default: "start"
		},
		width: {
			type: String,
			default: "content"
		}
	},
	setup(__props) {
		const props = __props;
		const active = ref(0);
		ref(null);
		provide(TABS, {
			active,
			group: () => props.group,
			show: (i) => {
				active.value = i;
			}
		});
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.tabs.length) {
				_push(`<div${ssrRenderAttrs(mergeProps({
					class: ["k-b-tabs", [`is-${__props.look === "pills" ? "pills" : "line"}`, __props.align === "center" && "is-center"]],
					"data-width": __props.width
				}, _attrs))} data-v-67cf7c09><div class="k-b-tabs__list" role="tablist" data-v-67cf7c09><!--[-->`);
				ssrRenderList(__props.tabs, (label, i) => {
					_push(`<button${ssrRenderAttr("id", unref(tabId)(__props.group, i))} type="button" role="tab" class="k-b-tabs__tab"${ssrRenderAttr("aria-selected", String(active.value === i))}${ssrRenderAttr("aria-controls", unref(panelId)(__props.group, i))}${ssrRenderAttr("tabindex", active.value === i ? 0 : -1)} data-v-67cf7c09>${ssrInterpolate(label)}</button>`);
				});
				_push(`<!--]--></div>`);
				ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
				_push(`</div>`);
			} else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Tabs.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Tabs_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-67cf7c09"]]);
export { Tabs_default as default };
