import { computed, mergeModels, mergeProps, unref, useModel, useSSRContext } from "vue";
import { ssrRenderComponent } from "vue/server-renderer";
import { KSwitch } from "@kestello/ui";
// core/resources/js/pages/fields/Boolean.vue
var _sfc_main = {
	__name: "Boolean",
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
			get: () => !!model.value,
			set: (v) => {
				model.value = v;
			}
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(unref(KSwitch), mergeProps({
				id: __props.id,
				modelValue: value.value,
				"onUpdate:modelValue": ($event) => value.value = $event,
				label: __props.field.options.text ?? __props.field.label,
				"hide-label": !__props.field.options.text,
				disabled: __props.disabled
			}, _attrs), null, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/Boolean.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
