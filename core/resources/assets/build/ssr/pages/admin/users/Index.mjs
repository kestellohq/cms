import { computed, createBlock, createSlots, createTextVNode, createVNode, openBlock, reactive, ref, renderList, toDisplayString, unref, useSSRContext, watch, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { Head, Link, useRouter } from "@kestello/app";
import { ApiError, __, notify, useApi, useCan, useExtensions, useResource } from "@kestello/sdk";
import { KAvatar, KBadge, KButton, KCard, KExtension, KField, KInput, KModal, KPageHeader, KSelect, KStatusBadge, KTable, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/users/Index.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Index",
	__ssrInlineRender: true,
	setup(__props) {
		const api = useApi();
		const can = useCan();
		const router = useRouter();
		const roles = useResource("/roles");
		const filters = reactive({
			search: "",
			role: "",
			active: ""
		});
		const rows = ref([]);
		const cursor = ref(null);
		const loading = ref(false);
		let seq = 0;
		const extColumns = useExtensions("columns", "users.list");
		const columns = computed(() => [
			{
				key: "username",
				label: __("Name")
			},
			{
				key: "role",
				label: __("Role")
			},
			{
				key: "is_active",
				label: __("Status")
			},
			{
				key: "last_login_at",
				label: __("Last signed in")
			},
			...extColumns.value.map((c) => ({
				key: c.key,
				label: c.label,
				align: c.align
			})),
			{
				key: "created_at",
				label: __("Joined"),
				align: "end"
			}
		]);
		const roleLabel = (name) => roles.data.value?.find((r) => r.name === name)?.label ?? name;
		const roleOptions = computed(() => [{
			value: "",
			label: __("Every role")
		}, ...(roles.data.value ?? []).map((r) => ({
			value: r.name,
			label: r.label
		}))]);
		const statusOptions = [
			{
				value: "",
				label: __("Active and deactivated")
			},
			{
				value: "1",
				label: __("Active")
			},
			{
				value: "0",
				label: __("Deactivated")
			}
		];
		async function load(more = false) {
			const mine = ++seq;
			loading.value = true;
			try {
				const query = {
					limit: 25,
					cursor: more ? cursor.value : void 0,
					search: filters.search.trim() || void 0
				};
				if (filters.role) query["filter[role]"] = filters.role;
				if (filters.active !== "") query["filter[active]"] = filters.active;
				const body = await api.get("/users", query);
				if (mine !== seq) return;
				rows.value = more ? [...rows.value, ...body.data] : body.data;
				cursor.value = body.meta.next_cursor;
			} catch (e) {
				notify.error(e);
			} finally {
				if (mine === seq) loading.value = false;
			}
		}
		load();
		let debounce = null;
		watch(() => filters.search, () => {
			clearTimeout(debounce);
			debounce = setTimeout(() => load(), 250);
		});
		watch(() => [filters.role, filters.active], () => load());
		const adding = ref(false);
		const saving = ref(false);
		const form = reactive({
			username: "",
			email: "",
			role: "user",
			password: ""
		});
		const errors = ref({});
		const assignable = computed(() => (roles.data.value ?? []).filter((r) => r.assignable).map((r) => ({
			value: r.name,
			label: r.label
		})));
		function openAdd() {
			Object.assign(form, {
				username: "",
				email: "",
				role: assignable.value.some((r) => r.value === "user") ? "user" : assignable.value[0]?.value ?? "",
				password: ""
			});
			errors.value = {};
			adding.value = true;
		}
		function generate() {
			const alphabet = "abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789";
			const bytes = crypto.getRandomValues(/* @__PURE__ */ new Uint8Array(20));
			form.password = Array.from(bytes, (b) => alphabet[b % 56]).join("").replace(/(.{5})(?!$)/g, "$1-");
		}
		async function create() {
			saving.value = true;
			errors.value = {};
			try {
				const { data } = await api.post("/users", {
					...form,
					email: form.email.trim()
				});
				adding.value = false;
				notify.success(__("{name} can now sign in.", { name: data.username }));
				router.visit(`/admin/users/${data.id}`);
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("People") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("People"),
				description: unref(__)("Everyone who can sign in to this site, and what each of them may do.")
			}, createSlots({ _: 2 }, [unref(can)("users.manage") ? {
				name: "actions",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						variant: "primary",
						icon: "user-plus",
						onClick: openAdd
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Add a person"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Add a person")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						variant: "primary",
						icon: "user-plus",
						onClick: openAdd
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Add a person")), 1)]),
						_: 1
					})];
				}),
				key: "0"
			} : void 0]), _parent));
			_push(`<div class="k-toolbar" role="search">`);
			_push(ssrRenderComponent(unref(KField), {
				label: unref(__)("Search"),
				class: "k-toolbar__grow"
			}, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: filters.search,
						"onUpdate:modelValue": ($event) => filters.search = $event,
						type: "search",
						autocomplete: "off"
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: filters.search,
						"onUpdate:modelValue": ($event) => filters.search = $event,
						type: "search",
						autocomplete: "off"
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue"
					])];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KField), { label: unref(__)("Role") }, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KSelect), {
						id,
						modelValue: filters.role,
						"onUpdate:modelValue": ($event) => filters.role = $event,
						options: roleOptions.value
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KSelect), {
						id,
						modelValue: filters.role,
						"onUpdate:modelValue": ($event) => filters.role = $event,
						options: roleOptions.value
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue",
						"options"
					])];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KField), { label: unref(__)("Status") }, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KSelect), {
						id,
						modelValue: filters.active,
						"onUpdate:modelValue": ($event) => filters.active = $event,
						options: statusOptions
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KSelect), {
						id,
						modelValue: filters.active,
						"onUpdate:modelValue": ($event) => filters.active = $event,
						options: statusOptions
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue"
					])];
				}),
				_: 1
			}, _parent));
			_push(`</div>`);
			_push(ssrRenderComponent(unref(KCard), {
				coord: "A1",
				title: unref(__)("People"),
				flush: ""
			}, createSlots({
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: columns.value,
						rows: rows.value,
						loading: loading.value && !rows.value.length,
						empty: filters.search || filters.role || filters.active !== "" ? unref(__)("Nobody matches these filters.") : unref(__)("Nobody has an account yet.")
					}, createSlots({
						"cell-username": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<div class="k-person"${_scopeId}>`);
								_push(ssrRenderComponent(unref(KAvatar), {
									user: row,
									size: 28
								}, null, _parent, _scopeId));
								_push(`<div${_scopeId}>`);
								_push(ssrRenderComponent(unref(Link), {
									href: `/admin/users/${row.id}`,
									class: "k-link"
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(row.username)}`);
										else return [createTextVNode(toDisplayString(row.username), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								_push(`<div class="k-muted k-small"${_scopeId}>${ssrInterpolate(row.email)}</div></div></div>`);
							} else return [createVNode("div", { class: "k-person" }, [createVNode(unref(KAvatar), {
								user: row,
								size: 28
							}, null, 8, ["user"]), createVNode("div", null, [createVNode(unref(Link), {
								href: `/admin/users/${row.id}`,
								class: "k-link"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.username), 1)]),
								_: 2
							}, 1032, ["href"]), createVNode("div", { class: "k-muted k-small" }, toDisplayString(row.email), 1)])])];
						}),
						"cell-role": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KBadge), null, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(roleLabel(row.role))}`);
									else return [createTextVNode(toDisplayString(roleLabel(row.role)), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else return [createVNode(unref(KBadge), null, {
								default: withCtx(() => [createTextVNode(toDisplayString(roleLabel(row.role)), 1)]),
								_: 2
							}, 1024)];
						}),
						"cell-is_active": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KStatusBadge), { status: row.is_active ? "good" : "disabled" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(row.is_active ? unref(__)("Active") : unref(__)("Deactivated"))}`);
									else return [createTextVNode(toDisplayString(row.is_active ? unref(__)("Active") : unref(__)("Deactivated")), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else return [createVNode(unref(KStatusBadge), { status: row.is_active ? "good" : "disabled" }, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.is_active ? unref(__)("Active") : unref(__)("Deactivated")), 1)]),
								_: 2
							}, 1032, ["status"])];
						}),
						"cell-last_login_at": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.last_login_at }, null, _parent, _scopeId));
							else return [createVNode(unref(KTime), { value: row.last_login_at }, null, 8, ["value"])];
						}),
						"cell-created_at": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(`<span class="k-muted"${_scopeId}>${ssrInterpolate(String(row.created_at ?? "").slice(0, 10) || "—")}</span>`);
							else return [createVNode("span", { class: "k-muted" }, toDisplayString(String(row.created_at ?? "").slice(0, 10) || "—"), 1)];
						}),
						_: 2
					}, [renderList(unref(extColumns), (col) => {
						return {
							name: `cell-${col.key}`,
							fn: withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (col.component) _push(ssrRenderComponent(unref(KExtension), {
										name: col.component,
										owner: col.owner,
										inline: "",
										row,
										value: row[col.key]
									}, null, _parent, _scopeId));
									else _push(`<span${_scopeId}>${ssrInterpolate(row[col.key] ?? "—")}</span>`);
								} else return [col.component ? (openBlock(), createBlock(unref(KExtension), {
									key: 0,
									name: col.component,
									owner: col.owner,
									inline: "",
									row,
									value: row[col.key]
								}, null, 8, [
									"name",
									"owner",
									"row",
									"value"
								])) : (openBlock(), createBlock("span", { key: 1 }, toDisplayString(row[col.key] ?? "—"), 1))];
							})
						};
					})]), _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: columns.value,
						rows: rows.value,
						loading: loading.value && !rows.value.length,
						empty: filters.search || filters.role || filters.active !== "" ? unref(__)("Nobody matches these filters.") : unref(__)("Nobody has an account yet.")
					}, createSlots({
						"cell-username": withCtx(({ row }) => [createVNode("div", { class: "k-person" }, [createVNode(unref(KAvatar), {
							user: row,
							size: 28
						}, null, 8, ["user"]), createVNode("div", null, [createVNode(unref(Link), {
							href: `/admin/users/${row.id}`,
							class: "k-link"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.username), 1)]),
							_: 2
						}, 1032, ["href"]), createVNode("div", { class: "k-muted k-small" }, toDisplayString(row.email), 1)])])]),
						"cell-role": withCtx(({ row }) => [createVNode(unref(KBadge), null, {
							default: withCtx(() => [createTextVNode(toDisplayString(roleLabel(row.role)), 1)]),
							_: 2
						}, 1024)]),
						"cell-is_active": withCtx(({ row }) => [createVNode(unref(KStatusBadge), { status: row.is_active ? "good" : "disabled" }, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.is_active ? unref(__)("Active") : unref(__)("Deactivated")), 1)]),
							_: 2
						}, 1032, ["status"])]),
						"cell-last_login_at": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.last_login_at }, null, 8, ["value"])]),
						"cell-created_at": withCtx(({ row }) => [createVNode("span", { class: "k-muted" }, toDisplayString(String(row.created_at ?? "").slice(0, 10) || "—"), 1)]),
						_: 2
					}, [renderList(unref(extColumns), (col) => {
						return {
							name: `cell-${col.key}`,
							fn: withCtx(({ row }) => [col.component ? (openBlock(), createBlock(unref(KExtension), {
								key: 0,
								name: col.component,
								owner: col.owner,
								inline: "",
								row,
								value: row[col.key]
							}, null, 8, [
								"name",
								"owner",
								"row",
								"value"
							])) : (openBlock(), createBlock("span", { key: 1 }, toDisplayString(row[col.key] ?? "—"), 1))])
						};
					})]), 1032, [
						"columns",
						"rows",
						"loading",
						"empty"
					])];
				}),
				_: 2
			}, [cursor.value ? {
				name: "footer",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						loading: loading.value,
						onClick: ($event) => load(true)
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Show more"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Show more")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						size: "sm",
						loading: loading.value,
						onClick: ($event) => load(true)
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Show more")), 1)]),
						_: 1
					}, 8, ["loading", "onClick"])];
				}),
				key: "0"
			} : void 0]), _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: adding.value,
				title: unref(__)("Add a person"),
				onClose: ($event) => adding.value = false
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => adding.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							type: "submit",
							form: "k-add-person",
							loading: saving.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Add person"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Add person")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => adding.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						variant: "primary",
						type: "submit",
						form: "k-add-person",
						loading: saving.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Add person")), 1)]),
						_: 1
					}, 8, ["loading"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-add-person" class="k-form"${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Username"),
							help: unref(__)("Letters, numbers, dashes and underscores. They can sign in with it."),
							error: errors.value.username ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.username,
									"onUpdate:modelValue": ($event) => form.username = $event,
									autocomplete: "off",
									invalid,
									"aria-describedby": describedBy
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.username,
									"onUpdate:modelValue": ($event) => form.username = $event,
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
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Email"),
							help: unref(__)("Password resets and notifications go here."),
							error: errors.value.email ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.email,
									"onUpdate:modelValue": ($event) => form.email = $event,
									type: "email",
									autocomplete: "off",
									invalid,
									"aria-describedby": describedBy
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.email,
									"onUpdate:modelValue": ($event) => form.email = $event,
									type: "email",
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
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Role"),
							error: errors.value.role ?? "",
							required: ""
						}, {
							default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KSelect), {
									id,
									modelValue: form.role,
									"onUpdate:modelValue": ($event) => form.role = $event,
									options: assignable.value,
									invalid
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KSelect), {
									id,
									modelValue: form.role,
									"onUpdate:modelValue": ($event) => form.role = $event,
									options: assignable.value,
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
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Password"),
							help: unref(__)("Share it with them privately; they can change it from their profile."),
							error: errors.value.password ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<div class="k-input-row"${_scopeId}>`);
									_push(ssrRenderComponent(unref(KInput), {
										id,
										modelValue: form.password,
										"onUpdate:modelValue": ($event) => form.password = $event,
										type: "text",
										autocomplete: "new-password",
										spellcheck: "false",
										class: "k-mono-input",
										invalid,
										"aria-describedby": describedBy
									}, null, _parent, _scopeId));
									_push(ssrRenderComponent(unref(KButton), {
										icon: "shuffle",
										onClick: generate
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Generate"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Generate")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(`</div>`);
								} else return [createVNode("div", { class: "k-input-row" }, [createVNode(unref(KInput), {
									id,
									modelValue: form.password,
									"onUpdate:modelValue": ($event) => form.password = $event,
									type: "text",
									autocomplete: "new-password",
									spellcheck: "false",
									class: "k-mono-input",
									invalid,
									"aria-describedby": describedBy
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"invalid",
									"aria-describedby"
								]), createVNode(unref(KButton), {
									icon: "shuffle",
									onClick: generate
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Generate")), 1)]),
									_: 1
								})])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</form>`);
					} else return [createVNode("form", {
						id: "k-add-person",
						class: "k-form",
						onSubmit: withModifiers(create, ["prevent"])
					}, [
						createVNode(unref(KField), {
							label: unref(__)("Username"),
							help: unref(__)("Letters, numbers, dashes and underscores. They can sign in with it."),
							error: errors.value.username ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.username,
								"onUpdate:modelValue": ($event) => form.username = $event,
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
						createVNode(unref(KField), {
							label: unref(__)("Email"),
							help: unref(__)("Password resets and notifications go here."),
							error: errors.value.email ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.email,
								"onUpdate:modelValue": ($event) => form.email = $event,
								type: "email",
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
						createVNode(unref(KField), {
							label: unref(__)("Role"),
							error: errors.value.role ?? "",
							required: ""
						}, {
							default: withCtx(({ id, invalid }) => [createVNode(unref(KSelect), {
								id,
								modelValue: form.role,
								"onUpdate:modelValue": ($event) => form.role = $event,
								options: assignable.value,
								invalid
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"options",
								"invalid"
							])]),
							_: 1
						}, 8, ["label", "error"]),
						createVNode(unref(KField), {
							label: unref(__)("Password"),
							help: unref(__)("Share it with them privately; they can change it from their profile."),
							error: errors.value.password ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode("div", { class: "k-input-row" }, [createVNode(unref(KInput), {
								id,
								modelValue: form.password,
								"onUpdate:modelValue": ($event) => form.password = $event,
								type: "text",
								autocomplete: "new-password",
								spellcheck: "false",
								class: "k-mono-input",
								invalid,
								"aria-describedby": describedBy
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"invalid",
								"aria-describedby"
							]), createVNode(unref(KButton), {
								icon: "shuffle",
								onClick: generate
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Generate")), 1)]),
								_: 1
							})])]),
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
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/users/Index.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
