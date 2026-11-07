import { computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, reactive, ref, toDisplayString, unref, useSSRContext, vModelText, watch, withCtx, withDirectives, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderStyle } from "vue/server-renderer";
import { Head, usePage } from "@kestello/app";
import { ApiError, __, confirm, notify, useApi, useResource } from "@kestello/sdk";
import { KAlert, KButton, KCard, KField, KInput, KPageHeader, KSelect, KTable } from "@kestello/ui";
// core/resources/js/pages/admin/content/Terms.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Terms",
	__ssrInlineRender: true,
	setup(__props) {
		const page = usePage();
		const api = useApi();
		const name = computed(() => String(page.props.params?.taxonomy ?? ""));
		const taxonomies = useResource("/taxonomies");
		const tax = computed(() => (taxonomies.data.value ?? []).find((t) => t.name === name.value) ?? null);
		const terms = useResource(() => `/taxonomies/${name.value}/terms`);
		const filter = ref("");
		const rows = computed(() => {
			const q = filter.value.trim().toLowerCase();
			const list = terms.data.value ?? [];
			return q ? list.filter((t) => t.name.toLowerCase().includes(q) || t.slug.includes(q)).map((t) => ({
				...t,
				depth: 0
			})) : list;
		});
		const editing = ref(null);
		const form = reactive({
			name: "",
			slug: "",
			parent_id: null,
			description: ""
		});
		const errors = ref({});
		const saving = ref(false);
		function reset() {
			editing.value = null;
			Object.assign(form, {
				name: "",
				slug: "",
				parent_id: null,
				description: ""
			});
			errors.value = {};
		}
		function edit(term) {
			editing.value = term;
			Object.assign(form, {
				name: term.name,
				slug: term.slug,
				parent_id: term.parent_id,
				description: term.description ?? ""
			});
			errors.value = {};
			document.getElementById("k-term-name")?.focus();
		}
		watch(name, reset);
		const descendants = computed(() => {
			const out = /* @__PURE__ */ new Set();
			if (!editing.value) return out;
			const list = terms.data.value ?? [];
			const walk = (id) => {
				for (const t of list) if (t.parent_id === id && !out.has(t.id)) {
					out.add(t.id);
					walk(t.id);
				}
			};
			out.add(editing.value.id);
			walk(editing.value.id);
			return out;
		});
		const parentOptions = computed(() => [{
			value: "",
			label: __("None (top level)")
		}, ...(terms.data.value ?? []).filter((t) => !descendants.value.has(t.id)).map((t) => ({
			value: String(t.id),
			label: `${" ".repeat(t.depth)}${t.name}`
		}))]);
		const parent = computed({
			get: () => form.parent_id === null ? "" : String(form.parent_id),
			set: (v) => {
				form.parent_id = v === "" ? null : Number(v);
			}
		});
		async function save() {
			saving.value = true;
			errors.value = {};
			const body = {
				name: form.name,
				slug: form.slug || void 0,
				description: form.description || null
			};
			if (tax.value.hierarchical) body.parent_id = form.parent_id;
			try {
				if (editing.value) {
					await api.patch(`/taxonomies/${name.value}/terms/${editing.value.id}`, body);
					notify.success(__("Saved “{name}”.", { name: form.name }));
				} else {
					await api.post(`/taxonomies/${name.value}/terms`, body);
					notify.success(__("Added “{name}”.", { name: form.name }));
				}
				reset();
				await terms.reload({ silent: true });
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		async function remove(term) {
			if (!await confirm({
				title: __("Delete “{name}”?", { name: term.name }),
				message: term.count ? __("{n, plural, one {# published entry loses it.} other {# published entries lose it.}} Entries themselves are not deleted.", { n: term.count }) : __("No published entries use it.") + (tax.value.hierarchical ? " " + __("Its sub-items move up a level.") : ""),
				confirmLabel: __("Delete"),
				danger: true
			})) return;
			try {
				await api.delete(`/taxonomies/${name.value}/terms/${term.id}`);
				if (editing.value?.id === term.id) reset();
				notify.success(__("Deleted “{name}”.", { name: term.name }));
				terms.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		const columns = computed(() => [
			{
				key: "name",
				label: __("Name")
			},
			{
				key: "slug",
				label: __("Address")
			},
			{
				key: "count",
				label: __("Published"),
				align: "end",
				width: "110px"
			},
			{
				key: "actions",
				label: "",
				align: "end",
				width: "56px"
			}
		]);
		const singular = computed(() => (tax.value?.singular ?? "").toLowerCase());
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: tax.value?.plural ?? unref(__)("Terms") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: tax.value?.plural ?? (unref(taxonomies).data.value ? unref(__)("Taxonomy not found") : "…"),
				description: tax.value?.description ?? ""
			}, null, _parent));
			if (unref(taxonomies).data.value && !tax.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("This taxonomy does not exist, or you cannot manage it."))}`);
					else return [createTextVNode(toDisplayString(unref(__)("This taxonomy does not exist, or you cannot manage it.")), 1)];
				}),
				_: 1
			}, _parent));
			else if (tax.value && !tax.value.can.manage) _push(ssrRenderComponent(unref(KAlert), { tone: "info" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("You can pick {plural} on entries, but only editors can change the list.", { plural: tax.value.plural.toLowerCase() }))}`);
					else return [createTextVNode(toDisplayString(unref(__)("You can pick {plural} on entries, but only editors can change the list.", { plural: tax.value.plural.toLowerCase() })), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (tax.value) {
				_push(`<div class="k-cells k-terms" style="--k-cols:3;">`);
				if (tax.value.can.manage) _push(ssrRenderComponent(unref(KCard), {
					coord: "A1",
					title: editing.value ? unref(__)("Edit “{name}”", { name: editing.value.name }) : unref(__)("Add a {item}", { item: singular.value })
				}, {
					footer: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-form__actions"${_scopeId}>`);
							if (editing.value) _push(ssrRenderComponent(unref(KButton), {
								variant: "ghost",
								onClick: reset
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							_push(ssrRenderComponent(unref(KButton), {
								type: "submit",
								form: "k-term-form",
								variant: "primary",
								loading: saving.value,
								disabled: !form.name.trim()
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(editing.value ? unref(__)("Save") : unref(__)("Add {item}", { item: singular.value }))}`);
									else return [createTextVNode(toDisplayString(editing.value ? unref(__)("Save") : unref(__)("Add {item}", { item: singular.value })), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else return [createVNode("div", { class: "k-form__actions" }, [editing.value ? (openBlock(), createBlock(unref(KButton), {
							key: 0,
							variant: "ghost",
							onClick: reset
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true), createVNode(unref(KButton), {
							type: "submit",
							form: "k-term-form",
							variant: "primary",
							loading: saving.value,
							disabled: !form.name.trim()
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(editing.value ? unref(__)("Save") : unref(__)("Add {item}", { item: singular.value })), 1)]),
							_: 1
						}, 8, ["loading", "disabled"])])];
					}),
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<form id="k-term-form" class="k-form"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KField), {
								id: "k-term-name",
								label: unref(__)("Name"),
								error: errors.value.name ?? "",
								required: ""
							}, {
								default: withCtx(({ invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id: "k-term-name",
										modelValue: form.name,
										"onUpdate:modelValue": ($event) => form.name = $event,
										maxlength: "191",
										autocomplete: "off",
										invalid
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id: "k-term-name",
										modelValue: form.name,
										"onUpdate:modelValue": ($event) => form.name = $event,
										maxlength: "191",
										autocomplete: "off",
										invalid
									}, null, 8, [
										"modelValue",
										"onUpdate:modelValue",
										"invalid"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Address"),
								help: tax.value.path ? unref(__)("Shown at {path}/{slug}. Leave empty to make one from the name.", {
									path: tax.value.path,
									slug: form.slug || "…"
								}) : unref(__)("Leave empty to make one from the name."),
								error: errors.value.slug ?? ""
							}, {
								default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id,
										modelValue: form.slug,
										"onUpdate:modelValue": ($event) => form.slug = $event,
										maxlength: "191",
										spellcheck: "false",
										autocomplete: "off",
										"data-lpignore": "true",
										"data-1p-ignore": "",
										class: "k-mono-input",
										invalid,
										"aria-describedby": describedBy
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id,
										modelValue: form.slug,
										"onUpdate:modelValue": ($event) => form.slug = $event,
										maxlength: "191",
										spellcheck: "false",
										autocomplete: "off",
										"data-lpignore": "true",
										"data-1p-ignore": "",
										class: "k-mono-input",
										invalid,
										"aria-describedby": describedBy
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"invalid",
										"aria-describedby"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							if (tax.value.hierarchical) _push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Parent"),
								error: errors.value.parent_id ?? ""
							}, {
								default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KSelect), {
										id,
										modelValue: parent.value,
										"onUpdate:modelValue": ($event) => parent.value = $event,
										options: parentOptions.value,
										invalid
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KSelect), {
										id,
										modelValue: parent.value,
										"onUpdate:modelValue": ($event) => parent.value = $event,
										options: parentOptions.value,
										invalid
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"options",
										"invalid"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Description"),
								help: unref(__)("Some themes show it on the {item} page.", { item: singular.value }),
								error: errors.value.description ?? ""
							}, {
								default: withCtx(({ id, describedBy }, _push, _parent, _scopeId) => {
									if (_push) _push(`<textarea${ssrRenderAttr("id", id)} class="k-input k-textarea" rows="3" maxlength="2000"${ssrRenderAttr("aria-describedby", describedBy)}${_scopeId}>${ssrInterpolate(form.description)}</textarea>`);
									else return [withDirectives(createVNode("textarea", {
										id,
										"onUpdate:modelValue": ($event) => form.description = $event,
										class: "k-input k-textarea",
										rows: "3",
										maxlength: "2000",
										"aria-describedby": describedBy
									}, null, 8, [
										"id",
										"onUpdate:modelValue",
										"aria-describedby"
									]), [[vModelText, form.description]])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</form>`);
						} else return [createVNode("form", {
							id: "k-term-form",
							class: "k-form",
							onSubmit: withModifiers(save, ["prevent"])
						}, [
							createVNode(unref(KField), {
								id: "k-term-name",
								label: unref(__)("Name"),
								error: errors.value.name ?? "",
								required: ""
							}, {
								default: withCtx(({ invalid }) => [createVNode(unref(KInput), {
									id: "k-term-name",
									modelValue: form.name,
									"onUpdate:modelValue": ($event) => form.name = $event,
									maxlength: "191",
									autocomplete: "off",
									invalid
								}, null, 8, [
									"modelValue",
									"onUpdate:modelValue",
									"invalid"
								])]),
								_: 1
							}, 8, ["label", "error"]),
							createVNode(unref(KField), {
								label: unref(__)("Address"),
								help: tax.value.path ? unref(__)("Shown at {path}/{slug}. Leave empty to make one from the name.", {
									path: tax.value.path,
									slug: form.slug || "…"
								}) : unref(__)("Leave empty to make one from the name."),
								error: errors.value.slug ?? ""
							}, {
								default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
									id,
									modelValue: form.slug,
									"onUpdate:modelValue": ($event) => form.slug = $event,
									maxlength: "191",
									spellcheck: "false",
									autocomplete: "off",
									"data-lpignore": "true",
									"data-1p-ignore": "",
									class: "k-mono-input",
									invalid,
									"aria-describedby": describedBy
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"invalid",
									"aria-describedby"
								])]),
								_: 1
							}, 8, [
								"label",
								"help",
								"error"
							]),
							tax.value.hierarchical ? (openBlock(), createBlock(unref(KField), {
								key: 0,
								label: unref(__)("Parent"),
								error: errors.value.parent_id ?? ""
							}, {
								default: withCtx(({ id, invalid }) => [createVNode(unref(KSelect), {
									id,
									modelValue: parent.value,
									"onUpdate:modelValue": ($event) => parent.value = $event,
									options: parentOptions.value,
									invalid
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"options",
									"invalid"
								])]),
								_: 1
							}, 8, ["label", "error"])) : createCommentVNode("v-if", true),
							createVNode(unref(KField), {
								label: unref(__)("Description"),
								help: unref(__)("Some themes show it on the {item} page.", { item: singular.value }),
								error: errors.value.description ?? ""
							}, {
								default: withCtx(({ id, describedBy }) => [withDirectives(createVNode("textarea", {
									id,
									"onUpdate:modelValue": ($event) => form.description = $event,
									class: "k-input k-textarea",
									rows: "3",
									maxlength: "2000",
									"aria-describedby": describedBy
								}, null, 8, [
									"id",
									"onUpdate:modelValue",
									"aria-describedby"
								]), [[vModelText, form.description]])]),
								_: 1
							}, 8, [
								"label",
								"help",
								"error"
							])
						], 32)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "B1",
					class: tax.value.can.manage ? "k-span-2" : "k-span-all",
					title: tax.value.plural,
					flush: ""
				}, {
					actions: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`<label class="k-inline-field"${_scopeId}><span${_scopeId}>${ssrInterpolate(unref(__)("Filter"))}</span><input${ssrRenderAttr("value", filter.value)} type="search" class="k-input k-terms__filter"${ssrRenderAttr("aria-label", unref(__)("Filter {plural}", { plural: tax.value.plural.toLowerCase() }))}${_scopeId}></label>`);
						else return [createVNode("label", { class: "k-inline-field" }, [createVNode("span", null, toDisplayString(unref(__)("Filter")), 1), withDirectives(createVNode("input", {
							"onUpdate:modelValue": ($event) => filter.value = $event,
							type: "search",
							class: "k-input k-terms__filter",
							"aria-label": unref(__)("Filter {plural}", { plural: tax.value.plural.toLowerCase() })
						}, null, 8, ["onUpdate:modelValue", "aria-label"]), [[vModelText, filter.value]])])];
					}),
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: columns.value,
							rows: rows.value,
							loading: unref(terms).loading.value && !unref(terms).data.value,
							caption: tax.value.plural,
							empty: filter.value ? unref(__)("Nothing matches “{q}”.", { q: filter.value }) : unref(__)("No {plural} yet", { plural: tax.value.plural.toLowerCase() }),
							"empty-text": filter.value ? "" : unref(__)("Add the first one on the left.")
						}, {
							"cell-name": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<span class="k-term-name" style="${ssrRenderStyle({ "--k-depth": row.depth })}"${_scopeId}>`);
									if (tax.value.can.manage) _push(`<button type="button" class="k-link-btn"${ssrRenderAttr("aria-current", editing.value?.id === row.id ? "true" : void 0)}${_scopeId}>${ssrInterpolate(row.name)}</button>`);
									else _push(`<span${_scopeId}>${ssrInterpolate(row.name)}</span>`);
									_push(`</span>`);
								} else return [createVNode("span", {
									class: "k-term-name",
									style: { "--k-depth": row.depth }
								}, [tax.value.can.manage ? (openBlock(), createBlock("button", {
									key: 0,
									type: "button",
									class: "k-link-btn",
									"aria-current": editing.value?.id === row.id ? "true" : void 0,
									onClick: ($event) => edit(row)
								}, toDisplayString(row.name), 9, ["aria-current", "onClick"])) : (openBlock(), createBlock("span", { key: 1 }, toDisplayString(row.name), 1))], 4)];
							}),
							"cell-slug": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (row.path && row.count) _push(`<a${ssrRenderAttr("href", row.path)} class="k-term-slug" target="_blank" rel="noopener"${_scopeId}>${ssrInterpolate(row.path)}</a>`);
									else _push(`<span class="k-term-slug"${_scopeId}>${ssrInterpolate(row.path ?? row.slug)}</span>`);
								} else return [row.path && row.count ? (openBlock(), createBlock("a", {
									key: 0,
									href: row.path,
									class: "k-term-slug",
									target: "_blank",
									rel: "noopener"
								}, toDisplayString(row.path), 9, ["href"])) : (openBlock(), createBlock("span", {
									key: 1,
									class: "k-term-slug"
								}, toDisplayString(row.path ?? row.slug), 1))];
							}),
							"cell-count": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`<span class="k-num"${_scopeId}>${ssrInterpolate(row.count)}</span>`);
								else return [createVNode("span", { class: "k-num" }, toDisplayString(row.count), 1)];
							}),
							"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (tax.value.can.manage) _push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "trash",
										"aria-label": unref(__)("Delete “{name}”", { name: row.name }),
										onClick: ($event) => remove(row)
									}, null, _parent, _scopeId));
									else _push(`<!---->`);
								} else return [tax.value.can.manage ? (openBlock(), createBlock(unref(KButton), {
									key: 0,
									size: "sm",
									variant: "ghost",
									icon: "trash",
									"aria-label": unref(__)("Delete “{name}”", { name: row.name }),
									onClick: ($event) => remove(row)
								}, null, 8, ["aria-label", "onClick"])) : createCommentVNode("v-if", true)];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: columns.value,
							rows: rows.value,
							loading: unref(terms).loading.value && !unref(terms).data.value,
							caption: tax.value.plural,
							empty: filter.value ? unref(__)("Nothing matches “{q}”.", { q: filter.value }) : unref(__)("No {plural} yet", { plural: tax.value.plural.toLowerCase() }),
							"empty-text": filter.value ? "" : unref(__)("Add the first one on the left.")
						}, {
							"cell-name": withCtx(({ row }) => [createVNode("span", {
								class: "k-term-name",
								style: { "--k-depth": row.depth }
							}, [tax.value.can.manage ? (openBlock(), createBlock("button", {
								key: 0,
								type: "button",
								class: "k-link-btn",
								"aria-current": editing.value?.id === row.id ? "true" : void 0,
								onClick: ($event) => edit(row)
							}, toDisplayString(row.name), 9, ["aria-current", "onClick"])) : (openBlock(), createBlock("span", { key: 1 }, toDisplayString(row.name), 1))], 4)]),
							"cell-slug": withCtx(({ row }) => [row.path && row.count ? (openBlock(), createBlock("a", {
								key: 0,
								href: row.path,
								class: "k-term-slug",
								target: "_blank",
								rel: "noopener"
							}, toDisplayString(row.path), 9, ["href"])) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-term-slug"
							}, toDisplayString(row.path ?? row.slug), 1))]),
							"cell-count": withCtx(({ row }) => [createVNode("span", { class: "k-num" }, toDisplayString(row.count), 1)]),
							"cell-actions": withCtx(({ row }) => [tax.value.can.manage ? (openBlock(), createBlock(unref(KButton), {
								key: 0,
								size: "sm",
								variant: "ghost",
								icon: "trash",
								"aria-label": unref(__)("Delete “{name}”", { name: row.name }),
								onClick: ($event) => remove(row)
							}, null, 8, ["aria-label", "onClick"])) : createCommentVNode("v-if", true)]),
							_: 1
						}, 8, [
							"columns",
							"rows",
							"loading",
							"caption",
							"empty",
							"empty-text"
						])];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/content/Terms.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
