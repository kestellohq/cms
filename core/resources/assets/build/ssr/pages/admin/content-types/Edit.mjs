import { n as FieldList_default } from "../../../chunks/FieldSettings-CeeHlog6.mjs";
import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, ref, renderList, toDisplayString, unref, useSSRContext, vModelText, withCtx, withDirectives } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrLooseContain, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head, Link, usePage, useRouter } from "@kestello/app";
import { ApiError, __, clone, confirm, notify, useApi } from "@kestello/sdk";
import { KAlert, KButton, KCard, KField, KIcon, KInput, KModal, KSelect, KSwitch } from "@kestello/ui";
// core/resources/js/pages/admin/content-types/Edit.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Edit",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* One content type or taxonomy built in the admin. Saving answers with
		* the changes made; changes that affect stored values are listed for
		* review first.
		*/
		const api = useApi();
		const page = usePage();
		const router = useRouter();
		const id = computed(() => String(page.props.params?.id ?? ""));
		const model = ref(null);
		const def = ref(null);
		const meta = ref(null);
		const loadError = ref(null);
		const errors = ref({});
		const saving = ref(false);
		const PERMALINKS = {
			"{slug}": "/events/first-one",
			"{year}/{slug}": "/events/2026/first-one",
			"{year}/{month}/{slug}": "/events/2026/03/first-one",
			"{year}/{month}/{day}/{slug}": "/events/2026/03/14/first-one"
		};
		const ICONS = [
			"file-text",
			"calendar-dots",
			"briefcase",
			"users",
			"identification-badge",
			"map-pin",
			"storefront",
			"package",
			"book",
			"graduation-cap",
			"camera",
			"film-strip",
			"music-notes",
			"megaphone",
			"quotes",
			"star",
			"trophy",
			"house",
			"wrench",
			"coffee",
			"heart",
			"lightbulb",
			"question",
			"notebook"
		];
		const toRows = (fields) => fields.map((f) => ({
			...f,
			options: {
				...f.options,
				...f.options?.choices && !Array.isArray(f.options.choices) ? { choices: Object.entries(f.options.choices).map(([value, label]) => ({
					value,
					label
				})) } : {}
			},
			children: toRows(f.children ?? [])
		}));
		const clean = (fields) => fields.map(({ _new, _namedByHand, ...f }) => ({
			...f,
			options: {
				...f.options,
				...Array.isArray(f.options?.choices) ? { choices: f.options.choices.map(({ value, label }) => ({
					value,
					label
				})) } : {}
			},
			children: clean(f.children ?? [])
		}));
		async function load() {
			try {
				const [one, all] = await Promise.all([api.get(`/content-models/${id.value}`), api.get("/content-models")]);
				model.value = one.data;
				meta.value = all.meta;
				const d = clone(one.data.definition);
				if (d.fields) d.fields = toRows(d.fields);
				def.value = d;
			} catch (e) {
				loadError.value = e;
			}
		}
		load();
		const isType = computed(() => model.value?.kind === "type");
		const permalinkOptions = computed(() => (meta.value?.permalinks ?? ["{slug}"]).map((p) => ({
			value: p,
			label: (PERMALINKS[p] ?? p).replace("/events", def.value?.path || "/events")
		})));
		const payload = () => ({
			...def.value,
			...def.value.fields ? { fields: clean(def.value.fields) } : {}
		});
		const err = (key) => errors.value[key] ?? "";
		const review = ref(null);
		const purge = ref(false);
		async function save(confirmed = false) {
			saving.value = true;
			errors.value = {};
			try {
				const { data } = await api.patch(`/content-models/${id.value}`, {
					definition: payload(),
					confirm: confirmed,
					purge: confirmed && purge.value
				});
				review.value = null;
				const moved = data.changes.filter((c) => c.change === "renamed").length;
				notify.success(moved ? __("Saved. Stored values followed {n} renamed fields.", { n: moved }) : __("Saved."));
				await load();
			} catch (e) {
				if (e instanceof ApiError && e.status === 409 && !confirmed) try {
					const { data } = await api.post(`/content-models/${id.value}/preview`, { definition: payload() });
					purge.value = false;
					review.value = data;
				} catch (previewError) {
					notify.error(previewError);
				}
				else if (e instanceof ApiError && e.status === 422) {
					errors.value = Object.fromEntries(Object.entries(e.fieldErrors()).map(([k, v]) => [k.replace(/^definition\./, ""), v]));
					notify.error(__("Some fields need attention."));
				} else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		const changeText = (c) => ({
			removed: c.entries ? __("{field} is removed. {n} entries have a value in it.", {
				field: c.from,
				n: c.entries
			}) : __("{field} is removed. No entry uses it.", { field: c.from }),
			retyped: __("{field} changes from {from} to {to}. {n} entries have a value that may no longer fit.", {
				field: c.field,
				from: c.from,
				to: c.to,
				n: c.entries
			}),
			renamed: __("{from} becomes {to}. Values move along.", {
				from: c.from,
				to: c.to
			}),
			path: c.from && c.to ? __("The address changes from {from} to {to}. Old links are redirected.", {
				from: c.from,
				to: c.to
			}) : __("The address changes.")
		})[c.change] ?? c.change;
		async function remove() {
			if (!await confirm({
				title: __("Delete {name}?", { name: def.value.plural }),
				message: __("Only possible when nothing uses it any more. This can't be undone."),
				confirmLabel: __("Delete"),
				danger: true
			})) return;
			try {
				await api.delete(`/content-models/${id.value}`);
				notify.success(__("{name} deleted.", { name: def.value.plural }));
				router.visit("/admin/content-types");
			} catch (e) {
				notify.error(e);
			}
		}
		const showPhp = ref(false);
		async function copyPhp() {
			try {
				await navigator.clipboard.writeText(model.value.php);
				notify.success(__("Copied."));
			} catch (e) {
				notify.error(e);
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: def.value ? def.value.plural : unref(__)("Content type") }, null, _parent));
			if (loadError.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(loadError.value.message)}`);
					else return [createTextVNode(toDisplayString(loadError.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (def.value) {
				_push(`<form data-v-cf351721><div class="k-editor-head" data-v-cf351721>`);
				_push(ssrRenderComponent(unref(Link), {
					href: "/admin/content-types",
					class: "k-link k-editor-head__back"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(ssrRenderComponent(unref(KIcon), { name: "arrow-left" }, null, _parent, _scopeId));
							_push(` ${ssrInterpolate(unref(__)("Content types"))}`);
						} else return [createVNode(unref(KIcon), { name: "arrow-left" }), createTextVNode(" " + toDisplayString(unref(__)("Content types")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`<span class="k-editor-head__state" data-v-cf351721><h1 class="k-ct-title" data-v-cf351721>${ssrInterpolate(def.value.plural)}</h1><code class="k-ct-code" data-v-cf351721>${ssrInterpolate(model.value.name)}</code></span>`);
				_push(ssrRenderComponent(unref(KButton), {
					icon: "code",
					onClick: ($event) => showPhp.value = true
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("View as PHP"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("View as PHP")), 1)];
					}),
					_: 1
				}, _parent));
				if (isType.value) _push(ssrRenderComponent(unref(KButton), {
					href: `/admin/content/${model.value.name}`,
					icon: "list-bullets"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Open entries"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Open entries")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(ssrRenderComponent(unref(KButton), {
					variant: "primary",
					type: "submit",
					icon: "check",
					loading: saving.value
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Save"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Save")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
				if (model.value.skipped ?? false) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(model.value.skipped)}`);
						else return [createTextVNode(toDisplayString(model.value.skipped), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`<div class="k-editor" data-v-cf351721><div class="k-editor__main" data-v-cf351721>`);
				if (isType.value) {
					_push(`<!--[--><h2 class="k-ct-section" data-v-cf351721>${ssrInterpolate(unref(__)("Fields"))}</h2><p class="k-muted k-ct-lead" data-v-cf351721>${ssrInterpolate(unref(__)("Every entry has a title, an address, an author and a publishing date. These fields come on top, in this order, in the editor."))}</p>`);
					_push(ssrRenderComponent(FieldList_default, {
						fields: def.value.fields,
						meta: meta.value,
						errors: errors.value
					}, null, _parent));
					if (err("fields")) _push(`<p class="k-field__error" role="alert" data-v-cf351721>${ssrInterpolate(err("fields"))}</p>`);
					else _push(`<!---->`);
					_push(`<!--]-->`);
				} else {
					_push(`<!--[--><h2 class="k-ct-section" data-v-cf351721>${ssrInterpolate(unref(__)("Used for"))}</h2><div class="k-ct-checks" data-v-cf351721><!--[-->`);
					ssrRenderList(meta.value.types, (t) => {
						_push(`<label class="k-ct-check" data-v-cf351721><input${ssrIncludeBooleanAttr(Array.isArray(def.value.types) ? ssrLooseContain(def.value.types, t.name) : def.value.types) ? " checked" : ""} type="checkbox"${ssrRenderAttr("value", t.name)} data-v-cf351721> ${ssrInterpolate(t.label)}</label>`);
					});
					_push(`<!--]--></div>`);
					if (err("types")) _push(`<p class="k-field__error" role="alert" data-v-cf351721>${ssrInterpolate(err("types"))}</p>`);
					else _push(`<!---->`);
					_push(`<!--]-->`);
				}
				_push(`</div><aside class="k-editor__side"${ssrRenderAttr("aria-label", unref(__)("Settings"))} data-v-cf351721>`);
				_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Names") }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("One"),
								error: err("singular"),
								required: ""
							}, {
								default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id: fid,
										modelValue: def.value.singular,
										"onUpdate:modelValue": ($event) => def.value.singular = $event,
										maxlength: "60",
										invalid
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id: fid,
										modelValue: def.value.singular,
										"onUpdate:modelValue": ($event) => def.value.singular = $event,
										maxlength: "60",
										invalid
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"invalid"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Several"),
								error: err("plural"),
								required: ""
							}, {
								default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id: fid,
										modelValue: def.value.plural,
										"onUpdate:modelValue": ($event) => def.value.plural = $event,
										maxlength: "60",
										invalid
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id: fid,
										modelValue: def.value.plural,
										"onUpdate:modelValue": ($event) => def.value.plural = $event,
										maxlength: "60",
										invalid
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"invalid"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Description"),
								help: unref(__)("Shown on its list page and to search engines.")
							}, {
								default: withCtx(({ id: fid }, _push, _parent, _scopeId) => {
									if (_push) _push(`<textarea${ssrRenderAttr("id", fid)} class="k-input k-ct-textarea" rows="3" maxlength="300" data-v-cf351721${_scopeId}>${ssrInterpolate(def.value.description)}</textarea>`);
									else return [withDirectives(createVNode("textarea", {
										id: fid,
										"onUpdate:modelValue": ($event) => def.value.description = $event,
										class: "k-input k-ct-textarea",
										rows: "3",
										maxlength: "300"
									}, null, 8, ["id", "onUpdate:modelValue"]), [[vModelText, def.value.description]])];
								}),
								_: 1
							}, _parent, _scopeId));
							if (isType.value) {
								_push(`<div class="k-ct-icons" role="radiogroup"${ssrRenderAttr("aria-label", unref(__)("Menu icon"))} data-v-cf351721${_scopeId}><span class="k-ct-icons__label" data-v-cf351721${_scopeId}>${ssrInterpolate(unref(__)("Menu icon"))}</span><!--[-->`);
								ssrRenderList(ICONS, (icon) => {
									_push(`<button type="button" role="radio" class="${ssrRenderClass([{ "is-on": def.value.icon === icon }, "k-ct-icon"])}"${ssrRenderAttr("aria-checked", def.value.icon === icon ? "true" : "false")}${ssrRenderAttr("aria-label", icon.replace(/-/g, " "))} data-v-cf351721${_scopeId}>`);
									_push(ssrRenderComponent(unref(KIcon), { name: icon }, null, _parent, _scopeId));
									_push(`</button>`);
								});
								_push(`<!--]--></div>`);
							} else _push(`<!---->`);
						} else return [
							createVNode(unref(KField), {
								label: unref(__)("One"),
								error: err("singular"),
								required: ""
							}, {
								default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
									id: fid,
									modelValue: def.value.singular,
									"onUpdate:modelValue": ($event) => def.value.singular = $event,
									maxlength: "60",
									invalid
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"invalid"
								])]),
								_: 1
							}, 8, ["label", "error"]),
							createVNode(unref(KField), {
								label: unref(__)("Several"),
								error: err("plural"),
								required: ""
							}, {
								default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
									id: fid,
									modelValue: def.value.plural,
									"onUpdate:modelValue": ($event) => def.value.plural = $event,
									maxlength: "60",
									invalid
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"invalid"
								])]),
								_: 1
							}, 8, ["label", "error"]),
							createVNode(unref(KField), {
								label: unref(__)("Description"),
								help: unref(__)("Shown on its list page and to search engines.")
							}, {
								default: withCtx(({ id: fid }) => [withDirectives(createVNode("textarea", {
									id: fid,
									"onUpdate:modelValue": ($event) => def.value.description = $event,
									class: "k-input k-ct-textarea",
									rows: "3",
									maxlength: "300"
								}, null, 8, ["id", "onUpdate:modelValue"]), [[vModelText, def.value.description]])]),
								_: 1
							}, 8, ["label", "help"]),
							isType.value ? (openBlock(), createBlock("div", {
								key: 0,
								class: "k-ct-icons",
								role: "radiogroup",
								"aria-label": unref(__)("Menu icon")
							}, [createVNode("span", { class: "k-ct-icons__label" }, toDisplayString(unref(__)("Menu icon")), 1), (openBlock(), createBlock(Fragment, null, renderList(ICONS, (icon) => {
								return createVNode("button", {
									key: icon,
									type: "button",
									role: "radio",
									class: ["k-ct-icon", { "is-on": def.value.icon === icon }],
									"aria-checked": def.value.icon === icon ? "true" : "false",
									"aria-label": icon.replace(/-/g, " "),
									onClick: ($event) => def.value.icon = icon
								}, [createVNode(unref(KIcon), { name: icon }, null, 8, ["name"])], 10, [
									"aria-checked",
									"aria-label",
									"onClick"
								]);
							}), 64))], 8, ["aria-label"])) : createCommentVNode("v-if", true)
						];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Public pages") }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Address"),
								help: def.value.path ? unref(__)("Leave empty to keep them off the public site.") : unref(__)("Such as /events. Empty: editors only, no public pages."),
								error: err("path")
							}, {
								default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id: fid,
										"model-value": def.value.path ?? "",
										class: "k-mono-input",
										maxlength: "100",
										spellcheck: "false",
										invalid,
										"onUpdate:modelValue": (v) => def.value.path = v.trim() === "" ? null : v
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id: fid,
										"model-value": def.value.path ?? "",
										class: "k-mono-input",
										maxlength: "100",
										spellcheck: "false",
										invalid,
										"onUpdate:modelValue": (v) => def.value.path = v.trim() === "" ? null : v
									}, null, 8, [
										"id",
										"model-value",
										"invalid",
										"onUpdate:modelValue"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							if (isType.value && def.value.path) {
								_push(`<!--[-->`);
								_push(ssrRenderComponent(unref(KSwitch), {
									modelValue: def.value.archive,
									"onUpdate:modelValue": ($event) => def.value.archive = $event,
									label: unref(__)("A page listing all of them")
								}, null, _parent, _scopeId));
								if (!def.value.hierarchical) _push(ssrRenderComponent(unref(KField), { label: unref(__)("Page addresses") }, {
									default: withCtx(({ id: fid }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KSelect), {
											id: fid,
											modelValue: def.value.permalink,
											"onUpdate:modelValue": ($event) => def.value.permalink = $event,
											options: permalinkOptions.value
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KSelect), {
											id: fid,
											modelValue: def.value.permalink,
											"onUpdate:modelValue": ($event) => def.value.permalink = $event,
											options: permalinkOptions.value
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
								_push(ssrRenderComponent(unref(KSwitch), {
									modelValue: def.value.searchable,
									"onUpdate:modelValue": ($event) => def.value.searchable = $event,
									label: unref(__)("Include in site search")
								}, null, _parent, _scopeId));
								_push(`<!--]-->`);
							} else _push(`<!---->`);
						} else return [createVNode(unref(KField), {
							label: unref(__)("Address"),
							help: def.value.path ? unref(__)("Leave empty to keep them off the public site.") : unref(__)("Such as /events. Empty: editors only, no public pages."),
							error: err("path")
						}, {
							default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
								id: fid,
								"model-value": def.value.path ?? "",
								class: "k-mono-input",
								maxlength: "100",
								spellcheck: "false",
								invalid,
								"onUpdate:modelValue": (v) => def.value.path = v.trim() === "" ? null : v
							}, null, 8, [
								"id",
								"model-value",
								"invalid",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, [
							"label",
							"help",
							"error"
						]), isType.value && def.value.path ? (openBlock(), createBlock(Fragment, { key: 0 }, [
							createVNode(unref(KSwitch), {
								modelValue: def.value.archive,
								"onUpdate:modelValue": ($event) => def.value.archive = $event,
								label: unref(__)("A page listing all of them")
							}, null, 8, [
								"modelValue",
								"onUpdate:modelValue",
								"label"
							]),
							!def.value.hierarchical ? (openBlock(), createBlock(unref(KField), {
								key: 0,
								label: unref(__)("Page addresses")
							}, {
								default: withCtx(({ id: fid }) => [createVNode(unref(KSelect), {
									id: fid,
									modelValue: def.value.permalink,
									"onUpdate:modelValue": ($event) => def.value.permalink = $event,
									options: permalinkOptions.value
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"options"
								])]),
								_: 1
							}, 8, ["label"])) : createCommentVNode("v-if", true),
							createVNode(unref(KSwitch), {
								modelValue: def.value.searchable,
								"onUpdate:modelValue": ($event) => def.value.searchable = $event,
								label: unref(__)("Include in site search")
							}, null, 8, [
								"modelValue",
								"onUpdate:modelValue",
								"label"
							])
						], 64)) : createCommentVNode("v-if", true)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Behavior") }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-ct-switches" data-v-cf351721${_scopeId}>`);
							_push(ssrRenderComponent(unref(KSwitch), {
								modelValue: def.value.hierarchical,
								"onUpdate:modelValue": ($event) => def.value.hierarchical = $event,
								label: isType.value ? unref(__)("Entries can have a parent") : unref(__)("Terms can have a parent")
							}, null, _parent, _scopeId));
							if (isType.value) _push(ssrRenderComponent(unref(KSwitch), {
								modelValue: def.value.excerpt,
								"onUpdate:modelValue": ($event) => def.value.excerpt = $event,
								label: unref(__)("A short summary field")
							}, null, _parent, _scopeId));
							else _push(`<!---->`);
							_push(`</div>`);
							if (isType.value) {
								_push(`<div class="k-form__row" data-v-cf351721${_scopeId}>`);
								_push(ssrRenderComponent(unref(KField), {
									label: unref(__)("Keep revisions"),
									help: unref(__)("Per entry. 0 keeps none.")
								}, {
									default: withCtx(({ id: fid }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KInput), {
											id: fid,
											modelValue: def.value.revisions,
											"onUpdate:modelValue": ($event) => def.value.revisions = $event,
											modelModifiers: { number: true },
											type: "number",
											min: "0",
											max: "500"
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KInput), {
											id: fid,
											modelValue: def.value.revisions,
											"onUpdate:modelValue": ($event) => def.value.revisions = $event,
											modelModifiers: { number: true },
											type: "number",
											min: "0",
											max: "500"
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KField), {
									label: unref(__)("Menu position"),
									help: unref(__)("Lower comes first.")
								}, {
									default: withCtx(({ id: fid }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KInput), {
											id: fid,
											modelValue: def.value.menu_order,
											"onUpdate:modelValue": ($event) => def.value.menu_order = $event,
											modelModifiers: { number: true },
											type: "number",
											min: "0",
											max: "1000"
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KInput), {
											id: fid,
											modelValue: def.value.menu_order,
											"onUpdate:modelValue": ($event) => def.value.menu_order = $event,
											modelModifiers: { number: true },
											type: "number",
											min: "0",
											max: "1000"
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div>`);
							} else _push(`<!---->`);
						} else return [createVNode("div", { class: "k-ct-switches" }, [createVNode(unref(KSwitch), {
							modelValue: def.value.hierarchical,
							"onUpdate:modelValue": ($event) => def.value.hierarchical = $event,
							label: isType.value ? unref(__)("Entries can have a parent") : unref(__)("Terms can have a parent")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						]), isType.value ? (openBlock(), createBlock(unref(KSwitch), {
							key: 0,
							modelValue: def.value.excerpt,
							"onUpdate:modelValue": ($event) => def.value.excerpt = $event,
							label: unref(__)("A short summary field")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						])) : createCommentVNode("v-if", true)]), isType.value ? (openBlock(), createBlock("div", {
							key: 0,
							class: "k-form__row"
						}, [createVNode(unref(KField), {
							label: unref(__)("Keep revisions"),
							help: unref(__)("Per entry. 0 keeps none.")
						}, {
							default: withCtx(({ id: fid }) => [createVNode(unref(KInput), {
								id: fid,
								modelValue: def.value.revisions,
								"onUpdate:modelValue": ($event) => def.value.revisions = $event,
								modelModifiers: { number: true },
								type: "number",
								min: "0",
								max: "500"
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label", "help"]), createVNode(unref(KField), {
							label: unref(__)("Menu position"),
							help: unref(__)("Lower comes first.")
						}, {
							default: withCtx(({ id: fid }) => [createVNode(unref(KInput), {
								id: fid,
								modelValue: def.value.menu_order,
								"onUpdate:modelValue": ($event) => def.value.menu_order = $event,
								modelModifiers: { number: true },
								type: "number",
								min: "0",
								max: "1000"
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label", "help"])])) : createCommentVNode("v-if", true)];
					}),
					_: 1
				}, _parent));
				_push(`<div class="k-editor__trash" data-v-cf351721>`);
				_push(ssrRenderComponent(unref(KButton), {
					variant: "ghost",
					icon: "trash",
					onClick: remove
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(isType.value ? unref(__)("Delete this content type") : unref(__)("Delete this taxonomy"))}`);
						else return [createTextVNode(toDisplayString(isType.value ? unref(__)("Delete this content type") : unref(__)("Delete this taxonomy")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div></aside></div></form>`);
			} else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KModal), {
				open: review.value !== null,
				title: unref(__)("Review the changes"),
				onClose: ($event) => review.value = null
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => review.value = null }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Go back"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Go back")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: purge.value ? "danger" : "primary",
							loading: saving.value,
							onClick: ($event) => save(true)
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(purge.value ? unref(__)("Save and delete the values") : unref(__)("Save"))}`);
								else return [createTextVNode(toDisplayString(purge.value ? unref(__)("Save and delete the values") : unref(__)("Save")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => review.value = null }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Go back")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						variant: purge.value ? "danger" : "primary",
						loading: saving.value,
						onClick: ($event) => save(true)
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(purge.value ? unref(__)("Save and delete the values") : unref(__)("Save")), 1)]),
						_: 1
					}, 8, [
						"variant",
						"loading",
						"onClick"
					])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (review.value) {
							_push(`<!--[--><p data-v-cf351721${_scopeId}>${ssrInterpolate(unref(__)("Some changes affect values already stored in entries."))}</p><ul class="k-ct-changes" data-v-cf351721${_scopeId}><!--[-->`);
							ssrRenderList(review.value.changes, (c, i) => {
								_push(`<li class="${ssrRenderClass({ "is-destructive": c.destructive })}" data-v-cf351721${_scopeId}>`);
								_push(ssrRenderComponent(unref(KIcon), { name: c.destructive ? "warning" : "check" }, null, _parent, _scopeId));
								_push(`<span data-v-cf351721${_scopeId}>${ssrInterpolate(changeText(c))}`);
								if (c.reason) _push(`<!--[--> (${ssrInterpolate(c.reason)})<!--]-->`);
								else _push(`<!---->`);
								_push(`</span></li>`);
							});
							_push(`<!--]--></ul>`);
							if (review.value.changes.some((c) => c.change === "removed" && c.destructive)) _push(ssrRenderComponent(unref(KSwitch), {
								modelValue: purge.value,
								"onUpdate:modelValue": ($event) => purge.value = $event,
								label: unref(__)("Also delete the removed fields' values")
							}, null, _parent, _scopeId));
							else _push(`<!---->`);
							_push(`<p class="k-muted k-small" data-v-cf351721${_scopeId}>${ssrInterpolate(purge.value ? unref(__)("The values are deleted from every entry. This can't be undone.") : unref(__)("The values stay stored but hidden. Adding the field back with the same name shows them again."))}</p><!--]-->`);
						} else _push(`<!---->`);
					} else return [review.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
						createVNode("p", null, toDisplayString(unref(__)("Some changes affect values already stored in entries.")), 1),
						createVNode("ul", { class: "k-ct-changes" }, [(openBlock(true), createBlock(Fragment, null, renderList(review.value.changes, (c, i) => {
							return openBlock(), createBlock("li", {
								key: i,
								class: { "is-destructive": c.destructive }
							}, [createVNode(unref(KIcon), { name: c.destructive ? "warning" : "check" }, null, 8, ["name"]), createVNode("span", null, [createTextVNode(toDisplayString(changeText(c)), 1), c.reason ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" (" + toDisplayString(c.reason) + ")", 1)], 64)) : createCommentVNode("v-if", true)])], 2);
						}), 128))]),
						review.value.changes.some((c) => c.change === "removed" && c.destructive) ? (openBlock(), createBlock(unref(KSwitch), {
							key: 0,
							modelValue: purge.value,
							"onUpdate:modelValue": ($event) => purge.value = $event,
							label: unref(__)("Also delete the removed fields' values")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						])) : createCommentVNode("v-if", true),
						createVNode("p", { class: "k-muted k-small" }, toDisplayString(purge.value ? unref(__)("The values are deleted from every entry. This can't be undone.") : unref(__)("The values stay stored but hidden. Adding the field back with the same name shows them again.")), 1)
					], 64)) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: showPhp.value,
				title: unref(__)("As PHP"),
				size: "lg",
				onClose: ($event) => showPhp.value = false
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => showPhp.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Close"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Close")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "clipboard",
							onClick: copyPhp
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Copy"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Copy")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => showPhp.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Close")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						variant: "primary",
						icon: "clipboard",
						onClick: copyPhp
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Copy")), 1)]),
						_: 1
					})];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`<p class="k-muted" data-v-cf351721${_scopeId}>${ssrInterpolate(unref(__)("The same structure for a theme or plugin's boot() method. Declared in code, it takes over from the one built here, so delete this one once the code is live."))}</p><pre class="k-ct-php" data-v-cf351721${_scopeId}><code data-v-cf351721${_scopeId}>${ssrInterpolate(model.value?.php)}</code></pre>`);
					else return [createVNode("p", { class: "k-muted" }, toDisplayString(unref(__)("The same structure for a theme or plugin's boot() method. Declared in code, it takes over from the one built here, so delete this one once the code is live.")), 1), createVNode("pre", { class: "k-ct-php" }, [createVNode("code", null, toDisplayString(model.value?.php), 1)])];
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/content-types/Edit.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Edit_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-cf351721"]]);
export { Edit_default as default };
