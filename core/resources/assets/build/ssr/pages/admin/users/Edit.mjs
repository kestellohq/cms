import { computed, createSlots, createTextVNode, createVNode, reactive, ref, toDisplayString, unref, useSSRContext, vModelText, watch, withCtx, withDirectives, withModifiers } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderComponent } from "vue/server-renderer";
import { Head, Link, usePage, useRouter } from "@kestello/app";
import { ApiError, __, confirm, notify, useApi, useCan, useExtensions, useResource, useUser } from "@kestello/sdk";
import { KAlert, KButton, KCard, KField, KInput, KPageHeader, KSelect, KSlot, KSwitch, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/users/Edit.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Edit",
	__ssrInlineRender: true,
	setup(__props) {
		const panels = useExtensions("slots", "users.edit.panels", () => ({ user: person.data.value }));
		const page = usePage();
		const api = useApi();
		const can = useCan();
		const me = useUser();
		const router = useRouter();
		const id = computed(() => Number(page.props.params?.id ?? 0));
		const person = useResource(() => `/users/${id.value}`);
		const roles = useResource("/roles");
		const manage = computed(() => can("users.manage"));
		const self = computed(() => me.value?.id === id.value);
		const form = reactive({
			username: "",
			email: "",
			role: "",
			is_active: true,
			bio: ""
		});
		const errors = ref({});
		const saving = ref(false);
		watch(() => person.data.value, (u) => {
			if (u) Object.assign(form, {
				username: u.username,
				email: u.email,
				role: u.role,
				is_active: u.is_active,
				bio: u.bio ?? ""
			});
		}, { immediate: true });
		const roleInfo = computed(() => (roles.data.value ?? []).find((r) => r.name === form.role) ?? null);
		const roleOptions = computed(() => (roles.data.value ?? []).map((r) => ({
			value: r.name,
			label: r.label,
			disabled: !r.assignable && r.name !== person.data.value?.role
		})));
		const locked = computed(() => !manage.value || person.data.value && roles.data.value && !(roles.data.value.find((r) => r.name === person.data.value.role)?.assignable ?? true));
		const dirty = computed(() => {
			const u = person.data.value;
			return !!u && (form.username !== u.username || form.email !== u.email || form.role !== u.role || form.is_active !== u.is_active || form.bio !== (u.bio ?? ""));
		});
		async function save() {
			const u = person.data.value;
			const changes = {};
			for (const key of [
				"username",
				"email",
				"role",
				"is_active",
				"bio"
			]) if (form[key] !== (key === "bio" ? u.bio ?? "" : u[key])) changes[key] = form[key];
			if (!Object.keys(changes).length) return;
			saving.value = true;
			errors.value = {};
			try {
				await api.patch(`/users/${id.value}`, changes);
				notify.success(changes.is_active === false ? __("{name} was deactivated and signed out everywhere.", { name: form.username }) : __("Changes saved."));
				await person.reload({ silent: true });
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		const password = ref("");
		const passwordError = ref("");
		const settingPassword = ref(false);
		async function setPassword() {
			settingPassword.value = true;
			passwordError.value = "";
			try {
				await api.patch(`/users/${id.value}`, { password: password.value });
				password.value = "";
				notify.success(__("Password changed. {name} was signed out on every device.", { name: form.username }));
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) passwordError.value = e.fieldErrors().password ?? e.message;
				else notify.error(e);
			} finally {
				settingPassword.value = false;
			}
		}
		async function remove() {
			const name = person.data.value?.username ?? "";
			if (!await confirm({
				title: __("Delete {name}?", { name }),
				message: __("Their account, sessions and access tokens are removed. Content they created stays. This cannot be undone; deactivating keeps the account instead."),
				confirmLabel: __("Delete account"),
				danger: true
			})) return;
			try {
				await api.delete(`/users/${id.value}`);
				notify.success(__("{name} was deleted.", { name }));
				router.visit("/admin/users");
			} catch (e) {
				notify.error(e);
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(person).data.value?.username ?? (unref(person).error.value ? unref(__)("Person not found") : unref(__)("Person")) }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(person).data.value?.username ?? (unref(person).error.value ? unref(__)("Person not found") : "…"),
				description: unref(person).data.value?.email ?? ""
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(Link), {
						href: "/admin/users",
						class: "k-link"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("All people"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("All people")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(Link), {
						href: "/admin/users",
						class: "k-link"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("All people")), 1)]),
						_: 1
					})];
				}),
				_: 1
			}, _parent));
			if (unref(person).error.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(person).error.value.message)}`);
					else return [createTextVNode(toDisplayString(unref(person).error.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else if (self.value) _push(ssrRenderComponent(unref(KAlert), { tone: "info" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`${ssrInterpolate(unref(__)("This is your own account. Change your password, email and sign-in security from your profile."))} `);
						_push(ssrRenderComponent(unref(Link), {
							href: "/admin/profile",
							class: "k-link"
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Open your profile"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Open your profile")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createTextVNode(toDisplayString(unref(__)("This is your own account. Change your password, email and sign-in security from your profile.")) + " ", 1), createVNode(unref(Link), {
						href: "/admin/profile",
						class: "k-link"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Open your profile")), 1)]),
						_: 1
					})];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (unref(person).data.value) {
				_push(`<div class="k-cells" style="--k-cols:3;">`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "A1",
					class: "k-span-2",
					title: unref(__)("Details")
				}, createSlots({
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<form id="k-person" class="k-form"${_scopeId}><div class="k-form__row"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Username"),
								error: errors.value.username ?? ""
							}, {
								default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id: fid,
										modelValue: form.username,
										"onUpdate:modelValue": ($event) => form.username = $event,
										autocomplete: "off",
										disabled: locked.value,
										invalid
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id: fid,
										modelValue: form.username,
										"onUpdate:modelValue": ($event) => form.username = $event,
										autocomplete: "off",
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
								label: unref(__)("Email"),
								error: errors.value.email ?? ""
							}, {
								default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id: fid,
										modelValue: form.email,
										"onUpdate:modelValue": ($event) => form.email = $event,
										type: "email",
										autocomplete: "off",
										disabled: locked.value,
										invalid
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id: fid,
										modelValue: form.email,
										"onUpdate:modelValue": ($event) => form.email = $event,
										type: "email",
										autocomplete: "off",
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
							_push(`</div>`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Role"),
								help: roleInfo.value ? roleInfo.value.summary.join(" · ") : "",
								error: errors.value.role ?? ""
							}, {
								default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KSelect), {
										id: fid,
										modelValue: form.role,
										"onUpdate:modelValue": ($event) => form.role = $event,
										options: roleOptions.value,
										disabled: locked.value || self.value,
										invalid
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KSelect), {
										id: fid,
										modelValue: form.role,
										"onUpdate:modelValue": ($event) => form.role = $event,
										options: roleOptions.value,
										disabled: locked.value || self.value,
										invalid
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"options",
										"disabled",
										"invalid"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Note"),
								help: unref(__)("Visible to people who manage accounts."),
								error: errors.value.bio ?? ""
							}, {
								default: withCtx(({ id: fid }, _push, _parent, _scopeId) => {
									if (_push) _push(`<textarea${ssrRenderAttr("id", fid)} class="k-input k-textarea" rows="3" maxlength="2000"${ssrIncludeBooleanAttr(locked.value) ? " disabled" : ""}${_scopeId}>${ssrInterpolate(form.bio)}</textarea>`);
									else return [withDirectives(createVNode("textarea", {
										id: fid,
										"onUpdate:modelValue": ($event) => form.bio = $event,
										class: "k-input k-textarea",
										rows: "3",
										maxlength: "2000",
										disabled: locked.value
									}, null, 8, [
										"id",
										"onUpdate:modelValue",
										"disabled"
									]), [[vModelText, form.bio]])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Can sign in"),
								help: form.is_active ? unref(__)("Turn off to block sign-in without deleting the account.") : unref(__)("Signed out everywhere; sign-in is blocked."),
								error: errors.value.is_active ?? ""
							}, {
								default: withCtx(({ id: fid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KSwitch), {
										id: fid,
										modelValue: form.is_active,
										"onUpdate:modelValue": ($event) => form.is_active = $event,
										label: unref(__)("Can sign in"),
										"hide-label": "",
										disabled: locked.value || self.value
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KSwitch), {
										id: fid,
										modelValue: form.is_active,
										"onUpdate:modelValue": ($event) => form.is_active = $event,
										label: unref(__)("Can sign in"),
										"hide-label": "",
										disabled: locked.value || self.value
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"label",
										"disabled"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</form>`);
						} else return [createVNode("form", {
							id: "k-person",
							class: "k-form",
							onSubmit: withModifiers(save, ["prevent"])
						}, [
							createVNode("div", { class: "k-form__row" }, [createVNode(unref(KField), {
								label: unref(__)("Username"),
								error: errors.value.username ?? ""
							}, {
								default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
									id: fid,
									modelValue: form.username,
									"onUpdate:modelValue": ($event) => form.username = $event,
									autocomplete: "off",
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
								label: unref(__)("Email"),
								error: errors.value.email ?? ""
							}, {
								default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
									id: fid,
									modelValue: form.email,
									"onUpdate:modelValue": ($event) => form.email = $event,
									type: "email",
									autocomplete: "off",
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
							}, 8, ["label", "error"])]),
							createVNode(unref(KField), {
								label: unref(__)("Role"),
								help: roleInfo.value ? roleInfo.value.summary.join(" · ") : "",
								error: errors.value.role ?? ""
							}, {
								default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KSelect), {
									id: fid,
									modelValue: form.role,
									"onUpdate:modelValue": ($event) => form.role = $event,
									options: roleOptions.value,
									disabled: locked.value || self.value,
									invalid
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"options",
									"disabled",
									"invalid"
								])]),
								_: 1
							}, 8, [
								"label",
								"help",
								"error"
							]),
							createVNode(unref(KField), {
								label: unref(__)("Note"),
								help: unref(__)("Visible to people who manage accounts."),
								error: errors.value.bio ?? ""
							}, {
								default: withCtx(({ id: fid }) => [withDirectives(createVNode("textarea", {
									id: fid,
									"onUpdate:modelValue": ($event) => form.bio = $event,
									class: "k-input k-textarea",
									rows: "3",
									maxlength: "2000",
									disabled: locked.value
								}, null, 8, [
									"id",
									"onUpdate:modelValue",
									"disabled"
								]), [[vModelText, form.bio]])]),
								_: 1
							}, 8, [
								"label",
								"help",
								"error"
							]),
							createVNode(unref(KField), {
								label: unref(__)("Can sign in"),
								help: form.is_active ? unref(__)("Turn off to block sign-in without deleting the account.") : unref(__)("Signed out everywhere; sign-in is blocked."),
								error: errors.value.is_active ?? ""
							}, {
								default: withCtx(({ id: fid }) => [createVNode(unref(KSwitch), {
									id: fid,
									modelValue: form.is_active,
									"onUpdate:modelValue": ($event) => form.is_active = $event,
									label: unref(__)("Can sign in"),
									"hide-label": "",
									disabled: locked.value || self.value
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"label",
									"disabled"
								])]),
								_: 1
							}, 8, [
								"label",
								"help",
								"error"
							])
						], 32)];
					}),
					_: 2
				}, [!locked.value ? {
					name: "footer",
					fn: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-form__actions"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								type: "submit",
								form: "k-person",
								variant: "primary",
								disabled: !dirty.value,
								loading: saving.value
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Save changes"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Save changes")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else return [createVNode("div", { class: "k-form__actions" }, [createVNode(unref(KButton), {
							type: "submit",
							form: "k-person",
							variant: "primary",
							disabled: !dirty.value,
							loading: saving.value
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save changes")), 1)]),
							_: 1
						}, 8, ["disabled", "loading"])])];
					}),
					key: "0"
				} : void 0]), _parent));
				_push(ssrRenderComponent(unref(KCard), {
					coord: "C1",
					title: unref(__)("Record")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<dl class="k-facts"${_scopeId}><div${_scopeId}><dt${_scopeId}>${ssrInterpolate(unref(__)("Last signed in"))}</dt><dd${_scopeId}>`);
							_push(ssrRenderComponent(unref(KTime), { value: unref(person).data.value.last_login_at }, null, _parent, _scopeId));
							_push(`</dd></div><div${_scopeId}><dt${_scopeId}>${ssrInterpolate(unref(__)("Joined"))}</dt><dd${_scopeId}>${ssrInterpolate(String(unref(person).data.value.created_at ?? "").slice(0, 10) || "—")}</dd></div><div${_scopeId}><dt${_scopeId}>${ssrInterpolate(unref(__)("Status"))}</dt><dd${_scopeId}>${ssrInterpolate(unref(person).data.value.is_active ? unref(__)("Can sign in") : unref(__)("Deactivated"))}</dd></div></dl>`);
						} else return [createVNode("dl", { class: "k-facts" }, [
							createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("Last signed in")), 1), createVNode("dd", null, [createVNode(unref(KTime), { value: unref(person).data.value.last_login_at }, null, 8, ["value"])])]),
							createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("Joined")), 1), createVNode("dd", null, toDisplayString(String(unref(person).data.value.created_at ?? "").slice(0, 10) || "—"), 1)]),
							createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("Status")), 1), createVNode("dd", null, toDisplayString(unref(person).data.value.is_active ? unref(__)("Can sign in") : unref(__)("Deactivated")), 1)])
						])];
					}),
					_: 1
				}, _parent));
				if (!locked.value && !self.value) _push(ssrRenderComponent(unref(KCard), {
					coord: "A2",
					class: "k-span-2",
					title: unref(__)("Set a new password"),
					description: unref(__)("They are signed out on every device and their access tokens stop working.")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<form class="k-input-row"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("New password"),
								error: passwordError.value,
								class: "k-toolbar__grow"
							}, {
								default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id: fid,
										modelValue: password.value,
										"onUpdate:modelValue": ($event) => password.value = $event,
										type: "password",
										autocomplete: "new-password",
										invalid
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id: fid,
										modelValue: password.value,
										"onUpdate:modelValue": ($event) => password.value = $event,
										type: "password",
										autocomplete: "new-password",
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
							_push(ssrRenderComponent(unref(KButton), {
								type: "submit",
								disabled: !password.value,
								loading: settingPassword.value
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Set password"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Set password")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</form>`);
						} else return [createVNode("form", {
							class: "k-input-row",
							onSubmit: withModifiers(setPassword, ["prevent"])
						}, [createVNode(unref(KField), {
							label: unref(__)("New password"),
							error: passwordError.value,
							class: "k-toolbar__grow"
						}, {
							default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
								id: fid,
								modelValue: password.value,
								"onUpdate:modelValue": ($event) => password.value = $event,
								type: "password",
								autocomplete: "new-password",
								invalid
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"invalid"
							])]),
							_: 1
						}, 8, ["label", "error"]), createVNode(unref(KButton), {
							type: "submit",
							disabled: !password.value,
							loading: settingPassword.value
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Set password")), 1)]),
							_: 1
						}, 8, ["disabled", "loading"])], 32)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				if (!locked.value && !self.value) _push(ssrRenderComponent(unref(KCard), {
					coord: "C2",
					title: unref(__)("Delete account"),
					description: unref(__)("Removes the account for good. To pause access instead, turn off Can sign in.")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								variant: "danger",
								icon: "trash",
								onClick: remove
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Delete {name}", { name: unref(person).data.value.username }))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Delete {name}", { name: unref(person).data.value.username })), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else return [createVNode("div", null, [createVNode(unref(KButton), {
							variant: "danger",
							icon: "trash",
							onClick: remove
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Delete {name}", { name: unref(person).data.value.username })), 1)]),
							_: 1
						})])];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`</div>`);
			} else _push(`<!---->`);
			if (unref(person).data.value && unref(panels).length) {
				_push(`<section class="k-cells" style="--k-cols:3;"${ssrRenderAttr("aria-label", unref(__)("From plugins"))}>`);
				_push(ssrRenderComponent(unref(KSlot), {
					point: "users.edit.panels",
					context: { user: unref(person).data.value }
				}, null, _parent));
				_push(`</section>`);
			} else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/users/Edit.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
