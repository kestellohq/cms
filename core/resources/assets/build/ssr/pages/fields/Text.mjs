import { computed, mergeModels, mergeProps, unref, useModel, useSSRContext } from "vue";
import { ssrGetDynamicModelProps, ssrIncludeBooleanAttr, ssrInterpolate, ssrLooseContain, ssrLooseEqual, ssrRenderAttr, ssrRenderAttrs, ssrRenderList } from "vue/server-renderer";
import { __ } from "@kestello/sdk";
// core/resources/js/pages/fields/Text.vue
var _sfc_main = {
	__name: "Text",
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
		const props = __props;
		const suggest = computed(() => {
			const list = Array.isArray(props.field.options.suggest) ? props.field.options.suggest : null;
			if (!list) return null;
			return model.value && !list.some((o) => o.value === model.value) ? [...list, {
				value: model.value,
				label: __("{name} (not found)", { name: model.value })
			}] : list;
		});
		return (_ctx, _push, _parent, _attrs) => {
			let _temp0;
			if (suggest.value) {
				_push(`<select${ssrRenderAttrs(mergeProps({
					id: __props.id,
					class: "k-input k-select",
					disabled: __props.disabled,
					"aria-invalid": __props.invalid ? "true" : void 0
				}, _attrs))}><option${ssrRenderAttr("value", null)} disabled${ssrIncludeBooleanAttr(Array.isArray(model.value) ? ssrLooseContain(model.value, null) : ssrLooseEqual(model.value, null)) ? " selected" : ""}>${ssrInterpolate(suggest.value.length ? unref(__)("Choose…") : unref(__)("None yet"))}</option><!--[-->`);
				ssrRenderList(suggest.value, (o) => {
					_push(`<option${ssrRenderAttr("value", o.value)}${ssrIncludeBooleanAttr(Array.isArray(model.value) ? ssrLooseContain(model.value, o.value) : ssrLooseEqual(model.value, o.value)) ? " selected" : ""}>${ssrInterpolate(o.label)}</option>`);
				});
				_push(`<!--]--></select>`);
			} else _push(`<input${ssrRenderAttrs((_temp0 = mergeProps({
				id: __props.id,
				type: __props.field.options.input ?? "text",
				class: "k-input",
				maxlength: __props.field.options.max,
				placeholder: __props.field.options.placeholder,
				disabled: __props.disabled,
				"aria-invalid": __props.invalid ? "true" : void 0,
				autocomplete: __props.field.options.input === "email" ? "email" : "off",
				spellcheck: __props.field.options.input === "text" ? void 0 : "false"
			}, _attrs), mergeProps(_temp0, ssrGetDynamicModelProps(_temp0, model.value))))}>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/Text.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
