import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, ref, toDisplayString, unref, useSSRContext, watch, withCtx } from "vue";
import { ssrInterpolate, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { ApiError, __, confirm, notify, useApi, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KField, KIcon, KModal, KPageHeader, KSchemaForm, KSelect } from "@kestello/ui";
// core/resources/js/pages/admin/system/Platform.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Platform",
	__ssrInlineRender: true,
	setup(__props) {
		const api = useApi();
		const list = useResource("/system/platform");
		const SOURCE = {
			env: __("an environment variable"),
			file: __("config/platform.php"),
			admin: __("the admin"),
			default: __("the defaults"),
			core: __("the defaults")
		};
		const editing = ref(null);
		const driver = ref("");
		const options = ref({});
		const errors = ref({});
		const test = ref(null);
		const testing = ref(false);
		const saving = ref(false);
		const current = computed(() => editing.value?.drivers.find((d) => d.name === driver.value) ?? null);
		const driverOptions = computed(() => (editing.value?.drivers ?? []).map((d) => ({
			value: d.name,
			label: d.available ? `${d.label}${d.owner !== "core" ? ` (${d.owner})` : ""}` : `${d.label}: ${d.unavailableReason}`,
			disabled: !d.available
		})));
		const locked = computed(() => {
			const out = {};
			for (const key of editing.value?.lockedKeys ?? []) out[key] = SOURCE[editing.value.sources?.[key]] ?? SOURCE.env;
			return out;
		});
		const secretsSet = computed(() => {
			const out = {};
			if (editing.value && driver.value === editing.value.driver) {
				for (const [key, value] of Object.entries(editing.value.options)) if (value && typeof value === "object" && "set" in value) out[key] = value.set;
			}
			return out;
		});
		function open(subsystem) {
			editing.value = subsystem;
			driver.value = subsystem.driver;
			options.value = initialOptions(subsystem, subsystem.driver);
			errors.value = {};
			test.value = null;
		}
		function initialOptions(subsystem, name) {
			if (name !== subsystem.driver) return {};
			const out = {};
			for (const [key, value] of Object.entries(subsystem.options)) if (value === null || typeof value !== "object") out[key] = value;
			return out;
		}
		watch(driver, (name, previous) => {
			if (!editing.value || previous === "" || name === previous) return;
			options.value = initialOptions(editing.value, name);
			errors.value = {};
			test.value = null;
		});
		function payload() {
			const clean = {};
			const secrets = current.value?.secrets ?? [];
			for (const [key, value] of Object.entries(options.value)) {
				if (value === void 0 || locked.value[key] || !(key in (current.value?.schema?.properties ?? {}))) continue;
				if (value === "" && (secrets.includes(key) || current.value?.schema?.properties?.[key]?.default !== void 0)) continue;
				clean[key] = value;
			}
			return {
				driver: driver.value,
				options: clean
			};
		}
		function showErrors(error) {
			errors.value = {};
			for (const [field, message] of Object.entries(error.fieldErrors?.() ?? {})) errors.value[field.replace(/^options\./, "")] = message;
		}
		async function runTest() {
			testing.value = true;
			test.value = null;
			try {
				test.value = (await api.post(`/system/platform/${editing.value.name}/test`, payload())).data;
				errors.value = {};
				for (const [field, messages] of Object.entries(test.value.errors ?? {})) errors.value[field] = messages[0];
			} catch (e) {
				test.value = {
					ok: false,
					message: e.message
				};
				if (e instanceof ApiError) showErrors(e);
			} finally {
				testing.value = false;
			}
		}
		async function save() {
			saving.value = true;
			try {
				await api.put(`/system/platform/${editing.value.name}`, payload());
				notify.success(__("{label} now uses {driver}. Administrators were notified.", {
					label: editing.value.label,
					driver: current.value?.label ?? driver.value
				}));
				editing.value = null;
				await list.reload();
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) {
					showErrors(e);
					test.value = {
						ok: false,
						message: e.detail || e.message
					};
				} else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		async function reset(subsystem) {
			if (!await confirm({
				title: __("Reset {label}?", { label: subsystem.label }),
				message: __("The settings saved in the admin are removed. The config file, environment or defaults apply again."),
				confirmLabel: __("Reset")
			})) return;
			try {
				await api.delete(`/system/platform/${subsystem.name}`);
				notify.success(__("{label} was reset.", { label: subsystem.label }));
				await list.reload();
			} catch (e) {
				notify.error(e);
			}
		}
		function driverLabel(subsystem) {
			return subsystem.drivers.find((d) => d.name === subsystem.driver)?.label ?? subsystem.driver;
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Infrastructure"),
				description: unref(__)("Where Kestello keeps its cache, sessions, jobs and other services. Changes are tested before they are saved.")
			}, null, _parent));
			if (unref(list).error.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(list).error.value.message)}`);
					else return [createTextVNode(toDisplayString(unref(list).error.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (unref(list).data.value?.[0]?.locked) _push(ssrRenderComponent(unref(KAlert), {
				tone: "info",
				title: unref(__)("Managed by your host")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("Infrastructure settings are locked (config/platform.php or KESTELLO_PLATFORM_LOCKED). You can review them here but not change them."))}`);
					else return [createTextVNode(toDisplayString(unref(__)("Infrastructure settings are locked (config/platform.php or KESTELLO_PLATFORM_LOCKED). You can review them here but not change them.")), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`<div class="k-grid k-grid--2"><!--[-->`);
			ssrRenderList(unref(list).data.value ?? [], (subsystem) => {
				_push(ssrRenderComponent(unref(KCard), {
					key: subsystem.name,
					title: subsystem.label,
					description: subsystem.description
				}, {
					actions: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							if (subsystem.driverSource === "admin" && !subsystem.locked) _push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								variant: "ghost",
								onClick: ($event) => reset(subsystem)
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Reset"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Reset")), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else _push(`<!---->`);
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								disabled: subsystem.locked,
								icon: "sliders",
								onClick: ($event) => open(subsystem)
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Change"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Change")), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
						} else return [subsystem.driverSource === "admin" && !subsystem.locked ? (openBlock(), createBlock(unref(KButton), {
							key: 0,
							size: "sm",
							variant: "ghost",
							onClick: ($event) => reset(subsystem)
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Reset")), 1)]),
							_: 1
						}, 8, ["onClick"])) : createCommentVNode("v-if", true), createVNode(unref(KButton), {
							size: "sm",
							disabled: subsystem.locked,
							icon: "sliders",
							onClick: ($event) => open(subsystem)
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Change")), 1)]),
							_: 1
						}, 8, ["disabled", "onClick"])];
					}),
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-driver"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KIcon), { name: "hard-drives" }, null, _parent, _scopeId));
							_push(`<div${_scopeId}><strong${_scopeId}>${ssrInterpolate(driverLabel(subsystem))}</strong><p class="k-muted k-small"${_scopeId}>${ssrInterpolate(unref(__)("Set by {source}", { source: SOURCE[subsystem.driverSource] ?? subsystem.driverSource }))}</p></div>`);
							if (subsystem.degraded) _push(ssrRenderComponent(unref(KBadge), {
								tone: "danger",
								dot: ""
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Fallback active"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Fallback active")), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else _push(ssrRenderComponent(unref(KBadge), {
								tone: "success",
								dot: ""
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Working"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Working")), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							_push(`</div>`);
							if (subsystem.degraded) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("The {driver} driver failed ({error}), so a fallback is serving requests.", {
										driver: subsystem.degraded.driver,
										error: subsystem.degraded.error
									}))}`);
									else return [createTextVNode(toDisplayString(unref(__)("The {driver} driver failed ({error}), so a fallback is serving requests.", {
										driver: subsystem.degraded.driver,
										error: subsystem.degraded.error
									})), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else _push(`<!---->`);
						} else return [createVNode("div", { class: "k-driver" }, [
							createVNode(unref(KIcon), { name: "hard-drives" }),
							createVNode("div", null, [createVNode("strong", null, toDisplayString(driverLabel(subsystem)), 1), createVNode("p", { class: "k-muted k-small" }, toDisplayString(unref(__)("Set by {source}", { source: SOURCE[subsystem.driverSource] ?? subsystem.driverSource })), 1)]),
							subsystem.degraded ? (openBlock(), createBlock(unref(KBadge), {
								key: 0,
								tone: "danger",
								dot: ""
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Fallback active")), 1)]),
								_: 1
							})) : (openBlock(), createBlock(unref(KBadge), {
								key: 1,
								tone: "success",
								dot: ""
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Working")), 1)]),
								_: 1
							}))
						]), subsystem.degraded ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "danger"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("The {driver} driver failed ({error}), so a fallback is serving requests.", {
								driver: subsystem.degraded.driver,
								error: subsystem.degraded.error
							})), 1)]),
							_: 2
						}, 1024)) : createCommentVNode("v-if", true)];
					}),
					_: 2
				}, _parent));
			});
			_push(`<!--]--></div>`);
			_push(ssrRenderComponent(unref(KModal), {
				open: !!editing.value,
				title: editing.value ? unref(__)("Change {label}", { label: editing.value.label }) : "",
				size: "lg",
				onClose: ($event) => editing.value = null
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => editing.value = null }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							icon: "plug",
							loading: testing.value,
							onClick: runTest
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Test connection"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Test connection")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							loading: saving.value,
							onClick: save
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Test and save"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Test and save")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [
						createVNode(unref(KButton), { onClick: ($event) => editing.value = null }, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
							_: 1
						}, 8, ["onClick"]),
						createVNode(unref(KButton), {
							icon: "plug",
							loading: testing.value,
							onClick: runTest
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Test connection")), 1)]),
							_: 1
						}, 8, ["loading"]),
						createVNode(unref(KButton), {
							variant: "primary",
							loading: saving.value,
							onClick: save
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Test and save")), 1)]),
							_: 1
						}, 8, ["loading"])
					];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (editing.value) {
							_push(`<!--[-->`);
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Driver"),
								help: current.value?.description ?? "",
								error: errors.value.driver ?? ""
							}, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KSelect), {
										id,
										modelValue: driver.value,
										"onUpdate:modelValue": ($event) => driver.value = $event,
										options: driverOptions.value,
										disabled: !!locked.value.driver
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KSelect), {
										id,
										modelValue: driver.value,
										"onUpdate:modelValue": ($event) => driver.value = $event,
										options: driverOptions.value,
										disabled: !!locked.value.driver
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"options",
										"disabled"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							if (current.value) _push(ssrRenderComponent(unref(KSchemaForm), {
								modelValue: options.value,
								"onUpdate:modelValue": ($event) => options.value = $event,
								schema: current.value.schema,
								errors: errors.value,
								secrets: current.value.secrets,
								"secrets-set": secretsSet.value,
								locked: locked.value
							}, null, _parent, _scopeId));
							else _push(`<!---->`);
							if (test.value) _push(ssrRenderComponent(unref(KAlert), {
								tone: test.value.ok ? "success" : "danger",
								title: test.value.ok ? unref(__)("Connection test passed") : unref(__)("Connection test failed")
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) {
										_push(`${ssrInterpolate(test.value.message)}`);
										if (test.value.ms !== void 0) _push(`<span class="k-muted"${_scopeId}> (${ssrInterpolate(test.value.ms)} ms)</span>`);
										else _push(`<!---->`);
									} else return [createTextVNode(toDisplayString(test.value.message), 1), test.value.ms !== void 0 ? (openBlock(), createBlock("span", {
										key: 0,
										class: "k-muted"
									}, " (" + toDisplayString(test.value.ms) + " ms)", 1)) : createCommentVNode("v-if", true)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							_push(`<!--]-->`);
						} else _push(`<!---->`);
					} else return [editing.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
						createVNode(unref(KField), {
							label: unref(__)("Driver"),
							help: current.value?.description ?? "",
							error: errors.value.driver ?? ""
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: driver.value,
								"onUpdate:modelValue": ($event) => driver.value = $event,
								options: driverOptions.value,
								disabled: !!locked.value.driver
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"options",
								"disabled"
							])]),
							_: 1
						}, 8, [
							"label",
							"help",
							"error"
						]),
						current.value ? (openBlock(), createBlock(unref(KSchemaForm), {
							key: 0,
							modelValue: options.value,
							"onUpdate:modelValue": ($event) => options.value = $event,
							schema: current.value.schema,
							errors: errors.value,
							secrets: current.value.secrets,
							"secrets-set": secretsSet.value,
							locked: locked.value
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"schema",
							"errors",
							"secrets",
							"secrets-set",
							"locked"
						])) : createCommentVNode("v-if", true),
						test.value ? (openBlock(), createBlock(unref(KAlert), {
							key: 1,
							tone: test.value.ok ? "success" : "danger",
							title: test.value.ok ? unref(__)("Connection test passed") : unref(__)("Connection test failed")
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(test.value.message), 1), test.value.ms !== void 0 ? (openBlock(), createBlock("span", {
								key: 0,
								class: "k-muted"
							}, " (" + toDisplayString(test.value.ms) + " ms)", 1)) : createCommentVNode("v-if", true)]),
							_: 1
						}, 8, ["tone", "title"])) : createCommentVNode("v-if", true)
					], 64)) : createCommentVNode("v-if", true)];
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Platform.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
