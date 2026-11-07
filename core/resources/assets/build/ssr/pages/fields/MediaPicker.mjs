import { computed, createTextVNode, mergeModels, mergeProps, ref, toDisplayString, unref, useModel, useSSRContext, watch, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttrs, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { KImage } from "@kestello/app";
import { __, useApi, useReplacement } from "@kestello/sdk";
import { KButton, KExtension, KIcon, KMediaPicker } from "@kestello/ui";
// core/resources/js/pages/fields/MediaPicker.vue
var _sfc_main = {
	__name: "MediaPicker",
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
		const replacement = useReplacement("media.picker");
		const multiple = computed(() => !!props.field.options.multiple);
		const kinds = computed(() => props.field.options.kinds ?? []);
		const ids = computed(() => model.value === null || model.value === void 0 ? [] : Array.isArray(model.value) ? model.value : [model.value]);
		const known = ref({});
		const items = computed(() => ids.value.map((id) => known.value[id] ?? {
			id,
			title: "…",
			kind: "document",
			ext: ""
		}));
		watch(ids, async (list) => {
			const missing = list.filter((id) => !known.value[id]);
			if (!missing.length) return;
			let res;
			try {
				res = await api.get("/media", {
					ids: missing.join(","),
					per_page: 100
				});
			} catch {
				for (const id of missing) known.value[id] ??= {
					id,
					title: __("Could not load this file"),
					kind: "missing",
					ext: ""
				};
				return;
			}
			known.value = {
				...known.value,
				...Object.fromEntries(res.data.map((m) => [m.id, m]))
			};
			for (const id of missing) if (!known.value[id]) known.value[id] = {
				id,
				title: __("Deleted file"),
				kind: "missing",
				ext: ""
			};
		}, { immediate: true });
		const open = ref(false);
		function select(picked) {
			for (const m of picked) known.value[m.id] = m;
			const chosen = picked.map((m) => m.id);
			model.value = multiple.value ? [...ids.value, ...chosen.filter((id) => !ids.value.includes(id))] : chosen[0] ?? null;
		}
		function remove(id) {
			model.value = multiple.value ? ids.value.filter((x) => x !== id) : null;
		}
		function move(i, delta) {
			const list = [...ids.value];
			[list[i], list[i + delta]] = [list[i + delta], list[i]];
			model.value = list;
		}
		const label = computed(() => multiple.value ? __("Add files") : ids.value.length ? __("Replace") : kinds.value.length === 1 && kinds.value[0] === "image" ? __("Choose an image") : __("Choose a file"));
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				id: __props.id,
				class: ["k-mfield", { "is-invalid": __props.invalid }]
			}, _attrs))}>`);
			if (items.value.length) {
				_push(`<ul class="k-mfield__list"><!--[-->`);
				ssrRenderList(items.value, (m, i) => {
					_push(`<li class="k-mfield__item"><span class="k-mfield__thumb">`);
					if ((m.kind === "image" || m.sizes?.thumb) && m.url) _push(ssrRenderComponent(unref(KImage), {
						media: m,
						size: "thumb",
						sizes: "56px"
					}, null, _parent));
					else _push(ssrRenderComponent(unref(KIcon), { name: m.kind === "missing" ? "warning" : "file" }, null, _parent));
					_push(`</span><span class="k-mfield__meta"><span class="k-mfield__title">${ssrInterpolate(m.title)}</span><span class="k-muted k-small">${ssrInterpolate(m.kind === "missing" ? unref(__)("Removed from the library") : [m.ext?.toUpperCase(), m.width ? `${m.width} × ${m.height}` : ""].filter(Boolean).join(" · "))}</span></span>`);
					if (!__props.disabled) {
						_push(`<span class="k-mfield__tools">`);
						if (multiple.value && items.value.length > 1) {
							_push(`<!--[-->`);
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
								disabled: i === items.value.length - 1,
								"aria-label": unref(__)("Move down"),
								onClick: ($event) => move(i, 1)
							}, null, _parent));
							_push(`<!--]-->`);
						} else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "x",
							"aria-label": unref(__)("Remove {title}", { title: m.title }),
							onClick: ($event) => remove(m.id)
						}, null, _parent));
						_push(`</span>`);
					} else _push(`<!---->`);
					_push(`</li>`);
				});
				_push(`<!--]--></ul>`);
			} else _push(`<!---->`);
			if (!__props.disabled) _push(ssrRenderComponent(unref(KButton), {
				size: "sm",
				icon: multiple.value || !ids.value.length ? "plus" : "arrows-clockwise",
				onClick: ($event) => open.value = true
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(label.value)}`);
					else return [createTextVNode(toDisplayString(label.value), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (unref(replacement)) _push(ssrRenderComponent(unref(KExtension), {
				open: open.value,
				"onUpdate:open": ($event) => open.value = $event,
				name: unref(replacement),
				kinds: kinds.value,
				multiple: multiple.value,
				selected: ids.value,
				field: __props.field,
				onSelect: select
			}, null, _parent));
			else _push(ssrRenderComponent(unref(KMediaPicker), {
				open: open.value,
				"onUpdate:open": ($event) => open.value = $event,
				kinds: kinds.value,
				multiple: multiple.value,
				selected: ids.value,
				title: __props.field.label,
				onSelect: select
			}, null, _parent));
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/MediaPicker.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
