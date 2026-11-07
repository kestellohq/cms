import { t as deviceLabel } from "../../chunks/device-DTfRdqhB.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, openBlock, reactive, ref, renderList, toDisplayString, unref, useSSRContext, vModelText, watch, withCtx, withDirectives, withModifiers } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head } from "@kestello/app";
import { ApiError, __, confirm, notify, useApi, useExtensions, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KEmpty, KExtension, KField, KInput, KModal, KPageHeader, KSelect, KSwitch, KTable, KTabs, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/Profile.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Profile",
	__ssrInlineRender: true,
	setup(__props) {
		/** Your own account: details, sign-in security, tokens, linked sign-ins, notifications. */
		const api = useApi();
		const profileTabs = useExtensions("slots", "profile.tabs");
		const pluginTabs = computed(() => profileTabs.value.map((e) => ({
			id: `x-${e.id.replace(/[^A-Za-z0-9-]/g, "-")}`,
			label: e.label,
			ext: e
		})));
		const pluginTab = computed(() => pluginTabs.value.find((t) => t.id === tab.value) ?? null);
		const TABS = [
			"account",
			"security",
			"tokens",
			"connections",
			"notifications"
		];
		const initial = typeof window !== "undefined" ? new URLSearchParams(window.location.search).get("tab") : null;
		const tab = ref(initial && (TABS.includes(initial) || initial.startsWith("x-")) ? initial : "account");
		watch(tab, (value) => {
			const url = new URL(window.location.href);
			url.searchParams.set("tab", value);
			window.history.replaceState(window.history.state, "", url);
		});
		const me = useResource("/me");
		const ROLE_LABELS = {
			admin: __("Administrator"),
			user: __("User")
		};
		const roleLabel = computed(() => ROLE_LABELS[me.data.value?.role] ?? me.data.value?.role ?? "—");
		const fieldErrors = (e) => e instanceof ApiError && e.status === 422 ? e.fieldErrors() : null;
		const account = reactive({
			email: "",
			bio: ""
		});
		const accountErrors = ref({});
		const savingAccount = ref(false);
		watch(() => me.data.value, (u) => {
			if (u) Object.assign(account, {
				email: u.email,
				bio: u.bio ?? ""
			});
		}, { immediate: true });
		const accountDirty = computed(() => me.data.value && (account.email !== me.data.value.email || account.bio !== (me.data.value.bio ?? "")));
		async function saveAccount() {
			savingAccount.value = true;
			accountErrors.value = {};
			try {
				await api.patch("/me", {
					email: account.email.trim(),
					bio: account.bio
				});
				notify.success(__("Your profile was saved."));
				await me.reload({ silent: true });
			} catch (e) {
				accountErrors.value = fieldErrors(e) ?? {};
				if (!fieldErrors(e)) notify.error(e);
			} finally {
				savingAccount.value = false;
			}
		}
		const pw = reactive({
			current_password: "",
			password: ""
		});
		const pwErrors = ref({});
		const savingPw = ref(false);
		async function changePassword() {
			savingPw.value = true;
			pwErrors.value = {};
			try {
				const { data } = await api.put("/me/password", { ...pw });
				Object.assign(pw, {
					current_password: "",
					password: ""
				});
				notify.success(data.sessions_revoked || data.tokens_revoked ? __("Password changed. {s, plural, one {# other session was signed out} other {# other sessions were signed out}} and {t, plural, one {# token revoked} other {# tokens revoked}}.", {
					s: data.sessions_revoked,
					t: data.tokens_revoked
				}) : __("Password changed."));
				sessions.reload({ silent: true });
			} catch (e) {
				pwErrors.value = fieldErrors(e) ?? {};
				if (!fieldErrors(e)) notify.error(e);
			} finally {
				savingPw.value = false;
			}
		}
		const twoFactor = useResource("/me/2fa");
		const enrolment = ref(null);
		const code = ref("");
		const codeError = ref("");
		const recoveryCodes = ref(null);
		const busy2fa = ref(false);
		const groupedSecret = computed(() => (enrolment.value?.secret ?? "").replace(/(.{4})/g, "$1 ").trim());
		async function beginTwoFactor() {
			busy2fa.value = true;
			try {
				enrolment.value = (await api.post("/me/2fa/totp", {})).data;
				code.value = "";
				codeError.value = "";
			} catch (e) {
				notify.error(e);
			} finally {
				busy2fa.value = false;
			}
		}
		async function confirmTwoFactor() {
			busy2fa.value = true;
			codeError.value = "";
			try {
				const { data } = await api.post("/me/2fa/totp/confirm", { code: code.value.replace(/\s/g, "") });
				enrolment.value = null;
				recoveryCodes.value = data.recovery_codes;
				await Promise.all([twoFactor.reload({ silent: true }), me.reload({ silent: true })]);
			} catch (e) {
				codeError.value = fieldErrors(e)?.code ?? e.message;
			} finally {
				busy2fa.value = false;
			}
		}
		async function disableTwoFactor() {
			if (!await confirm({
				title: __("Turn off two-step sign-in?"),
				message: __("Signing in will need only your password. Your recovery codes stop working."),
				confirmLabel: __("Turn off"),
				danger: true
			})) return;
			try {
				await api.delete("/me/2fa");
				notify.success(__("Two-step sign-in is off."));
				await Promise.all([twoFactor.reload({ silent: true }), me.reload({ silent: true })]);
			} catch (e) {
				notify.error(e);
			}
		}
		async function newRecoveryCodes() {
			if (!await confirm({
				title: __("Make new recovery codes?"),
				message: __("Your current codes stop working."),
				confirmLabel: __("Make new codes")
			})) return;
			try {
				recoveryCodes.value = (await api.post("/me/2fa/recovery-codes", {})).data.recovery_codes;
				await twoFactor.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		function copyCodes() {
			navigator.clipboard?.writeText(recoveryCodes.value.join("\n")).then(() => notify.success(__("Copied.")), () => {});
		}
		const sessions = useResource("/me/sessions");
		const device = (agent) => deviceLabel(agent, __);
		async function revokeSession(row) {
			try {
				await api.delete(`/me/sessions/${row.id}`);
				notify.success(__("That device was signed out."));
				await sessions.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		async function revokeOthers() {
			if (!await confirm({
				title: __("Sign out everywhere else?"),
				message: __("Every other browser and device signed in to your account is signed out. This one stays signed in."),
				confirmLabel: __("Sign out others")
			})) return;
			try {
				await api.delete("/me/sessions");
				notify.success(__("Signed out everywhere else."));
				await sessions.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		const tokens = useResource("/me/tokens");
		const creatingToken = ref(false);
		const tokenForm = reactive({
			name: "",
			scopes: [],
			expires_in_days: "90"
		});
		const tokenErrors = ref({});
		const newToken = ref(null);
		const savingToken = ref(false);
		const EXPIRY = [
			{
				value: "7",
				label: __("7 days")
			},
			{
				value: "30",
				label: __("30 days")
			},
			{
				value: "90",
				label: __("90 days")
			},
			{
				value: "365",
				label: __("One year")
			},
			{
				value: "never",
				label: __("Never")
			}
		];
		function openToken() {
			Object.assign(tokenForm, {
				name: "",
				scopes: [],
				expires_in_days: "90"
			});
			tokenErrors.value = {};
			creatingToken.value = true;
		}
		function toggleScope(name, on) {
			tokenForm.scopes = on ? [...tokenForm.scopes, name] : tokenForm.scopes.filter((s) => s !== name);
		}
		async function createToken() {
			savingToken.value = true;
			tokenErrors.value = {};
			try {
				const { data } = await api.post("/me/tokens", {
					name: tokenForm.name.trim(),
					scopes: tokenForm.scopes,
					expires_in_days: tokenForm.expires_in_days === "never" ? null : Number(tokenForm.expires_in_days)
				});
				creatingToken.value = false;
				newToken.value = data;
				await tokens.reload({ silent: true });
			} catch (e) {
				tokenErrors.value = fieldErrors(e) ?? {};
				if (!fieldErrors(e)) notify.error(e);
			} finally {
				savingToken.value = false;
			}
		}
		async function revokeToken(row) {
			if (!await confirm({
				title: __("Revoke {name}?", { name: row.name }),
				message: __("Anything using this token stops working immediately."),
				confirmLabel: __("Revoke"),
				danger: true
			})) return;
			try {
				await api.delete(`/me/tokens/${row.id}`);
				notify.success(__("Token revoked."));
				await tokens.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		function copyToken() {
			navigator.clipboard?.writeText(newToken.value.token).then(() => notify.success(__("Copied.")), () => {});
		}
		const identities = useResource("/me/identities");
		const hasConnections = computed(() => !!(identities.data.value?.length || identities.meta.value?.available?.length));
		watch(identities.data, () => {
			if (tab.value === "connections" && !hasConnections.value) tab.value = "account";
		});
		async function unlink(row) {
			if (!await confirm({
				title: __("Disconnect {provider}?", { provider: row.label }),
				message: __("You will no longer be able to sign in with {provider}.", { provider: row.label }),
				confirmLabel: __("Disconnect")
			})) return;
			try {
				await api.delete(`/me/identities/${row.id}`);
				notify.success(__("{provider} was disconnected.", { provider: row.label }));
				await identities.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		const prefs = useResource("/me/notification-preferences");
		const channels = computed(() => [...new Set((prefs.data.value ?? []).flatMap((t) => t.channels.map((c) => c.channel)))]);
		const CHANNEL_LABELS = {
			database: __("In the admin"),
			mail: __("Email")
		};
		async function setPref(type, channel, enabled) {
			try {
				await api.put("/me/notification-preferences", { preferences: [{
					type,
					channel,
					enabled
				}] });
				await prefs.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		const codesLeft = (n) => __("{n, plural, one {# recovery code left} other {# recovery codes left}}", { n });
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("Your profile") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(me).data.value?.username ?? unref(__)("Your profile"),
				description: unref(__)("Your account, how you sign in, and what Kestello tells you about.")
			}, null, _parent));
			_push(ssrRenderComponent(unref(KTabs), {
				modelValue: tab.value,
				"onUpdate:modelValue": ($event) => tab.value = $event,
				label: unref(__)("Profile sections"),
				tabs: [
					{
						id: "account",
						label: unref(__)("Account")
					},
					{
						id: "security",
						label: unref(__)("Sign-in & security")
					},
					{
						id: "tokens",
						label: unref(__)("Access tokens"),
						count: unref(tokens).data.value?.length || void 0
					},
					...hasConnections.value ? [{
						id: "connections",
						label: unref(__)("Connected sign-ins")
					}] : [],
					{
						id: "notifications",
						label: unref(__)("Notifications")
					},
					...pluginTabs.value.map(({ id, label }) => ({
						id,
						label
					}))
				]
			}, null, _parent));
			_push(`<!-- account -->`);
			if (tab.value === "account") {
				_push(`<div class="k-cells" style="--k-cols:3;">`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "A1",
					class: "k-span-2",
					title: unref(__)("Details")
				}, {
					footer: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-form__actions"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								type: "submit",
								form: "k-account",
								variant: "primary",
								disabled: !accountDirty.value,
								loading: savingAccount.value
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Save profile"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Save profile")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else return [createVNode("div", { class: "k-form__actions" }, [createVNode(unref(KButton), {
							type: "submit",
							form: "k-account",
							variant: "primary",
							disabled: !accountDirty.value,
							loading: savingAccount.value
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save profile")), 1)]),
							_: 1
						}, 8, ["disabled", "loading"])])];
					}),
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<form id="k-account" class="k-form"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Username"),
								help: unref(__)("You sign in with it. Ask an administrator to change it.")
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id,
										"model-value": unref(me).data.value?.username ?? "",
										disabled: ""
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id,
										"model-value": unref(me).data.value?.username ?? "",
										disabled: ""
									}, null, 8, ["id", "model-value"])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Email"),
								help: unref(__)("Password resets go here. Changing it asks for your password."),
								error: accountErrors.value.email ?? ""
							}, {
								default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id,
										modelValue: account.email,
										"onUpdate:modelValue": ($event) => account.email = $event,
										type: "email",
										autocomplete: "email",
										invalid
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id,
										modelValue: account.email,
										"onUpdate:modelValue": ($event) => account.email = $event,
										type: "email",
										autocomplete: "email",
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
								label: unref(__)("About you"),
								help: unref(__)("Optional. Themes may show it next to what you publish."),
								error: accountErrors.value.bio ?? ""
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(`<textarea${ssrRenderAttr("id", id)} class="k-input k-textarea" rows="3" maxlength="2000"${_scopeId}>${ssrInterpolate(account.bio)}</textarea>`);
									else return [withDirectives(createVNode("textarea", {
										id,
										"onUpdate:modelValue": ($event) => account.bio = $event,
										class: "k-input k-textarea",
										rows: "3",
										maxlength: "2000"
									}, null, 8, ["id", "onUpdate:modelValue"]), [[vModelText, account.bio]])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</form>`);
						} else return [createVNode("form", {
							id: "k-account",
							class: "k-form",
							onSubmit: withModifiers(saveAccount, ["prevent"])
						}, [
							createVNode(unref(KField), {
								label: unref(__)("Username"),
								help: unref(__)("You sign in with it. Ask an administrator to change it.")
							}, {
								default: withCtx(({ id }) => [createVNode(unref(KInput), {
									id,
									"model-value": unref(me).data.value?.username ?? "",
									disabled: ""
								}, null, 8, ["id", "model-value"])]),
								_: 1
							}, 8, ["label", "help"]),
							createVNode(unref(KField), {
								label: unref(__)("Email"),
								help: unref(__)("Password resets go here. Changing it asks for your password."),
								error: accountErrors.value.email ?? ""
							}, {
								default: withCtx(({ id, invalid }) => [createVNode(unref(KInput), {
									id,
									modelValue: account.email,
									"onUpdate:modelValue": ($event) => account.email = $event,
									type: "email",
									autocomplete: "email",
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
								label: unref(__)("About you"),
								help: unref(__)("Optional. Themes may show it next to what you publish."),
								error: accountErrors.value.bio ?? ""
							}, {
								default: withCtx(({ id }) => [withDirectives(createVNode("textarea", {
									id,
									"onUpdate:modelValue": ($event) => account.bio = $event,
									class: "k-input k-textarea",
									rows: "3",
									maxlength: "2000"
								}, null, 8, ["id", "onUpdate:modelValue"]), [[vModelText, account.bio]])]),
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
				_push(ssrRenderComponent(unref(KCard), {
					coord: "C1",
					title: unref(__)("Your access")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<dl class="k-facts"${_scopeId}><div${_scopeId}><dt${_scopeId}>${ssrInterpolate(unref(__)("Role"))}</dt><dd${_scopeId}>${ssrInterpolate(roleLabel.value)}</dd></div><div${_scopeId}><dt${_scopeId}>${ssrInterpolate(unref(__)("Two-step sign-in"))}</dt><dd${_scopeId}>${ssrInterpolate(unref(me).data.value?.auth.two_factor.enabled ? unref(__)("On") : unref(__)("Off"))}</dd></div><div${_scopeId}><dt${_scopeId}>${ssrInterpolate(unref(__)("Last signed in"))}</dt><dd${_scopeId}>`);
							_push(ssrRenderComponent(unref(KTime), { value: unref(me).data.value?.last_login_at ?? null }, null, _parent, _scopeId));
							_push(`</dd></div></dl>`);
						} else return [createVNode("dl", { class: "k-facts" }, [
							createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("Role")), 1), createVNode("dd", null, toDisplayString(roleLabel.value), 1)]),
							createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("Two-step sign-in")), 1), createVNode("dd", null, toDisplayString(unref(me).data.value?.auth.two_factor.enabled ? unref(__)("On") : unref(__)("Off")), 1)]),
							createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("Last signed in")), 1), createVNode("dd", null, [createVNode(unref(KTime), { value: unref(me).data.value?.last_login_at ?? null }, null, 8, ["value"])])])
						])];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
			} else if (tab.value === "security") {
				_push(`<!--[--><!-- security --><div class="k-cells" style="--k-cols:3;">`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "A1",
					class: "k-span-2",
					title: unref(__)("Two-step sign-in"),
					description: unref(__)("After your password, enter a code from an authenticator app. A stolen password alone is then not enough.")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							if (unref(me).data.value?.auth.two_factor.expected && !unref(twoFactor).data.value?.enabled) _push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Your role is expected to use two-step sign-in. Turn it on now."))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Your role is expected to use two-step sign-in. Turn it on now.")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							if (unref(twoFactor).data.value?.enabled) {
								_push(`<!--[--><p class="k-status-row"${_scopeId}>`);
								_push(ssrRenderComponent(unref(KBadge), {
									tone: "success",
									dot: ""
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("On"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("On")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`<span class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("Since"))} `);
								_push(ssrRenderComponent(unref(KTime), { value: unref(twoFactor).data.value.enabled_at }, null, _parent, _scopeId));
								_push(` · ${ssrInterpolate(codesLeft(unref(twoFactor).data.value.recovery_codes_remaining))}</span></p><div class="k-button-row"${_scopeId}>`);
								_push(ssrRenderComponent(unref(KButton), {
									icon: "key",
									onClick: newRecoveryCodes
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("New recovery codes"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("New recovery codes")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KButton), {
									variant: "danger",
									onClick: disableTwoFactor
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Turn off"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Turn off")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div><!--]-->`);
							} else if (enrolment.value) {
								_push(`<!--[--><ol class="k-steps"${_scopeId}><li${_scopeId}>${ssrInterpolate(unref(__)("Open your authenticator app (1Password, Google Authenticator, Authy…) and add an account."))}</li><li${_scopeId}>${ssrInterpolate(unref(__)("Enter this setup key, or on a phone tap Open in app:"))} <div class="k-secret"${_scopeId}><code${_scopeId}>${ssrInterpolate(groupedSecret.value)}</code> <a${ssrRenderAttr("href", enrolment.value.otpauth_uri)} class="k-link"${_scopeId}>${ssrInterpolate(unref(__)("Open in app"))}</a></div></li><li${_scopeId}>${ssrInterpolate(unref(__)("Type the six-digit code the app shows."))}</li></ol><form class="k-input-row"${_scopeId}>`);
								_push(ssrRenderComponent(unref(KField), {
									label: unref(__)("Code"),
									error: codeError.value
								}, {
									default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KInput), {
											id,
											modelValue: code.value,
											"onUpdate:modelValue": ($event) => code.value = $event,
											inputmode: "numeric",
											autocomplete: "one-time-code",
											maxlength: "7",
											class: "k-code-input",
											invalid
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KInput), {
											id,
											modelValue: code.value,
											"onUpdate:modelValue": ($event) => code.value = $event,
											inputmode: "numeric",
											autocomplete: "one-time-code",
											maxlength: "7",
											class: "k-code-input",
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
									variant: "primary",
									disabled: code.value.replace(/\s/g, "").length < 6,
									loading: busy2fa.value
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Turn on"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Turn on")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KButton), {
									variant: "ghost",
									onClick: ($event) => enrolment.value = null
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</form><!--]-->`);
							} else if (unref(twoFactor).data.value) {
								_push(`<div${_scopeId}>`);
								_push(ssrRenderComponent(unref(KButton), {
									variant: "primary",
									icon: "shield-check",
									loading: busy2fa.value,
									onClick: beginTwoFactor
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Set up two-step sign-in"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Set up two-step sign-in")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div>`);
							} else _push(`<!---->`);
						} else return [unref(me).data.value?.auth.two_factor.expected && !unref(twoFactor).data.value?.enabled ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "warning"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Your role is expected to use two-step sign-in. Turn it on now.")), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true), unref(twoFactor).data.value?.enabled ? (openBlock(), createBlock(Fragment, { key: 1 }, [createVNode("p", { class: "k-status-row" }, [createVNode(unref(KBadge), {
							tone: "success",
							dot: ""
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("On")), 1)]),
							_: 1
						}), createVNode("span", { class: "k-muted" }, [
							createTextVNode(toDisplayString(unref(__)("Since")) + " ", 1),
							createVNode(unref(KTime), { value: unref(twoFactor).data.value.enabled_at }, null, 8, ["value"]),
							createTextVNode(" · " + toDisplayString(codesLeft(unref(twoFactor).data.value.recovery_codes_remaining)), 1)
						])]), createVNode("div", { class: "k-button-row" }, [createVNode(unref(KButton), {
							icon: "key",
							onClick: newRecoveryCodes
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New recovery codes")), 1)]),
							_: 1
						}), createVNode(unref(KButton), {
							variant: "danger",
							onClick: disableTwoFactor
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Turn off")), 1)]),
							_: 1
						})])], 64)) : enrolment.value ? (openBlock(), createBlock(Fragment, { key: 2 }, [createVNode("ol", { class: "k-steps" }, [
							createVNode("li", null, toDisplayString(unref(__)("Open your authenticator app (1Password, Google Authenticator, Authy…) and add an account.")), 1),
							createVNode("li", null, [createTextVNode(toDisplayString(unref(__)("Enter this setup key, or on a phone tap Open in app:")) + " ", 1), createVNode("div", { class: "k-secret" }, [
								createVNode("code", null, toDisplayString(groupedSecret.value), 1),
								createTextVNode(),
								createVNode("a", {
									href: enrolment.value.otpauth_uri,
									class: "k-link"
								}, toDisplayString(unref(__)("Open in app")), 9, ["href"])
							])]),
							createVNode("li", null, toDisplayString(unref(__)("Type the six-digit code the app shows.")), 1)
						]), createVNode("form", {
							class: "k-input-row",
							onSubmit: withModifiers(confirmTwoFactor, ["prevent"])
						}, [
							createVNode(unref(KField), {
								label: unref(__)("Code"),
								error: codeError.value
							}, {
								default: withCtx(({ id, invalid }) => [createVNode(unref(KInput), {
									id,
									modelValue: code.value,
									"onUpdate:modelValue": ($event) => code.value = $event,
									inputmode: "numeric",
									autocomplete: "one-time-code",
									maxlength: "7",
									class: "k-code-input",
									invalid
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"invalid"
								])]),
								_: 1
							}, 8, ["label", "error"]),
							createVNode(unref(KButton), {
								type: "submit",
								variant: "primary",
								disabled: code.value.replace(/\s/g, "").length < 6,
								loading: busy2fa.value
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Turn on")), 1)]),
								_: 1
							}, 8, ["disabled", "loading"]),
							createVNode(unref(KButton), {
								variant: "ghost",
								onClick: ($event) => enrolment.value = null
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
								_: 1
							}, 8, ["onClick"])
						], 32)], 64)) : unref(twoFactor).data.value ? (openBlock(), createBlock("div", { key: 3 }, [createVNode(unref(KButton), {
							variant: "primary",
							icon: "shield-check",
							loading: busy2fa.value,
							onClick: beginTwoFactor
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Set up two-step sign-in")), 1)]),
							_: 1
						}, 8, ["loading"])])) : createCommentVNode("v-if", true)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KCard), {
					coord: "C1",
					title: unref(__)("Password")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<form id="k-password" class="k-form"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Current password"),
								error: pwErrors.value.current_password ?? ""
							}, {
								default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id,
										modelValue: pw.current_password,
										"onUpdate:modelValue": ($event) => pw.current_password = $event,
										type: "password",
										autocomplete: "current-password",
										invalid
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id,
										modelValue: pw.current_password,
										"onUpdate:modelValue": ($event) => pw.current_password = $event,
										type: "password",
										autocomplete: "current-password",
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
								label: unref(__)("New password"),
								help: unref(__)("Long beats clever: three or four unrelated words work well."),
								error: pwErrors.value.password ?? ""
							}, {
								default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id,
										modelValue: pw.password,
										"onUpdate:modelValue": ($event) => pw.password = $event,
										type: "password",
										autocomplete: "new-password",
										invalid
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id,
										modelValue: pw.password,
										"onUpdate:modelValue": ($event) => pw.password = $event,
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
							_push(`<div${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								type: "submit",
								disabled: !pw.current_password || !pw.password,
								loading: savingPw.value
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Change password"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Change password")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div></form>`);
						} else return [createVNode("form", {
							id: "k-password",
							class: "k-form",
							onSubmit: withModifiers(changePassword, ["prevent"])
						}, [
							createVNode(unref(KField), {
								label: unref(__)("Current password"),
								error: pwErrors.value.current_password ?? ""
							}, {
								default: withCtx(({ id, invalid }) => [createVNode(unref(KInput), {
									id,
									modelValue: pw.current_password,
									"onUpdate:modelValue": ($event) => pw.current_password = $event,
									type: "password",
									autocomplete: "current-password",
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
								label: unref(__)("New password"),
								help: unref(__)("Long beats clever: three or four unrelated words work well."),
								error: pwErrors.value.password ?? ""
							}, {
								default: withCtx(({ id, invalid }) => [createVNode(unref(KInput), {
									id,
									modelValue: pw.password,
									"onUpdate:modelValue": ($event) => pw.password = $event,
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
							}, 8, [
								"label",
								"help",
								"error"
							]),
							createVNode("div", null, [createVNode(unref(KButton), {
								type: "submit",
								disabled: !pw.current_password || !pw.password,
								loading: savingPw.value
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Change password")), 1)]),
								_: 1
							}, 8, ["disabled", "loading"])])
						], 32)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KCard), {
					coord: "A2",
					class: "k-span-all",
					title: unref(__)("Where you are signed in"),
					flush: ""
				}, createSlots({
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							if (unref(sessions).meta.value?.supported !== false) _push(ssrRenderComponent(unref(KTable), {
								columns: [
									{
										key: "device",
										label: unref(__)("Device")
									},
									{
										key: "ip",
										label: unref(__)("Network address")
									},
									{
										key: "last_active_at",
										label: unref(__)("Last active")
									},
									{
										key: "actions",
										label: "",
										align: "end",
										width: "1%"
									}
								],
								rows: unref(sessions).data.value ?? [],
								loading: unref(sessions).loading.value,
								empty: unref(__)("No sessions.")
							}, {
								"cell-device": withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) {
										_push(`<strong${_scopeId}>${ssrInterpolate(device(row.user_agent))}</strong> `);
										if (row.current) _push(ssrRenderComponent(unref(KBadge), { tone: "accent" }, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("This device"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("This device")), 1)];
											}),
											_: 2
										}, _parent, _scopeId));
										else _push(`<!---->`);
									} else return [
										createVNode("strong", null, toDisplayString(device(row.user_agent)), 1),
										createTextVNode(),
										row.current ? (openBlock(), createBlock(unref(KBadge), {
											key: 0,
											tone: "accent"
										}, {
											default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("This device")), 1)]),
											_: 1
										})) : createCommentVNode("v-if", true)
									];
								}),
								"cell-ip": withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) _push(`<code${_scopeId}>${ssrInterpolate(row.ip || "—")}</code>`);
									else return [createVNode("code", null, toDisplayString(row.ip || "—"), 1)];
								}),
								"cell-last_active_at": withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.last_active_at }, null, _parent, _scopeId));
									else return [createVNode(unref(KTime), { value: row.last_active_at }, null, 8, ["value"])];
								}),
								"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) {
										if (!row.current) _push(ssrRenderComponent(unref(KButton), {
											size: "sm",
											variant: "ghost",
											onClick: ($event) => revokeSession(row)
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Sign out"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Sign out")), 1)];
											}),
											_: 2
										}, _parent, _scopeId));
										else _push(`<span class="k-sr-only"${_scopeId}>${ssrInterpolate(unref(__)("Current session"))}</span>`);
									} else return [!row.current ? (openBlock(), createBlock(unref(KButton), {
										key: 0,
										size: "sm",
										variant: "ghost",
										onClick: ($event) => revokeSession(row)
									}, {
										default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Sign out")), 1)]),
										_: 1
									}, 8, ["onClick"])) : (openBlock(), createBlock("span", {
										key: 1,
										class: "k-sr-only"
									}, toDisplayString(unref(__)("Current session")), 1))];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<div class="k-card__body"${_scopeId}><p class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("This server keeps sessions in files, which cannot be listed. Switch sessions to the database or Redis under Infrastructure to see your devices here."))}</p></div>`);
						} else return [unref(sessions).meta.value?.supported !== false ? (openBlock(), createBlock(unref(KTable), {
							key: 0,
							columns: [
								{
									key: "device",
									label: unref(__)("Device")
								},
								{
									key: "ip",
									label: unref(__)("Network address")
								},
								{
									key: "last_active_at",
									label: unref(__)("Last active")
								},
								{
									key: "actions",
									label: "",
									align: "end",
									width: "1%"
								}
							],
							rows: unref(sessions).data.value ?? [],
							loading: unref(sessions).loading.value,
							empty: unref(__)("No sessions.")
						}, {
							"cell-device": withCtx(({ row }) => [
								createVNode("strong", null, toDisplayString(device(row.user_agent)), 1),
								createTextVNode(),
								row.current ? (openBlock(), createBlock(unref(KBadge), {
									key: 0,
									tone: "accent"
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("This device")), 1)]),
									_: 1
								})) : createCommentVNode("v-if", true)
							]),
							"cell-ip": withCtx(({ row }) => [createVNode("code", null, toDisplayString(row.ip || "—"), 1)]),
							"cell-last_active_at": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.last_active_at }, null, 8, ["value"])]),
							"cell-actions": withCtx(({ row }) => [!row.current ? (openBlock(), createBlock(unref(KButton), {
								key: 0,
								size: "sm",
								variant: "ghost",
								onClick: ($event) => revokeSession(row)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Sign out")), 1)]),
								_: 1
							}, 8, ["onClick"])) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-sr-only"
							}, toDisplayString(unref(__)("Current session")), 1))]),
							_: 1
						}, 8, [
							"columns",
							"rows",
							"loading",
							"empty"
						])) : (openBlock(), createBlock("div", {
							key: 1,
							class: "k-card__body"
						}, [createVNode("p", { class: "k-muted" }, toDisplayString(unref(__)("This server keeps sessions in files, which cannot be listed. Switch sessions to the database or Redis under Infrastructure to see your devices here.")), 1)]))];
					}),
					_: 2
				}, [(unref(sessions).data.value?.length ?? 0) > 1 ? {
					name: "actions",
					fn: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							onClick: revokeOthers
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Sign out everywhere else"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Sign out everywhere else")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KButton), {
							size: "sm",
							onClick: revokeOthers
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Sign out everywhere else")), 1)]),
							_: 1
						})];
					}),
					key: "0"
				} : void 0]), _parent));
				_push(`</div><!--]-->`);
			} else if (tab.value === "tokens") {
				_push(`<!--[--><!-- tokens --><div class="k-cells" style="--k-cols:1;">`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "A1",
					title: unref(__)("Access tokens"),
					description: unref(__)("Let scripts and other apps use the API as you, limited to the permissions you pick. Treat a token like a password."),
					flush: ""
				}, {
					actions: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "primary",
							icon: "plus",
							onClick: openToken
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Create token"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Create token")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KButton), {
							size: "sm",
							variant: "primary",
							icon: "plus",
							onClick: openToken
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Create token")), 1)]),
							_: 1
						})];
					}),
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: [
								{
									key: "name",
									label: unref(__)("Name")
								},
								{
									key: "scopes",
									label: unref(__)("Permissions")
								},
								{
									key: "last_used_at",
									label: unref(__)("Last used")
								},
								{
									key: "expires_at",
									label: unref(__)("Expires")
								},
								{
									key: "actions",
									label: "",
									align: "end",
									width: "1%"
								}
							],
							rows: unref(tokens).data.value ?? [],
							loading: unref(tokens).loading.value,
							empty: unref(__)("You have no access tokens.")
						}, {
							"cell-name": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`<strong${_scopeId}>${ssrInterpolate(row.name)}</strong><div class="k-muted k-small"${_scopeId}>${ssrInterpolate(unref(__)("Created"))} ${ssrInterpolate(String(row.created_at ?? "").slice(0, 10))}</div>`);
								else return [createVNode("strong", null, toDisplayString(row.name), 1), createVNode("div", { class: "k-muted k-small" }, toDisplayString(unref(__)("Created")) + " " + toDisplayString(String(row.created_at ?? "").slice(0, 10)), 1)];
							}),
							"cell-scopes": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<span class="k-chips"${_scopeId}><!--[-->`);
									ssrRenderList(row.scopes, (s) => {
										_push(`<code${_scopeId}>${ssrInterpolate(s)}</code>`);
									});
									_push(`<!--]--></span>`);
								} else return [createVNode("span", { class: "k-chips" }, [(openBlock(true), createBlock(Fragment, null, renderList(row.scopes, (s) => {
									return openBlock(), createBlock("code", { key: s }, toDisplayString(s), 1);
								}), 128))])];
							}),
							"cell-last_used_at": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.last_used_at }, null, _parent, _scopeId));
								else return [createVNode(unref(KTime), { value: row.last_used_at }, null, 8, ["value"])];
							}),
							"cell-expires_at": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (row.expires_at) _push(`<span${_scopeId}>${ssrInterpolate(String(row.expires_at).slice(0, 10))}</span>`);
									else _push(`<span class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("Never"))}</span>`);
								} else return [row.expires_at ? (openBlock(), createBlock("span", { key: 0 }, toDisplayString(String(row.expires_at).slice(0, 10)), 1)) : (openBlock(), createBlock("span", {
									key: 1,
									class: "k-muted"
								}, toDisplayString(unref(__)("Never")), 1))];
							}),
							"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "ghost",
									onClick: ($event) => revokeToken(row)
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Revoke"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Revoke")), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else return [createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									onClick: ($event) => revokeToken(row)
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Revoke")), 1)]),
									_: 1
								}, 8, ["onClick"])];
							}),
							_: 1
						}, _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: [
								{
									key: "name",
									label: unref(__)("Name")
								},
								{
									key: "scopes",
									label: unref(__)("Permissions")
								},
								{
									key: "last_used_at",
									label: unref(__)("Last used")
								},
								{
									key: "expires_at",
									label: unref(__)("Expires")
								},
								{
									key: "actions",
									label: "",
									align: "end",
									width: "1%"
								}
							],
							rows: unref(tokens).data.value ?? [],
							loading: unref(tokens).loading.value,
							empty: unref(__)("You have no access tokens.")
						}, {
							"cell-name": withCtx(({ row }) => [createVNode("strong", null, toDisplayString(row.name), 1), createVNode("div", { class: "k-muted k-small" }, toDisplayString(unref(__)("Created")) + " " + toDisplayString(String(row.created_at ?? "").slice(0, 10)), 1)]),
							"cell-scopes": withCtx(({ row }) => [createVNode("span", { class: "k-chips" }, [(openBlock(true), createBlock(Fragment, null, renderList(row.scopes, (s) => {
								return openBlock(), createBlock("code", { key: s }, toDisplayString(s), 1);
							}), 128))])]),
							"cell-last_used_at": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.last_used_at }, null, 8, ["value"])]),
							"cell-expires_at": withCtx(({ row }) => [row.expires_at ? (openBlock(), createBlock("span", { key: 0 }, toDisplayString(String(row.expires_at).slice(0, 10)), 1)) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-muted"
							}, toDisplayString(unref(__)("Never")), 1))]),
							"cell-actions": withCtx(({ row }) => [createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								onClick: ($event) => revokeToken(row)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Revoke")), 1)]),
								_: 1
							}, 8, ["onClick"])]),
							_: 1
						}, 8, [
							"columns",
							"rows",
							"loading",
							"empty"
						])];
					}),
					_: 1
				}, _parent));
				_push(`</div><!--]-->`);
			} else if (tab.value === "connections") {
				_push(`<!--[--><!-- connected sign-ins --><div class="k-cells" style="--k-cols:1;">`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "A1",
					title: unref(__)("Connected sign-ins"),
					description: unref(__)("Other accounts you can sign in with instead of your password.")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							if (unref(identities).data.value?.length) {
								_push(`<ul class="k-list"${_scopeId}><!--[-->`);
								ssrRenderList(unref(identities).data.value, (row) => {
									_push(`<li class="k-list__item"${_scopeId}><div class="k-list__main"${_scopeId}><strong${_scopeId}>${ssrInterpolate(row.label)}</strong><p class="k-muted"${_scopeId}>${ssrInterpolate(row.email ?? "")} · ${ssrInterpolate(unref(__)("last used"))} `);
									_push(ssrRenderComponent(unref(KTime), { value: row.last_used_at }, null, _parent, _scopeId));
									_push(`</p></div>`);
									_push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										disabled: unref(identities).data.value.length === 1 && !unref(identities).meta.value?.has_password,
										title: unref(identities).data.value.length === 1 && !unref(identities).meta.value?.has_password ? unref(__)("Set a password first, or you could not sign in.") : "",
										onClick: ($event) => unlink(row)
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Disconnect"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Disconnect")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(`</li>`);
								});
								_push(`<!--]--></ul>`);
							} else if (unref(identities).data.value) _push(ssrRenderComponent(unref(KEmpty), {
								icon: "link",
								title: unref(__)("No connected sign-ins.")
							}, null, _parent, _scopeId));
							else _push(`<!---->`);
							if (unref(identities).meta.value?.available?.length) {
								_push(`<div class="k-button-row"${_scopeId}><!--[-->`);
								ssrRenderList(unref(identities).meta.value.available, (p) => {
									_push(ssrRenderComponent(unref(KButton), {
										key: p.id,
										href: p.connect_url,
										icon: "link"
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Connect {provider}", { provider: p.label }))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Connect {provider}", { provider: p.label })), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
								});
								_push(`<!--]--></div>`);
							} else _push(`<!---->`);
						} else return [unref(identities).data.value?.length ? (openBlock(), createBlock("ul", {
							key: 0,
							class: "k-list"
						}, [(openBlock(true), createBlock(Fragment, null, renderList(unref(identities).data.value, (row) => {
							return openBlock(), createBlock("li", {
								key: row.id,
								class: "k-list__item"
							}, [createVNode("div", { class: "k-list__main" }, [createVNode("strong", null, toDisplayString(row.label), 1), createVNode("p", { class: "k-muted" }, [createTextVNode(toDisplayString(row.email ?? "") + " · " + toDisplayString(unref(__)("last used")) + " ", 1), createVNode(unref(KTime), { value: row.last_used_at }, null, 8, ["value"])])]), createVNode(unref(KButton), {
								size: "sm",
								disabled: unref(identities).data.value.length === 1 && !unref(identities).meta.value?.has_password,
								title: unref(identities).data.value.length === 1 && !unref(identities).meta.value?.has_password ? unref(__)("Set a password first, or you could not sign in.") : "",
								onClick: ($event) => unlink(row)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Disconnect")), 1)]),
								_: 1
							}, 8, [
								"disabled",
								"title",
								"onClick"
							])]);
						}), 128))])) : unref(identities).data.value ? (openBlock(), createBlock(unref(KEmpty), {
							key: 1,
							icon: "link",
							title: unref(__)("No connected sign-ins.")
						}, null, 8, ["title"])) : createCommentVNode("v-if", true), unref(identities).meta.value?.available?.length ? (openBlock(), createBlock("div", {
							key: 2,
							class: "k-button-row"
						}, [(openBlock(true), createBlock(Fragment, null, renderList(unref(identities).meta.value.available, (p) => {
							return openBlock(), createBlock(unref(KButton), {
								key: p.id,
								href: p.connect_url,
								icon: "link"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Connect {provider}", { provider: p.label })), 1)]),
								_: 2
							}, 1032, ["href"]);
						}), 128))])) : createCommentVNode("v-if", true)];
					}),
					_: 1
				}, _parent));
				_push(`</div><!--]-->`);
			} else if (tab.value === "notifications") {
				_push(`<!--[--><!-- notifications --><div class="k-cells" style="--k-cols:1;">`);
				_push(ssrRenderComponent(unref(KCard), {
					coord: "A1",
					title: unref(__)("What Kestello tells you about"),
					description: unref(__)("Choose where each kind of message reaches you."),
					flush: ""
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: [{
								key: "label",
								label: unref(__)("Message")
							}, ...channels.value.map((c) => ({
								key: `ch-${c}`,
								label: CHANNEL_LABELS[c] ?? c,
								align: "end",
								width: "1%"
							}))],
							rows: unref(prefs).data.value ?? [],
							"row-key": "type",
							loading: unref(prefs).loading.value,
							empty: unref(__)("There are no kinds of notification yet.")
						}, createSlots({
							"cell-label": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<strong${_scopeId}>${ssrInterpolate(row.label)}</strong>`);
									if (row.description) _push(`<div class="k-muted k-small"${_scopeId}>${ssrInterpolate(row.description)}</div>`);
									else _push(`<!---->`);
								} else return [createVNode("strong", null, toDisplayString(row.label), 1), row.description ? (openBlock(), createBlock("div", {
									key: 0,
									class: "k-muted k-small"
								}, toDisplayString(row.description), 1)) : createCommentVNode("v-if", true)];
							}),
							_: 2
						}, [renderList(channels.value, (c) => {
							return {
								name: `cell-ch-${c}`,
								fn: withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KSwitch), {
										"model-value": row.channels.find((x) => x.channel === c)?.enabled ?? false,
										label: `${row.label}: ${CHANNEL_LABELS[c] ?? c}`,
										"hide-label": "",
										"onUpdate:modelValue": ($event) => setPref(row.type, c, $event)
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KSwitch), {
										"model-value": row.channels.find((x) => x.channel === c)?.enabled ?? false,
										label: `${row.label}: ${CHANNEL_LABELS[c] ?? c}`,
										"hide-label": "",
										"onUpdate:modelValue": ($event) => setPref(row.type, c, $event)
									}, null, 8, [
										"model-value",
										"label",
										"onUpdate:modelValue"
									])];
								})
							};
						})]), _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: [{
								key: "label",
								label: unref(__)("Message")
							}, ...channels.value.map((c) => ({
								key: `ch-${c}`,
								label: CHANNEL_LABELS[c] ?? c,
								align: "end",
								width: "1%"
							}))],
							rows: unref(prefs).data.value ?? [],
							"row-key": "type",
							loading: unref(prefs).loading.value,
							empty: unref(__)("There are no kinds of notification yet.")
						}, createSlots({
							"cell-label": withCtx(({ row }) => [createVNode("strong", null, toDisplayString(row.label), 1), row.description ? (openBlock(), createBlock("div", {
								key: 0,
								class: "k-muted k-small"
							}, toDisplayString(row.description), 1)) : createCommentVNode("v-if", true)]),
							_: 2
						}, [renderList(channels.value, (c) => {
							return {
								name: `cell-ch-${c}`,
								fn: withCtx(({ row }) => [createVNode(unref(KSwitch), {
									"model-value": row.channels.find((x) => x.channel === c)?.enabled ?? false,
									label: `${row.label}: ${CHANNEL_LABELS[c] ?? c}`,
									"hide-label": "",
									"onUpdate:modelValue": ($event) => setPref(row.type, c, $event)
								}, null, 8, [
									"model-value",
									"label",
									"onUpdate:modelValue"
								])])
							};
						})]), 1032, [
							"columns",
							"rows",
							"loading",
							"empty"
						])];
					}),
					_: 1
				}, _parent));
				_push(`</div><!--]-->`);
			} else if (pluginTab.value && unref(me).data.value) {
				_push(`<!--[--><!-- plugin tabs -->`);
				_push(ssrRenderComponent(unref(KExtension), {
					key: pluginTab.value.id,
					name: pluginTab.value.ext.component,
					owner: pluginTab.value.ext.owner,
					context: { user: unref(me).data.value }
				}, null, _parent));
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(`<!-- create token -->`);
			_push(ssrRenderComponent(unref(KModal), {
				open: creatingToken.value,
				title: unref(__)("Create an access token"),
				size: "lg",
				onClose: ($event) => creatingToken.value = false
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => creatingToken.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							form: "k-token",
							variant: "primary",
							disabled: !tokenForm.name.trim() || !tokenForm.scopes.length,
							loading: savingToken.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Create token"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Create token")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => creatingToken.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						type: "submit",
						form: "k-token",
						variant: "primary",
						disabled: !tokenForm.name.trim() || !tokenForm.scopes.length,
						loading: savingToken.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Create token")), 1)]),
						_: 1
					}, 8, ["disabled", "loading"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-token" class="k-form"${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Name"),
							help: unref(__)("What will use it, so you recognise it later."),
							error: tokenErrors.value.name ?? "",
							required: ""
						}, {
							default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: tokenForm.name,
									"onUpdate:modelValue": ($event) => tokenForm.name = $event,
									autocomplete: "off",
									invalid
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: tokenForm.name,
									"onUpdate:modelValue": ($event) => tokenForm.name = $event,
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
						_push(ssrRenderComponent(unref(KField), { label: unref(__)("Expires") }, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KSelect), {
									id,
									modelValue: tokenForm.expires_in_days,
									"onUpdate:modelValue": ($event) => tokenForm.expires_in_days = $event,
									options: EXPIRY
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KSelect), {
									id,
									modelValue: tokenForm.expires_in_days,
									"onUpdate:modelValue": ($event) => tokenForm.expires_in_days = $event,
									options: EXPIRY
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`<fieldset class="k-fieldset"${_scopeId}><legend class="k-field__label"${_scopeId}>${ssrInterpolate(unref(__)("Permissions"))}</legend>`);
						if (tokenErrors.value.scopes) _push(`<p class="k-field__error"${_scopeId}>${ssrInterpolate(tokenErrors.value.scopes)}</p>`);
						else _push(`<!---->`);
						_push(`<!--[-->`);
						ssrRenderList(unref(tokens).meta.value?.scopes ?? [], (s) => {
							_push(`<label class="k-option"${_scopeId}><input type="checkbox"${ssrIncludeBooleanAttr(tokenForm.scopes.includes(s.name)) ? " checked" : ""}${_scopeId}><span${_scopeId}><code${_scopeId}>${ssrInterpolate(s.name)}</code> ${ssrInterpolate(s.description)}</span></label>`);
						});
						_push(`<!--]--></fieldset></form>`);
					} else return [createVNode("form", {
						id: "k-token",
						class: "k-form",
						onSubmit: withModifiers(createToken, ["prevent"])
					}, [
						createVNode(unref(KField), {
							label: unref(__)("Name"),
							help: unref(__)("What will use it, so you recognise it later."),
							error: tokenErrors.value.name ?? "",
							required: ""
						}, {
							default: withCtx(({ id, invalid }) => [createVNode(unref(KInput), {
								id,
								modelValue: tokenForm.name,
								"onUpdate:modelValue": ($event) => tokenForm.name = $event,
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
						createVNode(unref(KField), { label: unref(__)("Expires") }, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: tokenForm.expires_in_days,
								"onUpdate:modelValue": ($event) => tokenForm.expires_in_days = $event,
								options: EXPIRY
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label"]),
						createVNode("fieldset", { class: "k-fieldset" }, [
							createVNode("legend", { class: "k-field__label" }, toDisplayString(unref(__)("Permissions")), 1),
							tokenErrors.value.scopes ? (openBlock(), createBlock("p", {
								key: 0,
								class: "k-field__error"
							}, toDisplayString(tokenErrors.value.scopes), 1)) : createCommentVNode("v-if", true),
							(openBlock(true), createBlock(Fragment, null, renderList(unref(tokens).meta.value?.scopes ?? [], (s) => {
								return openBlock(), createBlock("label", {
									key: s.name,
									class: "k-option"
								}, [createVNode("input", {
									type: "checkbox",
									checked: tokenForm.scopes.includes(s.name),
									onChange: ($event) => toggleScope(s.name, $event.target.checked)
								}, null, 40, ["checked", "onChange"]), createVNode("span", null, [createVNode("code", null, toDisplayString(s.name), 1), createTextVNode(" " + toDisplayString(s.description), 1)])]);
							}), 128))
						])
					], 32)];
				}),
				_: 1
			}, _parent));
			_push(`<!-- shown once -->`);
			_push(ssrRenderComponent(unref(KModal), {
				open: !!newToken.value,
				title: unref(__)("Copy your token now"),
				dismissible: false,
				onClose: ($event) => newToken.value = null
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), {
							icon: "clipboard",
							onClick: copyToken
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Copy"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Copy")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							onClick: ($event) => newToken.value = null
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("I have stored it"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("I have stored it")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), {
						icon: "clipboard",
						onClick: copyToken
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Copy")), 1)]),
						_: 1
					}), createVNode(unref(KButton), {
						variant: "primary",
						onClick: ($event) => newToken.value = null
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("I have stored it")), 1)]),
						_: 1
					}, 8, ["onClick"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (newToken.value) _push(`<!--[--><p${_scopeId}>${ssrInterpolate(unref(__)("This is the only time it is shown. Store it somewhere safe, such as a password manager."))}</p><div class="k-secret k-secret--block"${_scopeId}><code${_scopeId}>${ssrInterpolate(newToken.value.token)}</code></div><!--]-->`);
						else _push(`<!---->`);
					} else return [newToken.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode("p", null, toDisplayString(unref(__)("This is the only time it is shown. Store it somewhere safe, such as a password manager.")), 1), createVNode("div", { class: "k-secret k-secret--block" }, [createVNode("code", null, toDisplayString(newToken.value.token), 1)])], 64)) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: !!recoveryCodes.value,
				title: unref(__)("Save your recovery codes"),
				dismissible: false,
				onClose: ($event) => recoveryCodes.value = null
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), {
							icon: "clipboard",
							onClick: copyCodes
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Copy"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Copy")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							onClick: ($event) => recoveryCodes.value = null
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("I have saved them"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("I have saved them")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), {
						icon: "clipboard",
						onClick: copyCodes
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Copy")), 1)]),
						_: 1
					}), createVNode(unref(KButton), {
						variant: "primary",
						onClick: ($event) => recoveryCodes.value = null
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("I have saved them")), 1)]),
						_: 1
					}, 8, ["onClick"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (recoveryCodes.value) {
							_push(`<!--[--><p${_scopeId}>${ssrInterpolate(unref(__)("If you lose your phone, each code signs you in once. Keep them somewhere other than your phone. They are not shown again."))}</p><ul class="k-recovery"${_scopeId}><!--[-->`);
							ssrRenderList(recoveryCodes.value, (c) => {
								_push(`<li${_scopeId}><code${_scopeId}>${ssrInterpolate(c)}</code></li>`);
							});
							_push(`<!--]--></ul><!--]-->`);
						} else _push(`<!---->`);
					} else return [recoveryCodes.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode("p", null, toDisplayString(unref(__)("If you lose your phone, each code signs you in once. Keep them somewhere other than your phone. They are not shown again.")), 1), createVNode("ul", { class: "k-recovery" }, [(openBlock(true), createBlock(Fragment, null, renderList(recoveryCodes.value, (c) => {
						return openBlock(), createBlock("li", { key: c }, [createVNode("code", null, toDisplayString(c), 1)]);
					}), 128))])], 64)) : createCommentVNode("v-if", true)];
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/Profile.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
