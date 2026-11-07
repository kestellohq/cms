import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, reactive, ref, renderList, toDisplayString, unref, useSSRContext, vModelCheckbox, withCtx, withDirectives, withModifiers } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrLooseContain, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head, Link, useRouter } from "@kestello/app";
import { ApiError, __, notify, useApi, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSwitch, KTable } from "@kestello/ui";
// core/resources/js/pages/admin/content-types/Index.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Index",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* System → Content types: the types and taxonomies built here, and the
		* ones themes and plugins declare in code (read-only).
		*/
		const api = useApi();
		const router = useRouter();
		const list = useResource("/content-models");
		const built = computed(() => list.data.value ?? []);
		const code = computed(() => list.meta.value?.code ?? []);
		const types = computed(() => list.meta.value?.types ?? []);
		const builtColumns = [
			{
				key: "label",
				label: __("Name")
			},
			{
				key: "kind",
				label: __("Kind")
			},
			{
				key: "path",
				label: __("Address")
			},
			{
				key: "fields",
				label: __("Fields"),
				align: "end"
			}
		];
		const codeColumns = [
			{
				key: "label",
				label: __("Name")
			},
			{
				key: "kind",
				label: __("Kind")
			},
			{
				key: "path",
				label: __("Address")
			},
			{
				key: "owner",
				label: __("Defined by")
			}
		];
		const owner = (o) => o === "core" ? __("Kestello") : o.startsWith("theme:") ? __("Theme {name}", { name: o.slice(6) }) : o.startsWith("plugin:") ? __("Plugin {name}", { name: o.slice(7) }) : o;
		const count = (fields) => {
			let n = 0;
			const walk = (list) => list.forEach((f) => {
				n++;
				walk(f.children ?? []);
			});
			walk(fields ?? []);
			return n;
		};
		const creating = ref(null);
		const form = reactive({
			singular: "",
			plural: "",
			name: "",
			public: true,
			path: "",
			types: []
		});
		const touched = reactive({
			plural: false,
			name: false,
			path: false
		});
		const errors = ref({});
		const saving = ref(false);
		const slug = (s) => s.toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
		function start(kind) {
			Object.assign(form, {
				singular: "",
				plural: "",
				name: "",
				public: true,
				path: "",
				types: []
			});
			Object.assign(touched, {
				plural: false,
				name: false,
				path: false
			});
			errors.value = {};
			creating.value = kind;
		}
		function singularChanged() {
			if (!touched.plural) form.plural = form.singular ? `${form.singular}s` : "";
			if (!touched.name) form.name = slug(form.singular).replace(/^[^a-z]+/, "").slice(0, 32);
			pluralChanged();
		}
		function pluralChanged() {
			if (!touched.path) form.path = form.plural ? `/${slug(form.plural)}` : "";
		}
		async function create() {
			saving.value = true;
			errors.value = {};
			const definition = {
				singular: form.singular.trim(),
				plural: form.plural.trim(),
				path: form.public ? form.path.trim() : null
			};
			if (creating.value === "type") Object.assign(definition, {
				archive: form.public,
				fields: []
			});
			else definition.types = form.types;
			try {
				const { data } = await api.post("/content-models", {
					kind: creating.value,
					name: form.name.trim(),
					definition
				});
				notify.success(creating.value === "type" ? __("{name} created. Now add its fields.", { name: data.definition.plural }) : __("{name} created.", { name: data.definition.plural }));
				creating.value = null;
				router.visit(`/admin/content-types/${data.id}`);
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		const err = (key) => errors.value[key] ?? errors.value[`definition.${key}`] ?? "";
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("Content types") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Content types"),
				description: unref(__)("The kinds of content this site has and the fields each one holds. Types you build here work like the built-in ones: the editor, the API, search and public pages all follow them.")
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), {
							icon: "tag",
							onClick: ($event) => start("taxonomy")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("New taxonomy"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("New taxonomy")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "plus",
							onClick: ($event) => start("type")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("New content type"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("New content type")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), {
						icon: "tag",
						onClick: ($event) => start("taxonomy")
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New taxonomy")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						variant: "primary",
						icon: "plus",
						onClick: ($event) => start("type")
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New content type")), 1)]),
						_: 1
					}, 8, ["onClick"])];
				}),
				_: 1
			}, _parent));
			if (unref(list).error.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(list).error.value.message)}`);
					else return [createTextVNode(toDisplayString(unref(list).error.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`<!--[-->`);
			ssrRenderList(built.value.filter((b) => b.skipped), (m) => {
				_push(ssrRenderComponent(unref(KAlert), {
					key: m.id,
					tone: "warning"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("{name} is switched off: {reason}", {
							name: m.definition.plural,
							reason: m.skipped
						}))}`);
						else return [createTextVNode(toDisplayString(unref(__)("{name} is switched off: {reason}", {
							name: m.definition.plural,
							reason: m.skipped
						})), 1)];
					}),
					_: 2
				}, _parent));
			});
			_push(`<!--]-->`);
			_push(ssrRenderComponent(unref(KCard), {
				coord: "T1",
				title: unref(__)("Built here"),
				flush: ""
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: builtColumns,
						rows: built.value,
						loading: unref(list).loading.value && !unref(list).data.value,
						empty: unref(__)("Nothing built yet"),
						"empty-text": unref(__)("Create a content type for things like events, products or team members, with exactly the fields they need.")
					}, {
						"cell-label": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(ssrRenderComponent(unref(Link), {
									href: `/admin/content-types/${row.id}`,
									class: "k-link k-ct__name"
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(row.definition.plural)}`);
										else return [createTextVNode(toDisplayString(row.definition.plural), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								_push(`<code class="k-ct__code" data-v-3e7618e4${_scopeId}>${ssrInterpolate(row.name)}</code>`);
							} else return [createVNode(unref(Link), {
								href: `/admin/content-types/${row.id}`,
								class: "k-link k-ct__name"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.definition.plural), 1)]),
								_: 2
							}, 1032, ["href"]), createVNode("code", { class: "k-ct__code" }, toDisplayString(row.name), 1)];
						}),
						"cell-kind": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KBadge), null, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(row.kind === "type" ? unref(__)("Content type") : unref(__)("Taxonomy"))}`);
									else return [createTextVNode(toDisplayString(row.kind === "type" ? unref(__)("Content type") : unref(__)("Taxonomy")), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else return [createVNode(unref(KBadge), null, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.kind === "type" ? unref(__)("Content type") : unref(__)("Taxonomy")), 1)]),
								_: 2
							}, 1024)];
						}),
						"cell-path": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (row.definition.path) _push(`<code class="k-ct__code" data-v-3e7618e4${_scopeId}>${ssrInterpolate(row.definition.path)}</code>`);
								else _push(`<span class="k-muted" data-v-3e7618e4${_scopeId}>${ssrInterpolate(unref(__)("Not public"))}</span>`);
							} else return [row.definition.path ? (openBlock(), createBlock("code", {
								key: 0,
								class: "k-ct__code"
							}, toDisplayString(row.definition.path), 1)) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-muted"
							}, toDisplayString(unref(__)("Not public")), 1))];
						}),
						"cell-fields": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (row.kind === "type") _push(`<span class="k-num" data-v-3e7618e4${_scopeId}>${ssrInterpolate(count(row.definition.fields))}</span>`);
								else _push(`<span class="k-muted k-small" data-v-3e7618e4${_scopeId}>${ssrInterpolate(row.definition.types.map((t) => types.value.find((x) => x.name === t)?.label ?? t).join(", "))}</span>`);
							} else return [row.kind === "type" ? (openBlock(), createBlock("span", {
								key: 0,
								class: "k-num"
							}, toDisplayString(count(row.definition.fields)), 1)) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-muted k-small"
							}, toDisplayString(row.definition.types.map((t) => types.value.find((x) => x.name === t)?.label ?? t).join(", ")), 1))];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: builtColumns,
						rows: built.value,
						loading: unref(list).loading.value && !unref(list).data.value,
						empty: unref(__)("Nothing built yet"),
						"empty-text": unref(__)("Create a content type for things like events, products or team members, with exactly the fields they need.")
					}, {
						"cell-label": withCtx(({ row }) => [createVNode(unref(Link), {
							href: `/admin/content-types/${row.id}`,
							class: "k-link k-ct__name"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.definition.plural), 1)]),
							_: 2
						}, 1032, ["href"]), createVNode("code", { class: "k-ct__code" }, toDisplayString(row.name), 1)]),
						"cell-kind": withCtx(({ row }) => [createVNode(unref(KBadge), null, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.kind === "type" ? unref(__)("Content type") : unref(__)("Taxonomy")), 1)]),
							_: 2
						}, 1024)]),
						"cell-path": withCtx(({ row }) => [row.definition.path ? (openBlock(), createBlock("code", {
							key: 0,
							class: "k-ct__code"
						}, toDisplayString(row.definition.path), 1)) : (openBlock(), createBlock("span", {
							key: 1,
							class: "k-muted"
						}, toDisplayString(unref(__)("Not public")), 1))]),
						"cell-fields": withCtx(({ row }) => [row.kind === "type" ? (openBlock(), createBlock("span", {
							key: 0,
							class: "k-num"
						}, toDisplayString(count(row.definition.fields)), 1)) : (openBlock(), createBlock("span", {
							key: 1,
							class: "k-muted k-small"
						}, toDisplayString(row.definition.types.map((t) => types.value.find((x) => x.name === t)?.label ?? t).join(", ")), 1))]),
						_: 1
					}, 8, [
						"rows",
						"loading",
						"empty",
						"empty-text"
					])];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KCard), {
				coord: "T2",
				title: unref(__)("From the theme and plugins"),
				description: unref(__)("Declared in code. Change them there, or copy one into a type built here."),
				flush: ""
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: codeColumns,
						rows: code.value,
						empty: unref(__)("None.")
					}, {
						"cell-label": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(`<span class="k-ct__name" data-v-3e7618e4${_scopeId}>${ssrInterpolate(row.label)}</span> <code class="k-ct__code" data-v-3e7618e4${_scopeId}>${ssrInterpolate(row.name)}</code>`);
							else return [
								createVNode("span", { class: "k-ct__name" }, toDisplayString(row.label), 1),
								createTextVNode(),
								createVNode("code", { class: "k-ct__code" }, toDisplayString(row.name), 1)
							];
						}),
						"cell-kind": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KBadge), null, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(row.kind === "type" ? unref(__)("Content type") : unref(__)("Taxonomy"))}`);
									else return [createTextVNode(toDisplayString(row.kind === "type" ? unref(__)("Content type") : unref(__)("Taxonomy")), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else return [createVNode(unref(KBadge), null, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.kind === "type" ? unref(__)("Content type") : unref(__)("Taxonomy")), 1)]),
								_: 2
							}, 1024)];
						}),
						"cell-path": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (row.path !== null) _push(`<code class="k-ct__code" data-v-3e7618e4${_scopeId}>${ssrInterpolate(row.path === "" ? "/" : row.path)}</code>`);
								else _push(`<span class="k-muted" data-v-3e7618e4${_scopeId}>${ssrInterpolate(unref(__)("Not public"))}</span>`);
							} else return [row.path !== null ? (openBlock(), createBlock("code", {
								key: 0,
								class: "k-ct__code"
							}, toDisplayString(row.path === "" ? "/" : row.path), 1)) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-muted"
							}, toDisplayString(unref(__)("Not public")), 1))];
						}),
						"cell-owner": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(`<span class="k-muted" data-v-3e7618e4${_scopeId}>${ssrInterpolate(owner(row.owner))}</span>`);
							else return [createVNode("span", { class: "k-muted" }, toDisplayString(owner(row.owner)), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: codeColumns,
						rows: code.value,
						empty: unref(__)("None.")
					}, {
						"cell-label": withCtx(({ row }) => [
							createVNode("span", { class: "k-ct__name" }, toDisplayString(row.label), 1),
							createTextVNode(),
							createVNode("code", { class: "k-ct__code" }, toDisplayString(row.name), 1)
						]),
						"cell-kind": withCtx(({ row }) => [createVNode(unref(KBadge), null, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.kind === "type" ? unref(__)("Content type") : unref(__)("Taxonomy")), 1)]),
							_: 2
						}, 1024)]),
						"cell-path": withCtx(({ row }) => [row.path !== null ? (openBlock(), createBlock("code", {
							key: 0,
							class: "k-ct__code"
						}, toDisplayString(row.path === "" ? "/" : row.path), 1)) : (openBlock(), createBlock("span", {
							key: 1,
							class: "k-muted"
						}, toDisplayString(unref(__)("Not public")), 1))]),
						"cell-owner": withCtx(({ row }) => [createVNode("span", { class: "k-muted" }, toDisplayString(owner(row.owner)), 1)]),
						_: 1
					}, 8, ["rows", "empty"])];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: creating.value !== null,
				title: creating.value === "type" ? unref(__)("New content type") : unref(__)("New taxonomy"),
				onClose: ($event) => creating.value = null
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => creating.value = null }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							type: "submit",
							form: "k-ct-create",
							loading: saving.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Create"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Create")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => creating.value = null }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						variant: "primary",
						type: "submit",
						form: "k-ct-create",
						loading: saving.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Create")), 1)]),
						_: 1
					}, 8, ["loading"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-ct-create" class="k-form" data-v-3e7618e4${_scopeId}><div class="k-form__row" data-v-3e7618e4${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("One"),
							help: creating.value === "type" ? unref(__)("Such as Event") : unref(__)("Such as Topic"),
							error: err("singular"),
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.singular,
									"onUpdate:modelValue": [($event) => form.singular = $event, singularChanged],
									maxlength: "60",
									invalid,
									"aria-describedby": describedBy
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.singular,
									"onUpdate:modelValue": [($event) => form.singular = $event, singularChanged],
									maxlength: "60",
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
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Several"),
							help: creating.value === "type" ? unref(__)("Such as Events") : unref(__)("Such as Topics"),
							error: err("plural"),
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.plural,
									"onUpdate:modelValue": [($event) => form.plural = $event, ($event) => {
										touched.plural = true;
										pluralChanged();
									}],
									maxlength: "60",
									invalid,
									"aria-describedby": describedBy
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.plural,
									"onUpdate:modelValue": [($event) => form.plural = $event, ($event) => {
										touched.plural = true;
										pluralChanged();
									}],
									maxlength: "60",
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
						_push(`</div>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Name"),
							help: unref(__)("Used in the API and templates, and can't change later. Lowercase letters, digits and dashes."),
							error: err("name"),
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.name,
									"onUpdate:modelValue": [($event) => form.name = $event, ($event) => touched.name = true],
									class: "k-mono-input",
									maxlength: "32",
									spellcheck: "false",
									autocomplete: "off",
									invalid,
									"aria-describedby": describedBy
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.name,
									"onUpdate:modelValue": [($event) => form.name = $event, ($event) => touched.name = true],
									class: "k-mono-input",
									maxlength: "32",
									spellcheck: "false",
									autocomplete: "off",
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
						if (creating.value === "taxonomy") {
							_push(`<fieldset class="k-ct__types" data-v-3e7618e4${_scopeId}><legend data-v-3e7618e4${_scopeId}>${ssrInterpolate(unref(__)("For"))}</legend><!--[-->`);
							ssrRenderList(types.value, (t) => {
								_push(`<label class="k-ct__check" data-v-3e7618e4${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.types) ? ssrLooseContain(form.types, t.name) : form.types) ? " checked" : ""} type="checkbox"${ssrRenderAttr("value", t.name)} data-v-3e7618e4${_scopeId}> ${ssrInterpolate(t.label)}</label>`);
							});
							_push(`<!--]-->`);
							if (err("types")) _push(`<p class="k-field__error" role="alert" data-v-3e7618e4${_scopeId}>${ssrInterpolate(err("types"))}</p>`);
							else _push(`<!---->`);
							_push(`</fieldset>`);
						} else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KSwitch), {
							modelValue: form.public,
							"onUpdate:modelValue": ($event) => form.public = $event,
							label: creating.value === "type" ? unref(__)("Public pages and a list of all of them") : unref(__)("A public page for each term")
						}, null, _parent, _scopeId));
						if (form.public) _push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Address"),
							help: unref(__)("Pages live below it, such as {path}/first-one.", { path: form.path || "/events" }),
							error: err("path")
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.path,
									"onUpdate:modelValue": [($event) => form.path = $event, ($event) => touched.path = true],
									class: "k-mono-input",
									maxlength: "100",
									spellcheck: "false",
									autocomplete: "off",
									invalid,
									"aria-describedby": describedBy
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.path,
									"onUpdate:modelValue": [($event) => form.path = $event, ($event) => touched.path = true],
									class: "k-mono-input",
									maxlength: "100",
									spellcheck: "false",
									autocomplete: "off",
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
						else _push(`<!---->`);
						_push(`</form>`);
					} else return [createVNode("form", {
						id: "k-ct-create",
						class: "k-form",
						onSubmit: withModifiers(create, ["prevent"])
					}, [
						createVNode("div", { class: "k-form__row" }, [createVNode(unref(KField), {
							label: unref(__)("One"),
							help: creating.value === "type" ? unref(__)("Such as Event") : unref(__)("Such as Topic"),
							error: err("singular"),
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.singular,
								"onUpdate:modelValue": [($event) => form.singular = $event, singularChanged],
								maxlength: "60",
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
						]), createVNode(unref(KField), {
							label: unref(__)("Several"),
							help: creating.value === "type" ? unref(__)("Such as Events") : unref(__)("Such as Topics"),
							error: err("plural"),
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.plural,
								"onUpdate:modelValue": [($event) => form.plural = $event, ($event) => {
									touched.plural = true;
									pluralChanged();
								}],
								maxlength: "60",
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
						])]),
						createVNode(unref(KField), {
							label: unref(__)("Name"),
							help: unref(__)("Used in the API and templates, and can't change later. Lowercase letters, digits and dashes."),
							error: err("name"),
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.name,
								"onUpdate:modelValue": [($event) => form.name = $event, ($event) => touched.name = true],
								class: "k-mono-input",
								maxlength: "32",
								spellcheck: "false",
								autocomplete: "off",
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
						creating.value === "taxonomy" ? (openBlock(), createBlock("fieldset", {
							key: 0,
							class: "k-ct__types"
						}, [
							createVNode("legend", null, toDisplayString(unref(__)("For")), 1),
							(openBlock(true), createBlock(Fragment, null, renderList(types.value, (t) => {
								return openBlock(), createBlock("label", {
									key: t.name,
									class: "k-ct__check"
								}, [withDirectives(createVNode("input", {
									"onUpdate:modelValue": ($event) => form.types = $event,
									type: "checkbox",
									value: t.name
								}, null, 8, ["onUpdate:modelValue", "value"]), [[vModelCheckbox, form.types]]), createTextVNode(" " + toDisplayString(t.label), 1)]);
							}), 128)),
							err("types") ? (openBlock(), createBlock("p", {
								key: 0,
								class: "k-field__error",
								role: "alert"
							}, toDisplayString(err("types")), 1)) : createCommentVNode("v-if", true)
						])) : createCommentVNode("v-if", true),
						createVNode(unref(KSwitch), {
							modelValue: form.public,
							"onUpdate:modelValue": ($event) => form.public = $event,
							label: creating.value === "type" ? unref(__)("Public pages and a list of all of them") : unref(__)("A public page for each term")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						]),
						form.public ? (openBlock(), createBlock(unref(KField), {
							key: 1,
							label: unref(__)("Address"),
							help: unref(__)("Pages live below it, such as {path}/first-one.", { path: form.path || "/events" }),
							error: err("path")
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.path,
								"onUpdate:modelValue": [($event) => form.path = $event, ($event) => touched.path = true],
								class: "k-mono-input",
								maxlength: "100",
								spellcheck: "false",
								autocomplete: "off",
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
						])) : createCommentVNode("v-if", true)
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/content-types/Index.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Index_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-3e7618e4"]]);
export { Index_default as default };
