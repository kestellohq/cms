import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createTextVNode, mergeProps, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderClass, ssrRenderComponent } from "vue/server-renderer";
import { Link, useCanvas } from "@kestello/app";
// core/resources/js/pages/blocks/Button.vue
var _sfc_main = {
	__name: "Button",
	__ssrInlineRender: true,
	props: {
		text: {
			type: String,
			default: ""
		},
		link: {
			type: String,
			default: ""
		},
		variant: {
			type: String,
			default: "primary"
		},
		new_tab: Boolean
	},
	setup(__props) {
		const props = __props;
		const canvas = useCanvas();
		const internal = computed(() => props.link.startsWith("/") && !props.link.startsWith("//") && !props.new_tab);
		return (_ctx, _push, _parent, _attrs) => {
			if (unref(canvas)) _push(`<p${ssrRenderAttrs(mergeProps({ class: "k-b-button" }, _attrs))} data-v-e3d9d9b0><span class="${ssrRenderClass([`k-b-button--${__props.variant}`, "k-b-button__link"])}" data-v-e3d9d9b0><span data-k-field="text" data-placeholder="Button" data-v-e3d9d9b0>${ssrInterpolate(__props.text)}</span></span></p>`);
			else if (__props.text && __props.link) {
				_push(`<p${ssrRenderAttrs(mergeProps({ class: "k-b-button" }, _attrs))} data-v-e3d9d9b0>`);
				if (internal.value) _push(ssrRenderComponent(unref(Link), {
					href: __props.link,
					class: ["k-b-button__link", `k-b-button--${__props.variant}`]
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(__props.text)}`);
						else return [createTextVNode(toDisplayString(__props.text), 1)];
					}),
					_: 1
				}, _parent));
				else {
					_push(`<a${ssrRenderAttr("href", __props.link)} class="${ssrRenderClass([`k-b-button--${__props.variant}`, "k-b-button__link"])}"${ssrRenderAttr("target", __props.new_tab ? "_blank" : void 0)}${ssrRenderAttr("rel", __props.new_tab ? "noopener noreferrer" : void 0)} data-v-e3d9d9b0>${ssrInterpolate(__props.text)}`);
					if (__props.new_tab) _push(`<span class="k-b-button__sr" data-v-e3d9d9b0> (opens in a new tab)</span>`);
					else _push(`<!---->`);
					_push(`</a>`);
				}
				_push(`</p>`);
			} else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Button.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Button_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-e3d9d9b0"]]);
export { Button_default as default };
