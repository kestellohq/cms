import { n as REVEAL } from "../../chunks/canvas-protocol-BnvfVFqm.mjs";
import { n as panelId, r as tabId, t as TABS } from "../../chunks/tabs-D4NgJFOQ.mjs";
import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, inject, mergeProps, onBeforeUnmount, onMounted, ref, unref, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderSlot } from "vue/server-renderer";
// core/resources/js/pages/blocks/Tab.vue
var _sfc_main = {
	__name: "Tab",
	__ssrInlineRender: true,
	props: {
		label: {
			type: String,
			default: ""
		},
		index: {
			type: Number,
			default: 0
		},
		group: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		const props = __props;
		const tabs = inject(TABS, null);
		const shown = computed(() => !tabs || tabs.active.value === props.index);
		const el = ref(null);
		const reveal = () => tabs?.show(props.index);
		onMounted(() => el.value?.addEventListener(REVEAL, reveal));
		onBeforeUnmount(() => el.value?.removeEventListener(REVEAL, reveal));
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				id: unref(panelId)(__props.group, __props.index),
				ref_key: "el",
				ref: el,
				class: "k-b-tab k-b-stack",
				role: "tabpanel",
				"aria-labelledby": unref(tabId)(__props.group, __props.index),
				tabindex: "0",
				hidden: !shown.value
			}, _attrs))} data-v-7263103b>`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Tab.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Tab_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-7263103b"]]);
export { Tab_default as default };
