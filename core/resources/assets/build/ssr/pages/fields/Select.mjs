import { computed, mergeModels, mergeProps, unref, useModel, useSSRContext } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrLooseContain, ssrLooseEqual, ssrRenderAttr, ssrRenderAttrs, ssrRenderList } from "vue/server-renderer";
import { __ } from "@kestello/sdk";
// core/resources/js/pages/fields/Select.vue
var _sfc_main = {
	__name: "Select",
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
				model.value = v === "" ? null : v;
			}
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<select${ssrRenderAttrs(mergeProps({
				id: __props.id,
				class: "k-input k-select",
				disabled: __props.disabled,
				"aria-invalid": __props.invalid ? "true" : void 0
			}, _attrs))}><option value=""${ssrIncludeBooleanAttr(Array.isArray(value.value) ? ssrLooseContain(value.value, "") : ssrLooseEqual(value.value, "")) ? " selected" : ""}>${ssrInterpolate(__props.field.required ? unref(__)("Choose…") : unref(__)("None"))}</option><!--[-->`);
			ssrRenderList(__props.field.options.choices, (c) => {
				_push(`<option${ssrRenderAttr("value", c.value)}${ssrIncludeBooleanAttr(Array.isArray(value.value) ? ssrLooseContain(value.value, c.value) : ssrLooseEqual(value.value, c.value)) ? " selected" : ""}>${ssrInterpolate(c.label)}</option>`);
			});
			_push(`<!--]--></select>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/Select.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
