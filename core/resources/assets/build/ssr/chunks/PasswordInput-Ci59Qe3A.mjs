import { mergeModels, mergeProps, ref, unref, useModel, useSSRContext } from "vue";
import { ssrGetDynamicModelProps, ssrRenderAttr, ssrRenderAttrs, ssrRenderComponent } from "vue/server-renderer";
import { __ } from "@kestello/sdk";
import { KIcon } from "@kestello/ui";
// core/resources/js/components/PasswordInput.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ inheritAttrs: false }, {
	__name: "PasswordInput",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({ invalid: Boolean }, {
		"modelValue": {
			type: String,
			default: ""
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		const shown = ref(false);
		return (_ctx, _push, _parent, _attrs) => {
			let _temp0;
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-password" }, _attrs))}><input${ssrRenderAttrs((_temp0 = mergeProps(_ctx.$attrs, {
				type: shown.value ? "text" : "password",
				class: "k-input",
				"aria-invalid": __props.invalid ? "true" : void 0,
				spellcheck: "false",
				autocapitalize: "off"
			}), mergeProps(_temp0, ssrGetDynamicModelProps(_temp0, model.value))))}><button type="button" class="k-password__toggle"${ssrRenderAttr("aria-pressed", shown.value ? "true" : "false")}${ssrRenderAttr("aria-label", shown.value ? unref(__)("Hide password") : unref(__)("Show password"))}>`);
			_push(ssrRenderComponent(unref(KIcon), { name: shown.value ? "eye-slash" : "eye" }, null, _parent));
			_push(`</button></div>`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/PasswordInput.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as t };
