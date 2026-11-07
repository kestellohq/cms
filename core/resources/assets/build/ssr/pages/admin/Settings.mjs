import _sfc_main$1 from "../fields/MediaPicker.mjs";
import { computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, ref, toDisplayString, unref, useSSRContext, watch, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent } from "vue/server-renderer";
import { Head } from "@kestello/app";
import { ApiError, __, notify, useApi, useResource, useUser } from "@kestello/sdk";
import { KAlert, KButton, KCard, KExtension, KField, KInput, KPageHeader, KSchemaForm, KTabs } from "@kestello/ui";
// core/resources/js/pages/admin/Settings.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Settings",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* Site settings. Every tab is a settings group the API describes with a
		* schema (GET /settings → meta.groups); the schema form renders it, so a
		* new group needs no new screen. Plugin tabs ($cms->admin->settings()) come
		* the same way; a tab with a component renders that component instead.
		*/
		const api = useApi();
		const me = useUser();
		const settings = useResource("/settings");
		const groups = computed(() => settings.meta.value?.groups ?? []);
		const tabs = computed(() => groups.value.map((g) => ({
			id: g.id,
			label: g.label
		})));
		const fieldCount = computed(() => Object.keys(group.value?.schema?.properties ?? {}).length);
		const tab = ref(typeof window !== "undefined" ? new URLSearchParams(window.location.search).get("tab") ?? "general" : "general");
		const drafts = ref({});
		const errors = ref({});
		const saving = ref(false);
		const group = computed(() => groups.value.find((g) => g.id === tab.value) ?? groups.value[0] ?? null);
		watch(group, (g) => {
			if (g && g.id !== tab.value) tab.value = g.id;
		});
		const stored = computed(() => settings.data.value?.[group.value?.id] ?? {});
		const secretsSet = computed(() => Object.fromEntries((group.value?.secrets ?? []).map((k) => [k, !!stored.value[`${k}_set`]])));
		const changes = computed(() => {
			if (!group.value) return {};
			const draft = drafts.value[group.value.id] ?? {};
			return Object.fromEntries(Object.entries(draft).filter(([k, v]) => group.value.secrets.includes(k) ? v !== "" && v !== void 0 : v !== stored.value[k]));
		});
		watch(() => settings.data.value, (data) => {
			if (!data) return;
			drafts.value = Object.fromEntries(groups.value.map((g) => [g.id, Object.fromEntries(Object.entries(data[g.id] ?? {}).filter(([k]) => !k.endsWith("_set")))]));
		});
		watch(tab, (value) => {
			errors.value = {};
			const url = new URL(window.location.href);
			url.searchParams.set("tab", value);
			window.history.replaceState(window.history.state, "", url);
		});
		async function save() {
			saving.value = true;
			errors.value = {};
			try {
				await api.patch("/settings", { [group.value.id]: changes.value });
				notify.success(__("{group} settings saved.", { group: group.value.label }));
				await settings.reload({ silent: true });
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) {
					const prefix = `${group.value.id}.`;
					errors.value = Object.fromEntries(Object.entries(e.fieldErrors()).map(([k, v]) => [k.startsWith(prefix) ? k.slice(prefix.length) : k, v]));
				} else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		function revert() {
			drafts.value = {
				...drafts.value,
				[group.value.id]: Object.fromEntries(Object.entries(stored.value).filter(([k]) => !k.endsWith("_set")))
			};
			errors.value = {};
		}
		const testTo = ref("");
		const testing = ref(false);
		const testResult = ref(null);
		watch(me, (u) => {
			if (u && !testTo.value) testTo.value = u.email ?? "";
		}, { immediate: true });
		async function sendTest() {
			testing.value = true;
			testResult.value = null;
			try {
				const { data } = await api.post("/settings/mail/test", { to: testTo.value.trim() });
				testResult.value = {
					ok: true,
					message: data.via === "smtp" ? __("Sent through your SMTP server. Check the inbox (and spam folder) of {to}.", { to: testTo.value }) : __("Handed to PHP mail(). If it does not arrive within a few minutes, configure SMTP.")
				};
			} catch (e) {
				testResult.value = {
					ok: false,
					message: e instanceof ApiError && e.status === 422 ? e.fieldErrors().to ?? e.message : e.message
				};
			} finally {
				testing.value = false;
			}
		}
		const unsavedText = (n) => __("{n, plural, one {# unsaved change} other {# unsaved changes}}", { n });
		const whereItShows = computed(() => ({
			general: __("The site name and tagline appear in the browser tab, in emails and wherever your theme prints them. The timezone sets the clock for dates and scheduled tasks."),
			reading: __("Addresses, the home page and feeds change what visitors and feed readers get. Old addresses keep working."),
			seo: __("Page titles show in the browser tab and as the headline in search results. The sharing image shows when someone posts a link to the site. Each entry can set its own under Search and sharing."),
			accounts: __("Applies to the sign-up page and to every sign-in provider. People you add under People are not affected."),
			media: __("Applies to new uploads. Files already in the library stay as they are."),
			performance: __("Visitors who are signed in always get fresh pages. Saving content refreshes the pages it appears on."),
			snippets: __("Added to every public page, never to the admin or the sign-in pages.")
		}));
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("Settings") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Settings"),
				description: unref(__)("How your site introduces itself and how it reaches people.")
			}, null, _parent));
			if (unref(settings).error.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(settings).error.value.message)}`);
					else return [createTextVNode(toDisplayString(unref(settings).error.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (group.value) {
				_push(`<!--[-->`);
				_push(ssrRenderComponent(unref(KTabs), {
					modelValue: tab.value,
					"onUpdate:modelValue": ($event) => tab.value = $event,
					tabs: tabs.value,
					label: unref(__)("Settings groups")
				}, null, _parent));
				_push(`<div class="k-cells" style="--k-cols:3;">`);
				if (group.value.component && !fieldCount.value) _push(ssrRenderComponent(unref(KCard), {
					coord: "A1",
					class: "k-span-all",
					title: group.value.label,
					description: group.value.description
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KExtension), {
							key: group.value.id,
							name: group.value.component,
							owner: group.value.source ?? "",
							context: { group: group.value.id }
						}, null, _parent, _scopeId));
						else return [(openBlock(), createBlock(unref(KExtension), {
							key: group.value.id,
							name: group.value.component,
							owner: group.value.source ?? "",
							context: { group: group.value.id }
						}, null, 8, [
							"name",
							"owner",
							"context"
						]))];
					}),
					_: 1
				}, _parent));
				else {
					_push(`<!--[-->`);
					_push(ssrRenderComponent(unref(KCard), {
						coord: "A1",
						class: "k-span-2",
						title: group.value.label,
						description: group.value.description
					}, {
						footer: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<div class="k-form__actions"${_scopeId}>`);
								if (Object.keys(changes.value).length) _push(`<span class="k-muted k-small"${_scopeId}>${ssrInterpolate(unsavedText(Object.keys(changes.value).length))}</span>`);
								else _push(`<!---->`);
								if (Object.keys(changes.value).length) _push(ssrRenderComponent(unref(KButton), {
									variant: "ghost",
									onClick: revert
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Discard"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Discard")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								else _push(`<!---->`);
								_push(ssrRenderComponent(unref(KButton), {
									type: "submit",
									form: `k-settings-${group.value.id}`,
									variant: "primary",
									disabled: !Object.keys(changes.value).length,
									loading: saving.value
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Save {group}", { group: group.value.label.toLowerCase() }))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Save {group}", { group: group.value.label.toLowerCase() })), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div>`);
							} else return [createVNode("div", { class: "k-form__actions" }, [
								Object.keys(changes.value).length ? (openBlock(), createBlock("span", {
									key: 0,
									class: "k-muted k-small"
								}, toDisplayString(unsavedText(Object.keys(changes.value).length)), 1)) : createCommentVNode("v-if", true),
								Object.keys(changes.value).length ? (openBlock(), createBlock(unref(KButton), {
									key: 1,
									variant: "ghost",
									onClick: revert
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Discard")), 1)]),
									_: 1
								})) : createCommentVNode("v-if", true),
								createVNode(unref(KButton), {
									type: "submit",
									form: `k-settings-${group.value.id}`,
									variant: "primary",
									disabled: !Object.keys(changes.value).length,
									loading: saving.value
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save {group}", { group: group.value.label.toLowerCase() })), 1)]),
									_: 1
								}, 8, [
									"form",
									"disabled",
									"loading"
								])
							])];
						}),
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<form${ssrRenderAttr("id", `k-settings-${group.value.id}`)} class="k-form"${_scopeId}>`);
								if (drafts.value[group.value.id]) _push(ssrRenderComponent(unref(KSchemaForm), {
									modelValue: drafts.value[group.value.id],
									"onUpdate:modelValue": ($event) => drafts.value[group.value.id] = $event,
									schema: group.value.schema,
									labels: group.value.labels,
									secrets: group.value.secrets,
									"secrets-set": secretsSet.value,
									errors: errors.value
								}, {
									"format-media": withCtx(({ id, field, value, set, invalid, disabled }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(_sfc_main$1, {
											id,
											"model-value": value > 0 ? value : null,
											field: {
												label: field.label,
												options: { kinds: ["image"] }
											},
											invalid,
											disabled,
											"onUpdate:modelValue": ($event) => set($event ?? 0)
										}, null, _parent, _scopeId));
										else return [createVNode(_sfc_main$1, {
											id,
											"model-value": value > 0 ? value : null,
											field: {
												label: field.label,
												options: { kinds: ["image"] }
											},
											invalid,
											disabled,
											"onUpdate:modelValue": ($event) => set($event ?? 0)
										}, null, 8, [
											"id",
											"model-value",
											"field",
											"invalid",
											"disabled",
											"onUpdate:modelValue"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								else _push(`<!---->`);
								_push(`</form>`);
							} else return [createVNode("form", {
								id: `k-settings-${group.value.id}`,
								class: "k-form",
								onSubmit: withModifiers(save, ["prevent"])
							}, [drafts.value[group.value.id] ? (openBlock(), createBlock(unref(KSchemaForm), {
								key: 0,
								modelValue: drafts.value[group.value.id],
								"onUpdate:modelValue": ($event) => drafts.value[group.value.id] = $event,
								schema: group.value.schema,
								labels: group.value.labels,
								secrets: group.value.secrets,
								"secrets-set": secretsSet.value,
								errors: errors.value
							}, {
								"format-media": withCtx(({ id, field, value, set, invalid, disabled }) => [createVNode(_sfc_main$1, {
									id,
									"model-value": value > 0 ? value : null,
									field: {
										label: field.label,
										options: { kinds: ["image"] }
									},
									invalid,
									disabled,
									"onUpdate:modelValue": ($event) => set($event ?? 0)
								}, null, 8, [
									"id",
									"model-value",
									"field",
									"invalid",
									"disabled",
									"onUpdate:modelValue"
								])]),
								_: 1
							}, 8, [
								"modelValue",
								"onUpdate:modelValue",
								"schema",
								"labels",
								"secrets",
								"secrets-set",
								"errors"
							])) : createCommentVNode("v-if", true)], 40, ["id"])];
						}),
						_: 1
					}, _parent));
					if (group.value.id === "mail") _push(ssrRenderComponent(unref(KCard), {
						coord: "C1",
						title: unref(__)("Send a test email"),
						description: unref(__)("Uses the saved settings, so save first.")
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<form class="k-form"${_scopeId}>`);
								_push(ssrRenderComponent(unref(KField), { label: unref(__)("Send to") }, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KInput), {
											id,
											modelValue: testTo.value,
											"onUpdate:modelValue": ($event) => testTo.value = $event,
											type: "email",
											autocomplete: "email"
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KInput), {
											id,
											modelValue: testTo.value,
											"onUpdate:modelValue": ($event) => testTo.value = $event,
											type: "email",
											autocomplete: "email"
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`<div${_scopeId}>`);
								_push(ssrRenderComponent(unref(KButton), {
									type: "submit",
									icon: "paper-plane-tilt",
									disabled: !testTo.value || Object.keys(changes.value).length > 0,
									loading: testing.value
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Send test"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Send test")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div></form>`);
								if (testResult.value) _push(ssrRenderComponent(unref(KAlert), { tone: testResult.value.ok ? "success" : "danger" }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(testResult.value.message)}`);
										else return [createTextVNode(toDisplayString(testResult.value.message), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								else _push(`<!---->`);
							} else return [createVNode("form", {
								class: "k-form",
								onSubmit: withModifiers(sendTest, ["prevent"])
							}, [createVNode(unref(KField), { label: unref(__)("Send to") }, {
								default: withCtx(({ id }) => [createVNode(unref(KInput), {
									id,
									modelValue: testTo.value,
									"onUpdate:modelValue": ($event) => testTo.value = $event,
									type: "email",
									autocomplete: "email"
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])]),
								_: 1
							}, 8, ["label"]), createVNode("div", null, [createVNode(unref(KButton), {
								type: "submit",
								icon: "paper-plane-tilt",
								disabled: !testTo.value || Object.keys(changes.value).length > 0,
								loading: testing.value
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Send test")), 1)]),
								_: 1
							}, 8, ["disabled", "loading"])])], 32), testResult.value ? (openBlock(), createBlock(unref(KAlert), {
								key: 0,
								tone: testResult.value.ok ? "success" : "danger"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(testResult.value.message), 1)]),
								_: 1
							}, 8, ["tone"])) : createCommentVNode("v-if", true)];
						}),
						_: 1
					}, _parent));
					else if (group.value.source) _push(ssrRenderComponent(unref(KCard), {
						coord: "C1",
						title: unref(__)("From a plugin")
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<p class="k-source"${_scopeId}>${ssrInterpolate(group.value.source)}</p><p class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("These settings belong to the {plugin} plugin and are saved with the rest of the site settings.", { plugin: group.value.source }))}</p>`);
								if (group.value.secrets.length) _push(`<p class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("Secret fields are stored encrypted and never shown again. Leave one blank to keep what is saved."))}</p>`);
								else _push(`<!---->`);
								if (group.value.component) _push(ssrRenderComponent(unref(KExtension), {
									key: group.value.id,
									name: group.value.component,
									owner: group.value.source,
									context: {
										group: group.value.id,
										values: stored.value
									}
								}, null, _parent, _scopeId));
								else _push(`<!---->`);
							} else return [
								createVNode("p", { class: "k-source" }, toDisplayString(group.value.source), 1),
								createVNode("p", { class: "k-muted" }, toDisplayString(unref(__)("These settings belong to the {plugin} plugin and are saved with the rest of the site settings.", { plugin: group.value.source })), 1),
								group.value.secrets.length ? (openBlock(), createBlock("p", {
									key: 0,
									class: "k-muted"
								}, toDisplayString(unref(__)("Secret fields are stored encrypted and never shown again. Leave one blank to keep what is saved.")), 1)) : createCommentVNode("v-if", true),
								group.value.component ? (openBlock(), createBlock(unref(KExtension), {
									key: group.value.id,
									name: group.value.component,
									owner: group.value.source,
									context: {
										group: group.value.id,
										values: stored.value
									}
								}, null, 8, [
									"name",
									"owner",
									"context"
								])) : createCommentVNode("v-if", true)
							];
						}),
						_: 1
					}, _parent));
					else if (whereItShows.value[group.value.id]) _push(ssrRenderComponent(unref(KCard), {
						coord: "C1",
						title: unref(__)("Where this shows")
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`<p class="k-muted"${_scopeId}>${ssrInterpolate(whereItShows.value[group.value.id])}</p>`);
							else return [createVNode("p", { class: "k-muted" }, toDisplayString(whereItShows.value[group.value.id]), 1)];
						}),
						_: 1
					}, _parent));
					else _push(`<!---->`);
					_push(`<!--]-->`);
				}
				_push(`</div><!--]-->`);
			} else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/Settings.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
