import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, isRef, openBlock, reactive, ref, renderList, toDisplayString, unref, useSSRContext, watch, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Link, useRouter } from "@kestello/app";
import { ApiError, __, notify, useApi } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KEmpty, KField, KIcon, KInput, KModal, KPageHeader, KSelect, KSwitch, KTabs, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/appearance/Templates.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Templates",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* Appearance → Templates: the block layouts of the running theme. Templates
		* lay out entries, parts are shared pieces (header, footer) and patterns are
		* ready-made groups of blocks for the editor.
		*/
		const api = useApi();
		const router = useRouter();
		const data = ref(null);
		const loadError = ref(null);
		async function load() {
			try {
				data.value = (await api.get("/design/templates")).data;
			} catch (e) {
				loadError.value = e;
			}
		}
		load();
		const KINDS = {
			template: {
				label: __("Templates"),
				one: __("template"),
				icon: "squares-four",
				empty: __("This theme lays out entries with its own pages. Add a template to lay them out with blocks instead.")
			},
			part: {
				label: __("Parts"),
				one: __("part"),
				icon: "layout",
				empty: __("No parts yet. A header or footer made here can be placed in every template.")
			},
			pattern: {
				label: __("Patterns"),
				one: __("pattern"),
				icon: "star",
				empty: __("No patterns yet. Patterns appear in the block inserter, ready to drop into any page.")
			}
		};
		const params = new URLSearchParams(typeof location === "undefined" ? "" : location.search);
		const tab = ref(KINDS[params.get("kind")] ? params.get("kind") : "template");
		watch(tab, (k) => {
			if (typeof history !== "undefined") history.replaceState(history.state, "", `?kind=${k}`);
		});
		const tabs = computed(() => Object.entries(KINDS).map(([id, k]) => ({
			id,
			label: k.label,
			count: data.value?.[id]?.length ?? 0
		})));
		const rows = computed(() => data.value?.[tab.value] ?? []);
		const SOURCE = {
			core: __("Comes with Kestello"),
			theme: __("From the theme"),
			customized: __("Changed on this site"),
			site: __("Made on this site")
		};
		const typeNames = computed(() => Object.fromEntries((data.value?.types ?? []).map((t) => [t.name, t.plural])));
		function usedFor(row) {
			if (tab.value === "template") {
				if (row.key === "entry") return __("Every entry without its own template");
				if (row.key.startsWith("entry-")) return __("Every entry of {type}", { type: typeNames.value[row.key.slice(6)] ?? row.key.slice(6) });
				return row.for ? __("Chosen per entry: {types}", { types: row.for.map((t) => typeNames.value[t] ?? t).join(", ") }) : __("Chosen per entry");
			}
			if (tab.value === "part") return data.value.areas[row.area] ?? row.area;
			return row.synced ? __("{category} · synced", { category: row.category }) : row.category;
		}
		const creating = ref(false);
		const form = reactive({
			title: "",
			key: "",
			applies: "custom",
			type: "",
			area: "header",
			category: "",
			synced: false
		});
		const formErrors = ref({});
		const saving = ref(false);
		const slug = (s) => s.toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 60);
		let keyTouched = false;
		watch(() => form.title, (t) => {
			if (!keyTouched) form.key = slug(t);
		});
		const typeOptions = computed(() => (data.value?.types ?? []).map((t) => ({
			value: t.name,
			label: t.plural
		})));
		const areaOptions = computed(() => Object.entries(data.value?.areas ?? {}).map(([value, label]) => ({
			value,
			label
		})));
		const appliesOptions = [
			{
				value: "custom",
				label: __("Entries choose it")
			},
			{
				value: "type",
				label: __("Every entry of one type")
			},
			{
				value: "all",
				label: __("Every entry without a template of its own")
			}
		];
		const finalKey = computed(() => {
			if (tab.value !== "template" || form.applies === "custom") return form.key;
			return form.applies === "all" ? "entry" : `entry-${form.type}`;
		});
		function openNew() {
			Object.assign(form, {
				title: "",
				key: "",
				applies: "custom",
				type: data.value?.types?.[0]?.name ?? "",
				area: "header",
				category: "",
				synced: false
			});
			keyTouched = false;
			formErrors.value = {};
			creating.value = true;
		}
		async function create() {
			saving.value = true;
			formErrors.value = {};
			const body = {
				key: finalKey.value,
				title: form.title,
				blocks: []
			};
			if (tab.value === "part") body.area = form.area;
			if (tab.value === "pattern") Object.assign(body, {
				category: form.category,
				synced: form.synced
			});
			try {
				await api.post(`/design/templates/${tab.value}`, body);
				creating.value = false;
				router.visit(`/admin/appearance/templates/${tab.value}/${finalKey.value}`);
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) formErrors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		const keyError = computed(() => {
			const e = formErrors.value.key;
			return Array.isArray(e) ? e[0] : e ?? "";
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Templates"),
				description: data.value ? unref(__)("Block layouts of {theme}. Changes are saved as drafts and reach visitors when you publish them.", { theme: data.value.theme.name }) : ""
			}, createSlots({ _: 2 }, [data.value ? {
				name: "actions",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						variant: "primary",
						icon: "plus",
						onClick: openNew
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("New {kind}", { kind: KINDS[tab.value].one }))}`);
							else return [createTextVNode(toDisplayString(unref(__)("New {kind}", { kind: KINDS[tab.value].one })), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						variant: "primary",
						icon: "plus",
						onClick: openNew
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New {kind}", { kind: KINDS[tab.value].one })), 1)]),
						_: 1
					})];
				}),
				key: "0"
			} : void 0]), _parent));
			if (loadError.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(loadError.value.message)}`);
					else return [createTextVNode(toDisplayString(loadError.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (data.value) {
				_push(`<!--[-->`);
				_push(ssrRenderComponent(unref(KTabs), {
					modelValue: tab.value,
					"onUpdate:modelValue": ($event) => tab.value = $event,
					tabs: tabs.value,
					label: unref(__)("Kinds")
				}, null, _parent));
				_push(ssrRenderComponent(unref(KCard), {
					flush: "",
					class: "k-tpl-card"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							if (!rows.value.length) _push(ssrRenderComponent(unref(KEmpty), {
								icon: KINDS[tab.value].icon,
								title: unref(__)("Nothing here yet")
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) {
										_push(`<p data-v-9aa07534${_scopeId}>${ssrInterpolate(KINDS[tab.value].empty)}</p>`);
										_push(ssrRenderComponent(unref(KButton), {
											icon: "plus",
											onClick: openNew
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("New {kind}", { kind: KINDS[tab.value].one }))}`);
												else return [createTextVNode(toDisplayString(unref(__)("New {kind}", { kind: KINDS[tab.value].one })), 1)];
											}),
											_: 1
										}, _parent, _scopeId));
									} else return [createVNode("p", null, toDisplayString(KINDS[tab.value].empty), 1), createVNode(unref(KButton), {
										icon: "plus",
										onClick: openNew
									}, {
										default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New {kind}", { kind: KINDS[tab.value].one })), 1)]),
										_: 1
									})];
								}),
								_: 1
							}, _parent, _scopeId));
							else {
								_push(`<ul class="k-tpl-list" data-v-9aa07534${_scopeId}><!--[-->`);
								ssrRenderList(rows.value, (row) => {
									_push(`<li data-v-9aa07534${_scopeId}>`);
									_push(ssrRenderComponent(unref(Link), {
										href: `/admin/appearance/templates/${tab.value}/${row.key}`,
										class: "k-tpl-row"
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) {
												_push(ssrRenderComponent(unref(KIcon), {
													name: KINDS[tab.value].icon,
													class: "k-tpl-row__icon"
												}, null, _parent, _scopeId));
												_push(`<span class="k-tpl-row__main" data-v-9aa07534${_scopeId}><span class="k-tpl-row__title" data-v-9aa07534${_scopeId}>${ssrInterpolate(row.title)}</span><span class="k-tpl-row__meta k-muted k-small" data-v-9aa07534${_scopeId}><span class="k-mono" data-v-9aa07534${_scopeId}>${ssrInterpolate(row.key)}</span> · ${ssrInterpolate(usedFor(row))}</span></span><span class="k-tpl-row__state" data-v-9aa07534${_scopeId}>`);
												if (row.draft) _push(ssrRenderComponent(unref(KBadge), { tone: "warning" }, {
													default: withCtx((_, _push, _parent, _scopeId) => {
														if (_push) _push(`${ssrInterpolate(row.published ? unref(__)("Unpublished changes") : unref(__)("Draft"))}`);
														else return [createTextVNode(toDisplayString(row.published ? unref(__)("Unpublished changes") : unref(__)("Draft")), 1)];
													}),
													_: 2
												}, _parent, _scopeId));
												else _push(`<!---->`);
												_push(`<span class="k-muted k-small" data-v-9aa07534${_scopeId}>${ssrInterpolate(SOURCE[row.source])}</span>`);
												if (row.info) {
													_push(`<span class="k-muted k-small" data-v-9aa07534${_scopeId}>`);
													_push(ssrRenderComponent(unref(KTime), { value: row.info.updated_at }, null, _parent, _scopeId));
													_push(`</span>`);
												} else _push(`<!---->`);
												_push(`</span>`);
												_push(ssrRenderComponent(unref(KIcon), {
													name: "caret-right",
													class: "k-tpl-row__go"
												}, null, _parent, _scopeId));
											} else return [
												createVNode(unref(KIcon), {
													name: KINDS[tab.value].icon,
													class: "k-tpl-row__icon"
												}, null, 8, ["name"]),
												createVNode("span", { class: "k-tpl-row__main" }, [createVNode("span", { class: "k-tpl-row__title" }, toDisplayString(row.title), 1), createVNode("span", { class: "k-tpl-row__meta k-muted k-small" }, [createVNode("span", { class: "k-mono" }, toDisplayString(row.key), 1), createTextVNode(" · " + toDisplayString(usedFor(row)), 1)])]),
												createVNode("span", { class: "k-tpl-row__state" }, [
													row.draft ? (openBlock(), createBlock(unref(KBadge), {
														key: 0,
														tone: "warning"
													}, {
														default: withCtx(() => [createTextVNode(toDisplayString(row.published ? unref(__)("Unpublished changes") : unref(__)("Draft")), 1)]),
														_: 2
													}, 1024)) : createCommentVNode("v-if", true),
													createVNode("span", { class: "k-muted k-small" }, toDisplayString(SOURCE[row.source]), 1),
													row.info ? (openBlock(), createBlock("span", {
														key: 1,
														class: "k-muted k-small"
													}, [createVNode(unref(KTime), { value: row.info.updated_at }, null, 8, ["value"])])) : createCommentVNode("v-if", true)
												]),
												createVNode(unref(KIcon), {
													name: "caret-right",
													class: "k-tpl-row__go"
												})
											];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(`</li>`);
								});
								_push(`<!--]--></ul>`);
							}
						} else return [!rows.value.length ? (openBlock(), createBlock(unref(KEmpty), {
							key: 0,
							icon: KINDS[tab.value].icon,
							title: unref(__)("Nothing here yet")
						}, {
							default: withCtx(() => [createVNode("p", null, toDisplayString(KINDS[tab.value].empty), 1), createVNode(unref(KButton), {
								icon: "plus",
								onClick: openNew
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New {kind}", { kind: KINDS[tab.value].one })), 1)]),
								_: 1
							})]),
							_: 1
						}, 8, ["icon", "title"])) : (openBlock(), createBlock("ul", {
							key: 1,
							class: "k-tpl-list"
						}, [(openBlock(true), createBlock(Fragment, null, renderList(rows.value, (row) => {
							return openBlock(), createBlock("li", { key: row.key }, [createVNode(unref(Link), {
								href: `/admin/appearance/templates/${tab.value}/${row.key}`,
								class: "k-tpl-row"
							}, {
								default: withCtx(() => [
									createVNode(unref(KIcon), {
										name: KINDS[tab.value].icon,
										class: "k-tpl-row__icon"
									}, null, 8, ["name"]),
									createVNode("span", { class: "k-tpl-row__main" }, [createVNode("span", { class: "k-tpl-row__title" }, toDisplayString(row.title), 1), createVNode("span", { class: "k-tpl-row__meta k-muted k-small" }, [createVNode("span", { class: "k-mono" }, toDisplayString(row.key), 1), createTextVNode(" · " + toDisplayString(usedFor(row)), 1)])]),
									createVNode("span", { class: "k-tpl-row__state" }, [
										row.draft ? (openBlock(), createBlock(unref(KBadge), {
											key: 0,
											tone: "warning"
										}, {
											default: withCtx(() => [createTextVNode(toDisplayString(row.published ? unref(__)("Unpublished changes") : unref(__)("Draft")), 1)]),
											_: 2
										}, 1024)) : createCommentVNode("v-if", true),
										createVNode("span", { class: "k-muted k-small" }, toDisplayString(SOURCE[row.source]), 1),
										row.info ? (openBlock(), createBlock("span", {
											key: 1,
											class: "k-muted k-small"
										}, [createVNode(unref(KTime), { value: row.info.updated_at }, null, 8, ["value"])])) : createCommentVNode("v-if", true)
									]),
									createVNode(unref(KIcon), {
										name: "caret-right",
										class: "k-tpl-row__go"
									})
								]),
								_: 2
							}, 1032, ["href"])]);
						}), 128))]))];
					}),
					_: 1
				}, _parent));
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KModal), {
				open: creating.value,
				"onUpdate:open": ($event) => creating.value = $event,
				title: unref(__)("New {kind}", { kind: KINDS[tab.value].one })
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => creating.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							form: "k-tpl-new",
							variant: "primary",
							icon: "arrow-right",
							loading: saving.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Create and edit"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Create and edit")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => creating.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						type: "submit",
						form: "k-tpl-new",
						variant: "primary",
						icon: "arrow-right",
						loading: saving.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Create and edit")), 1)]),
						_: 1
					}, 8, ["loading"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-tpl-new" class="k-form" data-v-9aa07534${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Name"),
							error: formErrors.value.title ?? ""
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.title,
									"onUpdate:modelValue": ($event) => form.title = $event,
									required: "",
									maxlength: "120"
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.title,
									"onUpdate:modelValue": ($event) => form.title = $event,
									required: "",
									maxlength: "120"
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						if (tab.value === "template") {
							_push(`<!--[-->`);
							_push(ssrRenderComponent(unref(KField), { label: unref(__)("Used for") }, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KSelect), {
										id,
										modelValue: form.applies,
										"onUpdate:modelValue": ($event) => form.applies = $event,
										options: appliesOptions
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KSelect), {
										id,
										modelValue: form.applies,
										"onUpdate:modelValue": ($event) => form.applies = $event,
										options: appliesOptions
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							if (form.applies === "type") _push(ssrRenderComponent(unref(KField), { label: unref(__)("Content type") }, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KSelect), {
										id,
										modelValue: form.type,
										"onUpdate:modelValue": ($event) => form.type = $event,
										options: typeOptions.value
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KSelect), {
										id,
										modelValue: form.type,
										"onUpdate:modelValue": ($event) => form.type = $event,
										options: typeOptions.value
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"options"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							_push(`<!--]-->`);
						} else _push(`<!---->`);
						if (tab.value !== "template" || form.applies === "custom") _push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Name in code"),
							help: unref(__)("Lowercase letters, digits and hyphens. It can't be changed later."),
							error: keyError.value
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.key,
									"onUpdate:modelValue": ($event) => form.key = $event,
									required: "",
									maxlength: "61",
									pattern: "[a-z0-9][a-z0-9-]*",
									class: "k-mono",
									onInput: ($event) => isRef(keyTouched) ? keyTouched.value = true : keyTouched = true
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.key,
									"onUpdate:modelValue": ($event) => form.key = $event,
									required: "",
									maxlength: "61",
									pattern: "[a-z0-9][a-z0-9-]*",
									class: "k-mono",
									onInput: ($event) => isRef(keyTouched) ? keyTouched.value = true : keyTouched = true
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"onInput"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						else if (keyError.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(keyError.value)}`);
								else return [createTextVNode(toDisplayString(keyError.value), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						if (tab.value === "part") _push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Area"),
							error: formErrors.value.area ?? ""
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KSelect), {
									id,
									modelValue: form.area,
									"onUpdate:modelValue": ($event) => form.area = $event,
									options: areaOptions.value
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KSelect), {
									id,
									modelValue: form.area,
									"onUpdate:modelValue": ($event) => form.area = $event,
									options: areaOptions.value
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"options"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						if (tab.value === "pattern") {
							_push(`<!--[-->`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Category"),
								error: formErrors.value.category ?? "",
								help: unref(__)("Groups patterns in the inserter, such as Heroes or Calls to action. Empty: General.")
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id,
										modelValue: form.category,
										"onUpdate:modelValue": ($event) => form.category = $event,
										maxlength: "40"
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id,
										modelValue: form.category,
										"onUpdate:modelValue": ($event) => form.category = $event,
										maxlength: "40"
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KSwitch), {
								modelValue: form.synced,
								"onUpdate:modelValue": ($event) => form.synced = $event,
								label: unref(__)("Synced")
							}, null, _parent, _scopeId));
							_push(`<p class="k-muted k-small k-tpl-note" data-v-9aa07534${_scopeId}>${ssrInterpolate(form.synced ? unref(__)("Every page that uses it shows the same blocks; changing the pattern changes them all.") : unref(__)("Each page gets its own copy to change freely."))}</p><!--]-->`);
						} else _push(`<!---->`);
						_push(`</form>`);
					} else return [createVNode("form", {
						id: "k-tpl-new",
						class: "k-form",
						onSubmit: withModifiers(create, ["prevent"])
					}, [
						createVNode(unref(KField), {
							label: unref(__)("Name"),
							error: formErrors.value.title ?? ""
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.title,
								"onUpdate:modelValue": ($event) => form.title = $event,
								required: "",
								maxlength: "120"
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label", "error"]),
						tab.value === "template" ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode(unref(KField), { label: unref(__)("Used for") }, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: form.applies,
								"onUpdate:modelValue": ($event) => form.applies = $event,
								options: appliesOptions
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label"]), form.applies === "type" ? (openBlock(), createBlock(unref(KField), {
							key: 0,
							label: unref(__)("Content type")
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: form.type,
								"onUpdate:modelValue": ($event) => form.type = $event,
								options: typeOptions.value
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"options"
							])]),
							_: 1
						}, 8, ["label"])) : createCommentVNode("v-if", true)], 64)) : createCommentVNode("v-if", true),
						tab.value !== "template" || form.applies === "custom" ? (openBlock(), createBlock(unref(KField), {
							key: 1,
							label: unref(__)("Name in code"),
							help: unref(__)("Lowercase letters, digits and hyphens. It can't be changed later."),
							error: keyError.value
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.key,
								"onUpdate:modelValue": ($event) => form.key = $event,
								required: "",
								maxlength: "61",
								pattern: "[a-z0-9][a-z0-9-]*",
								class: "k-mono",
								onInput: ($event) => isRef(keyTouched) ? keyTouched.value = true : keyTouched = true
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"onInput"
							])]),
							_: 1
						}, 8, [
							"label",
							"help",
							"error"
						])) : keyError.value ? (openBlock(), createBlock(unref(KAlert), {
							key: 2,
							tone: "danger"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(keyError.value), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true),
						tab.value === "part" ? (openBlock(), createBlock(unref(KField), {
							key: 3,
							label: unref(__)("Area"),
							error: formErrors.value.area ?? ""
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: form.area,
								"onUpdate:modelValue": ($event) => form.area = $event,
								options: areaOptions.value
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"options"
							])]),
							_: 1
						}, 8, ["label", "error"])) : createCommentVNode("v-if", true),
						tab.value === "pattern" ? (openBlock(), createBlock(Fragment, { key: 4 }, [
							createVNode(unref(KField), {
								label: unref(__)("Category"),
								error: formErrors.value.category ?? "",
								help: unref(__)("Groups patterns in the inserter, such as Heroes or Calls to action. Empty: General.")
							}, {
								default: withCtx(({ id }) => [createVNode(unref(KInput), {
									id,
									modelValue: form.category,
									"onUpdate:modelValue": ($event) => form.category = $event,
									maxlength: "40"
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])]),
								_: 1
							}, 8, [
								"label",
								"error",
								"help"
							]),
							createVNode(unref(KSwitch), {
								modelValue: form.synced,
								"onUpdate:modelValue": ($event) => form.synced = $event,
								label: unref(__)("Synced")
							}, null, 8, [
								"modelValue",
								"onUpdate:modelValue",
								"label"
							]),
							createVNode("p", { class: "k-muted k-small k-tpl-note" }, toDisplayString(form.synced ? unref(__)("Every page that uses it shows the same blocks; changing the pattern changes them all.") : unref(__)("Each page gets its own copy to change freely.")), 1)
						], 64)) : createCommentVNode("v-if", true)
					], 32)];
				}),
				_: 1
			}, _parent));
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/appearance/Templates.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Templates_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-9aa07534"]]);
export { Templates_default as default };
