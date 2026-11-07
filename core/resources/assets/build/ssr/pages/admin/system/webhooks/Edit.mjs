import { n as EventPicker_default, t as _sfc_main$1 } from "../../../../chunks/SecretModal-C63Bn-b7.mjs";
import { t as _plugin_vue_export_helper_default } from "../../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, openBlock, reactive, ref, toDisplayString, unref, useSSRContext, watch, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { Head, Link, usePage, useRouter } from "@kestello/app";
import { ApiError, __, confirm, formatDateTime, notify, useApi, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KSelect, KSwitch, KTable, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/system/webhooks/Edit.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Edit",
	__ssrInlineRender: true,
	setup(__props) {
		const page = usePage();
		const api = useApi();
		const router = useRouter();
		const id = computed(() => Number(page.props.params?.id ?? 0));
		const hook = useResource(() => `/webhooks/${id.value}`);
		const catalog = useResource("/webhooks/events");
		const form = reactive({
			name: "",
			url: "",
			events: [],
			active: true
		});
		const errors = ref({});
		const saving = ref(false);
		const differs = (w) => form.name !== w.name || form.url !== w.url || form.active !== w.active || [...form.events].sort().join() !== [...w.events].sort().join();
		watch(() => hook.data.value, (w, before) => {
			if (w && (!before || !differs(before))) Object.assign(form, {
				name: w.name,
				url: w.url,
				events: [...w.events],
				active: w.active
			});
		}, { immediate: true });
		const dirty = computed(() => !!hook.data.value && differs(hook.data.value));
		async function save() {
			saving.value = true;
			errors.value = {};
			try {
				await api.patch(`/webhooks/${id.value}`, { ...form });
				notify.success(__("Changes saved."));
				await hook.reload({ silent: true });
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		async function turnOn() {
			form.active = true;
			await save();
		}
		async function remove() {
			if (!await confirm({
				title: __("Delete \"{name}\"?", { name: hook.data.value.name }),
				message: __("Nothing more is sent to it, and its delivery log is deleted."),
				confirmLabel: __("Delete"),
				danger: true
			})) return;
			try {
				await api.delete(`/webhooks/${id.value}`);
				notify.success(__("Webhook deleted."));
				router.visit("/admin/system/webhooks");
			} catch (e) {
				notify.error(e);
			}
		}
		const secret = ref(null);
		async function rotate() {
			if (!await confirm({
				title: __("Make a new signing secret?"),
				message: __("The receiving service needs the new one. The old one keeps working for 24 hours."),
				confirmLabel: __("Make a new secret")
			})) return;
			try {
				const { data } = await api.post(`/webhooks/${id.value}/secret`, {});
				secret.value = data.secret;
				hook.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		const deliveries = ref([]);
		const nextBefore = ref(null);
		const loadingLog = ref(false);
		const filter = ref("");
		const shown = ref(null);
		const busy = ref(false);
		async function load(more = false) {
			loadingLog.value = true;
			try {
				const body = await api.get(`/webhooks/${id.value}/deliveries`, {
					limit: 25,
					status: filter.value || void 0,
					before_id: more ? nextBefore.value : void 0
				});
				deliveries.value = more ? [...deliveries.value, ...body.data] : body.data;
				nextBefore.value = body.meta.next_before_id;
			} catch (e) {
				notify.error(e);
			} finally {
				loadingLog.value = false;
			}
		}
		watch([id, filter], () => load(), { immediate: true });
		async function ping() {
			busy.value = true;
			try {
				const { data } = await api.post(`/webhooks/${id.value}/ping`, {});
				data.status === "succeeded" ? notify.success(__("The test arrived: answered {status}.", { status: data.response_status })) : notify.error(data.error ?? __("The test did not arrive."));
				shown.value = data;
				await Promise.all([load(), hook.reload({ silent: true })]);
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = false;
			}
		}
		async function redeliver(d) {
			busy.value = true;
			try {
				const { data } = await api.post(`/webhooks/${id.value}/deliveries/${d.id}/redeliver`, {});
				data.status === "succeeded" ? notify.success(__("Sent again: answered {status}.", { status: data.response_status })) : notify.error(data.error ?? __("It did not arrive."));
				shown.value = data;
				await Promise.all([load(), hook.reload({ silent: true })]);
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = false;
			}
		}
		const STATUS = {
			succeeded: ["success", __("Delivered")],
			retrying: ["warning", __("Retrying")],
			failed: ["danger", __("Failed")],
			pending: ["neutral", __("Waiting")],
			sending: ["info", __("Sending")]
		};
		const FILTERS = [
			{
				value: "",
				label: __("All results")
			},
			{
				value: "failed",
				label: __("Failed")
			},
			{
				value: "retrying",
				label: __("Retrying")
			},
			{
				value: "succeeded",
				label: __("Delivered")
			}
		];
		const pretty = (v) => JSON.stringify(v, null, 2);
		const attemptsText = (n) => __("{n, plural, one {# attempt} other {# attempts}}", { n });
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(hook).data.value?.name ?? unref(__)("Webhook") }, null, _parent));
			_push(`<p class="k-back" data-v-0b517769>`);
			_push(ssrRenderComponent(unref(Link), {
				href: "/admin/system/webhooks",
				class: "k-link"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("All webhooks"))}`);
					else return [createTextVNode(toDisplayString(unref(__)("All webhooks")), 1)];
				}),
				_: 1
			}, _parent));
			_push(`</p>`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(hook).data.value?.name ?? unref(__)("Webhook"),
				description: unref(hook).data.value?.url ?? ""
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						icon: "paper-plane-tilt",
						loading: busy.value,
						disabled: !unref(hook).data.value,
						onClick: ping
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Send a test"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Send a test")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						icon: "paper-plane-tilt",
						loading: busy.value,
						disabled: !unref(hook).data.value,
						onClick: ping
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Send a test")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"])];
				}),
				_: 1
			}, _parent));
			if (unref(hook).data.value?.disabled_reason) _push(ssrRenderComponent(unref(KAlert), {
				tone: "danger",
				title: unref(__)("This webhook is turned off")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<p class="k-hook__alert" data-v-0b517769${_scopeId}>${ssrInterpolate(unref(hook).data.value.disabled_reason)} ${ssrInterpolate(unref(__)("Check the delivery log below for what the service answered, fix it, then turn the webhook on again."))}</p>`);
						_push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							loading: saving.value,
							onClick: turnOn
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Turn on again"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Turn on again")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode("p", { class: "k-hook__alert" }, toDisplayString(unref(hook).data.value.disabled_reason) + " " + toDisplayString(unref(__)("Check the delivery log below for what the service answered, fix it, then turn the webhook on again.")), 1), createVNode(unref(KButton), {
						size: "sm",
						loading: saving.value,
						onClick: turnOn
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Turn on again")), 1)]),
						_: 1
					}, 8, ["loading"])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`<div class="k-hook" data-v-0b517769>`);
			_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Settings") }, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), {
							variant: "danger",
							icon: "trash",
							onClick: remove
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Delete"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Delete")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							form: "k-hook-edit",
							variant: "primary",
							loading: saving.value,
							disabled: !dirty.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Save changes"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Save changes")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), {
						variant: "danger",
						icon: "trash",
						onClick: remove
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Delete")), 1)]),
						_: 1
					}), createVNode(unref(KButton), {
						type: "submit",
						form: "k-hook-edit",
						variant: "primary",
						loading: saving.value,
						disabled: !dirty.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save changes")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-hook-edit" class="k-form" data-v-0b517769${_scopeId}>`);
						_push(ssrRenderComponent(unref(KSwitch), {
							modelValue: form.active,
							"onUpdate:modelValue": ($event) => form.active = $event,
							label: unref(__)("Send events to this webhook")
						}, null, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Name"),
							error: errors.value.name ?? "",
							required: ""
						}, {
							default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id: fid,
									modelValue: form.name,
									"onUpdate:modelValue": ($event) => form.name = $event,
									maxlength: "100",
									autocomplete: "off",
									invalid
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id: fid,
									modelValue: form.name,
									"onUpdate:modelValue": ($event) => form.name = $event,
									maxlength: "100",
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
							label: unref(__)("Address"),
							error: errors.value.url ?? "",
							required: ""
						}, {
							default: withCtx(({ id: fid, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id: fid,
									modelValue: form.url,
									"onUpdate:modelValue": ($event) => form.url = $event,
									type: "url",
									inputmode: "url",
									autocomplete: "off",
									spellcheck: "false",
									invalid
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id: fid,
									modelValue: form.url,
									"onUpdate:modelValue": ($event) => form.url = $event,
									type: "url",
									inputmode: "url",
									autocomplete: "off",
									spellcheck: "false",
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
						_push(ssrRenderComponent(EventPicker_default, {
							modelValue: form.events,
							"onUpdate:modelValue": ($event) => form.events = $event,
							events: unref(catalog).data.value ?? [],
							error: errors.value.events ?? ""
						}, null, _parent, _scopeId));
						_push(`</form>`);
					} else return [createVNode("form", {
						id: "k-hook-edit",
						class: "k-form",
						onSubmit: withModifiers(save, ["prevent"])
					}, [
						createVNode(unref(KSwitch), {
							modelValue: form.active,
							"onUpdate:modelValue": ($event) => form.active = $event,
							label: unref(__)("Send events to this webhook")
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"label"
						]),
						createVNode(unref(KField), {
							label: unref(__)("Name"),
							error: errors.value.name ?? "",
							required: ""
						}, {
							default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
								id: fid,
								modelValue: form.name,
								"onUpdate:modelValue": ($event) => form.name = $event,
								maxlength: "100",
								autocomplete: "off",
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
							label: unref(__)("Address"),
							error: errors.value.url ?? "",
							required: ""
						}, {
							default: withCtx(({ id: fid, invalid }) => [createVNode(unref(KInput), {
								id: fid,
								modelValue: form.url,
								"onUpdate:modelValue": ($event) => form.url = $event,
								type: "url",
								inputmode: "url",
								autocomplete: "off",
								spellcheck: "false",
								invalid
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"invalid"
							])]),
							_: 1
						}, 8, ["label", "error"]),
						createVNode(EventPicker_default, {
							modelValue: form.events,
							"onUpdate:modelValue": ($event) => form.events = $event,
							events: unref(catalog).data.value ?? [],
							error: errors.value.events ?? ""
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"events",
							"error"
						])
					], 32)];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Signing secret") }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<p class="k-small" data-v-0b517769${_scopeId}>${ssrInterpolate(unref(__)("Every message has a Kestello-Signature header made with this secret. The receiver checks it to know the message came from this site and was not changed."))}</p>`);
						if (unref(hook).data.value?.secret_rotating_until) _push(`<p class="k-small k-muted" data-v-0b517769${_scopeId}>${ssrInterpolate(unref(__)("The previous secret also signs messages until {when}.", { when: unref(formatDateTime)(unref(hook).data.value.secret_rotating_until) }))}</p>`);
						else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							icon: "key",
							onClick: rotate
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Make a new secret"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Make a new secret")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [
						createVNode("p", { class: "k-small" }, toDisplayString(unref(__)("Every message has a Kestello-Signature header made with this secret. The receiver checks it to know the message came from this site and was not changed.")), 1),
						unref(hook).data.value?.secret_rotating_until ? (openBlock(), createBlock("p", {
							key: 0,
							class: "k-small k-muted"
						}, toDisplayString(unref(__)("The previous secret also signs messages until {when}.", { when: unref(formatDateTime)(unref(hook).data.value.secret_rotating_until) })), 1)) : createCommentVNode("v-if", true),
						createVNode(unref(KButton), {
							size: "sm",
							icon: "key",
							onClick: rotate
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Make a new secret")), 1)]),
							_: 1
						})
					];
				}),
				_: 1
			}, _parent));
			_push(`</div>`);
			_push(ssrRenderComponent(unref(KCard), {
				title: unref(__)("Deliveries"),
				description: unref(__)("Kept for 30 days. A failed delivery is tried again after 1, 5 and 30 minutes, then 2, 6 and 12 hours."),
				flush: ""
			}, createSlots({
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KSelect), {
						modelValue: filter.value,
						"onUpdate:modelValue": ($event) => filter.value = $event,
						class: "k-hook__filter",
						options: FILTERS,
						"aria-label": unref(__)("Show")
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KSelect), {
						modelValue: filter.value,
						"onUpdate:modelValue": ($event) => filter.value = $event,
						class: "k-hook__filter",
						options: FILTERS,
						"aria-label": unref(__)("Show")
					}, null, 8, [
						"modelValue",
						"onUpdate:modelValue",
						"aria-label"
					])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: [
							{
								key: "event",
								label: unref(__)("Event")
							},
							{
								key: "status",
								label: unref(__)("Result")
							},
							{
								key: "created_at",
								label: unref(__)("Sent")
							},
							{
								key: "actions",
								label: "",
								align: "end",
								width: "1%"
							}
						],
						rows: deliveries.value,
						loading: loadingLog.value,
						empty: filter.value ? unref(__)("No deliveries like that.") : unref(__)("Nothing sent yet"),
						"empty-text": filter.value ? "" : unref(__)("Use Send a test to try it.")
					}, {
						"cell-event": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<button type="button" class="k-link-btn" data-v-0b517769${_scopeId}><code data-v-0b517769${_scopeId}>${ssrInterpolate(row.event)}</code></button><div class="k-muted k-small" data-v-0b517769${_scopeId}>${ssrInterpolate(attemptsText(row.attempts))}`);
								if (row.duration_ms !== null) _push(`<!--[--> · ${ssrInterpolate(row.duration_ms)} ms<!--]-->`);
								else _push(`<!---->`);
								_push(`</div>`);
							} else return [createVNode("button", {
								type: "button",
								class: "k-link-btn",
								onClick: ($event) => shown.value = row
							}, [createVNode("code", null, toDisplayString(row.event), 1)], 8, ["onClick"]), createVNode("div", { class: "k-muted k-small" }, [createTextVNode(toDisplayString(attemptsText(row.attempts)), 1), row.duration_ms !== null ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(row.duration_ms) + " ms", 1)], 64)) : createCommentVNode("v-if", true)])];
						}),
						"cell-status": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(ssrRenderComponent(unref(KBadge), {
									tone: (STATUS[row.status] ?? ["neutral"])[0],
									dot: ""
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) {
											_push(`${ssrInterpolate((STATUS[row.status] ?? [null, row.status])[1])}`);
											if (row.response_status) _push(`<!--[--> · ${ssrInterpolate(row.response_status)}<!--]-->`);
											else _push(`<!---->`);
										} else return [createTextVNode(toDisplayString((STATUS[row.status] ?? [null, row.status])[1]), 1), row.response_status ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(row.response_status), 1)], 64)) : createCommentVNode("v-if", true)];
									}),
									_: 2
								}, _parent, _scopeId));
								if (row.status === "retrying" && row.next_attempt_at) {
									_push(`<div class="k-muted k-small" data-v-0b517769${_scopeId}>${ssrInterpolate(unref(__)("Next try"))} `);
									_push(ssrRenderComponent(unref(KTime), { value: row.next_attempt_at }, null, _parent, _scopeId));
									_push(`</div>`);
								} else if (row.error) _push(`<div class="k-muted k-small k-truncate" data-v-0b517769${_scopeId}>${ssrInterpolate(row.error)}</div>`);
								else _push(`<!---->`);
							} else return [createVNode(unref(KBadge), {
								tone: (STATUS[row.status] ?? ["neutral"])[0],
								dot: ""
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString((STATUS[row.status] ?? [null, row.status])[1]), 1), row.response_status ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(row.response_status), 1)], 64)) : createCommentVNode("v-if", true)]),
								_: 2
							}, 1032, ["tone"]), row.status === "retrying" && row.next_attempt_at ? (openBlock(), createBlock("div", {
								key: 0,
								class: "k-muted k-small"
							}, [createTextVNode(toDisplayString(unref(__)("Next try")) + " ", 1), createVNode(unref(KTime), { value: row.next_attempt_at }, null, 8, ["value"])])) : row.error ? (openBlock(), createBlock("div", {
								key: 1,
								class: "k-muted k-small k-truncate"
							}, toDisplayString(row.error), 1)) : createCommentVNode("v-if", true)];
						}),
						"cell-created_at": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.created_at }, null, _parent, _scopeId));
							else return [createVNode(unref(KTime), { value: row.created_at }, null, 8, ["value"])];
						}),
						"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "arrow-clockwise",
								disabled: busy.value,
								"aria-label": unref(__)("Send again"),
								title: unref(__)("Send again"),
								onClick: ($event) => redeliver(row)
							}, null, _parent, _scopeId));
							else return [createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "arrow-clockwise",
								disabled: busy.value,
								"aria-label": unref(__)("Send again"),
								title: unref(__)("Send again"),
								onClick: ($event) => redeliver(row)
							}, null, 8, [
								"disabled",
								"aria-label",
								"title",
								"onClick"
							])];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: [
							{
								key: "event",
								label: unref(__)("Event")
							},
							{
								key: "status",
								label: unref(__)("Result")
							},
							{
								key: "created_at",
								label: unref(__)("Sent")
							},
							{
								key: "actions",
								label: "",
								align: "end",
								width: "1%"
							}
						],
						rows: deliveries.value,
						loading: loadingLog.value,
						empty: filter.value ? unref(__)("No deliveries like that.") : unref(__)("Nothing sent yet"),
						"empty-text": filter.value ? "" : unref(__)("Use Send a test to try it.")
					}, {
						"cell-event": withCtx(({ row }) => [createVNode("button", {
							type: "button",
							class: "k-link-btn",
							onClick: ($event) => shown.value = row
						}, [createVNode("code", null, toDisplayString(row.event), 1)], 8, ["onClick"]), createVNode("div", { class: "k-muted k-small" }, [createTextVNode(toDisplayString(attemptsText(row.attempts)), 1), row.duration_ms !== null ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(row.duration_ms) + " ms", 1)], 64)) : createCommentVNode("v-if", true)])]),
						"cell-status": withCtx(({ row }) => [createVNode(unref(KBadge), {
							tone: (STATUS[row.status] ?? ["neutral"])[0],
							dot: ""
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString((STATUS[row.status] ?? [null, row.status])[1]), 1), row.response_status ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(row.response_status), 1)], 64)) : createCommentVNode("v-if", true)]),
							_: 2
						}, 1032, ["tone"]), row.status === "retrying" && row.next_attempt_at ? (openBlock(), createBlock("div", {
							key: 0,
							class: "k-muted k-small"
						}, [createTextVNode(toDisplayString(unref(__)("Next try")) + " ", 1), createVNode(unref(KTime), { value: row.next_attempt_at }, null, 8, ["value"])])) : row.error ? (openBlock(), createBlock("div", {
							key: 1,
							class: "k-muted k-small k-truncate"
						}, toDisplayString(row.error), 1)) : createCommentVNode("v-if", true)]),
						"cell-created_at": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.created_at }, null, 8, ["value"])]),
						"cell-actions": withCtx(({ row }) => [createVNode(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "arrow-clockwise",
							disabled: busy.value,
							"aria-label": unref(__)("Send again"),
							title: unref(__)("Send again"),
							onClick: ($event) => redeliver(row)
						}, null, 8, [
							"disabled",
							"aria-label",
							"title",
							"onClick"
						])]),
						_: 1
					}, 8, [
						"columns",
						"rows",
						"loading",
						"empty",
						"empty-text"
					])];
				}),
				_: 2
			}, [nextBefore.value ? {
				name: "footer",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						loading: loadingLog.value,
						onClick: ($event) => load(true)
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Show older"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Show older")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						size: "sm",
						loading: loadingLog.value,
						onClick: ($event) => load(true)
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Show older")), 1)]),
						_: 1
					}, 8, ["loading", "onClick"])];
				}),
				key: "0"
			} : void 0]), _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: !!shown.value,
				title: shown.value ? `${shown.value.event} · ${unref(formatDateTime)(shown.value.created_at)}` : "",
				size: "lg",
				onClose: ($event) => shown.value = null
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => shown.value = null }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Close"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Close")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "arrow-clockwise",
							loading: busy.value,
							onClick: ($event) => redeliver(shown.value)
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Send again"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Send again")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => shown.value = null }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Close")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						variant: "primary",
						icon: "arrow-clockwise",
						loading: busy.value,
						onClick: ($event) => redeliver(shown.value)
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Send again")), 1)]),
						_: 1
					}, 8, ["loading", "onClick"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (shown.value) {
							_push(`<!--[--><dl class="k-hook__facts" data-v-0b517769${_scopeId}><dt data-v-0b517769${_scopeId}>${ssrInterpolate(unref(__)("Result"))}</dt><dd data-v-0b517769${_scopeId}>`);
							_push(ssrRenderComponent(unref(KBadge), {
								tone: (STATUS[shown.value.status] ?? ["neutral"])[0],
								dot: ""
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate((STATUS[shown.value.status] ?? [null, shown.value.status])[1])}`);
									else return [createTextVNode(toDisplayString((STATUS[shown.value.status] ?? [null, shown.value.status])[1]), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</dd><dt data-v-0b517769${_scopeId}>${ssrInterpolate(unref(__)("Answer"))}</dt><dd data-v-0b517769${_scopeId}>${ssrInterpolate(shown.value.response_status ?? "—")}`);
							if (shown.value.duration_ms !== null) _push(`<!--[-->${ssrInterpolate(unref(__)("in {ms} ms", { ms: shown.value.duration_ms }))}<!--]-->`);
							else _push(`<!---->`);
							_push(`</dd><dt data-v-0b517769${_scopeId}>${ssrInterpolate(unref(__)("Event id"))}</dt><dd data-v-0b517769${_scopeId}><code data-v-0b517769${_scopeId}>${ssrInterpolate(shown.value.event_id)}</code></dd>`);
							if (shown.value.error) _push(`<!--[--><dt data-v-0b517769${_scopeId}>${ssrInterpolate(unref(__)("Problem"))}</dt><dd data-v-0b517769${_scopeId}>${ssrInterpolate(shown.value.error)}</dd><!--]-->`);
							else _push(`<!---->`);
							_push(`</dl><h3 class="k-hook__h" data-v-0b517769${_scopeId}>${ssrInterpolate(unref(__)("Sent"))}</h3><pre class="k-pre k-hook__pre" data-v-0b517769${_scopeId}>${ssrInterpolate(pretty(shown.value.payload))}</pre>`);
							if (shown.value.response_body) _push(`<!--[--><h3 class="k-hook__h" data-v-0b517769${_scopeId}>${ssrInterpolate(unref(__)("Answered (start, secrets hidden)"))}</h3><pre class="k-pre k-hook__pre" data-v-0b517769${_scopeId}>${ssrInterpolate(shown.value.response_body)}</pre><!--]-->`);
							else _push(`<!---->`);
							_push(`<!--]-->`);
						} else _push(`<!---->`);
					} else return [shown.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
						createVNode("dl", { class: "k-hook__facts" }, [
							createVNode("dt", null, toDisplayString(unref(__)("Result")), 1),
							createVNode("dd", null, [createVNode(unref(KBadge), {
								tone: (STATUS[shown.value.status] ?? ["neutral"])[0],
								dot: ""
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString((STATUS[shown.value.status] ?? [null, shown.value.status])[1]), 1)]),
								_: 1
							}, 8, ["tone"])]),
							createVNode("dt", null, toDisplayString(unref(__)("Answer")), 1),
							createVNode("dd", null, [createTextVNode(toDisplayString(shown.value.response_status ?? "—"), 1), shown.value.duration_ms !== null ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(toDisplayString(unref(__)("in {ms} ms", { ms: shown.value.duration_ms })), 1)], 64)) : createCommentVNode("v-if", true)]),
							createVNode("dt", null, toDisplayString(unref(__)("Event id")), 1),
							createVNode("dd", null, [createVNode("code", null, toDisplayString(shown.value.event_id), 1)]),
							shown.value.error ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode("dt", null, toDisplayString(unref(__)("Problem")), 1), createVNode("dd", null, toDisplayString(shown.value.error), 1)], 64)) : createCommentVNode("v-if", true)
						]),
						createVNode("h3", { class: "k-hook__h" }, toDisplayString(unref(__)("Sent")), 1),
						createVNode("pre", { class: "k-pre k-hook__pre" }, toDisplayString(pretty(shown.value.payload)), 1),
						shown.value.response_body ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode("h3", { class: "k-hook__h" }, toDisplayString(unref(__)("Answered (start, secrets hidden)")), 1), createVNode("pre", { class: "k-pre k-hook__pre" }, toDisplayString(shown.value.response_body), 1)], 64)) : createCommentVNode("v-if", true)
					], 64)) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(_sfc_main$1, {
				secret: secret.value,
				rotated: "",
				onDone: ($event) => secret.value = null
			}, null, _parent));
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/webhooks/Edit.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Edit_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-0b517769"]]);
export { Edit_default as default };
