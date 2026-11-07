import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, openBlock, reactive, ref, renderList, toDisplayString, unref, useSSRContext, watch, withCtx, withModifiers } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head } from "@kestello/app";
import { ApiError, __, confirm, notify, useApi, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSelect } from "@kestello/ui";
// core/resources/js/pages/admin/roles/Index.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Index",
	__ssrInlineRender: true,
	setup(__props) {
		const api = useApi();
		const roles = useResource("/roles");
		const caps = useResource("/capabilities");
		const selected = ref(null);
		const role = computed(() => (roles.data.value ?? []).find((r) => r.name === selected.value) ?? null);
		watch(() => roles.data.value, (list) => {
			if (list?.length && !list.some((r) => r.name === selected.value)) selected.value = list.find((r) => !r.administrator)?.name ?? list[0].name;
		}, { immediate: true });
		const form = reactive({
			label: "",
			description: "",
			capabilities: /* @__PURE__ */ new Set()
		});
		const errors = ref({});
		const saving = ref(false);
		function fill(r) {
			if (!r) return;
			Object.assign(form, {
				label: r.label,
				description: r.description ?? "",
				capabilities: new Set(r.capabilities)
			});
			errors.value = {};
		}
		watch(role, fill, { immediate: true });
		const dirty = computed(() => {
			const r = role.value;
			if (!r || r.administrator) return false;
			const a = [...form.capabilities].sort().join(",");
			return form.label !== r.label || form.description !== (r.description ?? "") || a !== [...r.capabilities].sort().join(",");
		});
		async function pick(name) {
			if (name === selected.value) return;
			if (dirty.value && !await confirm({
				title: __("Discard unsaved changes?"),
				message: __("Your changes to {role} are not saved.", { role: role.value.label }),
				confirmLabel: __("Discard")
			})) return;
			selected.value = name;
		}
		function toggle(cap, on) {
			const next = new Set(form.capabilities);
			on ? next.add(cap) : next.delete(cap);
			form.capabilities = next;
		}
		async function save() {
			saving.value = true;
			errors.value = {};
			try {
				await api.patch(`/roles/${role.value.name}`, {
					label: form.label,
					description: form.description || null,
					capabilities: [...form.capabilities]
				});
				notify.success(__("{role} saved. People with this role get the change on their next click.", { role: form.label }));
				await roles.reload({ silent: true });
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) errors.value = e.errors;
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		async function reset() {
			if (!await confirm({
				title: __("Reset {role}?", { role: role.value.label }),
				message: __("Undo the changes made here and use the capabilities Kestello and your plugins give it."),
				confirmLabel: __("Reset role")
			})) return;
			try {
				await api.delete(`/roles/${role.value.name}`);
				notify.success(__("{role} is back to its defaults.", { role: role.value.label }));
				await roles.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		const reassign = ref("user");
		async function removeRole() {
			const r = role.value;
			if (!await confirm({
				title: __("Delete the {role} role?", { role: r.label }),
				message: r.users ? __("{n} people have it; they become {target}.", {
					n: r.users,
					target: roleLabel(reassign.value)
				}) : __("Nobody has it."),
				confirmLabel: __("Delete role"),
				danger: true
			})) return;
			try {
				await api.delete(`/roles/${r.name}`, { query: { reassign: reassign.value } });
				notify.success(__("Deleted {role}.", { role: r.label }));
				selected.value = null;
				await roles.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		const roleLabel = (name) => (roles.data.value ?? []).find((r) => r.name === name)?.label ?? name;
		const reassignOptions = computed(() => (roles.data.value ?? []).filter((r) => r.name !== selected.value && r.assignable).map((r) => ({
			value: r.name,
			label: r.label
		})));
		const SECTIONS = {
			read: "general",
			profile: "general",
			admin: "general",
			users: "people",
			roles: "people",
			security: "people",
			menus: "content",
			content: "content",
			taxonomy: "content",
			media: "content",
			forms: "content",
			redirects: "content",
			theme: "appearance",
			snippets: "appearance",
			settings: "site",
			extensions: "site",
			system: "site",
			audit: "site",
			backups: "site",
			plugins: "site",
			core: "site",
			content_models: "site"
		};
		const SECTION_LABELS = {
			general: __("Signing in"),
			content: __("Content"),
			people: __("People"),
			appearance: __("Appearance"),
			site: __("Site and system")
		};
		const groups = computed(() => {
			const out = /* @__PURE__ */ new Map();
			for (const c of caps.data.value ?? []) {
				const [first, second] = c.name.split(".");
				let key;
				let label;
				if (c.owner !== "core") {
					key = `owner:${c.owner}`;
					label = __("From {owner}", { owner: c.owner.replace(/^(plugin|theme):/, "") });
				} else if (first === "content" && second && second !== "access" || first === "taxonomy") {
					key = `${first}.${second}`;
					label = second.replace(/[-_]/g, " ").replace(/^\w/, (x) => x.toUpperCase());
				} else {
					key = SECTIONS[first] ?? "general";
					label = SECTION_LABELS[key];
				}
				if (!out.has(key)) out.set(key, {
					key,
					label,
					items: []
				});
				out.get(key).items.push(c);
			}
			const order = (g) => ["general", "content"].indexOf(g.key) + 1 || (g.key.startsWith("content.") ? 3 : g.key.startsWith("taxonomy.") ? 4 : g.key === "people" ? 5 : g.key === "appearance" ? 6 : g.key === "site" ? 7 : 8);
			return [...out.values()].sort((a, b) => order(a) - order(b));
		});
		const state = (cap) => {
			const r = role.value;
			if (!r) return "";
			const on = form.capabilities.has(cap);
			const def = r.defaults.includes(cap);
			return on && !def ? "added" : !on && def ? "removed" : "";
		};
		const locked = computed(() => !role.value || role.value.administrator || !role.value.editable);
		const sensitiveAdded = computed(() => (caps.data.value ?? []).filter((c) => c.sensitive && state(c.name) === "added").map((c) => c.label));
		const sensitiveText = (labels) => __("Whoever has this role will be able to act as an administrator: {list}.", { list: labels.join(", ") });
		const creating = ref(false);
		const draft = reactive({
			label: "",
			name: "",
			from: ""
		});
		const draftErrors = ref({});
		watch(() => draft.label, (v, old) => {
			const slug = (s) => s.toLowerCase().normalize("NFKD").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").replace(/^[^a-z]+/, "").slice(0, 20);
			if (!draft.name || draft.name === slug(old ?? "")) draft.name = slug(v);
		});
		const copyOptions = computed(() => [{
			value: "",
			label: __("Nothing (start empty)")
		}, ...(roles.data.value ?? []).filter((r) => !r.administrator).map((r) => ({
			value: r.name,
			label: r.label
		}))]);
		const creatingBusy = ref(false);
		async function create() {
			if (creatingBusy.value) return;
			creatingBusy.value = true;
			draftErrors.value = {};
			const from = (roles.data.value ?? []).find((r) => r.name === draft.from);
			const grantable = new Set((caps.data.value ?? []).filter((c) => c.grantable).map((c) => c.name));
			try {
				const { data } = await api.post("/roles", {
					name: draft.name,
					label: draft.label,
					capabilities: (from?.capabilities ?? []).filter((c) => grantable.has(c))
				});
				creating.value = false;
				Object.assign(draft, {
					label: "",
					name: "",
					from: ""
				});
				await roles.reload({ silent: true });
				selected.value = data.name;
				notify.success(__("Created {role}. Pick what it may do, then save.", { role: data.label }));
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) draftErrors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				creatingBusy.value = false;
			}
		}
		const err = (k) => Array.isArray(errors.value[k]) ? errors.value[k].join(" ") : errors.value[k] ?? "";
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("Roles") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Roles"),
				description: unref(__)("What each kind of account may do. Give people a role on their page under People.")
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						icon: "plus",
						onClick: ($event) => (draftErrors.value = {}, creating.value = true)
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("New role"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("New role")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						icon: "plus",
						onClick: ($event) => (draftErrors.value = {}, creating.value = true)
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New role")), 1)]),
						_: 1
					}, 8, ["onClick"])];
				}),
				_: 1
			}, _parent));
			if (unref(roles).error.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(roles).error.value.message)}`);
					else return [createTextVNode(toDisplayString(unref(roles).error.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (unref(roles).data.value) {
				_push(`<div class="k-cells k-roles" style="--k-cols:3;">`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "A1",
					title: unref(__)("Roles"),
					flush: ""
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<ul class="k-role-list"${_scopeId}><!--[-->`);
							ssrRenderList(unref(roles).data.value, (r) => {
								_push(`<li${_scopeId}><button type="button" class="k-role-list__item"${ssrRenderAttr("aria-current", r.name === selected.value ? "true" : void 0)}${_scopeId}><span class="k-role-list__name"${_scopeId}>${ssrInterpolate(r.label)}</span><span class="k-role-list__meta"${_scopeId}>`);
								if (r.administrator) _push(ssrRenderComponent(unref(KBadge), { tone: "accent" }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Everything"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Everything")), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else if (r.custom) _push(ssrRenderComponent(unref(KBadge), null, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Custom"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Custom")), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else if (r.customized) _push(ssrRenderComponent(unref(KBadge), { tone: "info" }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Changed"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Changed")), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else _push(`<!---->`);
								_push(`<span class="k-muted k-small k-num"${_scopeId}>${ssrInterpolate(r.users === 1 ? unref(__)("1 person") : unref(__)("{n} people", { n: r.users }))}</span></span></button></li>`);
							});
							_push(`<!--]--></ul>`);
						} else return [createVNode("ul", { class: "k-role-list" }, [(openBlock(true), createBlock(Fragment, null, renderList(unref(roles).data.value, (r) => {
							return openBlock(), createBlock("li", { key: r.name }, [createVNode("button", {
								type: "button",
								class: "k-role-list__item",
								"aria-current": r.name === selected.value ? "true" : void 0,
								onClick: ($event) => pick(r.name)
							}, [createVNode("span", { class: "k-role-list__name" }, toDisplayString(r.label), 1), createVNode("span", { class: "k-role-list__meta" }, [r.administrator ? (openBlock(), createBlock(unref(KBadge), {
								key: 0,
								tone: "accent"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Everything")), 1)]),
								_: 1
							})) : r.custom ? (openBlock(), createBlock(unref(KBadge), { key: 1 }, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Custom")), 1)]),
								_: 1
							})) : r.customized ? (openBlock(), createBlock(unref(KBadge), {
								key: 2,
								tone: "info"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Changed")), 1)]),
								_: 1
							})) : createCommentVNode("v-if", true), createVNode("span", { class: "k-muted k-small k-num" }, toDisplayString(r.users === 1 ? unref(__)("1 person") : unref(__)("{n} people", { n: r.users })), 1)])], 8, ["aria-current", "onClick"])]);
						}), 128))])];
					}),
					_: 1
				}, _parent));
				if (role.value) _push(ssrRenderComponent(unref(KCard), {
					coord: "B1",
					class: "k-span-2",
					title: role.value.label,
					description: role.value.administrator ? unref(__)("Administrators can do everything, including things plugins add later. This role cannot be changed.") : ""
				}, createSlots({
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							if (!role.value.administrator && !role.value.editable) _push(ssrRenderComponent(unref(KAlert), { tone: "info" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("This is your own role, so you cannot change it. Ask an administrator."))}`);
									else return [createTextVNode(toDisplayString(unref(__)("This is your own role, so you cannot change it. Ask an administrator.")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							if (!role.value.administrator) {
								_push(`<form id="k-role-form" class="k-form"${_scopeId}><div class="k-form__row"${_scopeId}>`);
								_push(ssrRenderComponent(unref(KField), {
									label: unref(__)("Name"),
									error: err("label")
								}, {
									default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KInput), {
											id,
											modelValue: form.label,
											"onUpdate:modelValue": ($event) => form.label = $event,
											maxlength: "60",
											disabled: locked.value,
											invalid
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KInput), {
											id,
											modelValue: form.label,
											"onUpdate:modelValue": ($event) => form.label = $event,
											maxlength: "60",
											disabled: locked.value,
											invalid
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue",
											"disabled",
											"invalid"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KField), {
									label: unref(__)("Description"),
									help: unref(__)("Helps whoever assigns roles."),
									error: err("description")
								}, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KInput), {
											id,
											modelValue: form.description,
											"onUpdate:modelValue": ($event) => form.description = $event,
											maxlength: "255",
											disabled: locked.value
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KInput), {
											id,
											modelValue: form.description,
											"onUpdate:modelValue": ($event) => form.description = $event,
											maxlength: "255",
											disabled: locked.value
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue",
											"disabled"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div>`);
								if (err("capabilities")) _push(`<p class="k-field__error"${_scopeId}>${ssrInterpolate(err("capabilities"))}</p>`);
								else _push(`<!---->`);
								if (!locked.value && sensitiveAdded.value.length) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(sensitiveText(sensitiveAdded.value))}`);
										else return [createTextVNode(toDisplayString(sensitiveText(sensitiveAdded.value)), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								else _push(`<!---->`);
								_push(`<div class="k-capgroups"${_scopeId}><!--[-->`);
								ssrRenderList(groups.value, (g) => {
									_push(`<fieldset class="k-capgroup"${_scopeId}><legend class="k-capgroup__title"${_scopeId}>${ssrInterpolate(g.label)}</legend><!--[-->`);
									ssrRenderList(g.items, (c) => {
										_push(`<label class="${ssrRenderClass([state(c.name) ? `is-${state(c.name)}` : "", "k-cap"])}"${ssrRenderAttr("title", c.grantable ? c.name : unref(__)("You can only grant what you have yourself."))}${_scopeId}><input type="checkbox"${ssrIncludeBooleanAttr(form.capabilities.has(c.name)) ? " checked" : ""}${ssrIncludeBooleanAttr(locked.value || !c.grantable && !form.capabilities.has(c.name)) ? " disabled" : ""}${_scopeId}><span class="k-cap__label"${_scopeId}>${ssrInterpolate(c.label)} `);
										if (c.sensitive) _push(`<span class="k-cap__note"${_scopeId}>${ssrInterpolate(unref(__)("Same as administrator access"))}</span>`);
										else _push(`<!---->`);
										_push(`</span>`);
										if (state(c.name) === "added") _push(`<span class="k-cap__diff"${_scopeId}>${ssrInterpolate(unref(__)("added"))}</span>`);
										else if (state(c.name) === "removed") _push(`<span class="k-cap__diff"${_scopeId}>${ssrInterpolate(unref(__)("removed"))}</span>`);
										else _push(`<!---->`);
										_push(`</label>`);
									});
									_push(`<!--]--></fieldset>`);
								});
								_push(`<!--]--></div></form>`);
							} else _push(`<!---->`);
						} else return [!role.value.administrator && !role.value.editable ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "info"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("This is your own role, so you cannot change it. Ask an administrator.")), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true), !role.value.administrator ? (openBlock(), createBlock("form", {
							key: 1,
							id: "k-role-form",
							class: "k-form",
							onSubmit: withModifiers(save, ["prevent"])
						}, [
							createVNode("div", { class: "k-form__row" }, [createVNode(unref(KField), {
								label: unref(__)("Name"),
								error: err("label")
							}, {
								default: withCtx(({ id, invalid }) => [createVNode(unref(KInput), {
									id,
									modelValue: form.label,
									"onUpdate:modelValue": ($event) => form.label = $event,
									maxlength: "60",
									disabled: locked.value,
									invalid
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"disabled",
									"invalid"
								])]),
								_: 1
							}, 8, ["label", "error"]), createVNode(unref(KField), {
								label: unref(__)("Description"),
								help: unref(__)("Helps whoever assigns roles."),
								error: err("description")
							}, {
								default: withCtx(({ id }) => [createVNode(unref(KInput), {
									id,
									modelValue: form.description,
									"onUpdate:modelValue": ($event) => form.description = $event,
									maxlength: "255",
									disabled: locked.value
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"disabled"
								])]),
								_: 1
							}, 8, [
								"label",
								"help",
								"error"
							])]),
							err("capabilities") ? (openBlock(), createBlock("p", {
								key: 0,
								class: "k-field__error"
							}, toDisplayString(err("capabilities")), 1)) : createCommentVNode("v-if", true),
							!locked.value && sensitiveAdded.value.length ? (openBlock(), createBlock(unref(KAlert), {
								key: 1,
								tone: "warning"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(sensitiveText(sensitiveAdded.value)), 1)]),
								_: 1
							})) : createCommentVNode("v-if", true),
							createVNode("div", { class: "k-capgroups" }, [(openBlock(true), createBlock(Fragment, null, renderList(groups.value, (g) => {
								return openBlock(), createBlock("fieldset", {
									key: g.key,
									class: "k-capgroup"
								}, [createVNode("legend", { class: "k-capgroup__title" }, toDisplayString(g.label), 1), (openBlock(true), createBlock(Fragment, null, renderList(g.items, (c) => {
									return openBlock(), createBlock("label", {
										key: c.name,
										class: ["k-cap", state(c.name) ? `is-${state(c.name)}` : ""],
										title: c.grantable ? c.name : unref(__)("You can only grant what you have yourself.")
									}, [
										createVNode("input", {
											type: "checkbox",
											checked: form.capabilities.has(c.name),
											disabled: locked.value || !c.grantable && !form.capabilities.has(c.name),
											onChange: ($event) => toggle(c.name, $event.target.checked)
										}, null, 40, [
											"checked",
											"disabled",
											"onChange"
										]),
										createVNode("span", { class: "k-cap__label" }, [createTextVNode(toDisplayString(c.label) + " ", 1), c.sensitive ? (openBlock(), createBlock("span", {
											key: 0,
											class: "k-cap__note"
										}, toDisplayString(unref(__)("Same as administrator access")), 1)) : createCommentVNode("v-if", true)]),
										state(c.name) === "added" ? (openBlock(), createBlock("span", {
											key: 0,
											class: "k-cap__diff"
										}, toDisplayString(unref(__)("added")), 1)) : state(c.name) === "removed" ? (openBlock(), createBlock("span", {
											key: 1,
											class: "k-cap__diff"
										}, toDisplayString(unref(__)("removed")), 1)) : createCommentVNode("v-if", true)
									], 10, ["title"]);
								}), 128))]);
							}), 128))])
						], 32)) : createCommentVNode("v-if", true)];
					}),
					_: 2
				}, [!locked.value ? {
					name: "footer",
					fn: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-form__actions"${_scopeId}>`);
							if (role.value.customized) _push(ssrRenderComponent(unref(KButton), {
								variant: "ghost",
								icon: "arrow-counter-clockwise",
								onClick: reset
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Reset to defaults"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Reset to defaults")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							if (role.value.custom) {
								_push(`<!--[-->`);
								if (role.value.users) _push(ssrRenderComponent(unref(KSelect), {
									modelValue: reassign.value,
									"onUpdate:modelValue": ($event) => reassign.value = $event,
									options: reassignOptions.value,
									"aria-label": unref(__)("Move people with this role to"),
									class: "k-role-reassign"
								}, null, _parent, _scopeId));
								else _push(`<!---->`);
								_push(ssrRenderComponent(unref(KButton), {
									variant: "ghost",
									icon: "trash",
									onClick: removeRole
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Delete role"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Delete role")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`<!--]-->`);
							} else _push(`<!---->`);
							if (dirty.value) _push(`<span class="k-muted k-small k-role-dirty"${_scopeId}>${ssrInterpolate(unref(__)("Unsaved changes"))}</span>`);
							else _push(`<!---->`);
							_push(ssrRenderComponent(unref(KButton), {
								type: "submit",
								form: "k-role-form",
								variant: "primary",
								loading: saving.value,
								disabled: !dirty.value
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Save role"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Save role")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else return [createVNode("div", { class: "k-form__actions" }, [
							role.value.customized ? (openBlock(), createBlock(unref(KButton), {
								key: 0,
								variant: "ghost",
								icon: "arrow-counter-clockwise",
								onClick: reset
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Reset to defaults")), 1)]),
								_: 1
							})) : createCommentVNode("v-if", true),
							role.value.custom ? (openBlock(), createBlock(Fragment, { key: 1 }, [role.value.users ? (openBlock(), createBlock(unref(KSelect), {
								key: 0,
								modelValue: reassign.value,
								"onUpdate:modelValue": ($event) => reassign.value = $event,
								options: reassignOptions.value,
								"aria-label": unref(__)("Move people with this role to"),
								class: "k-role-reassign"
							}, null, 8, [
								"modelValue",
								"onUpdate:modelValue",
								"options",
								"aria-label"
							])) : createCommentVNode("v-if", true), createVNode(unref(KButton), {
								variant: "ghost",
								icon: "trash",
								onClick: removeRole
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Delete role")), 1)]),
								_: 1
							})], 64)) : createCommentVNode("v-if", true),
							dirty.value ? (openBlock(), createBlock("span", {
								key: 2,
								class: "k-muted k-small k-role-dirty"
							}, toDisplayString(unref(__)("Unsaved changes")), 1)) : createCommentVNode("v-if", true),
							createVNode(unref(KButton), {
								type: "submit",
								form: "k-role-form",
								variant: "primary",
								loading: saving.value,
								disabled: !dirty.value
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save role")), 1)]),
								_: 1
							}, 8, ["loading", "disabled"])
						])];
					}),
					key: "0"
				} : void 0]), _parent));
				else _push(`<!---->`);
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KModal), {
				open: creating.value,
				title: unref(__)("New role"),
				onClose: ($event) => creating.value = false
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
							form: "k-new-role",
							variant: "primary",
							loading: creatingBusy.value,
							disabled: !draft.label.trim() || !draft.name
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Create role"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Create role")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => creating.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						type: "submit",
						form: "k-new-role",
						variant: "primary",
						loading: creatingBusy.value,
						disabled: !draft.label.trim() || !draft.name
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Create role")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-new-role" class="k-form"${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Name"),
							help: unref(__)("What people see, e.g. “Reviewer”."),
							error: draftErrors.value.label ?? "",
							required: ""
						}, {
							default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: draft.label,
									"onUpdate:modelValue": ($event) => draft.label = $event,
									maxlength: "60",
									autocomplete: "off",
									invalid
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: draft.label,
									"onUpdate:modelValue": ($event) => draft.label = $event,
									maxlength: "60",
									autocomplete: "off",
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
							label: unref(__)("Id"),
							help: unref(__)("Lowercase letters, digits, dashes. It cannot change later."),
							error: draftErrors.value.name ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: draft.name,
									"onUpdate:modelValue": ($event) => draft.name = $event,
									maxlength: "20",
									spellcheck: "false",
									autocomplete: "off",
									class: "k-mono-input",
									invalid,
									"aria-describedby": describedBy
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: draft.name,
									"onUpdate:modelValue": ($event) => draft.name = $event,
									maxlength: "20",
									spellcheck: "false",
									autocomplete: "off",
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
						_push(ssrRenderComponent(unref(KField), { label: unref(__)("Start with the capabilities of") }, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KSelect), {
									id,
									modelValue: draft.from,
									"onUpdate:modelValue": ($event) => draft.from = $event,
									options: copyOptions.value
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KSelect), {
									id,
									modelValue: draft.from,
									"onUpdate:modelValue": ($event) => draft.from = $event,
									options: copyOptions.value
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"options"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</form>`);
					} else return [createVNode("form", {
						id: "k-new-role",
						class: "k-form",
						onSubmit: withModifiers(create, ["prevent"])
					}, [
						createVNode(unref(KField), {
							label: unref(__)("Name"),
							help: unref(__)("What people see, e.g. “Reviewer”."),
							error: draftErrors.value.label ?? "",
							required: ""
						}, {
							default: withCtx(({ id, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: draft.label,
								"onUpdate:modelValue": ($event) => draft.label = $event,
								maxlength: "60",
								autocomplete: "off",
								invalid
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"invalid"
							])]),
							_: 1
						}, 8, [
							"label",
							"help",
							"error"
						]),
						createVNode(unref(KField), {
							label: unref(__)("Id"),
							help: unref(__)("Lowercase letters, digits, dashes. It cannot change later."),
							error: draftErrors.value.name ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: draft.name,
								"onUpdate:modelValue": ($event) => draft.name = $event,
								maxlength: "20",
								spellcheck: "false",
								autocomplete: "off",
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
						createVNode(unref(KField), { label: unref(__)("Start with the capabilities of") }, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: draft.from,
								"onUpdate:modelValue": ($event) => draft.from = $event,
								options: copyOptions.value
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"options"
							])]),
							_: 1
						}, 8, ["label"])
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/roles/Index.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
