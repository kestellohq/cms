import { computed, mergeModels, mergeProps, unref, useModel, useSSRContext } from "vue";
import { ssrRenderComponent } from "vue/server-renderer";
import { useReplacement } from "@kestello/sdk";
import { KExtension, KRichText } from "@kestello/ui";
// core/resources/js/pages/fields/RichText.vue
var _sfc_main = {
	__name: "RichText",
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
		const replacement = useReplacement("editor.richtext");
		const value = computed({
			get: () => model.value ?? "",
			set: (v) => {
				model.value = v;
			}
		});
		return (_ctx, _push, _parent, _attrs) => {
			if (unref(replacement)) _push(ssrRenderComponent(unref(KExtension), mergeProps({
				modelValue: value.value,
				"onUpdate:modelValue": ($event) => value.value = $event,
				name: unref(replacement),
				id: __props.id,
				field: __props.field,
				invalid: __props.invalid,
				disabled: __props.disabled
			}, _attrs), null, _parent));
			else _push(ssrRenderComponent(unref(KRichText), mergeProps({
				id: __props.id,
				modelValue: value.value,
				"onUpdate:modelValue": ($event) => value.value = $event,
				label: __props.field.label,
				invalid: __props.invalid,
				disabled: __props.disabled
			}, _attrs), null, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/RichText.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
