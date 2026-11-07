import { n as EventPicker_default, t as _sfc_main$1 } from "../../../../chunks/SecretModal-C63Bn-b7.mjs";
import { Fragment, createBlock, createTextVNode, createVNode, openBlock, reactive, ref, toDisplayString, unref, useSSRContext, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { Link, useRouter } from "@kestello/app";
import { ApiError, __, notify, useApi, useResource } from "@kestello/sdk";
import { KBadge, KButton, KCard, KEmpty, KField, KInput, KModal, KPageHeader, KTable, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/system/webhooks/Index.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Index",
	__ssrInlineRender: true,
	setup(__props) {
		const api = useApi();
		const router = useRouter();
		const hooks = useResource("/webhooks");
		const catalog = useResource("/webhooks/events");
		const creating = ref(false);
		const saving = ref(false);
		const errors = ref({});
		const form = reactive({
			name: "",
			url: "",
			events: []
		});
		const made = ref(null);
		function open() {
			Object.assign(form, {
				name: "",
				url: "",
				events: []
			});
			errors.value = {};
			creating.value = true;
		}
		async function create() {
			saving.value = true;
			errors.value = {};
			try {
				const { data } = await api.post("/webhooks", { ...form });
				creating.value = false;
				made.value = data;
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		function stored() {
			const id = made.value.id;
			made.value = null;
			router.visit(`/admin/system/webhooks/${id}`);
		}
		const STATUS = {
			succeeded: ["success", __("Delivered")],
			retrying: ["warning", __("Retrying")],
			failed: ["danger", __("Failed")],
			pending: ["neutral", __("Waiting")],
			sending: ["info", __("Sending")]
		};
		const host = (url) => {
			try {
				return new URL(url).host;
			} catch {
				return url;
			}
		};
		const eventCount = (w) => w.events.includes("*") ? __("Every event") : __("{n, plural, one {# event} other {# events}}", { n: w.events.length });
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Webhooks"),
				description: unref(__)("Tell other services when something happens on the site: a post goes live, a form is sent, someone signs up. Each message is signed so the receiver can check it came from here.")
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						variant: "primary",
						icon: "plus",
						onClick: open
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Add webhook"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Add webhook")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						variant: "primary",
						icon: "plus",
						onClick: open
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Add webhook")), 1)]),
						_: 1
					})];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KCard), { flush: "" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (unref(hooks).loading.value || unref(hooks).data.value?.length) _push(ssrRenderComponent(unref(KTable), {
							columns: [
								{
									key: "name",
									label: unref(__)("Webhook")
								},
								{
									key: "events",
									label: unref(__)("Sends")
								},
								{
									key: "last",
									label: unref(__)("Last delivery")
								},
								{
									key: "state",
									label: unref(__)("State"),
									align: "end"
								}
							],
							rows: unref(hooks).data.value ?? [],
							loading: unref(hooks).loading.value
						}, {
							"cell-name": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(ssrRenderComponent(unref(Link), {
										href: `/admin/system/webhooks/${row.id}`,
										class: "k-link"
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`<strong${_scopeId}>${ssrInterpolate(row.name)}</strong>`);
											else return [createVNode("strong", null, toDisplayString(row.name), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									_push(`<div class="k-muted k-small k-truncate"${_scopeId}>${ssrInterpolate(host(row.url))}</div>`);
								} else return [createVNode(unref(Link), {
									href: `/admin/system/webhooks/${row.id}`,
									class: "k-link"
								}, {
									default: withCtx(() => [createVNode("strong", null, toDisplayString(row.name), 1)]),
									_: 2
								}, 1032, ["href"]), createVNode("div", { class: "k-muted k-small k-truncate" }, toDisplayString(host(row.url)), 1)];
							}),
							"cell-events": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`<span class="k-small"${_scopeId}>${ssrInterpolate(eventCount(row))}</span>`);
								else return [createVNode("span", { class: "k-small" }, toDisplayString(eventCount(row)), 1)];
							}),
							"cell-last": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (row.last_delivery) {
										_push(`<!--[-->`);
										_push(ssrRenderComponent(unref(KBadge), {
											tone: (STATUS[row.last_delivery.status] ?? ["neutral"])[0],
											dot: ""
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate((STATUS[row.last_delivery.status] ?? [null, row.last_delivery.status])[1])}`);
												else return [createTextVNode(toDisplayString((STATUS[row.last_delivery.status] ?? [null, row.last_delivery.status])[1]), 1)];
											}),
											_: 2
										}, _parent, _scopeId));
										_push(`<div class="k-muted k-small"${_scopeId}><code${_scopeId}>${ssrInterpolate(row.last_delivery.event)}</code> · `);
										_push(ssrRenderComponent(unref(KTime), { value: row.last_delivery.created_at }, null, _parent, _scopeId));
										_push(`</div><!--]-->`);
									} else _push(`<span class="k-muted k-small"${_scopeId}>${ssrInterpolate(unref(__)("Nothing sent yet"))}</span>`);
								} else return [row.last_delivery ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode(unref(KBadge), {
									tone: (STATUS[row.last_delivery.status] ?? ["neutral"])[0],
									dot: ""
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString((STATUS[row.last_delivery.status] ?? [null, row.last_delivery.status])[1]), 1)]),
									_: 2
								}, 1032, ["tone"]), createVNode("div", { class: "k-muted k-small" }, [
									createVNode("code", null, toDisplayString(row.last_delivery.event), 1),
									createTextVNode(" · "),
									createVNode(unref(KTime), { value: row.last_delivery.created_at }, null, 8, ["value"])
								])], 64)) : (openBlock(), createBlock("span", {
									key: 1,
									class: "k-muted k-small"
								}, toDisplayString(unref(__)("Nothing sent yet")), 1))];
							}),
							"cell-state": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (row.active) _push(ssrRenderComponent(unref(KBadge), { tone: "success" }, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("On"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("On")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									else _push(ssrRenderComponent(unref(KBadge), {
										tone: row.disabled_reason ? "danger" : "neutral",
										title: row.disabled_reason ?? ""
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(row.disabled_reason ? unref(__)("Turned off after failures") : unref(__)("Off"))}`);
											else return [createTextVNode(toDisplayString(row.disabled_reason ? unref(__)("Turned off after failures") : unref(__)("Off")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
								} else return [row.active ? (openBlock(), createBlock(unref(KBadge), {
									key: 0,
									tone: "success"
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("On")), 1)]),
									_: 1
								})) : (openBlock(), createBlock(unref(KBadge), {
									key: 1,
									tone: row.disabled_reason ? "danger" : "neutral",
									title: row.disabled_reason ?? ""
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(row.disabled_reason ? unref(__)("Turned off after failures") : unref(__)("Off")), 1)]),
									_: 2
								}, 1032, ["tone", "title"]))];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(ssrRenderComponent(unref(KEmpty), {
							icon: "webhooks-logo",
							title: unref(__)("No webhooks yet")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Add one with the address of the service that should hear about changes, such as a build hook that redeploys a static site, or an automation tool."))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Add one with the address of the service that should hear about changes, such as a build hook that redeploys a static site, or an automation tool.")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [unref(hooks).loading.value || unref(hooks).data.value?.length ? (openBlock(), createBlock(unref(KTable), {
						key: 0,
						columns: [
							{
								key: "name",
								label: unref(__)("Webhook")
							},
							{
								key: "events",
								label: unref(__)("Sends")
							},
							{
								key: "last",
								label: unref(__)("Last delivery")
							},
							{
								key: "state",
								label: unref(__)("State"),
								align: "end"
							}
						],
						rows: unref(hooks).data.value ?? [],
						loading: unref(hooks).loading.value
					}, {
						"cell-name": withCtx(({ row }) => [createVNode(unref(Link), {
							href: `/admin/system/webhooks/${row.id}`,
							class: "k-link"
						}, {
							default: withCtx(() => [createVNode("strong", null, toDisplayString(row.name), 1)]),
							_: 2
						}, 1032, ["href"]), createVNode("div", { class: "k-muted k-small k-truncate" }, toDisplayString(host(row.url)), 1)]),
						"cell-events": withCtx(({ row }) => [createVNode("span", { class: "k-small" }, toDisplayString(eventCount(row)), 1)]),
						"cell-last": withCtx(({ row }) => [row.last_delivery ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode(unref(KBadge), {
							tone: (STATUS[row.last_delivery.status] ?? ["neutral"])[0],
							dot: ""
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString((STATUS[row.last_delivery.status] ?? [null, row.last_delivery.status])[1]), 1)]),
							_: 2
						}, 1032, ["tone"]), createVNode("div", { class: "k-muted k-small" }, [
							createVNode("code", null, toDisplayString(row.last_delivery.event), 1),
							createTextVNode(" · "),
							createVNode(unref(KTime), { value: row.last_delivery.created_at }, null, 8, ["value"])
						])], 64)) : (openBlock(), createBlock("span", {
							key: 1,
							class: "k-muted k-small"
						}, toDisplayString(unref(__)("Nothing sent yet")), 1))]),
						"cell-state": withCtx(({ row }) => [row.active ? (openBlock(), createBlock(unref(KBadge), {
							key: 0,
							tone: "success"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("On")), 1)]),
							_: 1
						})) : (openBlock(), createBlock(unref(KBadge), {
							key: 1,
							tone: row.disabled_reason ? "danger" : "neutral",
							title: row.disabled_reason ?? ""
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.disabled_reason ? unref(__)("Turned off after failures") : unref(__)("Off")), 1)]),
							_: 2
						}, 1032, ["tone", "title"]))]),
						_: 1
					}, 8, [
						"columns",
						"rows",
						"loading"
					])) : (openBlock(), createBlock(unref(KEmpty), {
						key: 1,
						icon: "webhooks-logo",
						title: unref(__)("No webhooks yet")
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Add one with the address of the service that should hear about changes, such as a build hook that redeploys a static site, or an automation tool.")), 1)]),
						_: 1
					}, 8, ["title"]))];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: creating.value,
				title: unref(__)("Add a webhook"),
				size: "lg",
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
							form: "k-hook-create",
							variant: "primary",
							loading: saving.value,
							disabled: !form.name.trim() || !form.url.trim() || !form.events.length
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Add webhook"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Add webhook")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => creating.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						type: "submit",
						form: "k-hook-create",
						variant: "primary",
						loading: saving.value,
						disabled: !form.name.trim() || !form.url.trim() || !form.events.length
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Add webhook")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-hook-create" class="k-form"${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Name"),
							help: unref(__)("So you recognise it later, such as 'Netlify rebuild'."),
							error: errors.value.name ?? "",
							required: ""
						}, {
							default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.name,
									"onUpdate:modelValue": ($event) => form.name = $event,
									maxlength: "100",
									autocomplete: "off",
									invalid
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
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
							help: unref(__)("The https:// URL the receiving service gave you."),
							error: errors.value.url ?? "",
							required: ""
						}, {
							default: withCtx(({ id, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.url,
									"onUpdate:modelValue": ($event) => form.url = $event,
									type: "url",
									inputmode: "url",
									autocomplete: "off",
									spellcheck: "false",
									invalid
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
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
						id: "k-hook-create",
						class: "k-form",
						onSubmit: withModifiers(create, ["prevent"])
					}, [
						createVNode(unref(KField), {
							label: unref(__)("Name"),
							help: unref(__)("So you recognise it later, such as 'Netlify rebuild'."),
							error: errors.value.name ?? "",
							required: ""
						}, {
							default: withCtx(({ id, invalid }) => [createVNode(unref(KInput), {
								id,
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
						}, 8, [
							"label",
							"help",
							"error"
						]),
						createVNode(unref(KField), {
							label: unref(__)("Address"),
							help: unref(__)("The https:// URL the receiving service gave you."),
							error: errors.value.url ?? "",
							required: ""
						}, {
							default: withCtx(({ id, invalid }) => [createVNode(unref(KInput), {
								id,
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
						}, 8, [
							"label",
							"help",
							"error"
						]),
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
			_push(ssrRenderComponent(_sfc_main$1, {
				secret: made.value?.secret ?? null,
				onDone: stored
			}, null, _parent));
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/webhooks/Index.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
