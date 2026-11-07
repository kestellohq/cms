import { mergeModels, mergeProps, useModel, useSSRContext } from "vue";
import { ssrIncludeBooleanAttr, ssrRenderAttr, ssrRenderAttrs } from "vue/server-renderer";
// core/resources/js/pages/fields/Color.vue
var _sfc_main = {
	__name: "Color",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		field: {
			type: Object,
			required: true
		},
		id: {
			type: String,
			default: ""
		},
		invalid: Boolean,
		disabled: Boolean,
		errors: {
			type: Object,
			default: () => ({})
		},
		path: {
			type: String,
			default: ""
		}
	}, {
		"modelValue": { default: null },
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-input-row k-color-field" }, _attrs))}><input${ssrRenderAttr("value", model.value)} type="color" class="k-color-field__swatch"${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}${ssrRenderAttr("aria-label", __props.field.label)} tabindex="-1"><input${ssrRenderAttr("id", __props.id)}${ssrRenderAttr("value", model.value)} class="k-input k-mono-input" maxlength="7" placeholder="#000000" pattern="#[0-9a-fA-F]{6}" spellcheck="false" autocomplete="off"${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}${ssrRenderAttr("aria-invalid", __props.invalid ? "true" : void 0)}></div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/Color.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
