import { mergeModels, mergeProps, unref, useModel, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { KContentField } from "@kestello/ui";
// core/resources/js/pages/fields/Group.vue
var _sfc_main = {
	__name: "Group",
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
		function set(name, value) {
			model.value = {
				...model.value ?? {},
				[name]: value
			};
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				id: __props.id,
				class: "k-fields k-group-field"
			}, _attrs))}><!--[-->`);
			ssrRenderList(__props.field.fields, (child) => {
				_push(ssrRenderComponent(unref(KContentField), {
					key: child.name,
					"model-value": model.value?.[child.name] ?? null,
					field: child,
					errors: __props.errors,
					path: __props.path,
					disabled: __props.disabled,
					"onUpdate:modelValue": ($event) => set(child.name, $event)
				}, null, _parent));
			});
			_push(`<!--]--></div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/Group.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
