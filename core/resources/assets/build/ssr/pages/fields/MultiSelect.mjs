import { mergeModels, mergeProps, useModel, useSSRContext } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttrs, ssrRenderList } from "vue/server-renderer";
// core/resources/js/pages/fields/MultiSelect.vue
var _sfc_main = {
	__name: "MultiSelect",
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
			_push(`<div${ssrRenderAttrs(mergeProps({
				id: __props.id,
				class: ["k-choices", { "k-choices--inline": __props.field.options.choices.length <= 4 }]
			}, _attrs))}><!--[-->`);
			ssrRenderList(__props.field.options.choices, (c) => {
				_push(`<label class="k-choice"><input type="checkbox"${ssrIncludeBooleanAttr((model.value ?? []).includes(c.value)) ? " checked" : ""}${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}><span>${ssrInterpolate(c.label)}</span></label>`);
			});
			_push(`<!--]--></div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/MultiSelect.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
