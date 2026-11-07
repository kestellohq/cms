import { computed, createTextVNode, mergeModels, mergeProps, ref, toDisplayString, unref, useModel, useSSRContext, watch, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttrs, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { __ } from "@kestello/sdk";
import { KButton, KContentField } from "@kestello/ui";
// core/resources/js/pages/fields/Repeater.vue
var _sfc_main = {
	__name: "Repeater",
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
		const rows = computed(() => Array.isArray(model.value) ? model.value : []);
		let next = 0;
		const keys = ref(rows.value.map(() => next++));
		watch(() => rows.value.length, (n) => {
			while (keys.value.length < n) keys.value.push(next++);
			if (keys.value.length > n) keys.value = keys.value.slice(0, n);
		});
		const max = computed(() => props.field.options.max ?? null);
		const canAdd = computed(() => !props.disabled && (max.value === null || rows.value.length < max.value));
		const single = computed(() => props.field.options.singular ?? __("item"));
		function add() {
			const blank = Object.fromEntries(props.field.fields.map((f) => [f.name, f.default ?? null]));
			keys.value.push(next++);
			model.value = [...rows.value, blank];
		}
		function remove(i) {
			keys.value.splice(i, 1);
			model.value = rows.value.filter((_, j) => j !== i);
		}
		function move(i, delta) {
			const j = i + delta;
			if (j < 0 || j >= rows.value.length) return;
			const list = [...rows.value];
			[list[i], list[j]] = [list[j], list[i]];
			const k = [...keys.value];
			[k[i], k[j]] = [k[j], k[i]];
			keys.value = k;
			model.value = list;
		}
		function set(i, name, value) {
			model.value = rows.value.map((row, j) => j === i ? {
				...row,
				[name]: value
			} : row);
		}
		const summary = (row) => {
			const first = props.field.fields.find((f) => [
				"text",
				"email",
				"url"
			].includes(f.type));
			return first ? String(row?.[first.name] ?? "").trim() : "";
		};
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				id: __props.id,
				class: "k-repeater"
			}, _attrs))}>`);
			if (rows.value.length) {
				_push(`<ol class="k-repeater__rows"><!--[-->`);
				ssrRenderList(rows.value, (row, i) => {
					_push(`<li class="k-repeater__row"><div class="k-repeater__head"><span class="k-repeater__index">${ssrInterpolate(i + 1)}</span><span class="k-repeater__summary">${ssrInterpolate(summary(row) || unref(__)("Untitled {item}", { item: single.value }))}</span>`);
					if (!__props.disabled) {
						_push(`<span class="k-repeater__tools">`);
						_push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "arrow-up",
							disabled: i === 0,
							"aria-label": unref(__)("Move up"),
							onClick: ($event) => move(i, -1)
						}, null, _parent));
						_push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "arrow-down",
							disabled: i === rows.value.length - 1,
							"aria-label": unref(__)("Move down"),
							onClick: ($event) => move(i, 1)
						}, null, _parent));
						_push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "x",
							"aria-label": unref(__)("Remove {item} {n}", {
								item: single.value,
								n: i + 1
							}),
							onClick: ($event) => remove(i)
						}, null, _parent));
						_push(`</span>`);
					} else _push(`<!---->`);
					_push(`</div><div class="k-fields"><!--[-->`);
					ssrRenderList(__props.field.fields, (child) => {
						_push(ssrRenderComponent(unref(KContentField), {
							key: child.name,
							"model-value": row?.[child.name] ?? null,
							field: child,
							errors: __props.errors,
							path: `${__props.path}.${i}`,
							disabled: __props.disabled,
							"onUpdate:modelValue": ($event) => set(i, child.name, $event)
						}, null, _parent));
					});
					_push(`<!--]--></div></li>`);
				});
				_push(`<!--]--></ol>`);
			} else _push(`<p class="k-muted k-small k-repeater__empty">${ssrInterpolate(unref(__)("Nothing added yet."))}</p>`);
			if (canAdd.value) {
				_push(`<div>`);
				_push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					icon: "plus",
					onClick: add
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Add {item}", { item: single.value }))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Add {item}", { item: single.value })), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/Repeater.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
