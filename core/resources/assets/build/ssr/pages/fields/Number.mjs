import { computed, mergeModels, mergeProps, useModel, useSSRContext } from "vue";
import { ssrGetDynamicModelProps, ssrRenderAttrs } from "vue/server-renderer";
// core/resources/js/pages/fields/Number.vue
var _sfc_main = {
	__name: "Number",
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
		const value = computed({
			get: () => model.value ?? "",
			set: (v) => {
				model.value = v === "" || v === null ? null : Number(v);
			}
		});
		return (_ctx, _push, _parent, _attrs) => {
			let _temp0;
			_push(`<input${ssrRenderAttrs((_temp0 = mergeProps({
				id: __props.id,
				value: value.value,
				type: "number",
				class: "k-input k-number-field",
				min: __props.field.options.min,
				max: __props.field.options.max,
				step: __props.field.options.integer ? 1 : __props.field.options.step ?? "any",
				disabled: __props.disabled,
				"aria-invalid": __props.invalid ? "true" : void 0,
				inputmode: "decimal"
			}, _attrs), mergeProps(_temp0, ssrGetDynamicModelProps(_temp0, value.value))))}>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/Number.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
