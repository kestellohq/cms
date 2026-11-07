import { mergeModels, mergeProps, unref, useModel, useSSRContext } from "vue";
import { ssrRenderComponent } from "vue/server-renderer";
import { __, useApi } from "@kestello/sdk";
import { KPicker } from "@kestello/ui";
// core/resources/js/pages/fields/EntryPicker.vue
var _sfc_main = {
	__name: "EntryPicker",
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
		const types = (props.field.options.types ?? []).join(",") || void 0;
		const STATUS = {
			draft: __("Draft"),
			pending: __("In review"),
			scheduled: __("Scheduled"),
			private: __("Private"),
			published: ""
		};
		const item = (e) => ({
			id: e.id,
			label: e.title || __("(untitled)"),
			meta: [e.type_label, STATUS[e.status]].filter(Boolean).join(" · ")
		});
		const search = async (q) => (await api.get("/content/lookup", {
			search: q || void 0,
			types
		})).data.map(item);
		const resolve = async (ids) => (await api.get("/content/lookup", {
			ids: ids.join(","),
			types
		})).data.map(item);
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/EntryPicker.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
