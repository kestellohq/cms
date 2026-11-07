import { mergeModels, mergeProps, unref, useModel, useSSRContext } from "vue";
import { ssrRenderComponent } from "vue/server-renderer";
import { useApi } from "@kestello/sdk";
import { KPicker } from "@kestello/ui";
// core/resources/js/pages/fields/TermPicker.vue
var _sfc_main = {
	__name: "TermPicker",
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
		const api = useApi();
		const base = `/taxonomies/${props.field.options.taxonomy}/terms`;
		const item = (t) => ({
			id: t.id,
			label: t.name
		});
		const search = async (q) => (await api.get(base, {
			search: q || void 0,
			limit: 20
		})).data.map(item);
		const resolve = async (ids) => (await api.get(base, { ids: ids.join(",") })).data.map(item);
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(unref(KPicker), mergeProps({
				id: __props.id,
				modelValue: model.value,
				"onUpdate:modelValue": ($event) => model.value = $event,
				multiple: __props.field.options.multiple,
				search,
				resolve,
				disabled: __props.disabled,
				invalid: __props.invalid
			}, _attrs), null, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/TermPicker.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
