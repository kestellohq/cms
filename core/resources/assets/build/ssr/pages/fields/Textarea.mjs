import { mergeModels, mergeProps, useModel, useSSRContext } from "vue";
import { ssrInterpolate, ssrRenderAttrs } from "vue/server-renderer";
// core/resources/js/pages/fields/Textarea.vue
var _sfc_main = {
	__name: "Textarea",
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
			_push(`<textarea${ssrRenderAttrs(mergeProps({
				id: __props.id,
				class: "k-input k-textarea",
				rows: __props.field.options.rows ?? 4,
				maxlength: __props.field.options.max,
				placeholder: __props.field.options.placeholder,
				disabled: __props.disabled,
				"aria-invalid": __props.invalid ? "true" : void 0
			}, _attrs), "textarea")}>${ssrInterpolate(model.value)}</textarea>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/Textarea.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
