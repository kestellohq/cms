import { computed, mergeModels, mergeProps, useModel, useSSRContext } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderDynamicModel } from "vue/server-renderer";
// core/resources/js/pages/fields/Date.vue
var _sfc_main = {
	__name: "Date",
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
		const pad = (n) => String(n).padStart(2, "0");
		const value = computed({
			get() {
				if (!model.value) return "";
				if (!props.field.options.time) return String(model.value).slice(0, 10);
				const d = new Date(model.value);
				return Number.isNaN(d.getTime()) ? "" : `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
			},
			set(v) {
				if (!v) {
					model.value = null;
					return;
				}
				model.value = props.field.options.time ? new Date(v).toISOString().replace(/\.\d{3}Z$/, "Z") : v;
			}
		});
		const zone = computed(() => props.field.options.time ? Intl.DateTimeFormat().resolvedOptions().timeZone : "");
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-date-field" }, _attrs))}><input${ssrRenderAttr("id", __props.id)}${ssrRenderDynamicModel(__props.field.options.time ? "datetime-local" : "date", value.value, null)}${ssrRenderAttr("type", __props.field.options.time ? "datetime-local" : "date")} class="k-input"${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}${ssrRenderAttr("aria-invalid", __props.invalid ? "true" : void 0)}>`);
			if (zone.value) _push(`<span class="k-muted k-small">${ssrInterpolate(zone.value)}</span>`);
			else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/Date.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
