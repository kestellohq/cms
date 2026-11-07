import { t as _plugin_vue_export_helper_default } from "../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { i as linkFromInput, r as blockCommand } from "../chunks/canvas-protocol-BnvfVFqm.mjs";
import { a as withValue, i as effective, n as SCREENS, t as PROPS } from "../chunks/block-design-BwxGdv-2.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, inject, isRef, mergeModels, mergeProps, nextTick, onBeforeUnmount, onErrorCaptured, onMounted, openBlock, provide, reactive, ref, renderList, renderSlot, resolveDynamicComponent, shallowRef, toDisplayString, toRaw, unref, useId, useModel, useSSRContext, vModelText, watch, withCtx, withDirectives, withModifiers } from "vue";
import { ssrGetDynamicModelProps, ssrIncludeBooleanAttr, ssrInterpolate, ssrLooseContain, ssrLooseEqual, ssrRenderAttr, ssrRenderAttrs, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderSlot, ssrRenderStyle, ssrRenderTeleport, ssrRenderVNode } from "vue/server-renderer";
import { Head, KImage, Link, useComponentLoader, useRouter } from "@kestello/app";
import { ApiError, __, dialogs, dismissToast, formatBytes, formatDateTime, formatRelative, notify, toasts, uploadMedia, useAdmin, useApi, useExtensions, useReplacement, useResource } from "@kestello/sdk";
// \0rolldown/runtime.js
var __defProp = Object.defineProperty;
var __exportAll = (all, no_symbols) => {
	let target = {};
	for (var name in all) __defProp(target, name, {
		get: all[name],
		enumerable: true
	});
	if (!no_symbols) __defProp(target, Symbol.toStringTag, { value: "Module" });
	return target;
};
// core/resources/js/ui/KIcon.vue
var _sfc_main$14 = {
	__name: "KIcon",
	__ssrInlineRender: true,
	props: {
		name: {
			type: String,
			required: true
		},
		label: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<i${ssrRenderAttrs(mergeProps({
				class: ["ph k-icon", `ph-${__props.name}`],
				"aria-hidden": __props.label ? void 0 : "true",
				"aria-label": __props.label || void 0,
				role: __props.label ? "img" : void 0
			}, _attrs))}></i>`);
		};
	}
};
var _sfc_setup$53 = _sfc_main$14.setup;
_sfc_main$14.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KIcon.vue");
	return _sfc_setup$53 ? _sfc_setup$53(props, ctx) : void 0;
};
// core/resources/js/ui/KAlert.vue
var _sfc_main = {
	__name: "KAlert",
	__ssrInlineRender: true,
	props: {
		tone: {
			type: String,
			default: "info"
		},
		title: {
			type: String,
			default: ""
		},
		dismissible: Boolean
	},
	emits: ["dismiss"],
	setup(__props, { emit: __emit }) {
		const props = __props;
		const ICONS = {
			info: "info",
			success: "check-circle",
			warning: "warning",
			danger: "warning-octagon"
		};
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				class: ["k-alert", `k-alert--${__props.tone}`],
				role: __props.tone === "danger" ? "alert" : "status"
			}, _attrs))}>`);
			_push(ssrRenderComponent(_sfc_main$14, { name: ICONS[props.tone] ?? "info" }, null, _parent));
			_push(`<div class="k-alert__body">`);
			if (__props.title) _push(`<strong class="k-alert__title">${ssrInterpolate(__props.title)}</strong>`);
			else _push(`<!---->`);
			_push(`<div>`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div></div>`);
			if (__props.dismissible) {
				_push(`<button type="button" class="k-alert__close"${ssrRenderAttr("aria-label", unref(__)("Dismiss"))}>`);
				_push(ssrRenderComponent(_sfc_main$14, { name: "x" }, null, _parent));
				_push(`</button>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$52 = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KAlert.vue");
	return _sfc_setup$52 ? _sfc_setup$52(props, ctx) : void 0;
};
// core/resources/js/ui/KBadge.vue
var _sfc_main$3 = {
	__name: "KBadge",
	__ssrInlineRender: true,
	props: {
		tone: {
			type: String,
			default: "neutral"
		},
		dot: Boolean
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<span${ssrRenderAttrs(mergeProps({ class: ["k-badge", `k-badge--${__props.tone}`] }, _attrs))}>`);
			if (__props.dot) _push(`<span class="k-badge__dot" aria-hidden="true"></span>`);
			else _push(`<!---->`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</span>`);
		};
	}
};
var _sfc_setup$51 = _sfc_main$3.setup;
_sfc_main$3.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KBadge.vue");
	return _sfc_setup$51 ? _sfc_setup$51(props, ctx) : void 0;
};
// core/resources/js/ui/KSpinner.vue
var _sfc_main$27 = {
	__name: "KSpinner",
	__ssrInlineRender: true,
	props: {
		label: {
			type: String,
			default: "Loading"
		},
		size: {
			type: String,
			default: "md"
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<span${ssrRenderAttrs(mergeProps({
				class: ["k-spinner", `k-spinner--${__props.size}`],
				role: "status"
			}, _attrs))}><span class="k-sr-only">${ssrInterpolate(__props.label)}</span></span>`);
		};
	}
};
var _sfc_setup$50 = _sfc_main$27.setup;
_sfc_main$27.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KSpinner.vue");
	return _sfc_setup$50 ? _sfc_setup$50(props, ctx) : void 0;
};
// core/resources/js/ui/KButton.vue
var _sfc_main$6 = {
	__name: "KButton",
	__ssrInlineRender: true,
	props: {
		variant: {
			type: String,
			default: "secondary"
		},
		size: {
			type: String,
			default: "md"
		},
		type: {
			type: String,
			default: "button"
		},
		href: {
			type: String,
			default: null
		},
		icon: {
			type: String,
			default: null
		},
		loading: Boolean,
		disabled: Boolean,
		block: Boolean
	},
	setup(__props) {
		const props = __props;
		const classes = computed(() => [
			"k-btn",
			`k-btn--${props.variant}`,
			`k-btn--${props.size}`,
			{
				"k-btn--block": props.block,
				"is-loading": props.loading
			}
		]);
		const external = computed(() => props.href !== null && /^https?:\/\//.test(props.href));
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.href && external.value) {
				_push(`<a${ssrRenderAttrs(mergeProps({
					href: __props.href,
					class: classes.value,
					target: "_blank",
					rel: "noopener noreferrer"
				}, _attrs))}>`);
				if (__props.icon) _push(ssrRenderComponent(_sfc_main$14, { name: __props.icon }, null, _parent));
				else _push(`<!---->`);
				_push(`<span>`);
				ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
				_push(`</span></a>`);
			} else if (__props.href) _push(ssrRenderComponent(unref(Link), mergeProps({
				href: __props.href,
				class: classes.value
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (__props.icon) _push(ssrRenderComponent(_sfc_main$14, { name: __props.icon }, null, _parent, _scopeId));
						else _push(`<!---->`);
						_push(`<span${_scopeId}>`);
						ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent, _scopeId);
						_push(`</span>`);
					} else return [__props.icon ? (openBlock(), createBlock(_sfc_main$14, {
						key: 0,
						name: __props.icon
					}, null, 8, ["name"])) : createCommentVNode("v-if", true), createVNode("span", null, [renderSlot(_ctx.$slots, "default")])];
				}),
				_: 3
			}, _parent));
			else {
				_push(`<button${ssrRenderAttrs(mergeProps({
					type: __props.type,
					class: classes.value,
					disabled: __props.disabled || __props.loading,
					"aria-busy": __props.loading ? "true" : void 0
				}, _attrs))}>`);
				if (__props.loading) _push(ssrRenderComponent(_sfc_main$27, {
					size: "sm",
					label: ""
				}, null, _parent));
				else if (__props.icon) _push(ssrRenderComponent(_sfc_main$14, { name: __props.icon }, null, _parent));
				else _push(`<!---->`);
				if (_ctx.$slots.default) {
					_push(`<span>`);
					ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
					_push(`</span>`);
				} else _push(`<!---->`);
				_push(`</button>`);
			}
		};
	}
};
var _sfc_setup$49 = _sfc_main$6.setup;
_sfc_main$6.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KButton.vue");
	return _sfc_setup$49 ? _sfc_setup$49(props, ctx) : void 0;
};
// core/resources/js/ui/KCard.vue
var _sfc_main$7 = {
	__name: "KCard",
	__ssrInlineRender: true,
	props: {
		title: {
			type: String,
			default: ""
		},
		description: {
			type: String,
			default: ""
		},
		flush: Boolean,
		coord: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		/** A cell on the sheet. `coord` adds a grid reference (A2) before the title. */
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<section${ssrRenderAttrs(mergeProps({ class: "k-card" }, _attrs))}>`);
			if (__props.title || _ctx.$slots.title || _ctx.$slots.actions) {
				_push(`<header class="k-card__header"><div><h2 class="k-card__title">`);
				if (__props.coord) _push(`<span class="k-card__coord" aria-hidden="true">${ssrInterpolate(__props.coord)}</span>`);
				else _push(`<!---->`);
				ssrRenderSlot(_ctx.$slots, "title", {}, () => {
					_push(`${ssrInterpolate(__props.title)}`);
				}, _push, _parent);
				_push(`</h2>`);
				if (__props.description) _push(`<p class="k-card__description">${ssrInterpolate(__props.description)}</p>`);
				else _push(`<!---->`);
				_push(`</div>`);
				if (_ctx.$slots.actions) {
					_push(`<div class="k-card__actions">`);
					ssrRenderSlot(_ctx.$slots, "actions", {}, null, _push, _parent);
					_push(`</div>`);
				} else _push(`<!---->`);
				_push(`</header>`);
			} else _push(`<!---->`);
			_push(`<div class="${ssrRenderClass(__props.flush ? "k-card__flush" : "k-card__body")}">`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div>`);
			if (_ctx.$slots.footer) {
				_push(`<footer class="k-card__footer">`);
				ssrRenderSlot(_ctx.$slots, "footer", {}, null, _push, _parent);
				_push(`</footer>`);
			} else _push(`<!---->`);
			_push(`</section>`);
		};
	}
};
var _sfc_setup$48 = _sfc_main$7.setup;
_sfc_main$7.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KCard.vue");
	return _sfc_setup$48 ? _sfc_setup$48(props, ctx) : void 0;
};
// core/resources/js/ui/KField.vue
var _sfc_main$12 = {
	__name: "KField",
	__ssrInlineRender: true,
	props: {
		label: {
			type: String,
			required: true
		},
		help: {
			type: String,
			default: ""
		},
		error: {
			type: String,
			default: ""
		},
		required: Boolean,
		id: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		const props = __props;
		const auto = useId();
		const fieldId = computed(() => props.id || `k-field-${auto}`);
		const describedBy = computed(() => [props.help ? `${fieldId.value}-help` : "", props.error ? `${fieldId.value}-error` : ""].filter(Boolean).join(" ") || void 0);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: ["k-field", { "has-error": __props.error }] }, _attrs))}><label class="k-field__label"${ssrRenderAttr("for", fieldId.value)}>${ssrInterpolate(__props.label)}`);
			if (__props.required) _push(`<span class="k-field__required" aria-hidden="true"> *</span>`);
			else _push(`<!---->`);
			_push(`</label>`);
			ssrRenderSlot(_ctx.$slots, "default", {
				id: fieldId.value,
				describedBy: describedBy.value,
				invalid: !!__props.error
			}, null, _push, _parent);
			if (__props.help) _push(`<p${ssrRenderAttr("id", `${fieldId.value}-help`)} class="k-field__help">${ssrInterpolate(__props.help)}</p>`);
			else _push(`<!---->`);
			if (__props.error) _push(`<p${ssrRenderAttr("id", `${fieldId.value}-error`)} class="k-field__error">${ssrInterpolate(__props.error)}</p>`);
			else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$47 = _sfc_main$12.setup;
_sfc_main$12.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KField.vue");
	return _sfc_setup$47 ? _sfc_setup$47(props, ctx) : void 0;
};
// core/resources/js/ui/KInput.vue
var _sfc_main$15 = {
	__name: "KInput",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		type: {
			type: String,
			default: "text"
		},
		invalid: Boolean
	}, {
		"modelValue": {
			type: [String, Number],
			default: ""
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		return (_ctx, _push, _parent, _attrs) => {
			let _temp0;
			_push(`<input${ssrRenderAttrs((_temp0 = mergeProps({
				type: __props.type,
				class: "k-input",
				"aria-invalid": __props.invalid ? "true" : void 0
			}, _attrs), mergeProps(_temp0, ssrGetDynamicModelProps(_temp0, model.value))))}>`);
		};
	}
};
var _sfc_setup$46 = _sfc_main$15.setup;
_sfc_main$15.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KInput.vue");
	return _sfc_setup$46 ? _sfc_setup$46(props, ctx) : void 0;
};
// core/resources/js/ui/KModal.vue
var FOCUSABLE = "a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\"-1\"])";
var _sfc_main$19 = {
	__name: "KModal",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		title: {
			type: String,
			required: true
		},
		size: {
			type: String,
			default: "md"
		},
		dismissible: {
			type: Boolean,
			default: true
		}
	}, {
		"open": {
			type: Boolean,
			default: false
		},
		"openModifiers": {}
	}),
	emits: /*@__PURE__*/ mergeModels(["close"], ["update:open"]),
	setup(__props, { emit: __emit }) {
		const open = useModel(__props, "open");
		const panel = ref(null);
		const titleId = `k-modal-${useId()}`;
		let previous = null;
		watch(open, async (value) => {
			if (value) {
				previous = document.activeElement;
				document.body.classList.add("k-modal-open");
				await nextTick();
				const field = panel.value?.querySelector(".k-modal__body :is(input:not([type=hidden]):not(:disabled), select:not(:disabled), textarea:not(:disabled))");
				(panel.value?.querySelector("[autofocus]") ?? field ?? panel.value?.querySelector(FOCUSABLE) ?? panel.value)?.focus();
			} else {
				document.body.classList.remove("k-modal-open");
				previous?.focus?.();
			}
		}, { immediate: true });
		onBeforeUnmount(() => document.body.classList.remove("k-modal-open"));
		return (_ctx, _push, _parent, _attrs) => {
			ssrRenderTeleport(_push, (_push) => {
				if (open.value) {
					_push(`<div class="k-modal"><div class="k-modal__backdrop"></div><div class="${ssrRenderClass([`k-modal__panel--${__props.size}`, "k-modal__panel"])}" role="dialog" aria-modal="true"${ssrRenderAttr("aria-labelledby", titleId)} tabindex="-1"><header class="k-modal__header"><h2${ssrRenderAttr("id", titleId)} class="k-modal__title">${ssrInterpolate(__props.title)}</h2>`);
					if (__props.dismissible) {
						_push(`<button type="button" class="k-modal__close"${ssrRenderAttr("aria-label", unref(__)("Close"))}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "x" }, null, _parent));
						_push(`</button>`);
					} else _push(`<!---->`);
					_push(`</header><div class="k-modal__body">`);
					ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
					_push(`</div>`);
					if (_ctx.$slots.footer) {
						_push(`<footer class="k-modal__footer">`);
						ssrRenderSlot(_ctx.$slots, "footer", {}, null, _push, _parent);
						_push(`</footer>`);
					} else _push(`<!---->`);
					_push(`</div></div>`);
				} else _push(`<!---->`);
			}, "body", false, _parent);
		};
	}
};
var _sfc_setup$45 = _sfc_main$19.setup;
_sfc_main$19.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KModal.vue");
	return _sfc_setup$45 ? _sfc_setup$45(props, ctx) : void 0;
};
// core/resources/js/ui/KDialogs.vue
/** Hosts confirm() and the password prompt from @kestello/sdk (one at a time). */
var _sfc_main$9 = {
	__name: "KDialogs",
	__ssrInlineRender: true,
	setup(__props) {
		const api = useApi();
		const current = computed(() => dialogs[0] ?? null);
		const open = ref(false);
		const password = ref("");
		const error = ref("");
		const busy = ref(false);
		watch(current, (dialog) => {
			open.value = !!dialog;
			password.value = "";
			error.value = "";
		}, { immediate: true });
		function finish(result) {
			dialogs.shift()?.resolve(result);
		}
		async function submitPassword() {
			busy.value = true;
			error.value = "";
			try {
				await api.post("/auth/confirm", { password: password.value });
				finish(true);
			} catch (e) {
				error.value = e instanceof ApiError && e.status === 401 ? __("That password is not correct.") : e.message;
			} finally {
				busy.value = false;
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			if (current.value) _push(ssrRenderComponent(_sfc_main$19, mergeProps({
				open: open.value,
				title: current.value.title,
				size: "sm",
				onClose: ($event) => finish(false)
			}, _attrs), {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(_sfc_main$6, { onClick: ($event) => finish(false) }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						if (current.value.kind === "confirm") _push(ssrRenderComponent(_sfc_main$6, {
							variant: current.value.danger ? "danger" : "primary",
							onClick: ($event) => finish(true)
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(current.value.confirmLabel)}`);
								else return [createTextVNode(toDisplayString(current.value.confirmLabel), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(ssrRenderComponent(_sfc_main$6, {
							variant: "primary",
							type: "submit",
							form: "k-reauth",
							loading: busy.value,
							disabled: !password.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Confirm"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Confirm")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(_sfc_main$6, { onClick: ($event) => finish(false) }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), current.value.kind === "confirm" ? (openBlock(), createBlock(_sfc_main$6, {
						key: 0,
						variant: current.value.danger ? "danger" : "primary",
						onClick: ($event) => finish(true)
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(current.value.confirmLabel), 1)]),
						_: 1
					}, 8, ["variant", "onClick"])) : (openBlock(), createBlock(_sfc_main$6, {
						key: 1,
						variant: "primary",
						type: "submit",
						form: "k-reauth",
						loading: busy.value,
						disabled: !password.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Confirm")), 1)]),
						_: 1
					}, 8, ["loading", "disabled"]))];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (current.value.kind === "confirm") _push(`<p class="k-dialog__message"${_scopeId}>${ssrInterpolate(current.value.message)}</p>`);
						else {
							_push(`<form id="k-reauth" class="k-form"${_scopeId}><p class="k-dialog__message"${_scopeId}>${ssrInterpolate(unref(__)("This action changes sensitive settings. Enter your password to continue."))}</p>`);
							_push(ssrRenderComponent(_sfc_main$12, {
								label: unref(__)("Password"),
								error: error.value
							}, {
								default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(_sfc_main$15, {
										id,
										modelValue: password.value,
										"onUpdate:modelValue": ($event) => password.value = $event,
										type: "password",
										autocomplete: "current-password",
										autofocus: "",
										invalid,
										"aria-describedby": describedBy
									}, null, _parent, _scopeId));
									else return [createVNode(_sfc_main$15, {
										id,
										modelValue: password.value,
										"onUpdate:modelValue": ($event) => password.value = $event,
										type: "password",
										autocomplete: "current-password",
										autofocus: "",
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
							_push(`</form>`);
						}
					} else return [current.value.kind === "confirm" ? (openBlock(), createBlock("p", {
						key: 0,
						class: "k-dialog__message"
					}, toDisplayString(current.value.message), 1)) : (openBlock(), createBlock("form", {
						key: 1,
						id: "k-reauth",
						class: "k-form",
						onSubmit: withModifiers(submitPassword, ["prevent"])
					}, [createVNode("p", { class: "k-dialog__message" }, toDisplayString(unref(__)("This action changes sensitive settings. Enter your password to continue.")), 1), createVNode(_sfc_main$12, {
						label: unref(__)("Password"),
						error: error.value
					}, {
						default: withCtx(({ id, describedBy, invalid }) => [createVNode(_sfc_main$15, {
							id,
							modelValue: password.value,
							"onUpdate:modelValue": ($event) => password.value = $event,
							type: "password",
							autocomplete: "current-password",
							autofocus: "",
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
					}, 8, ["label", "error"])], 32))];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
		};
	}
};
var _sfc_setup$44 = _sfc_main$9.setup;
_sfc_main$9.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KDialogs.vue");
	return _sfc_setup$44 ? _sfc_setup$44(props, ctx) : void 0;
};
// core/resources/js/ui/KEmpty.vue
var _sfc_main$10 = {
	__name: "KEmpty",
	__ssrInlineRender: true,
	props: {
		icon: {
			type: String,
			default: "tray"
		},
		title: {
			type: String,
			required: true
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-empty" }, _attrs))}>`);
			_push(ssrRenderComponent(_sfc_main$14, { name: __props.icon }, null, _parent));
			_push(`<p class="k-empty__title">${ssrInterpolate(__props.title)}</p>`);
			if (_ctx.$slots.default) {
				_push(`<div class="k-empty__body">`);
				ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$43 = _sfc_main$10.setup;
_sfc_main$10.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KEmpty.vue");
	return _sfc_setup$43 ? _sfc_setup$43(props, ctx) : void 0;
};
// core/resources/js/ui/KKeyValue.vue
var _sfc_main$16 = {
	__name: "KKeyValue",
	__ssrInlineRender: true,
	props: { items: {
		type: Object,
		required: true
	} },
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<dl${ssrRenderAttrs(mergeProps({ class: "k-kv" }, _attrs))}><!--[-->`);
			ssrRenderList(__props.items, (value, key) => {
				_push(`<!--[--><dt>${ssrInterpolate(key)}</dt><dd>${ssrInterpolate(value === true ? "Yes" : value === false ? "No" : value ?? "—")}</dd><!--]-->`);
			});
			_push(`<!--]--></dl>`);
		};
	}
};
var _sfc_setup$42 = _sfc_main$16.setup;
_sfc_main$16.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KKeyValue.vue");
	return _sfc_setup$42 ? _sfc_setup$42(props, ctx) : void 0;
};
// core/resources/js/ui/KPageHeader.vue
var _sfc_main$20 = {
	__name: "KPageHeader",
	__ssrInlineRender: true,
	props: {
		title: {
			type: String,
			required: true
		},
		description: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: __props.title }, null, _parent));
			_push(`<header class="k-page-header"><div><h1 class="k-page-header__title">${ssrInterpolate(__props.title)}</h1>`);
			if (__props.description) _push(`<p class="k-page-header__description">${ssrInterpolate(__props.description)}</p>`);
			else _push(`<!---->`);
			_push(`</div>`);
			if (_ctx.$slots.actions) {
				_push(`<div class="k-page-header__actions">`);
				ssrRenderSlot(_ctx.$slots, "actions", {}, null, _push, _parent);
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`</header><!--]-->`);
		};
	}
};
var _sfc_setup$41 = _sfc_main$20.setup;
_sfc_main$20.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KPageHeader.vue");
	return _sfc_setup$41 ? _sfc_setup$41(props, ctx) : void 0;
};
// core/resources/js/ui/KSelect.vue
var _sfc_main$25 = {
	__name: "KSelect",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		options: {
			type: Array,
			default: () => []
		},
		invalid: Boolean
	}, {
		"modelValue": {
			type: [String, Number],
			default: ""
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<select${ssrRenderAttrs(mergeProps({
				class: "k-input k-select",
				"aria-invalid": __props.invalid ? "true" : void 0
			}, _attrs))}><!--[-->`);
			ssrRenderList(__props.options, (option) => {
				_push(`<option${ssrRenderAttr("value", option.value)}${ssrIncludeBooleanAttr(option.disabled) ? " disabled" : ""}${ssrIncludeBooleanAttr(Array.isArray(model.value) ? ssrLooseContain(model.value, option.value) : ssrLooseEqual(model.value, option.value)) ? " selected" : ""}>${ssrInterpolate(option.label)}</option>`);
			});
			_push(`<!--]--></select>`);
		};
	}
};
var _sfc_setup$40 = _sfc_main$25.setup;
_sfc_main$25.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KSelect.vue");
	return _sfc_setup$40 ? _sfc_setup$40(props, ctx) : void 0;
};
// core/resources/js/ui/KStat.vue
/** A fact cell: label, value and a one-line hint. `coord` adds a grid reference (A1). */
var _sfc_main$28 = {
	__name: "KStat",
	__ssrInlineRender: true,
	props: {
		label: {
			type: String,
			required: true
		},
		value: {
			type: [String, Number],
			default: "—"
		},
		hint: {
			type: String,
			default: ""
		},
		icon: {
			type: String,
			default: ""
		},
		tone: {
			type: String,
			default: "neutral"
		},
		coord: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: ["k-stat", `k-stat--${__props.tone}`] }, _attrs))}><div class="k-stat__label"><span>`);
			if (__props.icon) _push(ssrRenderComponent(_sfc_main$14, { name: __props.icon }, null, _parent));
			else _push(`<!---->`);
			_push(` ${ssrInterpolate(__props.label)}</span>`);
			if (__props.coord) _push(`<span class="k-card__coord" aria-hidden="true">${ssrInterpolate(__props.coord)}</span>`);
			else _push(`<!---->`);
			_push(`</div><div class="k-stat__value">${ssrInterpolate(__props.value)}</div>`);
			if (__props.hint || _ctx.$slots.default) {
				_push(`<div class="k-stat__hint">`);
				ssrRenderSlot(_ctx.$slots, "default", {}, () => {
					_push(`${ssrInterpolate(__props.hint)}`);
				}, _push, _parent);
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$39 = _sfc_main$28.setup;
_sfc_main$28.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KStat.vue");
	return _sfc_setup$39 ? _sfc_setup$39(props, ctx) : void 0;
};
// core/resources/js/ui/KStatusBadge.vue
/** Site Health / task statuses → badge tone and label. */
var _sfc_main$29 = {
	__name: "KStatusBadge",
	__ssrInlineRender: true,
	props: { status: {
		type: String,
		default: ""
	} },
	setup(__props) {
		const props = __props;
		const MAP = {
			good: ["success", "Good"],
			ok: ["success", "OK"],
			done: ["success", "Done"],
			sent: ["success", "Sent"],
			recommended: ["warning", "Recommended"],
			skipped: ["neutral", "Skipped"],
			running: ["info", "Running"],
			disabled: ["neutral", "Disabled"],
			critical: ["danger", "Critical"],
			failed: ["danger", "Failed"]
		};
		const entry = computed(() => MAP[props.status] ?? ["neutral", props.status || "—"]);
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$3, mergeProps({
				tone: entry.value[0],
				dot: ""
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) ssrRenderSlot(_ctx.$slots, "default", {}, () => {
						_push(`${ssrInterpolate(entry.value[1])}`);
					}, _push, _parent, _scopeId);
					else return [renderSlot(_ctx.$slots, "default", {}, () => [createTextVNode(toDisplayString(entry.value[1]), 1)])];
				}),
				_: 3
			}, _parent));
		};
	}
};
var _sfc_setup$38 = _sfc_main$29.setup;
_sfc_main$29.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KStatusBadge.vue");
	return _sfc_setup$38 ? _sfc_setup$38(props, ctx) : void 0;
};
// core/resources/js/ui/KSwitch.vue
var _sfc_main$30 = {
	__name: "KSwitch",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		label: {
			type: String,
			required: true
		},
		disabled: Boolean,
		hideLabel: Boolean
	}, {
		"modelValue": {
			type: Boolean,
			default: false
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<button${ssrRenderAttrs(mergeProps({
				type: "button",
				role: "switch",
				class: "k-switch",
				"aria-checked": model.value ? "true" : "false",
				disabled: __props.disabled
			}, _attrs))}><span class="k-switch__track" aria-hidden="true"><span class="k-switch__thumb"></span></span><span class="${ssrRenderClass(__props.hideLabel ? "k-sr-only" : "k-switch__label")}">${ssrInterpolate(__props.label)}</span></button>`);
		};
	}
};
var _sfc_setup$37 = _sfc_main$30.setup;
_sfc_main$30.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KSwitch.vue");
	return _sfc_setup$37 ? _sfc_setup$37(props, ctx) : void 0;
};
// core/resources/js/ui/KTable.vue
/**
* <KTable :columns="[{ key: 'name', label: 'Task' }, { key: 'status', label: 'Status', align: 'end' }]" :rows="rows" row-key="id">
*   <template #cell-status="{ row }"><KStatusBadge :status="row.status" /></template>
* </KTable>
*
* Timetable extras: `row-class` returns classes per row (is-scheduled, is-faded),
* and `marker` draws a labelled rule before one row, e.g. the now-line:
*   :marker="{ before: 2, label: 'Now 14:52' }"
*/
var _sfc_main$31 = {
	__name: "KTable",
	__ssrInlineRender: true,
	props: {
		columns: {
			type: Array,
			required: true
		},
		rows: {
			type: Array,
			default: () => []
		},
		rowKey: {
			type: String,
			default: "id"
		},
		loading: Boolean,
		empty: {
			type: String,
			default: () => __("Nothing here yet.")
		},
		emptyText: {
			type: String,
			default: ""
		},
		caption: {
			type: String,
			default: ""
		},
		rowClass: {
			type: Function,
			default: null
		},
		marker: {
			type: Object,
			default: null
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-table-wrap" }, _attrs))}><table class="k-table">`);
			if (__props.caption) _push(`<caption class="k-sr-only">${ssrInterpolate(__props.caption)}</caption>`);
			else _push(`<!---->`);
			_push(`<thead><tr><!--[-->`);
			ssrRenderList(__props.columns, (col) => {
				_push(`<th scope="col" class="${ssrRenderClass(col.align ? `is-${col.align}` : "")}" style="${ssrRenderStyle(col.width ? { width: col.width } : null)}">${ssrInterpolate(col.label)}</th>`);
			});
			_push(`<!--]--></tr></thead><tbody>`);
			if (__props.loading && !__props.rows.length) {
				_push(`<tr><td${ssrRenderAttr("colspan", __props.columns.length)} class="k-table__state">`);
				_push(ssrRenderComponent(_sfc_main$27, null, null, _parent));
				_push(`</td></tr>`);
			} else if (!__props.rows.length) {
				_push(`<tr><td${ssrRenderAttr("colspan", __props.columns.length)} class="k-table__state">`);
				_push(ssrRenderComponent(_sfc_main$10, {
					icon: "tray",
					title: __props.empty
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							if (__props.emptyText) _push(`<p${_scopeId}>${ssrInterpolate(__props.emptyText)}</p>`);
							else _push(`<!---->`);
						} else return [__props.emptyText ? (openBlock(), createBlock("p", { key: 0 }, toDisplayString(__props.emptyText), 1)) : createCommentVNode("v-if", true)];
					}),
					_: 1
				}, _parent));
				_push(`</td></tr>`);
			} else {
				_push(`<!--[-->`);
				ssrRenderList(__props.rows, (row, index) => {
					_push(`<!--[-->`);
					if (__props.marker && __props.marker.before === index) _push(`<tr class="k-now"><td${ssrRenderAttr("colspan", __props.columns.length)}><span class="k-now__label">${ssrInterpolate(__props.marker.label)}</span></td></tr>`);
					else _push(`<!---->`);
					_push(`<tr class="${ssrRenderClass(__props.rowClass ? __props.rowClass(row) : null)}"><!--[-->`);
					ssrRenderList(__props.columns, (col) => {
						_push(`<td class="${ssrRenderClass([col.align ? `is-${col.align}` : "", col.class ?? ""])}">`);
						ssrRenderSlot(_ctx.$slots, `cell-${col.key}`, {
							row,
							value: row[col.key]
						}, () => {
							_push(`${ssrInterpolate(row[col.key] ?? "—")}`);
						}, _push, _parent);
						_push(`</td>`);
					});
					_push(`<!--]--></tr><!--]-->`);
				});
				_push(`<!--]-->`);
			}
			_push(`</tbody></table></div>`);
		};
	}
};
var _sfc_setup$36 = _sfc_main$31.setup;
_sfc_main$31.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KTable.vue");
	return _sfc_setup$36 ? _sfc_setup$36(props, ctx) : void 0;
};
// core/resources/js/ui/KTabs.vue
var _sfc_main$32 = {
	__name: "KTabs",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		tabs: {
			type: Array,
			required: true
		},
		label: {
			type: String,
			default: () => __("Sections")
		}
	}, {
		"modelValue": {
			type: String,
			default: ""
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				class: "k-tabs",
				role: "tablist",
				"aria-label": __props.label
			}, _attrs))}><!--[-->`);
			ssrRenderList(__props.tabs, (tab, i) => {
				_push(`<button type="button" role="tab" class="k-tabs__tab"${ssrRenderAttr("aria-selected", model.value === tab.id ? "true" : "false")}${ssrRenderAttr("tabindex", model.value === tab.id ? 0 : -1)}>${ssrInterpolate(tab.label)}`);
				if (tab.count !== void 0 && tab.count !== null) _push(`<span class="k-tabs__count">${ssrInterpolate(tab.count)}</span>`);
				else _push(`<!---->`);
				_push(`</button>`);
			});
			_push(`<!--]--></div>`);
		};
	}
};
var _sfc_setup$35 = _sfc_main$32.setup;
_sfc_main$32.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KTabs.vue");
	return _sfc_setup$35 ? _sfc_setup$35(props, ctx) : void 0;
};
// core/resources/js/ui/KTime.vue
var _sfc_main$34 = {
	__name: "KTime",
	__ssrInlineRender: true,
	props: { value: {
		type: [
			Number,
			String,
			null
		],
		default: null
	} },
	setup(__props) {
		const props = __props;
		const seconds = computed(() => {
			if (props.value === null || props.value === "" || props.value === void 0) return null;
			const n = typeof props.value === "number" ? props.value : Date.parse(props.value) / 1e3;
			return Number.isFinite(n) && n > 0 ? n : null;
		});
		const iso = computed(() => seconds.value ? (/* @__PURE__ */ new Date(seconds.value * 1e3)).toISOString() : void 0);
		return (_ctx, _push, _parent, _attrs) => {
			if (seconds.value) _push(`<time${ssrRenderAttrs(mergeProps({
				datetime: iso.value,
				title: unref(formatDateTime)(seconds.value)
			}, _attrs))}>${ssrInterpolate(unref(formatRelative)(seconds.value))}</time>`);
			else _push(`<span${ssrRenderAttrs(mergeProps({ class: "k-muted" }, _attrs))}>—</span>`);
		};
	}
};
var _sfc_setup$34 = _sfc_main$34.setup;
_sfc_main$34.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KTime.vue");
	return _sfc_setup$34 ? _sfc_setup$34(props, ctx) : void 0;
};
// core/resources/js/ui/KWhen.vue
/**
* A timetable time: the clock time large, the day under it.
* "14:20 / Today", "09:00 / Mon 9 Nov", "08:30 / 30 Oct 2025".
*/
var _sfc_main$37 = {
	__name: "KWhen",
	__ssrInlineRender: true,
	props: { value: {
		type: [
			Number,
			String,
			null
		],
		default: null
	} },
	setup(__props) {
		const props = __props;
		const admin = useAdmin();
		const date = computed(() => {
			if (props.value === null || props.value === "" || props.value === void 0) return null;
			const n = typeof props.value === "number" ? props.value * 1e3 : Date.parse(props.value);
			return Number.isFinite(n) && n > 0 ? new Date(n) : null;
		});
		const locale = computed(() => admin.value?.locale || void 0);
		const time = computed(() => date.value && new Intl.DateTimeFormat(locale.value, {
			hour: "2-digit",
			minute: "2-digit"
		}).format(date.value));
		const day = computed(() => {
			const d = date.value;
			if (!d) return "";
			const now = /* @__PURE__ */ new Date();
			const start = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
			const diff = Math.round((start(d) - start(now)) / 864e5);
			const rel = new Intl.RelativeTimeFormat(locale.value, { numeric: "auto" });
			if (diff === 0 || diff === -1 || diff === 1) return rel.format(diff, "day").replace(/^\w/, (c) => c.toUpperCase());
			const opts = Math.abs(diff) < 7 ? {
				weekday: "short",
				day: "numeric",
				month: "short"
			} : d.getFullYear() === now.getFullYear() ? {
				day: "numeric",
				month: "short"
			} : {
				day: "numeric",
				month: "short",
				year: "numeric"
			};
			return new Intl.DateTimeFormat(locale.value, opts).format(d);
		});
		return (_ctx, _push, _parent, _attrs) => {
			if (date.value) _push(`<time${ssrRenderAttrs(mergeProps({
				class: "k-when__stamp",
				datetime: date.value.toISOString(),
				title: unref(formatDateTime)(date.value.getTime() / 1e3)
			}, _attrs))}><span class="k-when__time">${ssrInterpolate(time.value)}</span><span class="k-when__day">${ssrInterpolate(day.value)}</span></time>`);
			else _push(`<span${ssrRenderAttrs(mergeProps({ class: "k-muted" }, _attrs))}>—</span>`);
		};
	}
};
var _sfc_setup$33 = _sfc_main$37.setup;
_sfc_main$37.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KWhen.vue");
	return _sfc_setup$33 ? _sfc_setup$33(props, ctx) : void 0;
};
// core/resources/js/ui/KToasts.vue
var _sfc_main$35 = {
	__name: "KToasts",
	__ssrInlineRender: true,
	setup(__props) {
		const ICONS = {
			success: "check-circle",
			info: "info",
			warning: "warning",
			danger: "warning-octagon"
		};
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				class: "k-toasts",
				"aria-live": "polite",
				"aria-relevant": "additions"
			}, _attrs))}><!--[-->`);
			ssrRenderList(unref(toasts), (toast) => {
				_push(`<div class="${ssrRenderClass([`k-toast--${toast.kind}`, "k-toast"])}"${ssrRenderAttr("role", toast.kind === "danger" ? "alert" : "status")}>`);
				_push(ssrRenderComponent(_sfc_main$14, { name: ICONS[toast.kind] ?? "info" }, null, _parent));
				_push(`<span class="k-toast__message">${ssrInterpolate(toast.message)}</span>`);
				if (toast.action) _push(`<button type="button" class="k-toast__action">${ssrInterpolate(toast.action.label)}</button>`);
				else _push(`<!---->`);
				_push(`<button type="button" class="k-toast__close"${ssrRenderAttr("aria-label", unref(__)("Dismiss"))}>`);
				_push(ssrRenderComponent(_sfc_main$14, { name: "x" }, null, _parent));
				_push(`</button></div>`);
			});
			_push(`<!--]--></div>`);
		};
	}
};
var _sfc_setup$32 = _sfc_main$35.setup;
_sfc_main$35.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KToasts.vue");
	return _sfc_setup$32 ? _sfc_setup$32(props, ctx) : void 0;
};
// core/resources/js/ui/KSchemaForm.vue
/**
* Renders fields for an OpenAPI object schema (what the API's Schema
* builder emits): strings, numbers, booleans and enums.
*
*   <KSchemaForm v-model="options" :schema="driver.schema" :errors="errors"
*                :secrets="['password']" :secrets-set="{ password: true }" :locked="{ host: 'environment' }" />
*
* Secrets render as password fields; leaving one blank keeps the stored value.
* A format the form can't draw itself ("media") can come from the page:
*   <template #format-media="{ id, value, set, field, invalid }">…</template>
* Without that slot the field is an ordinary input.
*/
var _sfc_main$24 = {
	__name: "KSchemaForm",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		schema: {
			type: Object,
			required: true
		},
		errors: {
			type: Object,
			default: () => ({})
		},
		secrets: {
			type: Array,
			default: () => []
		},
		secretsSet: {
			type: Object,
			default: () => ({})
		},
		locked: {
			type: Object,
			default: () => ({})
		},
		labels: {
			type: Object,
			default: () => ({})
		}
	}, {
		"modelValue": {
			type: Object,
			default: () => ({})
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		const props = __props;
		const fields = computed(() => Object.entries(props.schema.properties ?? {}).map(([key, def]) => ({
			key,
			def,
			label: props.labels[key] ?? def.title ?? key.replace(/[_.-]/g, " ").replace(/^\w/, (c) => c.toUpperCase()),
			required: (props.schema.required ?? []).includes(key) && def.default === void 0,
			secret: props.secrets.includes(key),
			kind: Array.isArray(def.enum) ? "enum" : def.type === "boolean" ? "bool" : def.type === "integer" || def.type === "number" ? "number" : "string"
		})));
		function set(key, value) {
			model.value = {
				...model.value,
				[key]: value
			};
		}
		function help(field) {
			if (props.locked[field.key]) return __("Set by {source}; it cannot be changed here.", { source: props.locked[field.key] });
			if (field.secret && props.secretsSet[field.key]) return __("Stored. Leave blank to keep it.");
			const parts = [];
			if (field.def.description) parts.push(field.def.description);
			if (field.def.default !== void 0 && field.def.default !== null && field.def.default !== "" && !field.secret && field.kind !== "bool" && field.def.format !== "multiline") parts.push(__("Default: {value}", { value: String(field.def.default) }));
			return parts.join(" ");
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-schema-form" }, _attrs))}><!--[-->`);
			ssrRenderList(fields.value, (field) => {
				_push(ssrRenderComponent(_sfc_main$12, {
					key: field.key,
					label: field.label,
					required: field.required,
					help: help(field),
					error: __props.errors[field.key] ?? ""
				}, {
					default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
						if (_push) {
							if (field.def.format && _ctx.$slots[`format-${field.def.format}`]) ssrRenderSlot(_ctx.$slots, `format-${field.def.format}`, {
								id,
								field,
								value: model.value[field.key],
								set: (value) => set(field.key, value),
								invalid,
								describedBy,
								disabled: !!__props.locked[field.key]
							}, null, _push, _parent, _scopeId);
							else if (field.kind === "bool") _push(ssrRenderComponent(_sfc_main$30, {
								id,
								"model-value": !!(model.value[field.key] ?? field.def.default),
								label: field.label,
								"hide-label": "",
								disabled: !!__props.locked[field.key],
								"onUpdate:modelValue": ($event) => set(field.key, $event)
							}, null, _parent, _scopeId));
							else if (field.kind === "enum") _push(ssrRenderComponent(_sfc_main$25, {
								id,
								"model-value": model.value[field.key] ?? field.def.default ?? "",
								options: field.def.enum.map((v) => ({
									value: v,
									label: field.def["x-enum-labels"]?.[v] ?? String(v)
								})),
								disabled: !!__props.locked[field.key],
								invalid,
								"aria-describedby": describedBy,
								"onUpdate:modelValue": ($event) => set(field.key, $event)
							}, null, _parent, _scopeId));
							else if (field.kind === "number") _push(ssrRenderComponent(_sfc_main$15, {
								id,
								type: "number",
								"model-value": model.value[field.key] ?? "",
								placeholder: field.def.default !== void 0 ? String(field.def.default) : "",
								min: field.def.minimum,
								max: field.def.maximum,
								disabled: !!__props.locked[field.key],
								invalid,
								"aria-describedby": describedBy,
								"onUpdate:modelValue": ($event) => set(field.key, $event === "" ? void 0 : Number($event))
							}, null, _parent, _scopeId));
							else if (field.def.format === "multiline") _push(`<textarea${ssrRenderAttr("id", id)} class="k-input k-schema-form__text" rows="8" spellcheck="false"${ssrRenderAttr("maxlength", field.def.maxLength)}${ssrIncludeBooleanAttr(!!__props.locked[field.key]) ? " disabled" : ""}${ssrRenderAttr("aria-invalid", invalid ? "true" : void 0)}${ssrRenderAttr("aria-describedby", describedBy)}${_scopeId}>${ssrInterpolate(model.value[field.key] ?? "")}</textarea>`);
							else _push(ssrRenderComponent(_sfc_main$15, {
								id,
								type: field.secret ? "password" : "text",
								"model-value": model.value[field.key] ?? "",
								placeholder: field.secret && __props.secretsSet[field.key] ? "••••••••" : field.def.default !== void 0 && field.def.default !== null ? String(field.def.default) : "",
								autocomplete: field.secret ? "new-password" : "off",
								maxlength: field.def.maxLength,
								disabled: !!__props.locked[field.key],
								invalid,
								"aria-describedby": describedBy,
								"onUpdate:modelValue": ($event) => set(field.key, $event)
							}, null, _parent, _scopeId));
						} else return [field.def.format && _ctx.$slots[`format-${field.def.format}`] ? renderSlot(_ctx.$slots, `format-${field.def.format}`, {
							id,
							field,
							value: model.value[field.key],
							set: (value) => set(field.key, value),
							invalid,
							describedBy,
							disabled: !!__props.locked[field.key]
						}, void 0, void 0, 0) : field.kind === "bool" ? (openBlock(), createBlock(_sfc_main$30, {
							key: 1,
							id,
							"model-value": !!(model.value[field.key] ?? field.def.default),
							label: field.label,
							"hide-label": "",
							disabled: !!__props.locked[field.key],
							"onUpdate:modelValue": ($event) => set(field.key, $event)
						}, null, 8, [
							"id",
							"model-value",
							"label",
							"disabled",
							"onUpdate:modelValue"
						])) : field.kind === "enum" ? (openBlock(), createBlock(_sfc_main$25, {
							key: 2,
							id,
							"model-value": model.value[field.key] ?? field.def.default ?? "",
							options: field.def.enum.map((v) => ({
								value: v,
								label: field.def["x-enum-labels"]?.[v] ?? String(v)
							})),
							disabled: !!__props.locked[field.key],
							invalid,
							"aria-describedby": describedBy,
							"onUpdate:modelValue": ($event) => set(field.key, $event)
						}, null, 8, [
							"id",
							"model-value",
							"options",
							"disabled",
							"invalid",
							"aria-describedby",
							"onUpdate:modelValue"
						])) : field.kind === "number" ? (openBlock(), createBlock(_sfc_main$15, {
							key: 3,
							id,
							type: "number",
							"model-value": model.value[field.key] ?? "",
							placeholder: field.def.default !== void 0 ? String(field.def.default) : "",
							min: field.def.minimum,
							max: field.def.maximum,
							disabled: !!__props.locked[field.key],
							invalid,
							"aria-describedby": describedBy,
							"onUpdate:modelValue": ($event) => set(field.key, $event === "" ? void 0 : Number($event))
						}, null, 8, [
							"id",
							"model-value",
							"placeholder",
							"min",
							"max",
							"disabled",
							"invalid",
							"aria-describedby",
							"onUpdate:modelValue"
						])) : field.def.format === "multiline" ? (openBlock(), createBlock("textarea", {
							key: 4,
							id,
							class: "k-input k-schema-form__text",
							rows: "8",
							spellcheck: "false",
							value: model.value[field.key] ?? "",
							maxlength: field.def.maxLength,
							disabled: !!__props.locked[field.key],
							"aria-invalid": invalid ? "true" : void 0,
							"aria-describedby": describedBy,
							onInput: ($event) => set(field.key, $event.target.value)
						}, null, 40, [
							"id",
							"value",
							"maxlength",
							"disabled",
							"aria-invalid",
							"aria-describedby",
							"onInput"
						])) : (openBlock(), createBlock(_sfc_main$15, {
							key: 5,
							id,
							type: field.secret ? "password" : "text",
							"model-value": model.value[field.key] ?? "",
							placeholder: field.secret && __props.secretsSet[field.key] ? "••••••••" : field.def.default !== void 0 && field.def.default !== null ? String(field.def.default) : "",
							autocomplete: field.secret ? "new-password" : "off",
							maxlength: field.def.maxLength,
							disabled: !!__props.locked[field.key],
							invalid,
							"aria-describedby": describedBy,
							"onUpdate:modelValue": ($event) => set(field.key, $event)
						}, null, 8, [
							"id",
							"type",
							"model-value",
							"placeholder",
							"autocomplete",
							"maxlength",
							"disabled",
							"invalid",
							"aria-describedby",
							"onUpdate:modelValue"
						]))];
					}),
					_: 2
				}, _parent));
			});
			_push(`<!--]-->`);
			if (!fields.value.length) _push(`<p class="k-muted">${ssrInterpolate(unref(__)("This driver has no settings."))}</p>`);
			else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$31 = _sfc_main$24.setup;
_sfc_main$24.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KSchemaForm.vue");
	return _sfc_setup$31 ? _sfc_setup$31(props, ctx) : void 0;
};
// core/resources/js/ui/KRevision.vue
var _sfc_main$22 = {
	__name: "KRevision",
	__ssrInlineRender: true,
	props: { number: {
		type: [Number, String],
		default: 1
	} },
	setup(__props) {
		/**
		* The notice: the one thing on a screen that needs the user, numbered like
		* a service notice on a departures board.
		*
		*   <KRevision :number="1"> …content… <template #action>…</template> </KRevision>
		*/
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				class: "k-revision",
				role: "status"
			}, _attrs))}><span class="k-revision__mark" aria-hidden="true">${ssrInterpolate(__props.number)}</span><div class="k-revision__text">`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div>`);
			ssrRenderSlot(_ctx.$slots, "action", {}, null, _push, _parent);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$30 = _sfc_main$22.setup;
_sfc_main$22.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KRevision.vue");
	return _sfc_setup$30 ? _sfc_setup$30(props, ctx) : void 0;
};
// core/resources/js/ui/KExtension.vue
/**
* Renders a component a plugin registered (widget, slot, cell, settings
* tab, replacement) by its registry name. A plugin that fails to load or
* throws while rendering is contained here: the rest of the screen keeps
* working and the failure is named.
*
*   <KExtension name="@acme/admin/SalesWidget" owner="acme" :context="{ user }" />
*
* Every other attribute is passed to the component as props.
*/
var _sfc_main$11 = /*@__PURE__*/ Object.assign({ inheritAttrs: false }, {
	__name: "KExtension",
	__ssrInlineRender: true,
	props: {
		name: {
			type: String,
			required: true
		},
		owner: {
			type: String,
			default: ""
		},
		inline: Boolean
	},
	setup(__props) {
		const props = __props;
		const load = useComponentLoader();
		const component = shallowRef(null);
		const failed = ref(false);
		watch(() => props.name, async (name) => {
			failed.value = false;
			component.value = null;
			try {
				component.value = await load(name);
			} catch (error) {
				console.error(`[kestello] ${props.owner || name}: ${error.message}`, error);
				failed.value = true;
			}
		}, { immediate: true });
		onErrorCaptured((error) => {
			console.error(`[kestello] ${props.owner || props.name} failed while rendering`, error);
			failed.value = true;
			return false;
		});
		return (_ctx, _push, _parent, _attrs) => {
			if (failed.value) {
				_push(`<!--[-->`);
				if (__props.inline) _push(`<span class="k-muted"${ssrRenderAttr("title", unref(__)("{owner} could not show this", { owner: __props.owner || __props.name }))}>—</span>`);
				else _push(`<p class="k-extension-failed" role="status">${ssrInterpolate(unref(__)("{owner} could not show this part.", { owner: __props.owner || unref(__)("A plugin") }))} <span class="k-muted">${ssrInterpolate(unref(__)("The rest of the screen is unaffected. Details are in the browser console."))}</span></p>`);
				_push(`<!--]-->`);
			} else if (component.value) ssrRenderVNode(_push, createVNode(resolveDynamicComponent(component.value), mergeProps(_ctx.$attrs, _attrs), null), _parent);
			else _push(`<!---->`);
		};
	}
});
var _sfc_setup$29 = _sfc_main$11.setup;
_sfc_main$11.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KExtension.vue");
	return _sfc_setup$29 ? _sfc_setup$29(props, ctx) : void 0;
};
// core/resources/js/ui/KSlot.vue
/**
* Every component plugins registered at a slot point, in order, each
* receiving `context`. Renders nothing when the point is empty.
*
*   <KSlot point="users.edit.panels" :context="{ user }" />
*/
var _sfc_main$26 = {
	__name: "KSlot",
	__ssrInlineRender: true,
	props: {
		point: {
			type: String,
			required: true
		},
		context: {
			type: Object,
			default: () => ({})
		}
	},
	setup(__props) {
		const props = __props;
		const items = useExtensions("slots", props.point, () => props.context);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			ssrRenderList(unref(items), (item) => {
				_push(ssrRenderComponent(_sfc_main$11, {
					key: item.id,
					name: item.component,
					owner: item.owner,
					context: __props.context
				}, null, _parent));
			});
			_push(`<!--]-->`);
		};
	}
};
var _sfc_setup$28 = _sfc_main$26.setup;
_sfc_main$26.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KSlot.vue");
	return _sfc_setup$28 ? _sfc_setup$28(props, ctx) : void 0;
};
// core/resources/js/ui/KAvatarCore.vue
/** The core avatar: a square stamp with the person's initial. */
var _sfc_main$2 = {
	__name: "KAvatarCore",
	__ssrInlineRender: true,
	props: {
		user: {
			type: Object,
			default: null
		},
		size: {
			type: Number,
			default: 30
		}
	},
	setup(__props) {
		const props = __props;
		const initial = computed(() => (props.user?.username ?? "?").slice(0, 1).toUpperCase());
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<span${ssrRenderAttrs(mergeProps({
				class: "k-avatar",
				style: __props.size !== 30 ? {
					width: `${__props.size}px`,
					height: `${__props.size}px`,
					fontSize: `${Math.round(__props.size * .44)}px`
				} : null,
				"aria-hidden": "true"
			}, _attrs))}>${ssrInterpolate(initial.value)}</span>`);
		};
	}
};
var _sfc_setup$27 = _sfc_main$2.setup;
_sfc_main$2.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KAvatarCore.vue");
	return _sfc_setup$27 ? _sfc_setup$27(props, ctx) : void 0;
};
// core/resources/js/ui/KAvatar.vue
/**
* A person's avatar. Replaceable as "users.avatar": a plugin's component
* receives the same props plus `core` (this core avatar) so it can wrap it,
* for example to fall back to the initial when there is no picture.
*
*   <KAvatar :user="row" :size="24" />
*/
var _sfc_main$1 = {
	__name: "KAvatar",
	__ssrInlineRender: true,
	props: {
		user: {
			type: Object,
			default: null
		},
		size: {
			type: Number,
			default: 30
		}
	},
	setup(__props) {
		const replacement = useReplacement("users.avatar");
		return (_ctx, _push, _parent, _attrs) => {
			if (unref(replacement)) _push(ssrRenderComponent(_sfc_main$11, mergeProps({
				name: unref(replacement),
				inline: "",
				user: __props.user,
				size: __props.size,
				core: _sfc_main$2
			}, _attrs), null, _parent));
			else _push(ssrRenderComponent(_sfc_main$2, mergeProps({
				user: __props.user,
				size: __props.size
			}, _attrs), null, _parent));
		};
	}
};
var _sfc_setup$26 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KAvatar.vue");
	return _sfc_setup$26 ? _sfc_setup$26(props, ctx) : void 0;
};
// core/resources/js/ui/KContentField.vue
/**
* One content field: label, help, error and the field type's input
* component (loaded by name, so plugin field types work the same way).
* Group and repeater components nest more of these.
*
*   <KContentField v-for="f in fields" :key="f.name" v-model="data[f.name]" :field="f" :errors="errors" path="data" />
*
* errors are keyed by dotted path, as the API returns them (data.speakers.0.name).
*/
var _sfc_main$8 = {
	__name: "KContentField",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		field: {
			type: Object,
			required: true
		},
		errors: {
			type: Object,
			default: () => ({})
		},
		path: {
			type: String,
			default: "data"
		},
		disabled: Boolean
	}, {
		"modelValue": { default: null },
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		const props = __props;
		const uid = useId();
		const id = computed(() => `k-cf-${uid}`);
		const key = computed(() => `${props.path}.${props.field.name}`);
		const error = computed(() => {
			const e = props.errors[key.value];
			return Array.isArray(e) ? e[0] : e ?? "";
		});
		const set = computed(() => [
			"group",
			"repeater",
			"multi_select",
			"boolean",
			"blocks"
		].includes(props.field.type) || props.field.options?.fieldset);
		const ownErrors = computed(() => props.field.type === "blocks");
		const describedBy = computed(() => [props.field.help ? `${id.value}-help` : "", error.value && !ownErrors.value ? `${id.value}-error` : ""].filter(Boolean).join(" ") || void 0);
		return (_ctx, _push, _parent, _attrs) => {
			ssrRenderVNode(_push, createVNode(resolveDynamicComponent(set.value ? "fieldset" : "div"), mergeProps({ class: ["k-field k-content-field", [{ "has-error": error.value }, `k-content-field--${__props.field.width === "half" ? "half" : "full"}`]] }, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (set.value) {
							_push(`<legend class="k-field__label"${_scopeId}>${ssrInterpolate(__props.field.label)}`);
							if (__props.field.required) _push(`<span class="k-field__required" aria-hidden="true"${_scopeId}> *</span>`);
							else _push(`<!---->`);
							_push(`</legend>`);
						} else {
							_push(`<label class="k-field__label"${ssrRenderAttr("for", id.value)}${_scopeId}>${ssrInterpolate(__props.field.label)}`);
							if (__props.field.required) _push(`<span class="k-field__required" aria-hidden="true"${_scopeId}> *</span>`);
							else _push(`<!---->`);
							_push(`</label>`);
						}
						_push(ssrRenderComponent(_sfc_main$11, {
							modelValue: model.value,
							"onUpdate:modelValue": ($event) => model.value = $event,
							name: __props.field.component,
							id: id.value,
							field: __props.field,
							errors: __props.errors,
							path: key.value,
							invalid: !!error.value,
							disabled: __props.disabled,
							"aria-describedby": describedBy.value
						}, null, _parent, _scopeId));
						if (__props.field.help) _push(`<p${ssrRenderAttr("id", `${id.value}-help`)} class="k-field__help"${_scopeId}>${ssrInterpolate(__props.field.help)}</p>`);
						else _push(`<!---->`);
						if (error.value && !ownErrors.value) _push(`<p${ssrRenderAttr("id", `${id.value}-error`)} class="k-field__error"${_scopeId}>${ssrInterpolate(error.value)}</p>`);
						else _push(`<!---->`);
					} else return [
						set.value ? (openBlock(), createBlock("legend", {
							key: 0,
							class: "k-field__label"
						}, [createTextVNode(toDisplayString(__props.field.label), 1), __props.field.required ? (openBlock(), createBlock("span", {
							key: 0,
							class: "k-field__required",
							"aria-hidden": "true"
						}, " *")) : createCommentVNode("v-if", true)])) : (openBlock(), createBlock("label", {
							key: 1,
							class: "k-field__label",
							for: id.value
						}, [createTextVNode(toDisplayString(__props.field.label), 1), __props.field.required ? (openBlock(), createBlock("span", {
							key: 0,
							class: "k-field__required",
							"aria-hidden": "true"
						}, " *")) : createCommentVNode("v-if", true)], 8, ["for"])),
						createVNode(_sfc_main$11, {
							modelValue: model.value,
							"onUpdate:modelValue": ($event) => model.value = $event,
							name: __props.field.component,
							id: id.value,
							field: __props.field,
							errors: __props.errors,
							path: key.value,
							invalid: !!error.value,
							disabled: __props.disabled,
							"aria-describedby": describedBy.value
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"name",
							"id",
							"field",
							"errors",
							"path",
							"invalid",
							"disabled",
							"aria-describedby"
						]),
						__props.field.help ? (openBlock(), createBlock("p", {
							key: 2,
							id: `${id.value}-help`,
							class: "k-field__help"
						}, toDisplayString(__props.field.help), 9, ["id"])) : createCommentVNode("v-if", true),
						error.value && !ownErrors.value ? (openBlock(), createBlock("p", {
							key: 3,
							id: `${id.value}-error`,
							class: "k-field__error"
						}, toDisplayString(error.value), 9, ["id"])) : createCommentVNode("v-if", true)
					];
				}),
				_: 1
			}), _parent);
		};
	}
};
var _sfc_setup$25 = _sfc_main$8.setup;
_sfc_main$8.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KContentField.vue");
	return _sfc_setup$25 ? _sfc_setup$25(props, ctx) : void 0;
};
// core/resources/js/ui/dropdown.js
/**
* Where a dropdown (KPicker's results) goes beside the box it belongs to, in viewport px for
* position: fixed, so a card, a scrolling panel or a dialog that clips what it holds never
* cuts the list off. Under the box while a useful height fits there, else on the side with
* more room; never taller than that room; as wide as the box.
*
* @param {{top:number,bottom:number,left:number,width:number}} anchor  the box (getBoundingClientRect)
* @param {{width:number,height:number}} viewport
* @param {{gap?:number, maxHeight?:number, margin?:number}} [options]  margin: kept free at the screen's edge
* @returns {{left:number,width:number,maxHeight:number,up:boolean,top?:number,bottom?:number}}
*/
function dropdownPlace(anchor, viewport, { gap = 4, maxHeight = 280, margin = 8 } = {}) {
	const below = viewport.height - anchor.bottom - gap - margin;
	const above = anchor.top - gap - margin;
	const up = below < Math.min(maxHeight, 160) && above > below;
	const room = Math.max(0, up ? above : below);
	return {
		...up ? { bottom: viewport.height - anchor.top + gap } : { top: anchor.bottom + gap },
		left: anchor.left,
		width: anchor.width,
		maxHeight: Math.min(maxHeight, room),
		up
	};
}
// core/resources/js/ui/KPicker.vue
/**
* Pick one or more records from a search (entries, people, terms…).
*
*   <KPicker v-model="ids" multiple :search="q => api.get('/x', { search: q })" :resolve="ids => …" />
*
* search(query) and resolve(ids) return [{ id, label, meta? }]. The model
* holds ids only; labels for stored ids come from resolve(). With
* create(text), typing a name that isn't found offers to add it; create
* returns the new { id, label } (or the raw text, kept as-is in the model).
*/
var _sfc_main$21 = {
	__name: "KPicker",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		search: {
			type: Function,
			required: true
		},
		resolve: {
			type: Function,
			required: true
		},
		multiple: Boolean,
		disabled: Boolean,
		invalid: Boolean,
		id: {
			type: String,
			default: ""
		},
		placeholder: {
			type: String,
			default: ""
		},
		create: {
			type: Function,
			default: null
		}
	}, {
		"modelValue": { default: null },
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		const props = __props;
		const listId = `k-picker-${useId()}`;
		const ids = computed(() => props.multiple ? Array.isArray(model.value) ? model.value : [] : model.value ? [model.value] : []);
		const known = ref(/* @__PURE__ */ new Map());
		const query = ref("");
		const results = ref([]);
		const open = ref(false);
		const active = ref(-1);
		const busy = ref(false);
		let seq = 0;
		let timer = null;
		watch(ids, async (list) => {
			const missing = list.filter((id) => !known.value.has(id));
			if (!missing.length) return;
			try {
				for (const item of await props.resolve(missing)) known.value.set(item.id, item);
				known.value = new Map(known.value);
			} catch {}
		}, { immediate: true });
		async function run() {
			const mine = ++seq;
			busy.value = true;
			try {
				const items = await props.search(query.value.trim());
				if (mine !== seq) return;
				results.value = items;
				active.value = items.length || props.create ? 0 : -1;
			} catch {
				if (mine === seq) results.value = [];
			} finally {
				if (mine === seq) busy.value = false;
			}
		}
		watch(query, () => {
			clearTimeout(timer);
			timer = setTimeout(run, 200);
			open.value = true;
		});
		const offer = computed(() => {
			const text = query.value.trim();
			if (!props.create || text === "" || busy.value) return null;
			return results.value.some((r) => String(r.label).toLowerCase() === text.toLowerCase()) ? null : text;
		});
		const options = computed(() => offer.value ? [...results.value, {
			id: "__create",
			label: offer.value,
			create: true
		}] : results.value);
		const field = ref(null);
		const place = ref(null);
		const shown = computed(() => open.value && (options.value.length || busy.value));
		function reposition() {
			if (!field.value) return;
			const at = dropdownPlace(field.value.getBoundingClientRect(), {
				width: window.innerWidth,
				height: window.innerHeight
			});
			const px = (n) => n === void 0 ? "auto" : `${Math.round(n)}px`;
			place.value = {
				top: px(at.top),
				bottom: px(at.bottom),
				left: px(at.left),
				width: px(at.width),
				maxHeight: px(at.maxHeight)
			};
		}
		function follow(on) {
			const how = on ? "addEventListener" : "removeEventListener";
			window[how]("scroll", reposition, {
				capture: true,
				passive: true
			});
			window[how]("resize", reposition, { passive: true });
		}
		watch(shown, (on) => {
			if (on) reposition();
			follow(on);
		});
		onBeforeUnmount(() => follow(false));
		const label = (id) => known.value.get(id)?.label ?? `#${id}`;
		const meta = (id) => known.value.get(id)?.meta ?? "";
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: ["k-picker", { "is-open": shown.value }] }, _attrs))}>`);
			if (ids.value.length) {
				_push(`<ul class="k-picker__chosen"><!--[-->`);
				ssrRenderList(ids.value, (cid) => {
					_push(`<li class="k-picker__item"><span class="k-picker__label">${ssrInterpolate(label(cid))}</span>`);
					if (meta(cid)) _push(`<span class="k-picker__meta">${ssrInterpolate(meta(cid))}</span>`);
					else _push(`<!---->`);
					if (!__props.disabled) {
						_push(`<button type="button" class="k-picker__remove"${ssrRenderAttr("aria-label", unref(__)("Remove {name}", { name: label(cid) }))}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "x" }, null, _parent));
						_push(`</button>`);
					} else _push(`<!---->`);
					_push(`</li>`);
				});
				_push(`<!--]--></ul>`);
			} else _push(`<!---->`);
			if (!__props.disabled && (__props.multiple || !ids.value.length)) {
				_push(`<div class="k-picker__field">`);
				_push(ssrRenderComponent(_sfc_main$14, { name: "magnifying-glass" }, null, _parent));
				_push(`<input${ssrRenderAttr("id", __props.id)}${ssrRenderAttr("value", query.value)} class="k-input" role="combobox" autocomplete="off"${ssrRenderAttr("aria-expanded", open.value ? "true" : "false")}${ssrRenderAttr("aria-controls", listId)}${ssrRenderAttr("aria-activedescendant", open.value && active.value >= 0 ? `${listId}-${active.value}` : void 0)}${ssrRenderAttr("aria-invalid", __props.invalid ? "true" : void 0)}${ssrRenderAttr("placeholder", __props.placeholder || void 0)}><ul${ssrRenderAttr("id", listId)} class="k-picker__results" role="listbox" style="${ssrRenderStyle([place.value, shown.value ? null : { display: "none" }])}">`);
				if (busy.value && !options.value.length) _push(`<li class="k-picker__hint">${ssrInterpolate(unref(__)("Searching…"))}</li>`);
				else _push(`<!---->`);
				_push(`<!--[-->`);
				ssrRenderList(options.value, (item, i) => {
					_push(`<li${ssrRenderAttr("id", `${listId}-${i}`)} role="option"${ssrRenderAttr("aria-selected", i === active.value ? "true" : "false")} class="${ssrRenderClass([{
						"is-chosen": ids.value.includes(item.id),
						"is-create": item.create
					}, "k-picker__option"])}">`);
					if (item.create) {
						_push(`<span class="k-picker__label">`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "plus" }, null, _parent));
						_push(` ${ssrInterpolate(unref(__)("Add “{name}”", { name: item.label }))}</span>`);
					} else _push(`<span class="k-picker__label">${ssrInterpolate(item.label)}</span>`);
					if (item.meta || ids.value.includes(item.id)) _push(`<span class="k-picker__meta">${ssrInterpolate([item.meta, ids.value.includes(item.id) ? unref(__)("added") : ""].filter(Boolean).join(" · "))}</span>`);
					else _push(`<!---->`);
					_push(`</li>`);
				});
				_push(`<!--]--></ul></div>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$24 = _sfc_main$21.setup;
_sfc_main$21.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KPicker.vue");
	return _sfc_setup$24 ? _sfc_setup$24(props, ctx) : void 0;
};
// core/resources/js/ui/KRichText.vue
/**
* The core rich-text editor: paragraphs, two heading levels, bold, italic,
* links, lists and quotes. It emits HTML; the server sanitizes it again
* with the rich-text profile, so this editor is a convenience, not a
* security boundary. Sites swap it with $cms->admin->replace('editor.richtext', …).
*/
var _sfc_main$23 = {
	__name: "KRichText",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		id: {
			type: String,
			default: ""
		},
		disabled: Boolean,
		invalid: Boolean,
		label: {
			type: String,
			default: ""
		}
	}, {
		"modelValue": {
			type: String,
			default: ""
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		const area = ref(null);
		const state = reactive({
			block: "p",
			bold: false,
			italic: false,
			ul: false,
			ol: false,
			link: false
		});
		const linking = ref(false);
		const href = ref("");
		const linkInput = ref(null);
		let last = null;
		function render(html) {
			if (area.value && html !== last) {
				area.value.innerHTML = html || "";
				last = html;
			}
		}
		watch(() => model.value, (v) => render(v ?? ""));
		onMounted(() => {
			render(model.value ?? "");
			document.addEventListener("selectionchange", sync);
		});
		onBeforeUnmount(() => document.removeEventListener("selectionchange", sync));
		function emit() {
			let html = area.value.innerHTML;
			if (/^(<p>(<br>)?<\/p>|<br>)?$/.test(html.trim())) html = "";
			last = html;
			model.value = html;
		}
		function inside() {
			const sel = document.getSelection();
			return sel && sel.rangeCount && area.value?.contains(sel.anchorNode);
		}
		function sync() {
			if (!inside()) return;
			state.bold = document.queryCommandState("bold");
			state.italic = document.queryCommandState("italic");
			state.ul = document.queryCommandState("insertUnorderedList");
			state.ol = document.queryCommandState("insertOrderedList");
			state.block = String(document.queryCommandValue("formatBlock") || "p").toLowerCase().replace(/[<>]/g, "");
			let node = document.getSelection().anchorNode;
			state.link = false;
			while (node && node !== area.value) {
				if (node.nodeName === "A") {
					state.link = true;
					break;
				}
				node = node.parentNode;
			}
		}
		function run(command, value = null) {
			area.value.focus();
			document.execCommand("styleWithCSS", false, false);
			document.execCommand(command, false, value);
			emit();
			sync();
		}
		const block = (tag) => run("formatBlock", state.block === tag && tag !== "p" ? "<p>" : `<${tag}>`);
		function openLink() {
			const sel = document.getSelection();
			if (!inside()) area.value.focus();
			sel.rangeCount && sel.getRangeAt(0).cloneRange();
			let node = sel.anchorNode;
			while (node && node !== area.value && node.nodeName !== "A") node = node.parentNode;
			href.value = node?.nodeName === "A" ? node.getAttribute("href") : "";
			linking.value = true;
			setTimeout(() => linkInput.value?.focus());
		}
		const safe = (url) => /^(https?:\/\/|mailto:|tel:|\/|#)/i.test(url);
		const TOOLS = [
			{
				id: "p",
				icon: "paragraph",
				label: __("Paragraph"),
				on: () => state.block === "p" || state.block === "div",
				act: () => block("p")
			},
			{
				id: "h2",
				icon: "text-h-two",
				label: __("Heading"),
				on: () => state.block === "h2",
				act: () => block("h2")
			},
			{
				id: "h3",
				icon: "text-h-three",
				label: __("Subheading"),
				on: () => state.block === "h3",
				act: () => block("h3")
			},
			"sep",
			{
				id: "b",
				icon: "text-b",
				label: __("Bold"),
				on: () => state.bold,
				act: () => run("bold")
			},
			{
				id: "i",
				icon: "text-italic",
				label: __("Italic"),
				on: () => state.italic,
				act: () => run("italic")
			},
			{
				id: "a",
				icon: "link",
				label: __("Link"),
				on: () => state.link,
				act: openLink
			},
			"sep",
			{
				id: "ul",
				icon: "list-bullets",
				label: __("Bulleted list"),
				on: () => state.ul,
				act: () => run("insertUnorderedList")
			},
			{
				id: "ol",
				icon: "list-numbers",
				label: __("Numbered list"),
				on: () => state.ol,
				act: () => run("insertOrderedList")
			},
			{
				id: "q",
				icon: "quotes",
				label: __("Quote"),
				on: () => state.block === "blockquote",
				act: () => block("blockquote")
			},
			"sep",
			{
				id: "x",
				icon: "eraser",
				label: __("Clear formatting"),
				on: () => false,
				act: () => {
					run("removeFormat");
					run("formatBlock", "<p>");
				}
			}
		];
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: ["k-richtext", {
				"is-disabled": __props.disabled,
				"is-invalid": __props.invalid
			}] }, _attrs))}>`);
			if (!__props.disabled) {
				_push(`<div class="k-richtext__bar" role="toolbar"${ssrRenderAttr("aria-label", unref(__)("Formatting"))}${ssrRenderAttr("aria-controls", __props.id)}><!--[-->`);
				ssrRenderList(TOOLS, (tool, i) => {
					_push(`<!--[-->`);
					if (tool === "sep") _push(`<span class="k-richtext__sep" aria-hidden="true"></span>`);
					else {
						_push(`<button type="button" class="k-richtext__tool"${ssrRenderAttr("aria-pressed", tool.on() ? "true" : "false")}${ssrRenderAttr("title", tool.label)}${ssrRenderAttr("aria-label", tool.label)}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: tool.icon }, null, _parent));
						_push(`</button>`);
					}
					_push(`<!--]-->`);
				});
				_push(`<!--]--></div>`);
			} else _push(`<!---->`);
			if (linking.value) {
				_push(`<form class="k-richtext__link"><input${ssrRenderAttr("value", href.value)} class="k-input" type="text" inputmode="url" spellcheck="false"${ssrRenderAttr("aria-label", unref(__)("Link address"))} placeholder="https://"><button type="submit" class="k-btn k-btn--primary k-btn--sm">${ssrInterpolate(href.value.trim() === "" ? unref(__)("Remove link") : unref(__)("Apply"))}</button><button type="button" class="k-btn k-btn--ghost k-btn--sm">${ssrInterpolate(unref(__)("Cancel"))}</button>`);
				if (href.value.trim() && !safe(href.value.trim())) _push(`<span class="k-field__error">${ssrInterpolate(unref(__)("Use a web address (https://…), mailto:, or a path starting with /."))}</span>`);
				else _push(`<!---->`);
				_push(`</form>`);
			} else _push(`<!---->`);
			_push(`<div${ssrRenderAttr("id", __props.id)} class="k-richtext__area k-prose" role="textbox" aria-multiline="true"${ssrRenderAttr("aria-label", __props.label || void 0)}${ssrRenderAttr("aria-invalid", __props.invalid ? "true" : void 0)}${ssrRenderAttr("contenteditable", __props.disabled ? "false" : "true")}></div></div>`);
		};
	}
};
var _sfc_setup$23 = _sfc_main$23.setup;
_sfc_main$23.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KRichText.vue");
	return _sfc_setup$23 ? _sfc_setup$23(props, ctx) : void 0;
};
// core/resources/js/ui/KTermSelect.vue
/**
* Terms of one taxonomy for an entry. Hierarchical taxonomies show a
* checklist; flat ones (tags) a picker where typing a new name adds it.
* New terms stay names in the model until the entry is saved (the server
* creates them), so abandoning the edit leaves nothing behind.
*
*   <KTermSelect v-model="form.terms.category" :taxonomy="{ name, singular, plural, hierarchical, can }" />
*/
var _sfc_main$33 = {
	__name: "KTermSelect",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		taxonomy: {
			type: Object,
			required: true
		},
		disabled: Boolean,
		id: {
			type: String,
			default: ""
		}
	}, {
		"modelValue": {
			type: Array,
			default: () => []
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		const props = __props;
		const api = useApi();
		const base = computed(() => `/taxonomies/${props.taxonomy.name}/terms`);
		const item = (t) => ({
			id: t.id,
			label: t.name
		});
		const all = ref([]);
		const filter = ref("");
		const adding = ref("");
		const loaded = ref(false);
		async function loadAll() {
			try {
				all.value = (await api.get(base.value)).data;
			} catch {
				all.value = [];
			}
			loaded.value = true;
		}
		watch(() => props.taxonomy.name, () => {
			if (props.taxonomy.hierarchical) loadAll();
		}, { immediate: true });
		const shown = computed(() => {
			const q = filter.value.trim().toLowerCase();
			return q ? all.value.filter((t) => t.name.toLowerCase().includes(q)) : all.value;
		});
		const pending = computed(() => model.value.filter((v) => typeof v === "string"));
		const search = async (q) => (await api.get(base.value, {
			search: q || void 0,
			limit: 20
		})).data.map(item);
		const resolve = async (ids) => {
			const numeric = ids.filter((v) => typeof v === "number");
			const named = ids.filter((v) => typeof v === "string").map((v) => ({
				id: v,
				label: v,
				meta: __("new")
			}));
			return [...numeric.length ? (await api.get(base.value, { ids: numeric.join(",") })).data.map(item) : [], ...named];
		};
		const create = computed(() => props.taxonomy.can?.create ? (name) => ({
			id: name,
			label: name,
			meta: __("new")
		}) : null);
		return (_ctx, _push, _parent, _attrs) => {
			if (__props.taxonomy.hierarchical) {
				_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-termlist" }, _attrs))}>`);
				if (all.value.length > 8) _push(`<input${ssrRenderAttr("value", filter.value)} type="search" class="k-input k-termlist__filter"${ssrRenderAttr("title", unref(__)("Filter {plural}", { plural: __props.taxonomy.plural.toLowerCase() }))}${ssrRenderAttr("aria-label", unref(__)("Filter {plural}", { plural: __props.taxonomy.plural.toLowerCase() }))}>`);
				else _push(`<!---->`);
				if (shown.value.length || pending.value.length) {
					_push(`<ul${ssrRenderAttr("id", __props.id)} class="k-termlist__items"><!--[-->`);
					ssrRenderList(shown.value, (t) => {
						_push(`<li style="${ssrRenderStyle({ "--k-depth": filter.value ? 0 : t.depth })}"><label class="k-choice"><input type="checkbox"${ssrIncludeBooleanAttr(model.value.includes(t.id)) ? " checked" : ""}${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}><span>${ssrInterpolate(t.name)}</span></label></li>`);
					});
					_push(`<!--]--><!--[-->`);
					ssrRenderList(pending.value, (name) => {
						_push(`<li><label class="k-choice"><input type="checkbox" checked${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}><span>${ssrInterpolate(name)} <span class="k-muted k-small">${ssrInterpolate(unref(__)("new"))}</span></span></label></li>`);
					});
					_push(`<!--]--></ul>`);
				} else if (loaded.value) _push(`<p class="k-muted k-small k-termlist__empty">${ssrInterpolate(unref(__)("No {plural} yet.", { plural: __props.taxonomy.plural.toLowerCase() }))}</p>`);
				else _push(`<!---->`);
				if (__props.taxonomy.can?.create && !__props.disabled) _push(`<form class="k-termlist__add"><label class="k-inline-field"><span>${ssrInterpolate(unref(__)("New {item}", { item: __props.taxonomy.singular.toLowerCase() }))}</span><input${ssrRenderAttr("value", adding.value)} class="k-input" maxlength="191"></label><button type="submit" class="k-btn k-btn--secondary k-btn--sm"${ssrIncludeBooleanAttr(!adding.value.trim()) ? " disabled" : ""}>${ssrInterpolate(unref(__)("Add"))}</button></form>`);
				else _push(`<!---->`);
				_push(`</div>`);
			} else _push(ssrRenderComponent(_sfc_main$21, mergeProps({
				id: __props.id,
				modelValue: model.value,
				"onUpdate:modelValue": ($event) => model.value = $event,
				multiple: "",
				search,
				resolve,
				create: create.value,
				disabled: __props.disabled,
				placeholder: __props.taxonomy.can?.create ? unref(__)("Find or add {plural}", { plural: __props.taxonomy.plural.toLowerCase() }) : unref(__)("Find {plural}", { plural: __props.taxonomy.plural.toLowerCase() })
			}, _attrs), null, _parent));
		};
	}
};
var _sfc_setup$22 = _sfc_main$33.setup;
_sfc_main$33.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KTermSelect.vue");
	return _sfc_setup$22 ? _sfc_setup$22(props, ctx) : void 0;
};
// core/resources/js/ui/KMediaGrid.vue
/**
* Media library items as a grid of tiles.
*   <KMediaGrid :items="items" :selected="ids" @pick="open" />
* With `selectable`, tiles are checkboxes (aria-pressed); otherwise buttons.
*/
var _sfc_main$17 = {
	__name: "KMediaGrid",
	__ssrInlineRender: true,
	props: {
		items: {
			type: Array,
			required: true
		},
		selected: {
			type: Array,
			default: () => []
		},
		selectable: Boolean,
		current: {
			type: [Number, null],
			default: null
		},
		label: {
			type: String,
			default: ""
		}
	},
	emits: ["pick"],
	setup(__props) {
		const props = __props;
		const ICONS = {
			pdf: "file-pdf",
			doc: "file-doc",
			docx: "file-doc",
			odt: "file-doc",
			rtf: "file-text",
			txt: "file-text",
			csv: "file-csv",
			xls: "file-xls",
			xlsx: "file-xls",
			ods: "file-csv",
			ppt: "file-ppt",
			pptx: "file-ppt",
			odp: "file-ppt",
			zip: "file-zip"
		};
		const KIND_ICONS = {
			audio: "file-audio",
			video: "file-video",
			archive: "file-zip",
			document: "file"
		};
		const icon = (m) => ICONS[m.ext] ?? KIND_ICONS[m.kind] ?? "file";
		const isOn = (m) => props.selected.includes(m.id);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<ul${ssrRenderAttrs(mergeProps({
				class: "k-media-grid",
				"aria-label": __props.label || void 0
			}, _attrs))}><!--[-->`);
			ssrRenderList(__props.items, (m) => {
				_push(`<li><button type="button" class="${ssrRenderClass([{
					"is-selected": isOn(m),
					"is-current": __props.current === m.id
				}, "k-media-tile"])}"${ssrRenderAttr("aria-pressed", __props.selectable ? isOn(m) ? "true" : "false" : void 0)}${ssrRenderAttr("aria-current", !__props.selectable && __props.current === m.id ? "true" : void 0)}${ssrRenderAttr("title", m.filename)}><span class="k-media-tile__thumb">`);
				if ((m.kind === "image" || m.sizes?.thumb) && m.url) _push(ssrRenderComponent(unref(KImage), {
					media: m,
					size: "thumb",
					sizes: "160px"
				}, null, _parent));
				else {
					_push(`<span class="k-media-tile__file">`);
					_push(ssrRenderComponent(_sfc_main$14, { name: icon(m) }, null, _parent));
					_push(`<span class="k-media-tile__ext">${ssrInterpolate(m.ext)}</span></span>`);
				}
				if (__props.selectable && isOn(m)) {
					_push(`<span class="k-media-tile__check" aria-hidden="true">`);
					_push(ssrRenderComponent(_sfc_main$14, { name: "check" }, null, _parent));
					_push(`</span>`);
				} else _push(`<!---->`);
				_push(`</span><span class="k-media-tile__name">${ssrInterpolate(m.title)}</span>`);
				if (m.kind === "image" && !m.alt) _push(`<span class="k-media-tile__flag">${ssrInterpolate(unref(__)("No alt text"))}</span>`);
				else _push(`<!---->`);
				_push(`</button></li>`);
			});
			_push(`<!--]--></ul>`);
		};
	}
};
var _sfc_setup$21 = _sfc_main$17.setup;
_sfc_main$17.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KMediaGrid.vue");
	return _sfc_setup$21 ? _sfc_setup$21(props, ctx) : void 0;
};
// core/resources/js/ui/KUploadZone.vue
/**
* Drop files here (or anywhere on the page with `page`) to add them to the
* media library. Emits `uploaded` with each new media item.
*   <KUploadZone kind="image" @uploaded="add" />
*/
var _sfc_main$36 = {
	__name: "KUploadZone",
	__ssrInlineRender: true,
	props: {
		kind: {
			type: String,
			default: ""
		},
		page: Boolean,
		compact: Boolean
	},
	emits: ["uploaded"],
	setup(__props, { expose: __expose, emit: __emit }) {
		const props = __props;
		const emit = __emit;
		const api = useApi();
		const limits = useResource("/media/limits");
		const input = ref(null);
		const over = ref(false);
		const queue = reactive([]);
		let key = 0;
		const accept = computed(() => {
			const l = limits.data.value;
			if (!l) return "";
			return props.kind ? l.accept_by_kind?.[props.kind] ?? "" : l.accept;
		});
		const busy = computed(() => queue.some((q) => !q.error && q.progress < 1));
		const hint = computed(() => {
			const l = limits.data.value;
			return l ? __("Up to {size} per file.", { size: formatBytes(l.max_bytes) }) : "";
		});
		async function add(files) {
			const l = limits.data.value;
			const allowed = accept.value.split(",").filter(Boolean);
			for (const file of files) {
				const ext = "." + (file.name.split(".").pop() ?? "").toLowerCase();
				const item = reactive({
					key: ++key,
					name: file.name,
					size: file.size,
					progress: 0,
					error: "",
					controller: new AbortController()
				});
				queue.unshift(item);
				if (allowed.length && !allowed.includes(ext)) {
					item.error = __("This type of file is not allowed here.");
					continue;
				}
				if (l && file.size > l.max_bytes) {
					item.error = __("Larger than {size}.", { size: formatBytes(l.max_bytes) });
					continue;
				}
				try {
					const media = await uploadMedia(api, file, {
						signal: item.controller.signal,
						onProgress: (f) => {
							item.progress = Math.min(.99, f);
						}
					});
					item.progress = 1;
					emit("uploaded", media);
					setTimeout(() => {
						const i = queue.indexOf(item);
						if (i >= 0) queue.splice(i, 1);
					}, 2500);
				} catch (e) {
					if (e.name === "AbortError") {
						queue.splice(queue.indexOf(item), 1);
						continue;
					}
					item.error = Object.values(e.fieldErrors?.() ?? {})[0] ?? e.message;
				}
			}
		}
		function choose() {
			input.value?.click();
		}
		let depth = 0;
		const hasFiles = (e) => [...e.dataTransfer?.types ?? []].includes("Files");
		function enter(e) {
			if (!hasFiles(e)) return;
			depth++;
			over.value = true;
		}
		function leave() {
			depth = Math.max(0, depth - 1);
			if (!depth) over.value = false;
		}
		function dragover(e) {
			if (hasFiles(e)) e.preventDefault();
		}
		function drop(e) {
			if (!hasFiles(e)) return;
			e.preventDefault();
			depth = 0;
			over.value = false;
			if (limits.data.value && !limits.data.value.can_upload) return notify.error(__("You can't upload files."));
			add([...e.dataTransfer.files]);
		}
		function beforeUnload(e) {
			if (busy.value) {
				e.preventDefault();
				e.returnValue = "";
			}
		}
		onMounted(() => {
			window.addEventListener("beforeunload", beforeUnload);
			if (!props.page) return;
			window.addEventListener("dragenter", enter);
			window.addEventListener("dragleave", leave);
			window.addEventListener("dragover", dragover);
			window.addEventListener("drop", drop);
		});
		onBeforeUnmount(() => {
			window.removeEventListener("beforeunload", beforeUnload);
			window.removeEventListener("dragenter", enter);
			window.removeEventListener("dragleave", leave);
			window.removeEventListener("dragover", dragover);
			window.removeEventListener("drop", drop);
		});
		__expose({ choose });
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: ["k-upload", {
				"is-over": over.value,
				"is-compact": __props.compact
			}] }, _attrs))}><input type="file" multiple class="k-sr-only"${ssrRenderAttr("accept", accept.value)} tabindex="-1" aria-hidden="true"><div class="k-upload__target">`);
			_push(ssrRenderComponent(_sfc_main$14, { name: "cloud-arrow-up" }, null, _parent));
			_push(`<p class="k-upload__text"><button type="button" class="k-link-btn"${ssrIncludeBooleanAttr(unref(limits).data.value && !unref(limits).data.value.can_upload) ? " disabled" : ""}>${ssrInterpolate(unref(__)("Choose files"))}</button> ${ssrInterpolate(unref(__)("or drop them here."))} <span class="k-muted k-small">${ssrInterpolate(hint.value)}</span></p></div>`);
			if (queue.length) {
				_push(`<ul class="k-upload__queue" aria-live="polite"><!--[-->`);
				ssrRenderList(queue, (q) => {
					_push(`<li class="${ssrRenderClass([{
						"has-error": q.error,
						"is-done": q.progress === 1
					}, "k-upload__item"])}"><span class="k-upload__name">${ssrInterpolate(q.name)}</span><span class="k-muted k-small k-num">${ssrInterpolate(unref(formatBytes)(q.size))}</span>`);
					if (q.error) _push(`<span class="k-upload__error">${ssrInterpolate(q.error)}</span>`);
					else if (q.progress === 1) {
						_push(`<span class="k-upload__done">`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "check" }, null, _parent));
						_push(` ${ssrInterpolate(unref(__)("Added"))}</span>`);
					} else _push(`<progress class="k-upload__bar" max="1"${ssrRenderAttr("value", q.progress)}${ssrRenderAttr("aria-label", unref(__)("Uploading {name}", { name: q.name }))}></progress>`);
					if (q.progress < 1) {
						_push(`<button type="button" class="k-upload__x"${ssrRenderAttr("aria-label", q.error ? unref(__)("Dismiss") : unref(__)("Cancel upload"))}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "x" }, null, _parent));
						_push(`</button>`);
					} else _push(`<!---->`);
					_push(`</li>`);
				});
				_push(`<!--]--></ul>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$20 = _sfc_main$36.setup;
_sfc_main$36.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KUploadZone.vue");
	return _sfc_setup$20 ? _sfc_setup$20(props, ctx) : void 0;
};
// core/resources/js/ui/KMediaPicker.vue
/**
* Choose files from the media library (or upload new ones).
*   <KMediaPicker v-model:open="open" :kinds="['image']" multiple :selected="ids" @select="(items) => …" />
* Sites can swap it: $cms->admin->replace('media.picker', '@acme/MyPicker') gets the same props and events.
*/
var _sfc_main$18 = {
	__name: "KMediaPicker",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		kinds: {
			type: Array,
			default: () => []
		},
		multiple: Boolean,
		selected: {
			type: Array,
			default: () => []
		},
		title: {
			type: String,
			default: ""
		}
	}, {
		"open": {
			type: Boolean,
			default: false
		},
		"openModifiers": {}
	}),
	emits: /*@__PURE__*/ mergeModels(["select"], ["update:open"]),
	setup(__props, { emit: __emit }) {
		const open = useModel(__props, "open");
		const props = __props;
		const emit = __emit;
		const api = useApi();
		const items = ref([]);
		const page = ref(1);
		const last = ref(1);
		const loading = ref(false);
		const failed = ref(null);
		const search = ref("");
		const kind = ref("");
		const chosen = ref([]);
		const kindOptions = computed(() => props.kinds.length === 1 ? [] : [{
			value: "",
			label: __("All files")
		}, ...[
			"image",
			"document",
			"audio",
			"video",
			"archive"
		].filter((k) => !props.kinds.length || props.kinds.includes(k)).map((k) => ({
			value: k,
			label: {
				image: __("Images"),
				document: __("Documents"),
				audio: __("Audio"),
				video: __("Video"),
				archive: __("Archives")
			}[k]
		}))]);
		const effectiveKind = computed(() => kind.value || (props.kinds.length === 1 ? props.kinds[0] : ""));
		async function load(reset = false) {
			loading.value = true;
			failed.value = null;
			try {
				let next = reset ? 1 : page.value + 1;
				let found = reset ? [] : [...items.value];
				for (let tries = 0; tries < 10; tries++) {
					const res = await api.get("/media", {
						page: next,
						per_page: 40,
						search: search.value || void 0,
						kind: effectiveKind.value || void 0
					});
					const allowed = props.kinds.length ? res.data.filter((m) => props.kinds.includes(m.kind)) : res.data;
					found = [...found, ...allowed];
					page.value = res.meta.page;
					last.value = res.meta.last_page;
					if (allowed.length || res.meta.page >= res.meta.last_page) break;
					next = res.meta.page + 1;
				}
				items.value = found;
			} catch (e) {
				failed.value = e;
			} finally {
				loading.value = false;
			}
		}
		let timer;
		watch(search, () => {
			clearTimeout(timer);
			timer = setTimeout(() => load(true), 250);
		});
		watch(kind, () => load(true));
		watch(open, (on) => {
			if (!on) return;
			chosen.value = [];
			load(true).then(() => {
				chosen.value = items.value.filter((m) => props.selected.includes(m.id));
			});
		}, { immediate: true });
		const ids = computed(() => chosen.value.map((m) => m.id));
		function pick(m) {
			if (!props.multiple) {
				chosen.value = ids.value.includes(m.id) ? [] : [m];
				return;
			}
			chosen.value = ids.value.includes(m.id) ? chosen.value.filter((x) => x.id !== m.id) : [...chosen.value, m];
		}
		function uploaded(m) {
			if (props.kinds.length && !props.kinds.includes(m.kind)) return;
			items.value = [m, ...items.value];
			pick(m);
		}
		function confirmPick() {
			emit("select", chosen.value);
			open.value = false;
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$19, mergeProps({
				open: open.value,
				"onUpdate:open": ($event) => open.value = $event,
				title: __props.title || (__props.multiple ? unref(__)("Choose files") : __props.kinds.length === 1 && __props.kinds[0] === "image" ? unref(__)("Choose an image") : unref(__)("Choose a file")),
				size: "xl",
				onClose: ($event) => open.value = false
			}, _attrs), {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<span class="k-muted k-small k-mpicker__count"${_scopeId}>${ssrInterpolate(__props.multiple ? unref(__)("{n} selected", { n: chosen.value.length }) : chosen.value[0]?.title ?? "")}</span>`);
						_push(ssrRenderComponent(_sfc_main$6, { onClick: ($event) => open.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(_sfc_main$6, {
							variant: "primary",
							disabled: !chosen.value.length,
							onClick: confirmPick
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(__props.multiple ? unref(__)("Use {n} files", { n: chosen.value.length }) : unref(__)("Use this file"))}`);
								else return [createTextVNode(toDisplayString(__props.multiple ? unref(__)("Use {n} files", { n: chosen.value.length }) : unref(__)("Use this file")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [
						createVNode("span", { class: "k-muted k-small k-mpicker__count" }, toDisplayString(__props.multiple ? unref(__)("{n} selected", { n: chosen.value.length }) : chosen.value[0]?.title ?? ""), 1),
						createVNode(_sfc_main$6, { onClick: ($event) => open.value = false }, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
							_: 1
						}, 8, ["onClick"]),
						createVNode(_sfc_main$6, {
							variant: "primary",
							disabled: !chosen.value.length,
							onClick: confirmPick
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(__props.multiple ? unref(__)("Use {n} files", { n: chosen.value.length }) : unref(__)("Use this file")), 1)]),
							_: 1
						}, 8, ["disabled"])
					];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-mpicker"${_scopeId}>`);
						_push(ssrRenderComponent(_sfc_main$36, {
							compact: "",
							kind: __props.kinds.length === 1 ? __props.kinds[0] : "",
							onUploaded: uploaded
						}, null, _parent, _scopeId));
						_push(`<div class="k-mpicker__bar"${_scopeId}><label class="k-inline-field k-mpicker__search"${_scopeId}><span${_scopeId}>${ssrInterpolate(unref(__)("Search"))}</span><input${ssrRenderAttr("value", search.value)} type="search" class="k-input"${ssrRenderAttr("aria-label", unref(__)("Search by title, file name or alt text"))}${_scopeId}></label>`);
						if (kindOptions.value.length) _push(ssrRenderComponent(_sfc_main$25, {
							modelValue: kind.value,
							"onUpdate:modelValue": ($event) => kind.value = $event,
							options: kindOptions.value,
							"aria-label": unref(__)("Kind of file"),
							class: "k-mpicker__kind"
						}, null, _parent, _scopeId));
						else _push(`<!---->`);
						_push(`</div>`);
						if (items.value.length) _push(ssrRenderComponent(_sfc_main$17, {
							items: items.value,
							selected: ids.value,
							selectable: "",
							label: unref(__)("Media library"),
							onPick: pick
						}, null, _parent, _scopeId));
						else if (failed.value) _push(`<p class="k-mpicker__empty" role="alert"${_scopeId}>${ssrInterpolate(failed.value.message || unref(__)("The media library could not be loaded."))}</p>`);
						else if (!loading.value) _push(`<p class="k-muted k-mpicker__empty"${_scopeId}>${ssrInterpolate(search.value ? unref(__)("Nothing matches “{q}”.", { q: search.value }) : unref(__)("No files yet. Upload one above."))}</p>`);
						else _push(`<!---->`);
						if (page.value < last.value) {
							_push(`<div class="k-mpicker__more"${_scopeId}>`);
							_push(ssrRenderComponent(_sfc_main$6, {
								loading: loading.value,
								onClick: ($event) => load()
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Show more"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Show more")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else _push(`<!---->`);
						_push(`</div>`);
					} else return [createVNode("div", { class: "k-mpicker" }, [
						createVNode(_sfc_main$36, {
							compact: "",
							kind: __props.kinds.length === 1 ? __props.kinds[0] : "",
							onUploaded: uploaded
						}, null, 8, ["kind"]),
						createVNode("div", { class: "k-mpicker__bar" }, [createVNode("label", { class: "k-inline-field k-mpicker__search" }, [createVNode("span", null, toDisplayString(unref(__)("Search")), 1), withDirectives(createVNode("input", {
							"onUpdate:modelValue": ($event) => search.value = $event,
							type: "search",
							class: "k-input",
							"aria-label": unref(__)("Search by title, file name or alt text")
						}, null, 8, ["onUpdate:modelValue", "aria-label"]), [[vModelText, search.value]])]), kindOptions.value.length ? (openBlock(), createBlock(_sfc_main$25, {
							key: 0,
							modelValue: kind.value,
							"onUpdate:modelValue": ($event) => kind.value = $event,
							options: kindOptions.value,
							"aria-label": unref(__)("Kind of file"),
							class: "k-mpicker__kind"
						}, null, 8, [
							"modelValue",
							"onUpdate:modelValue",
							"options",
							"aria-label"
						])) : createCommentVNode("v-if", true)]),
						items.value.length ? (openBlock(), createBlock(_sfc_main$17, {
							key: 0,
							items: items.value,
							selected: ids.value,
							selectable: "",
							label: unref(__)("Media library"),
							onPick: pick
						}, null, 8, [
							"items",
							"selected",
							"label"
						])) : failed.value ? (openBlock(), createBlock("p", {
							key: 1,
							class: "k-mpicker__empty",
							role: "alert"
						}, toDisplayString(failed.value.message || unref(__)("The media library could not be loaded.")), 1)) : !loading.value ? (openBlock(), createBlock("p", {
							key: 2,
							class: "k-muted k-mpicker__empty"
						}, toDisplayString(search.value ? unref(__)("Nothing matches “{q}”.", { q: search.value }) : unref(__)("No files yet. Upload one above.")), 1)) : createCommentVNode("v-if", true),
						page.value < last.value ? (openBlock(), createBlock("div", {
							key: 3,
							class: "k-mpicker__more"
						}, [createVNode(_sfc_main$6, {
							loading: loading.value,
							onClick: ($event) => load()
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Show more")), 1)]),
							_: 1
						}, 8, ["loading", "onClick"])])) : createCommentVNode("v-if", true)
					])];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup$19 = _sfc_main$18.setup;
_sfc_main$18.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KMediaPicker.vue");
	return _sfc_setup$19 ? _sfc_setup$19(props, ctx) : void 0;
};
// core/resources/js/ui/KBlockSettings.vue
/**
* The settings of one block: its fields (typed or bound to data), who sees
* it and on which screens, and (for people who design) what editors may
* not change. Emits patches: { props } | { visibility } | { lock }.
*/
var _sfc_main$53 = {
	__name: "KBlockSettings",
	__ssrInlineRender: true,
	props: {
		node: {
			type: Object,
			required: true
		},
		def: {
			type: Object,
			required: true
		},
		bindings: {
			type: Array,
			default: () => []
		},
		bindingLabel: {
			type: Function,
			default: (p) => p
		},
		disabled: Boolean,
		lockable: Boolean,
		open: {
			type: String,
			default: ""
		},
		hideAbout: Boolean,
		inline: {
			type: Array,
			default: () => []
		}
	},
	emits: ["update"],
	setup(__props, { emit: __emit }) {
		const props = __props;
		const emit = __emit;
		const setProp = (key, value) => emit("update", { props: {
			...props.node.props ?? {},
			[key]: value
		} });
		const bindable = (field) => (props.def.bindable ?? []).includes(field.name);
		const bound = (field) => {
			const v = props.node.props?.[field.name];
			return v && typeof v === "object" && typeof v.bind === "string" ? v.bind : null;
		};
		const bindChoices = computed(() => props.bindings.map((b) => b.path));
		const wildcard = (path) => (path ?? "").startsWith("entry.fields.");
		const vis = computed(() => props.node.visibility ?? {});
		const DEVICES = [
			[
				"phone",
				__("Phones"),
				"device-mobile"
			],
			[
				"tablet",
				__("Tablets"),
				"device-tablet"
			],
			[
				"desktop",
				__("Larger screens"),
				"monitor"
			]
		];
		const hidden = (d) => (vis.value.hide ?? []).includes(d);
		const toLocal = (iso) => {
			if (!iso) return "";
			const d = new Date(iso);
			if (Number.isNaN(d.getTime())) return "";
			const pad = (n) => String(n).padStart(2, "0");
			return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
		};
		const LOCKS = [
			["move", __("Moving it")],
			["remove", __("Removing it")],
			["edit", __("Changing its settings")]
		];
		const locked = (k) => (props.node.lock ?? []).includes(k);
		const editLocked = computed(() => !props.lockable && locked("edit"));
		const uid = computed(() => props.node.id ?? "b");
		const unfolded = ref(/* @__PURE__ */ new Set());
		const folded = (field) => field.type === "rich_text" && props.inline.includes(field.name) && bound(field) === null && !unfolded.value.has(field.name);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-block-settings" }, _attrs))} data-v-46f5c5bd>`);
			if (__props.def.description && !__props.hideAbout) _push(`<p class="k-block-settings__about" data-v-46f5c5bd>${ssrInterpolate(__props.def.description)}</p>`);
			else _push(`<!---->`);
			if (editLocked.value) {
				_push(`<p class="k-block-settings__locked" data-v-46f5c5bd>`);
				_push(ssrRenderComponent(_sfc_main$14, { name: "lock" }, null, _parent));
				_push(`${ssrInterpolate(unref(__)("This block's settings are locked by the layout."))}</p>`);
			} else _push(`<!---->`);
			_push(`<div class="k-fields" data-v-46f5c5bd><!--[-->`);
			ssrRenderList(__props.def.fields, (field) => {
				_push(`<div class="${ssrRenderClass([[`k-content-field--${field.width === "half" ? "half" : "full"}`, { "is-bindable": bindable(field) && !__props.disabled && !editLocked.value }], "k-block-settings__prop"])}" data-v-46f5c5bd>`);
				if (bound(field) !== null) {
					_push(`<div class="k-field" data-v-46f5c5bd><label class="k-field__label"${ssrRenderAttr("for", `bind-${uid.value}-${field.name}`)} data-v-46f5c5bd>${ssrInterpolate(field.label)}</label><div class="k-block-settings__bind" data-v-46f5c5bd><select${ssrRenderAttr("id", `bind-${uid.value}-${field.name}`)} class="k-input k-select"${ssrRenderAttr("value", wildcard(bound(field)) ? "entry.fields.*" : bound(field))}${ssrIncludeBooleanAttr(__props.disabled || editLocked.value) ? " disabled" : ""} data-v-46f5c5bd><!--[-->`);
					ssrRenderList(bindChoices.value, (b) => {
						_push(`<option${ssrRenderAttr("value", b)} data-v-46f5c5bd>${ssrInterpolate(__props.bindingLabel(b))}</option>`);
					});
					_push(`<!--]--></select>`);
					if (wildcard(bound(field))) _push(`<input class="k-input k-block-settings__bind-name"${ssrRenderAttr("value", bound(field).slice(13))}${ssrRenderAttr("placeholder", unref(__)("field name"))}${ssrRenderAttr("aria-label", unref(__)("Field name"))}${ssrIncludeBooleanAttr(__props.disabled || editLocked.value) ? " disabled" : ""} spellcheck="false" data-v-46f5c5bd>`);
					else _push(`<!---->`);
					_push(`</div></div>`);
				} else if (folded(field)) {
					_push(`<div class="k-field" data-v-46f5c5bd><span class="k-field__label" data-v-46f5c5bd>${ssrInterpolate(field.label)}</span><p class="k-block-settings__onpage" data-v-46f5c5bd>`);
					_push(ssrRenderComponent(_sfc_main$14, { name: "cursor-text" }, null, _parent));
					_push(`${ssrInterpolate(unref(__)("Edited on the page."))} <button type="button" class="k-block-settings__bind-toggle" data-v-46f5c5bd>${ssrInterpolate(unref(__)("Edit here instead"))}</button></p></div>`);
				} else _push(ssrRenderComponent(_sfc_main$8, {
					"model-value": __props.node.props?.[field.name] ?? null,
					field,
					path: "block",
					disabled: __props.disabled || editLocked.value,
					"onUpdate:modelValue": ($event) => setProp(field.name, $event)
				}, null, _parent));
				_push(`<!-- showing data: said in words under the choice; typed: a small button by the label offers it -->`);
				if (bindable(field) && !__props.disabled && !editLocked.value && bound(field) !== null) {
					_push(`<button type="button" class="k-block-settings__bind-toggle" data-v-46f5c5bd>`);
					_push(ssrRenderComponent(_sfc_main$14, { name: "pencil" }, null, _parent));
					_push(`${ssrInterpolate(unref(__)("Type it instead"))}</button>`);
				} else if (bindable(field) && !__props.disabled && !editLocked.value) {
					_push(`<button type="button" class="k-block-settings__bind-icon"${ssrRenderAttr("title", unref(__)("Show entry or site data instead"))}${ssrRenderAttr("aria-label", unref(__)("{field}: show entry or site data instead", { field: field.label }))} data-v-46f5c5bd>`);
					_push(ssrRenderComponent(_sfc_main$14, { name: "database" }, null, _parent));
					_push(`</button>`);
				} else _push(`<!---->`);
				_push(`</div>`);
			});
			_push(`<!--]--></div><details class="k-block-settings__more"${ssrIncludeBooleanAttr(__props.open === "visibility" || !!__props.node.visibility) ? " open" : ""} data-v-46f5c5bd><summary data-v-46f5c5bd>${ssrInterpolate(unref(__)("Who sees this block"))}</summary><div class="k-block-settings__group" data-v-46f5c5bd><div class="k-field" data-v-46f5c5bd><label class="k-field__label"${ssrRenderAttr("for", `vis-${uid.value}`)} data-v-46f5c5bd>${ssrInterpolate(unref(__)("Visitors"))}</label><select${ssrRenderAttr("id", `vis-${uid.value}`)} class="k-input k-select"${ssrRenderAttr("value", vis.value.auth ?? "")}${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""} data-v-46f5c5bd><option value="" data-v-46f5c5bd>${ssrInterpolate(unref(__)("Everyone"))}</option><option value="guest" data-v-46f5c5bd>${ssrInterpolate(unref(__)("Only visitors who are not signed in"))}</option><option value="user" data-v-46f5c5bd>${ssrInterpolate(unref(__)("Only signed-in members"))}</option></select></div><fieldset class="k-block-settings__devices" data-v-46f5c5bd><legend class="k-field__label" data-v-46f5c5bd>${ssrInterpolate(unref(__)("Screens"))}</legend><!--[-->`);
			ssrRenderList(DEVICES, ([d, label, icon]) => {
				_push(`<button type="button" class="${ssrRenderClass([{ "is-off": hidden(d) }, "k-block-settings__device"])}"${ssrRenderAttr("aria-pressed", String(!hidden(d)))}${ssrIncludeBooleanAttr(__props.disabled || !hidden(d) && (vis.value.hide ?? []).length === DEVICES.length - 1) ? " disabled" : ""} data-v-46f5c5bd>`);
				_push(ssrRenderComponent(_sfc_main$14, { name: hidden(d) ? "eye-slash" : icon }, null, _parent));
				_push(`${ssrInterpolate(label)}</button>`);
			});
			_push(`<!--]--></fieldset><div class="k-field" data-v-46f5c5bd><label class="k-field__label"${ssrRenderAttr("for", `from-${uid.value}`)} data-v-46f5c5bd>${ssrInterpolate(unref(__)("Show from"))}</label><input${ssrRenderAttr("id", `from-${uid.value}`)} type="datetime-local" class="k-input"${ssrRenderAttr("value", toLocal(vis.value.from))}${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""} data-v-46f5c5bd></div><div class="k-field" data-v-46f5c5bd><label class="k-field__label"${ssrRenderAttr("for", `until-${uid.value}`)} data-v-46f5c5bd>${ssrInterpolate(unref(__)("Hide after"))}</label><input${ssrRenderAttr("id", `until-${uid.value}`)} type="datetime-local" class="k-input"${ssrRenderAttr("value", toLocal(vis.value.until))}${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""} data-v-46f5c5bd></div></div></details>`);
			if (__props.lockable) {
				_push(`<details class="k-block-settings__more"${ssrIncludeBooleanAttr(!!__props.node.lock) ? " open" : ""} data-v-46f5c5bd><summary data-v-46f5c5bd>${ssrInterpolate(unref(__)("What editors can't change"))}</summary><div class="k-block-settings__group" data-v-46f5c5bd><p class="k-muted k-small" data-v-46f5c5bd>${ssrInterpolate(unref(__)("For pages built from this layout. People who design can still change everything."))}</p><!--[-->`);
				ssrRenderList(LOCKS, ([k, label]) => {
					_push(`<label class="k-block-settings__lock" data-v-46f5c5bd><input type="checkbox"${ssrIncludeBooleanAttr(locked(k)) ? " checked" : ""}${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""} data-v-46f5c5bd> ${ssrInterpolate(label)}</label>`);
				});
				_push(`<!--]--></div></details>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$18 = _sfc_main$53.setup;
_sfc_main$53.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KBlockSettings.vue");
	return _sfc_setup$18 ? _sfc_setup$18(props, ctx) : void 0;
};
var KBlockSettings_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main$53, [["__scopeId", "data-v-46f5c5bd"]]);
// core/resources/js/ui/KBlockList.vue
/**
* One level of a block tree in the editor (it nests itself for children).
* Lives inside the "@core/fields/Blocks" field, which provides the block
* catalog, the open/closed state and the inserter.
*/
var _sfc_main$4 = {
	__name: "KBlockList",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		parent: {
			type: Object,
			default: null
		},
		allowed: {
			type: Array,
			default: null
		},
		disabled: Boolean,
		trail: {
			type: String,
			default: ""
		}
	}, {
		"modelValue": {
			type: Array,
			default: () => []
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const blocks = useModel(__props, "modelValue");
		const props = __props;
		const editor = inject("kestello.blocks");
		const list = computed(() => Array.isArray(blocks.value) ? blocks.value : []);
		const defOf = (node) => editor.catalog.value.get(node?.type) ?? null;
		const name = (node, i) => `${defOf(node)?.label ?? node?.type} ${i + 1}`;
		const plain = (html) => String(html ?? "").replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
		function summary(node) {
			const def = defOf(node);
			if (!def) return __("Not available: its plugin may be off. It is kept, but not shown on the site.");
			const p = node.props ?? {};
			for (const f of def.fields) {
				const v = p[f.name];
				if (v && typeof v === "object" && typeof v.bind === "string") return __("Shows {data}", { data: editor.bindingLabel(v.bind) });
				if ([
					"text",
					"textarea",
					"rich_text",
					"url",
					"link"
				].includes(f.type) && v) return plain(v).slice(0, 90);
			}
			const choice = def.fields.find((f) => f.type === "select" && p[f.name]);
			if (choice) return choice.options.choices?.find((c) => c.value === p[choice.name])?.label ?? "";
			return "";
		}
		function visibilityNote(node) {
			const v = node.visibility;
			if (!v) return "";
			const parts = [];
			if (v.auth === "guest") parts.push(__("guests only"));
			if (v.auth === "user") parts.push(__("signed-in only"));
			if (v.roles?.length) parts.push(v.roles.join(", "));
			if (v.from || v.until) parts.push(__("scheduled"));
			if (v.hide?.length) parts.push(__("hidden on {screens}", { screens: v.hide.join(", ") }));
			return parts.join(", ");
		}
		const replace = (next) => {
			blocks.value = next;
		};
		function update(i, patch) {
			replace(list.value.map((n, j) => j === i ? {
				...n,
				...patch
			} : n));
		}
		function move(i, delta, event) {
			const j = i + delta;
			if (j < 0 || j >= list.value.length) return;
			const next = [...list.value];
			[next[i], next[j]] = [next[j], next[i]];
			replace(next);
			const root = event?.currentTarget?.closest(".k-blocks-ed__list");
			if (root) requestAnimationFrame(() => root.querySelectorAll(":scope > .k-blocks-ed__item > .k-blocks-ed__row .k-blocks-ed__toggle")[j]?.focus());
		}
		function duplicate(i) {
			const copy = editor.reid(JSON.parse(JSON.stringify(list.value[i])));
			const next = [...list.value];
			next.splice(i + 1, 0, copy);
			replace(next);
			editor.open(copy.id);
		}
		function remove(i) {
			replace(list.value.filter((_, j) => j !== i));
		}
		function insert(at) {
			editor.insert({
				parent: props.parent,
				allowed: props.allowed,
				where: props.trail
			}, (node) => {
				const next = [...list.value];
				next.splice(at, 0, node);
				replace(next);
				editor.open(node.id);
			});
		}
		const locked = (node, what) => (node.lock ?? []).includes(what);
		const addLabel = computed(() => props.parent ? __("Add to {block}", { block: props.parent.label }) : __("Add block"));
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-blocks-ed__level" }, _attrs))}>`);
			if (list.value.length) {
				_push(`<ol class="k-blocks-ed__list"><!--[-->`);
				ssrRenderList(list.value, (node, i) => {
					_push(`<li class="${ssrRenderClass([{
						"is-open": unref(editor).isOpen(node.id),
						"is-missing": !defOf(node)
					}, "k-blocks-ed__item"])}"><div class="k-blocks-ed__row"><button type="button" class="k-blocks-ed__toggle"${ssrRenderAttr("aria-expanded", defOf(node) ? String(unref(editor).isOpen(node.id)) : void 0)}${ssrIncludeBooleanAttr(!defOf(node)) ? " disabled" : ""}${ssrRenderAttr("aria-label", `${name(node, i)}${summary(node) ? `: ${summary(node)}` : ""}`)}>`);
					_push(ssrRenderComponent(_sfc_main$14, {
						name: defOf(node)?.icon ?? "warning",
						class: "k-blocks-ed__icon"
					}, null, _parent));
					_push(`<span class="k-blocks-ed__label">${ssrInterpolate(defOf(node)?.label ?? node.type)}</span><span class="k-blocks-ed__summary">${ssrInterpolate(summary(node))}</span>`);
					if (visibilityNote(node)) {
						_push(`<span class="k-blocks-ed__flag">`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "eye-slash" }, null, _parent));
						_push(`${ssrInterpolate(visibilityNote(node))}</span>`);
					} else _push(`<!---->`);
					if (defOf(node)) _push(ssrRenderComponent(_sfc_main$14, {
						name: unref(editor).isOpen(node.id) ? "caret-up" : "caret-down",
						class: "k-blocks-ed__chevron"
					}, null, _parent));
					else _push(`<!---->`);
					_push(`</button>`);
					if (!__props.disabled) {
						_push(`<span class="k-blocks-ed__tools">`);
						if (node.lock?.length) _push(ssrRenderComponent(_sfc_main$14, {
							name: "lock",
							class: "k-blocks-ed__lock",
							title: unref(__)("Locked by the layout")
						}, null, _parent));
						else _push(`<!---->`);
						_push(ssrRenderComponent(_sfc_main$6, {
							size: "sm",
							variant: "ghost",
							icon: "arrow-up",
							disabled: i === 0 || locked(node, "move"),
							"aria-label": unref(__)("Move {block} up", { block: name(node, i) }),
							onClick: ($event) => move(i, -1, $event)
						}, null, _parent));
						_push(ssrRenderComponent(_sfc_main$6, {
							size: "sm",
							variant: "ghost",
							icon: "arrow-down",
							disabled: i === list.value.length - 1 || locked(node, "move"),
							"aria-label": unref(__)("Move {block} down", { block: name(node, i) }),
							onClick: ($event) => move(i, 1, $event)
						}, null, _parent));
						if (defOf(node)) _push(ssrRenderComponent(_sfc_main$6, {
							size: "sm",
							variant: "ghost",
							icon: "copy",
							"aria-label": unref(__)("Duplicate {block}", { block: name(node, i) }),
							onClick: ($event) => duplicate(i)
						}, null, _parent));
						else _push(`<!---->`);
						if (!locked(node, "remove")) _push(ssrRenderComponent(_sfc_main$6, {
							size: "sm",
							variant: "ghost",
							icon: "trash",
							"aria-label": unref(__)("Remove {block}", { block: name(node, i) }),
							onClick: ($event) => remove(i)
						}, null, _parent));
						else _push(`<!---->`);
						_push(`</span>`);
					} else _push(`<!---->`);
					_push(`</div>`);
					if (defOf(node) && unref(editor).isOpen(node.id)) {
						_push(`<div class="k-blocks-ed__panel">`);
						_push(ssrRenderComponent(KBlockSettings_default, {
							node,
							def: defOf(node),
							bindings: unref(editor).bindings.value,
							"binding-label": unref(editor).bindingLabel,
							disabled: __props.disabled,
							onUpdate: ($event) => update(i, $event)
						}, null, _parent));
						_push(`</div>`);
					} else _push(`<!---->`);
					if (defOf(node)?.children) {
						_push(`<div class="k-blocks-ed__children">`);
						_push(ssrRenderComponent(_sfc_main$4, {
							"model-value": node.children ?? [],
							parent: defOf(node),
							disabled: __props.disabled,
							trail: `${__props.trail ? `${__props.trail} › ` : ""}${name(node, i)}`,
							"onUpdate:modelValue": ($event) => update(i, { children: $event })
						}, null, _parent));
						_push(`</div>`);
					} else _push(`<!---->`);
					_push(`</li>`);
				});
				_push(`<!--]--></ol>`);
			} else if (!__props.parent) _push(`<p class="k-blocks-ed__empty">${ssrInterpolate(unref(__)("No blocks yet. Add a heading, text, an image or a list of entries to start the page."))}</p>`);
			else _push(`<!---->`);
			if (!__props.disabled) {
				_push(`<div class="k-blocks-ed__add">`);
				_push(ssrRenderComponent(_sfc_main$6, {
					size: "sm",
					variant: __props.parent ? "ghost" : "secondary",
					icon: "plus",
					onClick: ($event) => insert(list.value.length)
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(addLabel.value)}`);
						else return [createTextVNode(toDisplayString(addLabel.value), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$17 = _sfc_main$4.setup;
_sfc_main$4.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KBlockList.vue");
	return _sfc_setup$17 ? _sfc_setup$17(props, ctx) : void 0;
};
// core/resources/js/ui/KFinder.vue
/**
* The admin finder (Ctrl/Cmd+K): type a few letters, move with the arrow
* keys, press Enter to open. Results come from GET /admin/find, already
* limited to what the user may open. A combobox with a grouped listbox.
*/
var _sfc_main$13 = {
	__name: "KFinder",
	__ssrInlineRender: true,
	props: {
		"open": {
			type: Boolean,
			default: false
		},
		"openModifiers": {}
	},
	emits: ["update:open"],
	setup(__props) {
		const open = useModel(__props, "open");
		const api = useApi();
		const router = useRouter();
		const q = ref("");
		const groups = ref([]);
		const loading = ref(false);
		const failed = ref(false);
		const active = ref(0);
		const input = ref(null);
		const LABELS = {
			screens: __("Screens"),
			entries: __("Content"),
			media: __("Media"),
			people: __("People")
		};
		const flat = computed(() => groups.value.flatMap((g) => g.items.map((item) => ({
			...item,
			group: g.id
		}))));
		const optionId = (i) => `k-finder-opt-${i}`;
		const indexOf = (gi, ii) => groups.value.slice(0, gi).reduce((n, g) => n + g.items.length, 0) + ii;
		let timer = null;
		let seq = 0;
		watch(q, (value) => {
			clearTimeout(timer);
			const text = value.trim();
			if (!text) {
				groups.value = [];
				loading.value = false;
				return;
			}
			loading.value = true;
			timer = setTimeout(async () => {
				const mine = ++seq;
				try {
					const res = await api.get("/admin/find", { q: text.slice(0, 100) });
					if (mine !== seq) return;
					groups.value = res.data.groups;
					failed.value = false;
					active.value = 0;
				} catch {
					if (mine === seq) failed.value = true;
				} finally {
					if (mine === seq) loading.value = false;
				}
			}, 160);
		});
		watch(open, async (value) => {
			if (value) {
				q.value = "";
				groups.value = [];
				await nextTick();
				input.value?.focus();
			}
		});
		function go(item) {
			if (!item) return;
			open.value = false;
			router.visit(item.href);
		}
		function onKey(event) {
			const n = flat.value.length;
			if (event.key === "ArrowDown" && n) {
				event.preventDefault();
				active.value = (active.value + 1) % n;
				scroll();
			} else if (event.key === "ArrowUp" && n) {
				event.preventDefault();
				active.value = (active.value - 1 + n) % n;
				scroll();
			} else if (event.key === "Home" && n && event.ctrlKey) {
				event.preventDefault();
				active.value = 0;
				scroll();
			} else if (event.key === "Enter") {
				event.preventDefault();
				go(flat.value[active.value]);
			}
		}
		function scroll() {
			nextTick(() => document.getElementById(optionId(active.value))?.scrollIntoView({ block: "nearest" }));
		}
		const status = computed(() => {
			if (!q.value.trim()) return "";
			if (loading.value) return __("Searching…");
			if (failed.value) return __("Search failed. Try again.");
			return flat.value.length === 0 ? __("Nothing found for “{q}”.", { q: q.value.trim() }) : flat.value.length === 1 ? __("1 result") : __("{n} results", { n: flat.value.length });
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$19, mergeProps({
				open: open.value,
				"onUpdate:open": ($event) => open.value = $event,
				title: unref(__)("Find"),
				size: "md"
			}, _attrs), {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<p class="k-finder__keys"${_scopeId}><kbd${ssrRenderAttr("aria-label", unref(__)("Up arrow"))}${_scopeId}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "arrow-up" }, null, _parent, _scopeId));
						_push(`</kbd><kbd${ssrRenderAttr("aria-label", unref(__)("Down arrow"))}${_scopeId}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "arrow-down" }, null, _parent, _scopeId));
						_push(`</kbd> ${ssrInterpolate(unref(__)("to move"))} <kbd${_scopeId}>Enter</kbd> ${ssrInterpolate(unref(__)("to open"))} <kbd${_scopeId}>Esc</kbd> ${ssrInterpolate(unref(__)("to close"))}</p>`);
					} else return [createVNode("p", { class: "k-finder__keys" }, [
						createVNode("kbd", { "aria-label": unref(__)("Up arrow") }, [createVNode(_sfc_main$14, { name: "arrow-up" })], 8, ["aria-label"]),
						createVNode("kbd", { "aria-label": unref(__)("Down arrow") }, [createVNode(_sfc_main$14, { name: "arrow-down" })], 8, ["aria-label"]),
						createTextVNode(" " + toDisplayString(unref(__)("to move")) + " ", 1),
						createVNode("kbd", null, "Enter"),
						createTextVNode(" " + toDisplayString(unref(__)("to open")) + " ", 1),
						createVNode("kbd", null, "Esc"),
						createTextVNode(" " + toDisplayString(unref(__)("to close")), 1)
					])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-finder"${_scopeId}><div class="k-finder__bar"${_scopeId}>`);
						_push(ssrRenderComponent(_sfc_main$14, {
							name: "magnifying-glass",
							class: "k-finder__glass"
						}, null, _parent, _scopeId));
						_push(`<input${ssrRenderAttr("value", q.value)} class="k-finder__input" type="text" autocomplete="off" spellcheck="false" maxlength="100" autofocus role="combobox" aria-autocomplete="list"${ssrRenderAttr("aria-expanded", flat.value.length ? "true" : "false")} aria-controls="k-finder-list"${ssrRenderAttr("aria-activedescendant", flat.value.length ? optionId(active.value) : void 0)}${ssrRenderAttr("aria-label", unref(__)("Find screens, content, media and people"))}${ssrRenderAttr("placeholder", unref(__)("Screens, content, media, people"))}${_scopeId}>`);
						if (loading.value) _push(ssrRenderComponent(_sfc_main$27, { class: "k-finder__spin" }, null, _parent, _scopeId));
						else _push(`<!---->`);
						_push(`</div><p class="k-finder__status" role="status" aria-live="polite"${_scopeId}>${ssrInterpolate(status.value)}</p><div id="k-finder-list" class="k-finder__list" role="listbox"${ssrRenderAttr("aria-label", unref(__)("Results"))}${_scopeId}><!--[-->`);
						ssrRenderList(groups.value, (group, gi) => {
							_push(`<div class="k-finder__group" role="group"${ssrRenderAttr("aria-labelledby", `k-finder-g-${group.id}`)}${_scopeId}><p${ssrRenderAttr("id", `k-finder-g-${group.id}`)} class="k-finder__heading" role="presentation"${_scopeId}>${ssrInterpolate(LABELS[group.id] ?? group.label)}</p><!--[-->`);
							ssrRenderList(group.items, (item, ii) => {
								_push(`<div${ssrRenderAttr("id", optionId(indexOf(gi, ii)))} class="k-finder__item" role="option"${ssrRenderAttr("aria-selected", indexOf(gi, ii) === active.value ? "true" : "false")}${_scopeId}>`);
								_push(ssrRenderComponent(_sfc_main$14, {
									name: item.icon,
									class: "k-finder__icon"
								}, null, _parent, _scopeId));
								_push(`<span class="k-finder__label"${_scopeId}>${ssrInterpolate(item.label)}</span><span class="k-finder__detail"${_scopeId}>${ssrInterpolate(item.detail)}</span></div>`);
							});
							_push(`<!--]--></div>`);
						});
						_push(`<!--]--></div>`);
						if (!q.value.trim()) _push(`<p class="k-finder__hint"${_scopeId}>${ssrInterpolate(unref(__)("Type a few letters of a title, file name, person or screen."))}</p>`);
						else _push(`<!---->`);
						_push(`</div>`);
					} else return [createVNode("div", { class: "k-finder" }, [
						createVNode("div", { class: "k-finder__bar" }, [
							createVNode(_sfc_main$14, {
								name: "magnifying-glass",
								class: "k-finder__glass"
							}),
							withDirectives(createVNode("input", {
								ref_key: "input",
								ref: input,
								"onUpdate:modelValue": ($event) => q.value = $event,
								class: "k-finder__input",
								type: "text",
								autocomplete: "off",
								spellcheck: "false",
								maxlength: "100",
								autofocus: "",
								role: "combobox",
								"aria-autocomplete": "list",
								"aria-expanded": flat.value.length ? "true" : "false",
								"aria-controls": "k-finder-list",
								"aria-activedescendant": flat.value.length ? optionId(active.value) : void 0,
								"aria-label": unref(__)("Find screens, content, media and people"),
								placeholder: unref(__)("Screens, content, media, people"),
								onKeydown: onKey
							}, null, 40, [
								"onUpdate:modelValue",
								"aria-expanded",
								"aria-activedescendant",
								"aria-label",
								"placeholder"
							]), [[vModelText, q.value]]),
							loading.value ? (openBlock(), createBlock(_sfc_main$27, {
								key: 0,
								class: "k-finder__spin"
							})) : createCommentVNode("v-if", true)
						]),
						createVNode("p", {
							class: "k-finder__status",
							role: "status",
							"aria-live": "polite"
						}, toDisplayString(status.value), 1),
						createVNode("div", {
							id: "k-finder-list",
							class: "k-finder__list",
							role: "listbox",
							"aria-label": unref(__)("Results")
						}, [(openBlock(true), createBlock(Fragment, null, renderList(groups.value, (group, gi) => {
							return openBlock(), createBlock("div", {
								key: group.id,
								class: "k-finder__group",
								role: "group",
								"aria-labelledby": `k-finder-g-${group.id}`
							}, [createVNode("p", {
								id: `k-finder-g-${group.id}`,
								class: "k-finder__heading",
								role: "presentation"
							}, toDisplayString(LABELS[group.id] ?? group.label), 9, ["id"]), (openBlock(true), createBlock(Fragment, null, renderList(group.items, (item, ii) => {
								return openBlock(), createBlock("div", {
									id: optionId(indexOf(gi, ii)),
									key: item.href,
									class: "k-finder__item",
									role: "option",
									"aria-selected": indexOf(gi, ii) === active.value ? "true" : "false",
									onMousemove: ($event) => active.value = indexOf(gi, ii),
									onClick: ($event) => go(item)
								}, [
									createVNode(_sfc_main$14, {
										name: item.icon,
										class: "k-finder__icon"
									}, null, 8, ["name"]),
									createVNode("span", { class: "k-finder__label" }, toDisplayString(item.label), 1),
									createVNode("span", { class: "k-finder__detail" }, toDisplayString(item.detail), 1)
								], 40, [
									"id",
									"aria-selected",
									"onMousemove",
									"onClick"
								]);
							}), 128))], 8, ["aria-labelledby"]);
						}), 128))], 8, ["aria-label"]),
						!q.value.trim() ? (openBlock(), createBlock("p", {
							key: 0,
							class: "k-finder__hint"
						}, toDisplayString(unref(__)("Type a few letters of a title, file name, person or screen.")), 1)) : createCommentVNode("v-if", true)
					])];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup$16 = _sfc_main$13.setup;
_sfc_main$13.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KFinder.vue");
	return _sfc_setup$16 ? _sfc_setup$16(props, ctx) : void 0;
};
// core/resources/js/ui/builder/context.js
/**
* The visual editor is one shared state (KBuilder creates it) and the parts that
* show it: the bar, layers, library, toolbar, quick inserter and inspector. Each
* part reads the state with useBuilder(); nothing is passed down as props.
*
* The state is put together from composables, each adding its own fields to the
* same object, so a part can call into another (selecting a block also tells the
* canvas). Order of creation: catalog, document, canvas, drag, quick insert, panels.
*/
var BUILDER = Symbol("kestello.builder");
function provideBuilder(state) {
	provide(BUILDER, state);
	return state;
}
function useBuilder() {
	const state = inject(BUILDER, null);
	if (!state) throw new Error("useBuilder() is for the parts of KBuilder");
	return state;
}
// core/resources/js/ui/builder/tree.js
var tree_exports = /* @__PURE__ */ __exportAll({
	contains: () => contains,
	fits: () => fits,
	flatten: () => flatten,
	insert: () => insert,
	locate: () => locate,
	move: () => move,
	newId: () => newId,
	reid: () => reid,
	remove: () => remove,
	update: () => update
});
/**
* Pure operations on a block tree ({ type, id, props, children, visibility?, lock? }[]),
* used by the theme builder. Every operation returns a new tree; nodes that
* didn't change are shared, so undo snapshots stay cheap.
*/
var newId = () => Array.from(crypto.getRandomValues(/* @__PURE__ */ new Uint8Array(4)), (b) => b.toString(16).padStart(2, "0")).join("");
/** A deep copy with fresh ids (duplicate, paste, patterns). */
function reid(node) {
	return {
		...JSON.parse(JSON.stringify(node)),
		id: newId(),
		children: (node.children ?? []).map(reid)
	};
}
/** Where a node is: { node, parent (node | null), list, index, path (ancestors, outermost first) }. */
function locate(tree, id, parent = null, path = []) {
	for (let i = 0; i < tree.length; i++) {
		const node = tree[i];
		if (node.id === id) return {
			node,
			parent,
			list: tree,
			index: i,
			path
		};
		const found = locate(node.children ?? [], id, node, [...path, node]);
		if (found) return found;
	}
	return null;
}
function contains(node, id) {
	return (node.children ?? []).some((c) => c.id === id || contains(c, id));
}
function mapList(tree, parentId, fn) {
	if (parentId === null) return fn(tree);
	return tree.map((n) => {
		if (n.id === parentId) return {
			...n,
			children: fn(n.children ?? [])
		};
		const children = n.children ?? [];
		const next = mapList(children, parentId, fn);
		return next === children ? n : {
			...n,
			children: next
		};
	});
}
function update(tree, id, patch) {
	return tree.map((n) => {
		if (n.id === id) {
			const next = {
				...n,
				...patch
			};
			for (const k of Object.keys(patch)) if (patch[k] === void 0) delete next[k];
			return next;
		}
		const children = n.children ?? [];
		if (!children.length) return n;
		const next = update(children, id, patch);
		return next === children ? n : {
			...n,
			children: next
		};
	});
}
function remove(tree, id) {
	const at = locate(tree, id);
	if (!at) return tree;
	return mapList(tree, at.parent?.id ?? null, (list) => list.filter((n) => n.id !== id));
}
function insert(tree, parentId, index, node) {
	return mapList(tree, parentId, (list) => {
		const next = [...list];
		next.splice(Math.max(0, Math.min(index, next.length)), 0, node);
		return next;
	});
}
/** Move a node to another place; refuses to put a node inside itself. */
function move(tree, id, parentId, index) {
	const at = locate(tree, id);
	if (!at || parentId !== null && (parentId === id || contains(at.node, parentId))) return tree;
	const target = (at.parent?.id ?? null) === parentId && index > at.index ? index - 1 : index;
	return insert(remove(tree, id), parentId, target, at.node);
}
/** Every node, depth first, with its depth (the layers list). */
function flatten(tree, collapsed = /* @__PURE__ */ new Set(), depth = 0, out = []) {
	for (const node of tree) {
		out.push({
			node,
			depth
		});
		if (!collapsed.has(node.id)) flatten(node.children ?? [], collapsed, depth + 1, out);
	}
	return out;
}
/** May a block of type def go into parentDef (null: the top level, limited by allowed)? */
function fits(def, parentDef, allowed = null, hidden = []) {
	if (!def || hidden.includes(def.name)) return false;
	if (parentDef === null) return allowed ? allowed.includes(def.name) : def.parents === null;
	return (parentDef.children === true || Array.isArray(parentDef.children) && parentDef.children.includes(def.name)) && (def.parents === null || def.parents.includes(parentDef.name));
}
// core/resources/js/ui/builder/blocks.js
/**
* What the editor knows about blocks, given the catalog (name → definition as
* GET /blocks describes it). Pure functions: nothing here touches Vue or the page.
*/
var ENTITIES$1 = {
	amp: "&",
	lt: "<",
	gt: ">",
	quot: "\"",
	apos: "'",
	nbsp: " "
};
/** Text without its markup: tags dropped, entities read, spaces collapsed. */
var plain = (html) => String(html ?? "").replace(/<[^>]*>/g, " ").replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (m, e) => {
	if (e[0] !== "#") return ENTITIES$1[e.toLowerCase()] ?? m;
	const code = e[1] === "x" || e[1] === "X" ? parseInt(e.slice(2), 16) : Number(e.slice(1));
	return code > 0 && code < 1114112 ? String.fromCodePoint(code) : m;
}).replace(/\s+/g, " ").trim();
var esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({
	"&": "&amp;",
	"<": "&lt;",
	">": "&gt;",
	"\"": "&quot;"
})[c]);
/**
* A new block of this kind with its defaults. A required choice starts on its
* first option; a block made of one kind of child (columns, tabs) starts with
* some of them.
*/
function createBlock$1(def, catalog) {
	const values = {};
	for (const f of def.fields) if (f.default !== null && f.default !== void 0) values[f.name] = f.default;
	else if (f.required && f.type === "select" && f.options?.choices?.length) values[f.name] = f.options.choices[0].value;
	else if (f.required && Array.isArray(f.options?.suggest) && f.options.suggest.length) values[f.name] = f.options.suggest[0].value;
	const node = {
		type: def.name,
		id: newId(),
		props: values,
		children: []
	};
	if (Array.isArray(def.starter) && def.starter.length) {
		node.children = def.starter.filter((s) => catalog.has(s.type)).map((s) => {
			const child = createBlock$1(catalog.get(s.type), catalog);
			return {
				...child,
				props: {
					...child.props,
					...s.props ?? {}
				}
			};
		});
		return node;
	}
	const only = Array.isArray(def.children) && def.children.length === 1 ? catalog.get(def.children[0]) : null;
	if (only) {
		const count = def.name === "core/columns" ? String(values.layout ?? "1-1").split("-").length : 2;
		node.children = Array.from({ length: count }, () => createBlock$1(only, catalog));
	}
	return node;
}
/** A pattern as blocks: a synced pattern stays one reference; the others are copied (in a group when there are several). */
function fromPattern(pattern, catalog) {
	if (pattern.synced) return {
		type: "core/pattern",
		id: newId(),
		props: { pattern: pattern.key },
		children: []
	};
	const copies = pattern.blocks.filter((b) => catalog.has(b.type)).map(reid);
	if (copies.length === 1) return copies[0];
	return {
		...createBlock$1(catalog.get("core/group"), catalog),
		children: copies
	};
}
/** The kind of block a pattern becomes, before it is made (dragging decides where it may go). */
function patternDef(pattern, catalog) {
	if (pattern.synced) return catalog.get("core/pattern") ?? null;
	const usable = pattern.blocks.filter((b) => catalog.has(b.type));
	return usable.length === 1 ? catalog.get(usable[0].type) : catalog.get("core/group") ?? null;
}
var bound = (v) => v && typeof v === "object" && typeof v.bind === "string" ? v.bind : null;
var TEXT_TYPES$1 = [
	"text",
	"textarea",
	"rich_text",
	"url",
	"link"
];
/** One line about a block for the layers: its text, what data it shows, or its main choice. */
/** The words a block shows itself (text fields or bound data), or '' (null: no definition). */
function ownText(node, def, bindingLabel) {
	if (!def) return null;
	const p = node.props ?? {};
	for (const f of def.fields) {
		const v = p[f.name];
		if (bound(v)) return bindingLabel(bound(v));
		if (TEXT_TYPES$1.includes(f.type) && typeof v === "string" && plain(v)) return plain(v).slice(0, 60);
	}
	return "";
}
var choiceLabel = (f, value) => f.options?.choices?.find((c) => c.value === value)?.label ?? "";
/**
* A block in a few words, for the layers and lists: what a visitor would read in it, so it
* matches the page. Its own text first; a field marked as the summary (options.summary,
* such as the columns' layout); for a block holding others, the first words inside it;
* for any other block, its first choice that is set (a space's size).
* @param {(node: object) => object|null} [defOf]  needed to look inside a block holding others
*/
function summarize(node, def, bindingLabel = (p) => p, defOf = null) {
	const own = ownText(node, def, bindingLabel);
	if (own !== "") return own;
	const p = node.props ?? {};
	const marked = def.fields.find((f) => f.options?.summary && p[f.name] !== void 0 && p[f.name] !== "");
	if (marked) return marked.type === "select" ? choiceLabel(marked, p[marked.name]) : String(p[marked.name]);
	if (def.fields.some((f) => [
		"text",
		"textarea",
		"rich_text"
	].includes(f.type))) return "";
	if (def.children) return defOf ? firstWords(node.children ?? [], defOf, bindingLabel) : "";
	const choice = def.fields.find((f) => f.type === "select" && p[f.name] !== void 0 && p[f.name] !== "");
	return choice ? choiceLabel(choice, p[choice.name]) : "";
}
/** The first words found inside these blocks, depth first ('' when none). */
function firstWords(nodes, defOf, bindingLabel) {
	for (const n of nodes) {
		const own = ownText(n, defOf(n), bindingLabel);
		if (own) return own;
		const inner = firstWords(n.children ?? [], defOf, bindingLabel);
		if (inner) return inner;
	}
	return "";
}
/** The icon a block shows: the one for its field's value (iconBy, a heading's level), else its own. */
function blockIcon(node, def) {
	if (!def) return "warning";
	const by = def.iconBy;
	if (by?.field) {
		const field = def.fields?.find((f) => f.name === by.field);
		const value = node?.props?.[by.field] ?? field?.default;
		const icon = value === void 0 || value === null ? null : by.icons?.[String(value)];
		if (icon) return icon;
	}
	return def.icon ?? "square";
}
/** How each kind of text field is typed on the page. */
var INLINE = {
	text: "plain",
	textarea: "multi",
	rich_text: "rich"
};
/** For each block, the fields that may be typed straight into the page: text that holds text (not bound to data, not locked). */
function editableFields(tree, defOf, { disabled = false, locks = true } = {}) {
	const out = {};
	if (disabled) return out;
	const visit = (nodes) => {
		for (const n of nodes) {
			const def = defOf(n);
			if (def && !(locks && (n.lock ?? []).includes("edit"))) for (const f of def.fields) {
				const v = n.props?.[f.name];
				if (INLINE[f.type] && (v === void 0 || v === null || typeof v === "string")) (out[n.id] ??= {})[f.name] = INLINE[f.type];
			}
			visit(n.children ?? []);
		}
	};
	visit(tree);
	return out;
}
/** The first field of a block that is typed on the page. */
var firstInline = (def) => def?.fields.find((f) => INLINE[f.type])?.name ?? null;
var TEXTUAL = [
	"core/text",
	"core/heading",
	"core/quote",
	"core/list",
	"core/code",
	"core/details"
];
var ALIGNABLE = [
	"core/text",
	"core/heading",
	"core/quote"
];
/** A text block's content as lines. */
function linesOf(node) {
	const p = node.props ?? {};
	if (node.type === "core/text" || node.type === "core/details") {
		const parts = String(p.text ?? "").split(/<\/p>|<br\s*\/?>|<\/li>|<\/h[1-6]>/i).map(plain).filter(Boolean);
		return node.type === "core/details" && p.summary ? [p.summary, ...parts] : parts;
	}
	if (node.type === "core/list") return (p.items ?? []).map((i) => String(i?.text ?? "").trim()).filter(Boolean);
	if (node.type === "core/code") return String(p.code ?? "").split("\n");
	return String(p.text ?? "").split("\n").map((l) => l.trim()).filter(Boolean);
}
/** Is the block already this kind (and variant)? */
function isKind(node, type, variant) {
	if (node.type !== type) return false;
	if (type === "core/heading") return String(node.props?.level ?? "2") === String(variant ?? "2");
	if (type === "core/list") return !!node.props?.ordered === (variant === "ordered");
	return true;
}
/** The same block as another kind: same id and place, its text carried over. */
function turnInto(node, type, variant = null) {
	const lines = linesOf(node);
	const paras = (ls) => ls.map((l) => `<p>${esc(l)}</p>`).join("");
	const props = {
		"core/text": () => ({ text: paras(lines) }),
		"core/heading": () => ({
			text: lines.join(" "),
			level: variant ?? "2"
		}),
		"core/list": () => ({
			items: (lines.length ? lines : [""]).map((t) => ({ text: t.slice(0, 500) })),
			ordered: variant === "ordered"
		}),
		"core/quote": () => ({
			text: lines.join("\n"),
			cite: ""
		}),
		"core/code": () => ({
			code: lines.join("\n"),
			language: ""
		}),
		"core/details": () => ({
			summary: lines[0] ?? "",
			text: paras(lines.slice(1)),
			open: false
		})
	}[type]?.();
	if (!props) return node;
	const align = node.props?.align;
	if (align && ALIGNABLE.includes(type) && ALIGNABLE.includes(node.type)) props.align = align;
	return {
		...node,
		type,
		props,
		children: []
	};
}
/** Words, characters, paragraphs, blocks and the headings (the outline) of a tree. */
function documentStats(tree, defOf) {
	let words = 0, chars = 0, paragraphs = 0, blocks = 0;
	const headings = [];
	const count = (v) => {
		const t = plain(v);
		if (t) {
			words += t.split(" ").length;
			chars += t.length;
		}
		return t;
	};
	const visit = (nodes) => {
		for (const n of nodes) {
			blocks++;
			for (const f of defOf(n)?.fields ?? []) {
				const v = n.props?.[f.name];
				if ([
					"text",
					"textarea",
					"rich_text"
				].includes(f.type) && typeof v === "string") {
					const t = count(v);
					if (f.type === "rich_text") paragraphs += (v.match(/<p[\s>]/gi) ?? []).length || (t ? 1 : 0);
				} else if (f.type === "repeater" && Array.isArray(v)) {
					for (const item of v) for (const x of Object.values(item ?? {})) if (typeof x === "string") count(x);
				}
			}
			if (n.type === "core/heading") headings.push({
				id: n.id,
				level: Number(n.props?.level ?? 2),
				text: plain(n.props?.text)
			});
			visit(n.children ?? []);
		}
	};
	visit(tree);
	return {
		words,
		chars,
		paragraphs,
		blocks,
		headings,
		minutes: Math.max(1, Math.round(words / 220))
	};
}
/**
* The block a server error is about: "blocks.2.children.0.props.text" → the third block's first
* child, and the field. Null when the path no longer leads to a block.
*/
function errorTarget(tree, key) {
	const parts = String(key).split(".");
	if (parts.shift() !== "blocks") return null;
	let list = tree;
	let node = null;
	while (parts.length && /^\d+$/.test(parts[0])) {
		node = Array.isArray(list) ? list[Number(parts.shift())] : null;
		if (!node) return null;
		if (parts[0] !== "children") break;
		parts.shift();
		list = node.children;
	}
	if (!node) return null;
	return {
		id: node.id,
		field: parts[0] === "props" ? parts[1] ?? null : null
	};
}
/**
* The block a server message is about. Messages start with where the block is, as
* BlockRegistry writes it: "Block 2 (Group) › Block 1 (Text), Text: This field is required."
* Returns the block's id and the message without that trail, or null.
*/
function errorFromMessage(tree, message) {
	const m = /^((?:Block \d+(?: \([^)]*\))?(?: › )?)+)(?:, |: )?/.exec(String(message));
	if (!m) return null;
	let list = tree;
	let node = null;
	for (const [, n] of m[1].matchAll(/Block (\d+)/g)) {
		node = Array.isArray(list) ? list[Number(n) - 1] : null;
		if (!node) return null;
		list = node.children;
	}
	if (!node) return null;
	const label = /\(([^)]*)\)\s*$/.exec(m[1].trim())?.[1];
	let rest = String(message).slice(m[0].length);
	if (label && rest.startsWith(`${label}: `)) rest = rest.slice(label.length + 2);
	return {
		id: node.id,
		rest
	};
}
// core/resources/js/ui/builder/checks.js
/**
* Accessibility checks on what the canvas shows: the page as visitors get it,
* theme included. Returns issues: { tone: danger | warning, id, message }, where
* id is the block of the tree the problem is in (null when it is elsewhere).
*/
var VAGUE = [
	"click here",
	"here",
	"read more",
	"more",
	"link",
	"this"
];
/**
* @param {Document} doc        the canvas document
* @param {{ kind: string, owns: (id: string) => boolean, t: Function }} options
*        owns: is this block in the tree being edited; t: the translation function
*/
function checkPage(doc, { kind, owns, t }) {
	const scope = doc?.querySelector(".k-template");
	if (!doc || !scope) return [];
	const out = [];
	const blockOf = (el) => {
		for (let x = el.closest("[data-block-id]"); x; x = x.parentElement?.closest("[data-block-id]") ?? null) {
			const id = x.getAttribute("data-block-id");
			if (owns(id)) return id;
		}
		return null;
	};
	const issue = (tone, el, message) => out.push({
		tone,
		id: el ? blockOf(el) : null,
		message
	});
	const text = (el) => (el.textContent ?? "").replace(/\s+/g, " ").trim();
	const named = (el) => text(el) || el.getAttribute("aria-label") || el.getAttribute("title") || [...el.querySelectorAll("img[alt]")].some((i) => i.alt.trim());
	let prev = 0;
	for (const h of doc.querySelectorAll("h1, h2, h3, h4, h5, h6")) {
		const level = Number(h.tagName[1]);
		if (!text(h)) issue("danger", h, t("An empty heading. Screen reader users jump between headings."));
		if (prev && level > prev + 1 && scope.contains(h)) issue("warning", h, t("Heading level jumps from {a} to {b}. Use the levels in order.", {
			a: `h${prev}`,
			b: `h${level}`
		}));
		prev = level;
	}
	const h1s = doc.querySelectorAll("h1");
	if (["template", "entry"].includes(kind) && !h1s.length) issue("warning", null, t("The page has no main heading (h1). Show the entry title as Heading 1."));
	if (h1s.length > 1) issue("warning", h1s[1], t("More than one main heading (h1)."));
	for (const img of scope.querySelectorAll("img:not([alt])")) issue("danger", img, t("An image without alt text."));
	for (const a of scope.querySelectorAll("a[href]")) if (!named(a)) issue("danger", a, t("A link with no text: nobody can tell where it goes."));
	else if (VAGUE.includes(text(a).toLowerCase())) issue("warning", a, t("Link text “{t}” doesn't say where it goes.", { t: text(a) }));
	for (const b of scope.querySelectorAll("button")) if (!named(b)) issue("danger", b, t("A button with no text."));
	for (const f of scope.querySelectorAll("iframe:not([title])")) issue("warning", f, t("An embedded frame without a title."));
	for (const input of scope.querySelectorAll("input:not([type=hidden]):not([type=submit]), select, textarea")) if (!(input.getAttribute("aria-label") || input.getAttribute("aria-labelledby") || input.id && doc.querySelector(`label[for="${CSS.escape(input.id)}"]`) || input.closest("label"))) issue("danger", input, t("A form field without a label."));
	return out;
}
// core/resources/js/ui/builder/cache.js
/**
* What the builder keeps between visits in one browser tab:
* - the block catalog (shown at once next time, refreshed behind the scenes)
* - blocks the server has prepared, by their content, so undo, redo and
*   moving things around need no round trip. Entries expire quickly: some
*   blocks show other content (entry lists, synced patterns) that can change.
*/
var catalogCache = { value: null };
var PREPARED_MS = 12e4;
var PREPARED_MAX = 500;
var prepared = /* @__PURE__ */ new Map();
function preparedGet(key) {
	const hit = prepared.get(key);
	if (!hit) return void 0;
	if (Date.now() - hit.at > PREPARED_MS) {
		prepared.delete(key);
		return;
	}
	return hit.node;
}
function preparedSet(key, node) {
	prepared.delete(key);
	prepared.set(key, {
		at: Date.now(),
		node
	});
	while (prepared.size > PREPARED_MAX) prepared.delete(prepared.keys().next().value);
}
// core/resources/js/ui/builder/prepare.js
/**
* The server prepares blocks for the canvas (checks, cleans and fills them in).
* Each top-level block is prepared on its own terms and remembered by its
* content, so only blocks it hasn't seen in this form are sent: undo, redo and
* moving things around need no round trip.
*/
/**
* @param {object[]} nodes  the tree's top-level blocks
* @param {string} scope    what else the result depends on (the document, the entry previewed with, the revision)
* @param {(blocks: object[]) => Promise<object[]>} fetchPrepared  prepares blocks on the server
* @returns {Promise<object[]>} the prepared blocks, in order; a block the server left out (it didn't validate) is skipped
*/
async function prepareTree(nodes, scope, fetchPrepared) {
	const keys = nodes.map((n) => `${scope}:${JSON.stringify(n)}`);
	const found = keys.map((k) => preparedGet(k));
	const missing = nodes.filter((_, i) => found[i] === void 0);
	if (missing.length) {
		const out = await fetchPrepared(JSON.parse(JSON.stringify(missing)));
		const byId = new Map((Array.isArray(out) ? out : []).map((n) => [n?.id, n]));
		nodes.forEach((n, i) => {
			if (found[i] !== void 0) return;
			found[i] = byId.get(n.id) ?? null;
			preparedSet(keys[i], found[i]);
		});
	}
	return found.filter(Boolean);
}
// core/resources/js/ui/builder/toolbar-spot.js
/**
* Where the floating block toolbar goes (BuilderToolbar), in the canvas section's px.
*
* The canvas reports three bands the toolbar could sit in (app/canvas/room.js): just above
* the block, just below it, and inside its top edge (a section's empty padding), each as
* columns saying how much content that strip holds. In each band the toolbar slides
* sideways to where it covers the least, staying as close to the block's left edge as it
* can; the band covering least wins (above, then below, then inside, on a tie). Inside is
* used only where it covers nothing: that is the block being edited. Without a report the
* toolbar goes above the block. A block taller than the screen, with no band on screen,
* gets the toolbar pinned to the top of the visible page. Always inside the page, or centred
* over it when the toolbar is the wider of the two.
*
* @param {object} o
* @param {{top:number,left:number,width:number,height:number}} o.block   the selected block
* @param {{top:number,left:number,width:number,height:number}} o.frame   the visible page (the canvas iframe)
* @param {{width:number,height:number}} o.size                           the toolbar
* @param {{step:number, above:number[], below:number[], inside:number[]|null}|null} o.room
* @param {number} [o.gap]  space between toolbar and block
* @returns {{top:number, left:number, side:'above'|'below'|'inside'|'pinned', cover:number}}
*/
function toolbarSpot({ block, frame, size, room, gap = 6 }) {
	const minTop = frame.top + 4;
	const maxTop = frame.top + frame.height - size.height - 4;
	const tooWide = size.width > frame.width - 8;
	const minLeft = tooWide ? Math.max(4, frame.left + (frame.width - size.width) / 2) : frame.left + 4;
	const maxLeft = tooWide ? minLeft : frame.left + frame.width - size.width - 4;
	const clampLeft = (x) => Math.max(minLeft, Math.min(x, maxLeft));
	const onScreen = (top) => top >= minTop && top <= maxTop;
	const bands = [
		{
			side: "above",
			top: block.top - size.height - gap,
			cols: room?.above,
			prefer: block.left
		},
		{
			side: "below",
			top: block.top + block.height + gap,
			cols: room?.below,
			prefer: block.left
		},
		{
			side: "inside",
			top: block.top + gap,
			cols: room?.inside,
			prefer: block.left + gap
		}
	].filter((b) => onScreen(b.top));
	if (!room) {
		const above = bands.find((b) => b.side === "above");
		if (above) return {
			top: Math.round(above.top),
			left: Math.round(clampLeft(above.prefer)),
			side: "above",
			cover: 0
		};
	}
	const pick = (room ? bands.filter((b) => b.cols).map((b) => ({
		...b,
		...bestLeft(b.cols, room.step, frame.left, size.width, clampLeft(b.prefer), minLeft, maxLeft)
	})) : []).filter((o) => o.side !== "inside" || o.cover === 0).sort((a, b) => a.cover - b.cover)[0];
	if (pick) return {
		top: Math.round(pick.top),
		left: Math.round(pick.left),
		side: pick.side,
		cover: pick.cover
	};
	if (block.top + block.height > minTop + size.height) return {
		top: Math.round(minTop),
		left: Math.round(clampLeft(block.left)),
		side: "pinned",
		cover: 1
	};
	return {
		top: Math.round(Math.min(block.top + block.height + gap, maxTop)),
		left: Math.round(clampLeft(block.left)),
		side: "below",
		cover: 1
	};
}
/**
* Within one band: the left edge where a toolbar `width` wide covers the least content, the
* nearest to `prefer` among equals. Columns are `step` px wide, starting at `origin`.
* @returns {{left:number, cover:number}}  cover: the share of the toolbar's columns that hold content
*/
function bestLeft(cols, step, origin, width, prefer, minLeft, maxLeft) {
	const coverAt = (left) => {
		const first = Math.max(0, Math.floor((left - origin) / step));
		const last = Math.min(cols.length - 1, Math.floor((left + width - 1 - origin) / step));
		if (last < first) return 1;
		let sum = 0;
		for (let i = first; i <= last; i++) sum += cols[i];
		return sum / (last - first + 1);
	};
	const candidates = /* @__PURE__ */ new Set([
		prefer,
		minLeft,
		maxLeft
	]);
	for (let x = origin; x <= maxLeft; x += step) if (x >= minLeft) candidates.add(x);
	let best = {
		left: prefer,
		cover: coverAt(prefer)
	};
	for (const left of candidates) {
		const cover = coverAt(left);
		if (cover < best.cover - 1e-9 || Math.abs(cover - best.cover) < 1e-9 && Math.abs(left - prefer) < Math.abs(best.left - prefer)) best = {
			left,
			cover
		};
	}
	return {
		left: best.left,
		cover: Math.round(best.cover * 100) / 100
	};
}
// core/resources/js/ui/builder/useCanvas.js
/**
* The canvas: the real site in a frame, showing the tree as it is edited. This
* side prepares the tree (the server checks and fills in blocks), sends it, and
* answers what the canvas reports: clicks, typing, keys, drops and handles.
* What the messages carry: shared/canvas-protocol.js.
*/
var TYPED_MS = 600;
var CHANGED_MS = 60;
function useCanvas$1(b, { api, props, emit, root }) {
	const frame = ref(null);
	const canvasEl = ref(null);
	const ready = ref(false);
	const updating = ref(false);
	const device = ref("desktop");
	const DEVICES = [
		[
			"phone",
			"device-mobile",
			__("Phone"),
			390
		],
		[
			"tablet",
			"device-tablet",
			__("Tablet"),
			820
		],
		[
			"desktop",
			"monitor",
			__("Desktop"),
			null
		]
	];
	const width = computed(() => DEVICES.find((d) => d[0] === device.value)?.[3]);
	const entries = ref([]);
	const entry = ref("");
	async function loadEntries() {
		if (props.kind !== "template") return;
		try {
			entries.value = (await api.get(`/design/templates/${props.kind}/${props.docKey}/preview-entries`)).data.entries;
			if (!entry.value && entries.value.length) entry.value = String(entries.value[0].id);
		} catch {
			entries.value = [];
		}
	}
	loadEntries();
	const entryOptions = computed(() => entries.value.map((e) => ({
		value: String(e.id),
		label: e.status === "published" ? e.title : `${e.title} (${e.status})`
	})));
	const canvasUrl = computed(() => {
		if (props.canvasSrc) return props.canvasSrc;
		const q = new URLSearchParams({
			kind: props.kind,
			key: props.docKey
		});
		if (props.kind === "template" && entry.value) q.set("entry", entry.value);
		return `/system/design/canvas?${q}`;
	});
	watch(canvasUrl, () => {
		ready.value = false;
	});
	const post = (message) => {
		if (ready.value) frame.value?.contentWindow?.postMessage(message, window.location.origin);
	};
	function sendMarks(scroll = false) {
		const also = b.multi?.value ? b.picked.value.filter((id) => id !== b.selected.value) : [];
		post({
			type: "kestello:mark",
			hover: b.hover.value,
			selected: b.selected.value,
			also,
			scroll
		});
	}
	watch(b.hover, () => sendMarks());
	watch(b.picked, () => sendMarks());
	/** What may be typed straight into the page (text fields that hold text, not data). */
	const editable = computed(() => editableFields(b.list.value, b.defOf, {
		disabled: props.disabled,
		locks: props.kind === "entry"
	}));
	const plainData = () => JSON.parse(JSON.stringify(props.canvasData ?? {}));
	const plainEditable = () => JSON.parse(JSON.stringify(editable.value));
	let seq = 0;
	let timer = null;
	let shown = null;
	let focusNext = null;
	let typedTree = null;
	let redraw = false;
	let typedSeq = 0;
	let shownSeen = 0;
	const scope = () => `${props.kind}:${props.docKey}:${props.resetKey}:${props.kind === "template" ? entry.value : ""}`;
	const fetchPrepared = async (blocks) => props.previewer ? props.previewer(blocks) : (await api.post("/design/preview", {
		blocks,
		entry: props.kind === "template" && entry.value ? Number(entry.value) : null
	})).data.blocks;
	async function sendTree() {
		if (!ready.value) return;
		const mine = ++seq;
		const seen = typedSeq;
		updating.value = true;
		try {
			const blocks = await prepareTree(b.list.value, scope(), fetchPrepared);
			if (mine !== seq) return;
			shown = blocks;
			shownSeen = seen;
			const focus = focusNext;
			const force = redraw;
			focusNext = null;
			redraw = false;
			post({
				type: "kestello:tree",
				blocks,
				editable: plainEditable(),
				focus,
				force,
				seen,
				...plainData()
			});
			sendMarks();
			setTimeout(runChecks, 400);
		} catch {} finally {
			if (mine === seq) updating.value = false;
		}
	}
	watch(b.list, (now) => {
		const typed = !!typedTree && toRaw(now) === toRaw(typedTree);
		if (!typed) redraw = true;
		clearTimeout(timer);
		timer = setTimeout(sendTree, typed && !redraw ? TYPED_MS : CHANGED_MS);
	});
	watch(() => JSON.stringify(props.canvasData ?? {}), () => {
		if (shown) post({
			type: "kestello:tree",
			blocks: shown,
			editable: plainEditable(),
			seen: shownSeen,
			...plainData()
		});
	});
	/** After this change, the caret goes into the block's first text field (a new block, a block turned into another). */
	function continueTyping(node, at = "end") {
		const field = firstInline(b.defOf(node));
		focusNext = field ? {
			id: node.id,
			field,
			at
		} : null;
	}
	const firstEditable = (id) => Object.keys(editable.value[id] ?? {})[0] ?? null;
	function focusOn(id, at = "end") {
		const field = firstEditable(id);
		b.select(id);
		if (field) post({
			type: "kestello:focus",
			id,
			field,
			at
		});
	}
	const typing = ref(null);
	const formats = ref({});
	function onEdit({ id, field, value, seq: n }) {
		if (Number.isInteger(n) && n > typedSeq) typedSeq = n;
		if (typeof id !== "string" || typeof value !== "string" || !editable.value[id]?.[field]) return;
		if (b.selected.value !== id) b.quietSelect(id);
		if (b.setTyped(id, field, value)) typedTree = b.list.value;
		else {
			clearTimeout(timer);
			timer = setTimeout(sendTree, TYPED_MS);
		}
	}
	function onEditing(data) {
		const kind = typeof data.id === "string" ? editable.value[data.id]?.[data.field] : null;
		typing.value = kind ? {
			id: data.id,
			field: data.field,
			kind
		} : null;
		if (!typing.value) formats.value = {};
	}
	/** A new text block after this one, the caret in it (Enter at the end of a block). */
	function newTextAfter(id, { first = false } = {}) {
		const def = b.catalog.value.get("core/text");
		const at = first ? null : locate(b.list.value, id);
		const parent = at?.parent ?? null;
		if (!def || !b.fitsIn(def, parent) || !first && !at) return false;
		const node = b.create(def);
		node.props = {
			...node.props,
			text: ""
		};
		focusNext = {
			id: node.id,
			field: "text",
			at: "start"
		};
		b.insertAt(node, parent?.id ?? null, first ? 0 : at.index + 1, { message: !first });
		b.quietSelect(node.id);
		return true;
	}
	/** An empty entry: start with a paragraph, the caret in it. */
	function startWriting() {
		if (!newTextAfter(null, { first: true })) b.openQuick({
			mode: "after",
			id: null
		});
	}
	function onCanvasKey({ id, key, rect }) {
		if (props.disabled || typeof id !== "string" || !locate(b.list.value, id)) return;
		if (key === "enter") newTextAfter(id);
		else if (key === "remove") {
			const at = b.removeNode(id, { focusRow: false });
			if (!at) return;
			const prev = at.list[at.index - 1] ?? at.parent;
			if (!prev) {
				b.select(null);
				return;
			}
			focusNext = firstEditable(prev.id) ? {
				id: prev.id,
				field: firstEditable(prev.id),
				at: "end"
			} : null;
			b.quietSelect(prev.id);
		} else if (key === "slash") b.openQuick({
			mode: "replace",
			id,
			rect
		});
		else if (key === "slash-after") b.openQuick({
			mode: "after",
			id,
			rect
		});
		else if (key === "escape") b.select(id);
		else if (key === "link") linkOpen.value = true;
		else b.runCommand(key, id, rect);
	}
	const rect = ref(null);
	/** A box from the frame's coordinates into the canvas section's (which scrolls). */
	function toCanvas(r) {
		const f = frame.value.getBoundingClientRect();
		const c = canvasEl.value.getBoundingClientRect();
		const top = f.top - c.top + canvasEl.value.scrollTop;
		const frameLeft = f.left - c.left + canvasEl.value.scrollLeft;
		return {
			top: top + r.top,
			left: frameLeft + r.left,
			frameTop: top,
			frameHeight: f.height,
			frameLeft,
			frameWidth: f.width
		};
	}
	const toolbarSize = ref({
		width: 360,
		height: 38
	});
	function onRect({ id, rect: r, room = null }) {
		if (!r || id !== b.selected.value || !frame.value || !canvasEl.value) {
			rect.value = null;
			return;
		}
		const at = toCanvas(r);
		const visible = r.top + r.height > 0 && r.top < at.frameHeight;
		rect.value = visible ? {
			top: at.top,
			left: at.left,
			width: r.width,
			height: r.height,
			frame: {
				top: at.frameTop,
				left: at.frameLeft,
				width: at.frameWidth,
				height: at.frameHeight
			},
			room
		} : null;
	}
	const toolbarDocked = computed(() => b.docked.value || !!rect.value && toolbarSize.value.width > rect.value.frame.width - 8);
	const toolbarStyle = computed(() => {
		const r = rect.value;
		if (!r) return null;
		const at = toolbarSpot({
			block: r,
			frame: r.frame,
			size: toolbarSize.value,
			room: r.room
		});
		return {
			top: `${at.top}px`,
			left: `${at.left}px`
		};
	});
	/** Where a popup goes under a box the canvas reported (the quick inserter under the block typed in). */
	function under(r) {
		if (!r || !frame.value || !canvasEl.value) return null;
		const at = toCanvas(r);
		return {
			top: `${at.top + r.height + 6}px`,
			left: `${Math.max(8, at.left)}px`
		};
	}
	const linkOpen = ref(false);
	const linkUrl = ref("");
	watch(linkOpen, (open) => {
		if (open) {
			linkUrl.value = "";
			nextTick(() => root.value?.querySelector(".k-builder__link input")?.focus());
		}
	});
	const format = (command, value) => post({
		type: "kestello:format",
		command,
		value
	});
	function applyLink() {
		const url = linkFromInput(linkUrl.value);
		linkOpen.value = false;
		if (url) format("link", url);
		else if (linkUrl.value.trim()) b.announce(__("That isn't an address a link can go to."));
	}
	const richSelected = computed(() => !!b.selectedAt.value && Object.values(editable.value[b.selected.value] ?? {}).includes("rich"));
	const typingRich = computed(() => typing.value?.kind === "rich" && typing.value.id === b.selected.value);
	watch(b.selected, () => {
		linkOpen.value = false;
	});
	const issues = ref([]);
	const bodyHidden = ref(false);
	function runChecks() {
		issues.value = checkPage(frame.value?.contentDocument, {
			kind: props.kind,
			owns: b.owns,
			t: __
		});
	}
	/** The first of the ids (innermost first) that is a block of this tree. */
	const own = (ids) => (Array.isArray(ids) ? ids.find((id) => b.owns(id)) : null) ?? null;
	const handlers = {
		"kestello:canvas-ready": () => {
			ready.value = true;
			sendTree();
		},
		"kestello:pick": (d) => {
			const id = own(d.ids);
			if (!id) return;
			if (d.extend === "range" || d.extend === "toggle") b.selectMore(id, d.extend);
			else if (id !== b.selected.value || b.multi.value) b.select(id, { scroll: true });
		},
		"kestello:hover": (d) => {
			b.hover.value = own(d.ids);
		},
		"kestello:rect": onRect,
		"kestello:edit": onEdit,
		"kestello:editing": onEditing,
		"kestello:formats": (d) => {
			if (d.formats && typeof d.formats === "object") formats.value = { ...d.formats };
		},
		"kestello:key": onCanvasKey,
		"kestello:dragover": (d) => b.onCanvasDrag(d),
		"kestello:drop": () => b.dropHere(),
		"kestello:resize": (d) => b.setNumber(d.id, d.prop, d.value),
		"kestello:body": (d) => {
			bodyHidden.value = d.shown === false;
		},
		"kestello:title": () => emit("title")
	};
	function onMessage(event) {
		if (event.origin !== window.location.origin || event.source !== frame.value?.contentWindow) return;
		const data = event.data;
		if (data && typeof data === "object" && Object.hasOwn(handlers, data.type)) handlers[data.type](data);
	}
	onMounted(() => window.addEventListener("message", onMessage));
	onBeforeUnmount(() => {
		window.removeEventListener("message", onMessage);
		clearTimeout(timer);
	});
	return {
		frame,
		canvasEl,
		ready,
		updating,
		device,
		DEVICES,
		width,
		entry,
		entryOptions,
		canvasUrl,
		post,
		sendMarks,
		editable,
		continueTyping,
		focusOn,
		startWriting,
		typing,
		formats,
		rect,
		toolbarStyle,
		toolbarSize,
		toolbarDocked,
		under,
		linkOpen,
		linkUrl,
		format,
		applyLink,
		richSelected,
		typingRich,
		issues,
		runChecks,
		bodyHidden
	};
}
// core/resources/js/ui/builder/useCatalog.js
/**
* The blocks this editor offers, what data they can show, and saved patterns.
* Seen before in this tab: drawn at once from the cache and refreshed behind the scenes.
*/
var HIDDEN = {
	template: [],
	part: ["core/template-part"],
	pattern: [
		"core/entry-content",
		"core/template-part",
		"core/pattern"
	],
	entry: ["core/entry-content", "core/template-part"]
};
function useCatalog({ api, props, emit }) {
	const catalog = shallowRef(/* @__PURE__ */ new Map());
	const definitions = shallowRef([]);
	const bindings = shallowRef([]);
	const patterns = shallowRef([]);
	const designSpec = shallowRef(null);
	const loading = ref(true);
	const failed = ref(false);
	function use({ blocks, bindings: b, patterns: p, design: d }) {
		designSpec.value = d ?? null;
		definitions.value = blocks;
		catalog.value = new Map(blocks.map((d) => [d.name, d]));
		bindings.value = b;
		patterns.value = p;
	}
	async function loadCatalog() {
		const cached = catalogCache.value;
		if (cached) use(cached);
		loading.value = !cached;
		failed.value = false;
		const fresh = Promise.all([api.get("/blocks"), api.get("/design/patterns").catch(() => ({ data: { patterns: [] } }))]).then(([b, p]) => {
			catalogCache.value = {
				blocks: b.data.blocks,
				bindings: b.data.bindings,
				design: b.data.design,
				patterns: p.data.patterns
			};
			use(catalogCache.value);
		});
		try {
			if (!cached) await fresh;
			else fresh.catch(() => {});
		} catch {
			failed.value = true;
		} finally {
			loading.value = false;
		}
		if (!failed.value) nextTick(() => emit("ready"));
	}
	const hidden = computed(() => HIDDEN[props.kind] ?? []);
	const defOf = (node) => catalog.value.get(node?.type) ?? null;
	/** May a block of this kind go into this parent (null: the top level, where allowed limits it)? */
	const fitsIn = (def, parent) => fits(def, parent ? defOf(parent) : null, parent ? null : props.allowed, hidden.value);
	const nameOf = (node) => defOf(node)?.label ?? node?.type ?? "";
	const make = (name) => catalog.value.has(name) ? createBlock$1(catalog.value.get(name), catalog.value) : null;
	const create = (def) => createBlock$1(def, catalog.value);
	const makePattern = (p) => fromPattern(p, catalog.value);
	const defOfPattern = (p) => patternDef(p, catalog.value);
	const bindingLabel = (path) => {
		const exact = bindings.value.find((b) => b.path === path);
		if (exact) return `${path.startsWith("site.") ? __("Site") : __("Entry")}: ${exact.label}`;
		if (path.startsWith("entry.fields.")) return `${__("Entry")}: ${path.slice(13) || __("a field")}`;
		return path;
	};
	loadCatalog();
	return {
		catalog,
		definitions,
		bindings,
		patterns,
		designSpec,
		loading,
		failed,
		loadCatalog,
		hidden,
		defOf,
		fitsIn,
		nameOf,
		make,
		create,
		makePattern,
		defOfPattern,
		bindingLabel
	};
}
// core/resources/js/ui/builder/undo-notice.js
/**
* "Undo" on a toast after a step that is easy to regret (removing blocks, adding a layout,
* replacing all). The offer holds only while the page is exactly as that step left it:
* any later change, an undo from the keyboard included, takes the toast away, so its
* Undo can never undo something else. One offer at a time.
*
* Pure: the toast and the version counter come from outside (useDocument).
* @param {{show: (message: string, action: {label: string, run: () => void}) => any, dismiss: (id: any) => void, label: string}} o
*/
function createUndoNotice({ show, dismiss, label }) {
	let current = null;
	const clear = () => {
		if (current) dismiss(current.id);
		current = null;
	};
	return {
		/** After a step: the page is now at `version`; `undo` takes that step back. */
		offer(message, version, undo) {
			clear();
			const mine = {
				id: null,
				version
			};
			mine.id = show(message, {
				label,
				run: () => {
					if (current !== mine) return;
					current = null;
					undo();
				}
			});
			current = mine;
		},
		/** The page changed: an offer made for another version goes away. */
		changed(version) {
			if (current && current.version !== version) clear();
		},
		clear,
		get open() {
			return current !== null;
		}
	};
}
// core/resources/js/ui/builder/history.js
/**
* Undo and redo: snapshots of the tree. Changes in one group (typing into one
* block's field) within a moment of each other are one step. Each step keeps
* which block was selected before it, so undoing it can select that again.
*/
var LIMIT = 200;
var GROUP_MS = 1200;
function createHistory(state = {
	stack: [],
	at: -1,
	group: "",
	time: 0
}, now = () => Date.now()) {
	state.selections ??= [];
	const snap = (tree) => JSON.stringify(tree ?? []);
	return {
		state,
		reset(tree) {
			state.stack = [snap(tree)];
			state.selections = [null];
			state.at = 0;
			state.group = "";
		},
		/** Is this tree the one the history is at? (a tree replaced from outside isn't) */
		isCurrent: (tree) => snap(tree) === state.stack[state.at],
		record(tree, group = "", selected = null) {
			const s = snap(tree);
			const t = now();
			if (group && group === state.group && t - state.time < GROUP_MS && state.at === state.stack.length - 1) state.stack[state.at] = s;
			else {
				state.stack = state.stack.slice(0, state.at + 1);
				state.selections = state.selections.slice(0, state.at + 1);
				state.stack.push(s);
				state.selections.push(selected);
				if (state.stack.length > LIMIT) {
					state.stack.shift();
					state.selections.shift();
				}
				state.at = state.stack.length - 1;
			}
			state.group = group;
			state.time = t;
		},
		canUndo: () => state.at > 0,
		canRedo: () => state.at < state.stack.length - 1,
		/** The tree one step back (or forward), or null. */
		undo() {
			return this.undoStep()?.tree ?? null;
		},
		/** One step back: the tree, and the block that was selected before the step undone; or null. */
		undoStep() {
			if (state.at <= 0) return null;
			const selected = state.selections[state.at] ?? null;
			state.at--;
			state.group = "";
			return {
				tree: JSON.parse(state.stack[state.at]),
				selected
			};
		},
		redo() {
			if (state.at >= state.stack.length - 1) return null;
			state.at++;
			state.group = "";
			return JSON.parse(state.stack[state.at]);
		}
	};
}
// core/resources/js/ui/builder/placement.js
/**
* Where a block may go, for dragging in the layers and on the page, and putting
* blocks side by side. Pure: given the tree and two questions about blocks
* (fits: may a block of this kind go into that parent; make: a new block of a
* kind), it answers with targets and new trees.
*
* A target is { id, where: before | after | inside | left | right } or { end: true }.
*/
var COLUMN_LAYOUTS = {
	2: "1-1",
	3: "1-1-1",
	4: "1-1-1-1"
};
var MAX_COLUMNS = 4;
/**
* @param {{ fits: (def: object, parent: object|null) => boolean, defOf: (node: object) => object|null, make: (name: string) => object|null }} rules
*/
function createPlacement({ fits, defOf, make }) {
	/** Is the moving block this one, or around it? A block can't go into itself. */
	const within = (tree, movingId, id) => {
		if (!movingId) return false;
		if (movingId === id) return true;
		const moving = locate(tree, movingId)?.node;
		return !!moving && contains(moving, id);
	};
	/** Where a target puts a block: the parent's id (null: the top level) and the index in it. */
	function position(tree, target) {
		if (!target || target.end) return {
			parentId: null,
			index: tree.length
		};
		const at = locate(tree, target.id);
		if (!at) return null;
		if (target.where === "inside") return {
			parentId: at.node.id,
			index: (at.node.children ?? []).length
		};
		return {
			parentId: at.parent?.id ?? null,
			index: at.index + (target.where === "after" ? 1 : 0)
		};
	}
	/**
	* Over a row in the layers, y from 0 (top) to 1 (bottom): the middle of a block that
	* holds others drops inside it, the top and bottom halves before and after it.
	*/
	function layerTarget(tree, id, y, def, movingId = null) {
		const at = locate(tree, id);
		if (!at || !def || within(tree, movingId, id)) return null;
		const holds = !!defOf(at.node)?.children && fits(def, at.node);
		if (holds && y > .25 && y < .75) return {
			id,
			where: "inside"
		};
		if (fits(def, at.parent)) return {
			id,
			where: y < .5 ? "before" : "after"
		};
		return holds ? {
			id,
			where: "inside"
		} : null;
	}
	const columnOf = (at) => at.node.type === "core/column" ? at.node : at.parent?.type === "core/column" ? at.parent : null;
	/** Could a block of this kind go beside this one, in columns (a new set of them, or one more)? */
	function canBeside(tree, at, def) {
		const column = make("core/column");
		if (!column || !make("core/columns") || !fits(def, column)) return false;
		const col = columnOf(at);
		if (col) {
			const cols = locate(tree, col.id)?.parent;
			return !!cols && (cols.children ?? []).length < MAX_COLUMNS;
		}
		return at.node.type !== "core/columns" && fits(defOf({ type: "core/columns" }), at.parent);
	}
	/**
	* On the page: the blocks under the pointer, innermost first, each with where the pointer
	* is in it (xr, yr from 0 to 1) and how wide it is. The outer sixth of a wide block puts
	* the dragged one beside it; the middle of a block that holds others puts it inside;
	* otherwise before or after the first block it may sit next to.
	*/
	function canvasTarget(tree, chain, def, movingId = null) {
		if (!def) return null;
		for (const c of chain) {
			const at = locate(tree, c.id);
			if (!at) continue;
			if (within(tree, movingId, c.id)) return null;
			if (c.w > 240 && (c.xr < .16 || c.xr > .84) && canBeside(tree, at, def)) return {
				id: c.id,
				where: c.xr < .5 ? "left" : "right"
			};
			const holds = !!defOf(at.node)?.children && fits(def, at.node);
			const empty = !(at.node.children ?? []).length;
			if (holds && (empty || c.yr > .2 && c.yr < .8 && at.node.type !== "core/columns")) return {
				id: c.id,
				where: "inside"
			};
			if (fits(def, at.parent)) return {
				id: c.id,
				where: c.yr < .5 ? "before" : "after"
			};
		}
		return !chain.length && fits(def, null) ? { end: true } : null;
	}
	/**
	* Side by side: the block goes in a new column next to the target's column, or the two
	* are made into columns. The columns' layout follows how many there are.
	* @returns {object[]|null} the new tree
	*/
	function placeBeside(tree, target, node, movingId = null) {
		let next = movingId ? remove(tree, movingId) : tree;
		const at = locate(next, target.id);
		if (!at) return null;
		const column = {
			...make("core/column"),
			children: [node]
		};
		const col = columnOf(at);
		if (col) {
			const colAt = locate(next, col.id);
			const cols = colAt.parent;
			next = insert(next, cols.id, colAt.index + (target.where === "right" ? 1 : 0), column);
			const count = (cols.children ?? []).length + 1;
			return update(next, cols.id, { props: {
				...cols.props ?? {},
				layout: COLUMN_LAYOUTS[count] ?? COLUMN_LAYOUTS[MAX_COLUMNS]
			} });
		}
		const base = make("core/columns");
		const beside = {
			...make("core/column"),
			children: [at.node]
		};
		const columns = {
			...base,
			props: {
				...base.props,
				layout: COLUMN_LAYOUTS[2]
			},
			children: target.where === "left" ? [column, beside] : [beside, column]
		};
		return insert(remove(next, at.node.id), at.parent?.id ?? null, at.index, columns);
	}
	/** Put a new block next to a selection: inside it if it fits, else after it, else at the end. Null: nowhere. */
	function near(tree, selectedId, def) {
		const at = selectedId ? locate(tree, selectedId) : null;
		if (at && fits(def, at.node)) return {
			parentId: at.node.id,
			index: (at.node.children ?? []).length
		};
		if (at && fits(def, at.parent)) return {
			parentId: at.parent?.id ?? null,
			index: at.index + 1
		};
		return fits(def, null) ? {
			parentId: null,
			index: tree.length
		} : null;
	}
	return {
		position,
		layerTarget,
		canvasTarget,
		canBeside,
		placeBeside,
		near
	};
}
// core/resources/js/ui/builder/design.js
/**
* The editor's side of block design (the styles panel, BuilderDesign.vue): what
* someone types becomes a value BlockDesign accepts, and the preview device
* picks the screen size being styled. What the values mean on the page is in
* shared/block-design.js.
*/
/** The screen size styled while the canvas shows a device: desktop styles everything (base). */
var screenOf = (device) => device === "tablet" || device === "phone" ? device : "base";
/** A 3-digit colour as 6 digits, for <input type="color">, which takes only those. */
function fullHex(color) {
	if (!/^#[0-9a-f]{3}$/i.test(color ?? "")) return color;
	return `#${color.slice(1).split("").map((c) => c + c).join("")}`;
}
/** Are all four sides the same (padding shown as one control)? */
function sameSides(values) {
	const [a, ...rest] = values;
	return rest.every((v) => (v ?? null) === (a ?? null));
}
/**
* The styles one block can take from another (Paste styles): only those in the groups
* the receiving block supports, on every screen size. Null when none are left.
* @param {object|null} design   the copied block's design
* @param {string[]} supports    the receiving block's groups (BlockDefinition::supports)
*/
function stylesFitting(design, supports) {
	const out = {};
	for (const screen of SCREENS) {
		const values = Object.entries(design?.[screen] ?? {}).filter(([prop]) => supports.includes(PROPS[prop]?.group));
		if (values.length) out[screen] = Object.fromEntries(values);
	}
	return Object.keys(out).length ? out : null;
}
// core/resources/js/ui/builder/useDocument.js
/**
* The tree being edited: changing it (each change one undo step), selecting
* blocks, and the actions on them (move, duplicate, remove, group, turn into).
* Screen readers hear what each action did; steps easy to regret also show a toast with
* Undo (undo-notice.js), which is then what is heard.
*/
var CLIPBOARD = "kestello.builder.clipboard";
var STYLE_CLIPBOARD = "kestello.builder.styles";
var TURN = () => [
	[
		"core/text",
		null,
		__("Text"),
		"text-align-left"
	],
	[
		"core/heading",
		"2",
		__("Heading 2"),
		"text-h-two"
	],
	[
		"core/heading",
		"3",
		__("Heading 3"),
		"text-h-three"
	],
	[
		"core/heading",
		"4",
		__("Heading 4"),
		"text-h-four"
	],
	[
		"core/list",
		"bullets",
		__("List"),
		"list-bullets"
	],
	[
		"core/list",
		"ordered",
		__("Numbered list"),
		"list-numbers"
	],
	[
		"core/quote",
		null,
		__("Quote"),
		"quotes"
	],
	[
		"core/code",
		null,
		__("Code"),
		"code-block"
	],
	[
		"core/details",
		null,
		__("Details"),
		"caret-circle-down"
	]
];
function useDocument(b, { tree, props, root }) {
	const asList = (t) => Array.isArray(t) ? t : [];
	const current = shallowRef(asList(tree.value));
	const list = computed(() => current.value);
	const placement = createPlacement({
		fits: (def, parent) => b.fitsIn(def, parent),
		defOf: (n) => b.defOf(n),
		make: (name) => b.make(name)
	});
	const live = ref("");
	function announce(message) {
		live.value = "";
		nextTick(() => {
			live.value = message;
		});
	}
	const history = createHistory(reactive({
		stack: [],
		at: -1,
		group: "",
		time: 0
	}));
	watch(() => props.resetKey, () => history.reset(list.value), { immediate: true });
	watch(tree, (t) => {
		if (toRaw(t) === toRaw(current.value)) return;
		current.value = asList(t);
		if (!history.isCurrent(t)) history.reset(t);
	});
	const version = ref(0);
	function show(next) {
		current.value = next;
		tree.value = next;
		version.value++;
	}
	function commit(next, group = "") {
		history.record(next, group, selected.value);
		show(next);
	}
	const canUndo = computed(() => history.canUndo());
	const canRedo = computed(() => history.canRedo());
	function undo() {
		const step = history.undoStep();
		if (step) {
			show(step.tree);
			const back = step.selected && owns(step.selected) ? step.selected : selected.value && owns(selected.value) ? selected.value : null;
			if (back !== selected.value) quietSelect(back);
			announce(__("Undone."));
		}
	}
	function redo() {
		const t = history.redo();
		if (t) {
			show(t);
			if (selected.value && !owns(selected.value)) quietSelect(null);
			announce(__("Redone."));
		}
	}
	const undoNotice = createUndoNotice({
		show: (message, action) => notify.info(message, {
			action,
			timeout: 8e3
		}),
		dismiss: dismissToast,
		label: __("Undo")
	});
	watch(version, (v) => undoNotice.changed(v));
	onBeforeUnmount(() => undoNotice.clear());
	/** Says what a step did, on a toast with Undo (heard from there; just committed). */
	function announceUndoable(message) {
		undoNotice.offer(message, version.value, undo);
	}
	const selected = ref(null);
	const hover = ref(null);
	const collapsed = reactive(/* @__PURE__ */ new Set());
	const rightTab = ref(["document", "block"].includes(props.startTab) ? props.startTab : "document");
	const selectedAt = computed(() => selected.value ? locate(list.value, selected.value) : null);
	const selectedDef = computed(() => b.defOf(selectedAt.value?.node));
	const rows = computed(() => flatten(list.value, collapsed));
	const trail = computed(() => selectedAt.value ? [...selectedAt.value.path, selectedAt.value.node] : []);
	const owns = (id) => typeof id === "string" && !!locate(list.value, id);
	const showBlock = () => {
		if (rightTab.value !== "style") rightTab.value = "block";
	};
	/** Select a block (null: none). focus: its row in the layers; scroll: bring it into view there and on the page. */
	function select(id, { focus = false, scroll = false } = {}) {
		if (b.picked) b.picked.value = [];
		selected.value = id;
		if (id) {
			showBlock();
			for (const a of locate(list.value, id)?.path ?? []) collapsed.delete(a.id);
			if (focus || scroll) nextTick(() => {
				const row = root.value?.querySelector(`[data-row="${id}"]`);
				if (focus) row?.focus();
				else row?.scrollIntoView({ block: "nearest" });
			});
		}
		b.sendMarks?.(scroll);
	}
	/** Select without moving focus or scrolling (typing on the page selects the block it is in). */
	function quietSelect(id) {
		if (b.picked) b.picked.value = [];
		selected.value = id;
		if (id) showBlock();
		b.sendMarks?.(false);
	}
	const toggleCollapse = (id) => collapsed.has(id) ? collapsed.delete(id) : collapsed.add(id);
	const summary = (node) => summarize(node, b.defOf(node), b.bindingLabel, b.defOf) ?? __("Not available");
	const iconOf = (node) => blockIcon(node, b.defOf(node));
	const locked = (node, what) => props.kind === "entry" && (node?.lock ?? []).includes(what);
	/** Change the selected block: { props } | { design } | { visibility } | { lock }; null removes a key. */
	function patchSelected(patch) {
		if (!selected.value) return;
		const clean = Object.fromEntries(Object.entries(patch).map(([k, v]) => [k, v === null && k !== "props" ? void 0 : v]));
		const group = patch.props ? `props:${selected.value}` : "design" in patch ? `design:${selected.value}` : "";
		commit(update(list.value, selected.value, clean), group);
	}
	function setProp(key, value) {
		const at = selectedAt.value;
		if (at) patchSelected({ props: {
			...at.node.props ?? {},
			[key]: value
		} });
	}
	/** A field typed on the page: grouped with the typing around it into one undo step. */
	function setTyped(id, field, value) {
		const at = locate(list.value, id);
		if (!at || at.node.props?.[field] === value) return false;
		commit(update(list.value, id, { props: {
			...at.node.props ?? {},
			[field]: value
		} }), `props:${id}`);
		return true;
	}
	/** A number set by dragging a handle on the page, kept within the field's limits. */
	function setNumber(id, prop, value) {
		const at = typeof id === "string" ? locate(list.value, id) : null;
		const field = at ? b.defOf(at.node)?.fields.find((f) => f.name === prop && f.type === "number") : null;
		if (!field || props.disabled || locked(at.node, "edit")) return;
		const n = Math.round(Math.min(field.options?.max ?? 100, Math.max(field.options?.min ?? 0, Number(value) || 0)));
		commit(update(list.value, id, { props: {
			...at.node.props ?? {},
			[prop]: n
		} }));
		announce(__("{block} resized to {n}%.", {
			block: b.nameOf(at.node),
			n
		}));
	}
	function insertAt(node, parentId, index, { message = true } = {}) {
		commit(insert(list.value, parentId, index, node));
		if (message) announce(__("{block} added.", { block: b.nameOf(node) }));
	}
	function removeNode(id, { focusRow = true } = {}) {
		const at = locate(list.value, id);
		if (!at) return null;
		if (locked(at.node, "remove")) {
			announce(__("{block} is locked by the layout and can't be removed.", { block: b.nameOf(at.node) }));
			return null;
		}
		const next = at.list[at.index + 1] ?? at.list[at.index - 1] ?? at.parent;
		commit(remove(list.value, id));
		announceUndoable(__("{block} removed.", { block: b.nameOf(at.node) }));
		if (focusRow) select(next?.id ?? null, { focus: true });
		return at;
	}
	function duplicateNode(id) {
		const at = locate(list.value, id);
		if (!at) return;
		const copy = reid(at.node);
		commit(insert(list.value, at.parent?.id ?? null, at.index + 1, copy));
		announce(__("{block} duplicated.", { block: b.nameOf(at.node) }));
		select(copy.id, { focus: true });
	}
	function moveNode(id, parentId, index, { focus = true } = {}) {
		const at = locate(list.value, id);
		const parent = parentId === null ? null : locate(list.value, parentId)?.node;
		if (at && locked(at.node, "move")) {
			announce(__("{block} is locked by the layout and can't be moved.", { block: b.nameOf(at.node) }));
			return false;
		}
		if (!at || !b.fitsIn(b.defOf(at.node), parent)) {
			announce(__("{block} can't go there.", { block: b.nameOf(at?.node) }));
			return false;
		}
		const next = move(list.value, id, parentId, index);
		if (next === list.value) return false;
		commit(next);
		const now = locate(next, id);
		announce(__("{block} moved to position {n} of {count}{where}.", {
			block: b.nameOf(at.node),
			n: now.index + 1,
			count: now.list.length,
			where: now.parent ? __(" in {parent}", { parent: b.nameOf(now.parent) }) : ""
		}));
		if (focus) select(id, { focus: true });
		return true;
	}
	function shift(id, delta) {
		const at = locate(list.value, id);
		const j = at ? at.index + delta : -1;
		if (!at || j < 0 || j >= at.list.length) return;
		moveNode(id, at.parent?.id ?? null, delta > 0 ? j + 1 : j);
	}
	function outdent(id) {
		const at = locate(list.value, id);
		if (!at?.parent) return;
		const p = locate(list.value, at.parent.id);
		moveNode(id, p.parent?.id ?? null, p.index + 1);
	}
	function indent(id) {
		const at = locate(list.value, id);
		const prev = at?.list[at.index - 1];
		if (!prev) return;
		collapsed.delete(prev.id);
		moveNode(id, prev.id, (prev.children ?? []).length);
	}
	/** A new block next to the selection: inside it if it fits, else after it, else at the end. */
	function place(node) {
		const where = placement.near(list.value, selected.value, b.defOf(node));
		if (!where) {
			announce(__("{block} can't be placed here. Select a block it can go into first.", { block: b.nameOf(node) }));
			return;
		}
		insertAt(node, where.parentId, where.index);
		select(node.id, { scroll: true });
	}
	function copyNode(id) {
		const at = locate(list.value, id);
		if (!at) return;
		try {
			localStorage.setItem(CLIPBOARD, JSON.stringify(at.node));
		} catch {}
		announce(__("{block} copied.", { block: b.nameOf(at.node) }));
	}
	function paste() {
		let node;
		try {
			node = JSON.parse(localStorage.getItem(CLIPBOARD) ?? "null");
		} catch {
			node = null;
		}
		if (!node || typeof node.type !== "string" || !b.catalog.value.has(node.type)) {
			announce(__("Nothing to paste."));
			return;
		}
		place(reid(node));
	}
	function copyStyles(id) {
		const at = locate(list.value, id);
		if (!at) return;
		try {
			localStorage.setItem(STYLE_CLIPBOARD, JSON.stringify({ design: at.node.design ?? null }));
		} catch {}
		announce(at.node.design ? __("Styles of {block} copied.", { block: b.nameOf(at.node) }) : __("{block} has no styles of its own; pasting them clears the styles of another.", { block: b.nameOf(at.node) }));
	}
	function pasteStyles(target) {
		const ids = Array.isArray(target) ? target : [target];
		if (ids.length > 1) return pasteStylesMany(ids);
		const at = locate(list.value, ids[0]);
		if (!at) return;
		const id = ids[0];
		const copied = copiedStyles();
		if (!copied) {
			announce(__("No styles copied yet. Copy a block's styles first."));
			return;
		}
		if (locked(at.node, "edit")) {
			announce(__("{block} is locked by the layout.", { block: b.nameOf(at.node) }));
			return;
		}
		const supports = b.defOf(at.node)?.supports ?? [];
		if (!supports.length) {
			announce(__("{block} has no style settings.", { block: b.nameOf(at.node) }));
			return;
		}
		const design = stylesFitting(copied.design, supports);
		if (copied.design && !design) {
			announce(__("None of these styles fit {block}.", { block: b.nameOf(at.node) }));
			return;
		}
		commit(update(list.value, id, { design: design ?? void 0 }));
		announce(design ? __("Styles pasted onto {block}.", { block: b.nameOf(at.node) }) : __("Styles of {block} cleared.", { block: b.nameOf(at.node) }));
	}
	function copiedStyles() {
		try {
			const c = JSON.parse(localStorage.getItem(STYLE_CLIPBOARD) ?? "null");
			return c && typeof c === "object" && "design" in c ? c : null;
		} catch {
			return null;
		}
	}
	function pasteStylesMany(ids) {
		const copied = copiedStyles();
		if (!copied) {
			announce(__("No styles copied yet. Copy a block's styles first."));
			return;
		}
		let next = list.value, n = 0;
		for (const id of ids) {
			const node = locate(next, id)?.node;
			const supports = b.defOf(node)?.supports ?? [];
			if (!node || locked(node, "edit") || !supports.length) continue;
			const design = stylesFitting(copied.design, supports);
			if (copied.design && !design) continue;
			next = update(next, id, { design: design ?? void 0 });
			n++;
		}
		if (!n) {
			announce(__("None of these blocks can take these styles."));
			return;
		}
		commit(next);
		announce(__("Styles pasted onto {n} of {total} blocks.", {
			n,
			total: ids.length
		}));
	}
	const editLocked = computed(() => locked(selectedAt.value?.node, "edit"));
	const turnChoices = computed(() => {
		const at = selectedAt.value;
		if (!at || !TEXTUAL.includes(at.node.type) || props.disabled || editLocked.value) return [];
		return TURN().filter(([t, v]) => b.catalog.value.has(t) && b.fitsIn(b.catalog.value.get(t), at.parent) && !isKind(at.node, t, v)).map(([type, variant, label, icon]) => ({
			type,
			variant,
			label,
			icon
		}));
	});
	function turnInto$1({ type, variant, label }) {
		const at = selectedAt.value;
		if (!at) return;
		const node = turnInto(at.node, type, variant);
		b.continueTyping?.(node);
		commit(insert(remove(list.value, at.node.id), at.parent?.id ?? null, at.index, node));
		announce(__("Turned into {block}.", { block: label }));
		select(node.id);
	}
	const canGroup = computed(() => {
		const at = selectedAt.value;
		const g = b.catalog.value.get("core/group");
		return !!at && !!g && at.node.type !== "core/group" && b.fitsIn(g, at.parent) && b.fitsIn(b.defOf(at.node), { type: "core/group" });
	});
	const canUngroup = computed(() => {
		const at = selectedAt.value;
		return !!at && at.node.type === "core/group" && (at.node.children ?? []).length > 0 && at.node.children.every((k) => b.fitsIn(b.defOf(k), at.parent));
	});
	function groupNode(id) {
		const at = locate(list.value, id);
		if (!at || !canGroup.value) return;
		const group = {
			...b.make("core/group"),
			children: [at.node]
		};
		commit(insert(remove(list.value, id), at.parent?.id ?? null, at.index, group));
		announce(__("{block} put in a group.", { block: b.nameOf(at.node) }));
		select(group.id);
	}
	function ungroupNode(id) {
		const at = locate(list.value, id);
		if (!at || !canUngroup.value) return;
		let next = remove(list.value, id);
		at.node.children.forEach((k, i) => {
			next = insert(next, at.parent?.id ?? null, at.index + i, k);
		});
		commit(next);
		announce(__("Group removed; its blocks stay."));
		select(at.node.children[0].id);
	}
	return {
		list,
		placement,
		locked,
		live,
		announce,
		announceUndoable,
		commit,
		iconOf,
		canUndo,
		canRedo,
		undo,
		redo,
		selected,
		hover,
		collapsed,
		rightTab,
		selectedAt,
		selectedDef,
		rows,
		trail,
		owns,
		select,
		quietSelect,
		toggleCollapse,
		summary,
		patchSelected,
		setProp,
		setTyped,
		setNumber,
		insertAt,
		removeNode,
		duplicateNode,
		moveNode,
		shift,
		outdent,
		indent,
		place,
		copyNode,
		paste,
		copyStyles,
		pasteStyles,
		editLocked,
		turnChoices,
		turnInto: turnInto$1,
		canGroup,
		canUngroup,
		groupNode,
		ungroupNode,
		stats: computed(() => {
			const s = documentStats(list.value, b.defOf);
			return {
				...s,
				headings: s.headings.map((h) => ({
					...h,
					text: h.text || __("(empty heading)")
				}))
			};
		}),
		blockErrors: computed(() => Object.entries(props.errors).filter(([k]) => k.startsWith("blocks")).flatMap(([k, v]) => (Array.isArray(v) ? v : [v]).map((message) => {
			const said = errorFromMessage(list.value, message);
			const id = said?.id ?? errorTarget(list.value, k)?.id ?? null;
			const node = id ? locate(list.value, id)?.node : null;
			return node ? {
				message: said ? said.rest : message,
				id,
				name: b.nameOf(node)
			} : {
				message,
				id: null,
				name: null
			};
		})))
	};
}
// core/resources/js/ui/builder/useDrag.js
/**
* Dragging blocks: rows in the layers, tiles in the library, the grip on the
* toolbar; dropped in the layers or on the page. The rules for where a block
* may land are in placement.js; this keeps what is being dragged and where it
* would go, and tells the canvas so it can show it.
*/
function useDrag(b, { props }) {
	const drag = ref(null);
	const drop = ref(null);
	function start(event, payload, effect) {
		drag.value = payload;
		event.dataTransfer.effectAllowed = effect;
		event.dataTransfer.setData("text/plain", payload.id ?? payload.def?.name ?? payload.pattern?.key ?? "");
		b.post({
			type: "kestello:drag",
			active: true
		});
	}
	const dragNode = (event, id) => {
		if (!props.disabled) start(event, { id }, "move");
	};
	const dragNew = (event, payload) => {
		if (!props.disabled) start(event, payload, "copy");
	};
	/** The kind of block being dragged (a pattern: what it becomes). */
	function dragDef() {
		const d = drag.value;
		if (!d) return null;
		if (d.id) return b.defOf(locate(b.list.value, d.id)?.node);
		return d.def ?? b.defOfPattern(d.pattern);
	}
	function clearDrag() {
		drag.value = null;
		drop.value = null;
		b.post({
			type: "kestello:drag",
			active: false
		});
	}
	const endDrag = () => setTimeout(clearDrag, 120);
	function overRow(event, row) {
		if (!drag.value) return;
		const r = event.currentTarget.getBoundingClientRect();
		const t = b.placement.layerTarget(b.list.value, row.node.id, (event.clientY - r.top) / r.height, dragDef(), drag.value.id ?? null);
		drop.value = t;
		if (t) {
			event.preventDefault();
			event.dataTransfer.dropEffect = drag.value.id ? "move" : "copy";
		}
	}
	function overEnd(event) {
		if (!drag.value || !b.fitsIn(dragDef(), null)) return;
		event.preventDefault();
		drop.value = { end: true };
	}
	function leave(match) {
		if (match(drop.value)) drop.value = null;
	}
	function finish(event) {
		event.preventDefault();
		dropHere();
	}
	function onCanvasDrag(data) {
		if (!drag.value) return;
		const chain = Array.isArray(data.chain) ? data.chain.filter((c) => typeof c?.id === "string").slice(0, 24) : [];
		const target = b.placement.canvasTarget(b.list.value, chain, dragDef(), drag.value.id ?? null);
		drop.value = target;
		b.post({
			type: "kestello:drop-mark",
			mark: target
		});
	}
	/** Put what is being dragged where it would go. */
	function dropHere() {
		const t = drop.value;
		const d = drag.value;
		clearDrag();
		if (!t || !d) return;
		if (t.where === "left" || t.where === "right") {
			beside(t, d);
			return;
		}
		const where = b.placement.position(b.list.value, t);
		if (!where) return;
		if (t.where === "inside") b.collapsed.delete(t.id);
		if (d.id) {
			b.moveNode(d.id, where.parentId, where.index, { focus: false });
			b.select(d.id);
			return;
		}
		const node = d.def ? b.create(d.def) : b.makePattern(d.pattern);
		b.insertAt(node, where.parentId, where.index);
		b.select(node.id);
	}
	function beside(t, d) {
		const node = d.id ? locate(b.list.value, d.id)?.node : d.def ? b.create(d.def) : b.makePattern(d.pattern);
		const other = locate(b.list.value, t.id)?.node;
		const next = node ? b.placement.placeBeside(b.list.value, t, node, d.id ?? null) : null;
		if (!next) return;
		b.commit(next);
		b.announce(__("{block} placed beside {other}.", {
			block: b.nameOf(node),
			other: b.nameOf(other)
		}));
		b.select(node.id);
	}
	return {
		drag,
		drop,
		dragNode,
		dragNew,
		endDrag,
		overRow,
		overEnd,
		leave,
		finish,
		onCanvasDrag,
		dropHere
	};
}
// core/resources/js/ui/builder/shortcuts.js
/**
* Keyboard shortcuts: how they are written for this computer, and the list the
* shortcuts dialog shows. Combos are written as "Mod+Shift+D" (Mod: ⌘ on a Mac,
* Ctrl elsewhere).
*/
var isMac = typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform ?? "");
function keyLabel(combo, mac = isMac) {
	const keys = combo.replace(/\bMod\b/g, mac ? "⌘" : "Ctrl").replace(/\bAlt\b/g, mac ? "⌥" : "Alt").replace(/\bShift\b/g, mac ? "⇧" : "Shift");
	return mac ? keys.replace(/([⌘⌥⇧])\+/g, "$1") : keys;
}
/** Groups of [what, combo] for the shortcuts dialog. */
var shortcutGroups = (t) => [
	[t("Editing"), [
		[t("Add a block"), "/"],
		[t("Undo"), "Mod+Z"],
		[t("Redo"), "Mod+Shift+Z"],
		[t("Save"), "Mod+S"],
		[t("Duplicate the block"), "Mod+Shift+D"],
		[t("Remove the block"), "Alt+Shift+Z"],
		[t("Add a block before"), "Mod+Alt+T"],
		[t("Add a block after"), "Mod+Alt+Y"],
		[t("Copy the block's styles"), "Mod+Alt+C"],
		[t("Paste styles onto the block"), "Mod+Alt+V"]
	]],
	[t("Text"), [
		[t("Bold"), "Mod+B"],
		[t("Italic"), "Mod+I"],
		[t("Link"), "Mod+K"],
		[t("Strikethrough"), "Alt+Shift+D"],
		[t("Inline code"), "Alt+Shift+X"],
		[t("New block (on an empty line)"), "Enter"],
		[t("Line break"), "Shift+Enter"]
	]],
	[t("Moving around"), [
		[t("Layers"), "Alt+Shift+O"],
		[t("Find and replace"), "Mod+Shift+F"],
		[t("Settings panel"), "Mod+Shift+,"],
		[t("Hide both panels"), "Mod+Shift+\\"],
		[t("Move the block (in layers)"), "Alt+↑↓"],
		[t("Select the parent (in layers)"), "←"],
		[t("These shortcuts"), "?"]
	]]
];
// core/resources/js/ui/builder/useKeyboard.js
/**
* Keys: the editor's own (anywhere in it), the layers tree's, and the block
* commands that work the same when typed on the page (shared/canvas-protocol.js).
*/
function useKeyboard(b, { props, emit, root }) {
	const shortcutsOpen = ref(false);
	const mod = (e) => e.metaKey || e.ctrlKey;
	/** A block command, from a key here or on the page. */
	function runCommand(command, id = b.selected.value, rect = null) {
		if (command === "undo") b.undo();
		else if (command === "redo") b.redo();
		else if (command === "save") emit("save");
		else if (command === "find") b.openFind();
		else if (!id || props.disabled) return;
		else if (command === "duplicate") b.multi.value ? b.duplicatePicked() : b.duplicateNode(id);
		else if (command === "remove-block") b.multi.value ? b.removePicked() : b.removeNode(id);
		else if (command === "insert-before") b.openQuick({
			mode: "before",
			id,
			rect
		});
		else if (command === "insert-after") b.openQuick({
			mode: "after",
			id,
			rect
		});
		else if (command === "copy-styles") b.copyStyles(id);
		else if (command === "paste-styles") b.pasteStyles(b.multi.value ? b.pickedIds.value : id);
	}
	function globalKey(event) {
		const target = event.target;
		const typing = target instanceof HTMLElement && (target.isContentEditable || [
			"INPUT",
			"TEXTAREA",
			"SELECT"
		].includes(target.tagName));
		if (mod(event) && event.shiftKey && event.key === ",") {
			event.preventDefault();
			b.right.value = !b.right.value;
			return;
		}
		if (event.altKey && event.shiftKey && event.code === "KeyO") {
			event.preventDefault();
			b.toggleLeft("layers");
			return;
		}
		if (mod(event) && event.shiftKey && event.code === "Backslash") {
			event.preventDefault();
			b.toggleFocus();
			return;
		}
		if (blockCommand(event) === "find") {
			event.preventDefault();
			b.openFind();
			return;
		}
		if (typing) return;
		if (event.key === "Escape" && b.multi.value) {
			event.preventDefault();
			b.select(b.selected.value);
			return;
		}
		if (event.key === "?" && !mod(event)) {
			event.preventDefault();
			shortcutsOpen.value = true;
			return;
		}
		if (event.key === "/" && !mod(event) && !props.disabled) {
			event.preventDefault();
			b.openQuick({
				mode: "after",
				id: b.selected.value
			});
			return;
		}
		const command = blockCommand(event);
		if (command && command !== "save") {
			event.preventDefault();
			runCommand(command);
		}
	}
	/** The layers tree: arrows move between rows, Alt+arrows move the block, Mod+D/C/V. */
	function rowKey(event, row, i) {
		const id = row.node.id;
		const rows = b.rows.value;
		const focusRow = (r) => {
			if (r) b.select(r.node.id, { focus: true });
		};
		if (event.altKey && !props.disabled) {
			const actions = {
				ArrowUp: () => b.shift(id, -1),
				ArrowDown: () => b.shift(id, 1),
				ArrowLeft: () => b.outdent(id),
				ArrowRight: () => b.indent(id)
			};
			if (actions[event.key]) {
				event.preventDefault();
				event.stopPropagation();
				actions[event.key]();
				return;
			}
		}
		if (mod(event) && !event.shiftKey && !props.disabled) {
			const act = {
				d: () => b.duplicateNode(id),
				c: () => b.copyNode(id),
				v: () => b.paste()
			}[event.key.toLowerCase()];
			if (act) {
				event.preventDefault();
				event.stopPropagation();
				act();
				return;
			}
		}
		if (event.shiftKey && !mod(event) && !event.altKey && (event.key === "ArrowUp" || event.key === "ArrowDown")) {
			event.preventDefault();
			const at = locate(b.list.value, id);
			const to = at?.list[at.index + (event.key === "ArrowUp" ? -1 : 1)];
			if (to) {
				b.selectMore(to.id, "range");
				nextTick(() => root.value?.querySelector(`[data-row="${to.id}"]`)?.focus());
			}
			return;
		}
		const hasChildren = (row.node.children ?? []).length > 0;
		switch (event.key) {
			case "ArrowDown":
				event.preventDefault();
				focusRow(rows[i + 1]);
				break;
			case "ArrowUp":
				event.preventDefault();
				focusRow(rows[i - 1]);
				break;
			case "Home":
				event.preventDefault();
				focusRow(rows[0]);
				break;
			case "End":
				event.preventDefault();
				focusRow(rows[rows.length - 1]);
				break;
			case "ArrowRight":
				event.preventDefault();
				if (hasChildren && b.collapsed.has(id)) b.collapsed.delete(id);
				else focusRow(rows[i + 1]?.depth > row.depth ? rows[i + 1] : null);
				break;
			case "ArrowLeft":
				event.preventDefault();
				if (hasChildren && !b.collapsed.has(id)) b.collapsed.add(id);
				else if (row.depth > 0) focusRow(rows.slice(0, i).reverse().find((r) => r.depth < row.depth));
				break;
			case "Delete":
			case "Backspace":
				if (!props.disabled) {
					event.preventDefault();
					event.stopPropagation();
					if (b.multi.value) b.removePicked();
					else b.removeNode(id);
				}
				break;
			case "Escape":
				event.preventDefault();
				if (b.multi.value) {
					event.stopPropagation();
					b.select(b.selected.value, { focus: true });
					break;
				}
				b.select(null);
				b.rightTab.value = "document";
		}
	}
	return {
		shortcutsOpen,
		runCommand,
		globalKey,
		rowKey,
		K: keyLabel
	};
}
// core/resources/js/ui/builder/find.js
/**
* Find and replace across a document's blocks (BuilderFind.vue). Only the words people
* read are searched: text, long text, rich text (its text, never its tags or links),
* table cells and the text inside repeating items (a list's items). Settings, keys and
* links are left alone, and so is a field marked option('findable', false).
* Everything here is pure: a tree in, matches or a new tree out.
*/
var TEXT_TYPES = /* @__PURE__ */ new Set([
	"text",
	"textarea",
	"rich_text",
	"table"
]);
var MAX_QUERY = 200;
var escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
/** A search as a RegExp (global), or null when there is nothing to look for. */
function searchPattern(query, { matchCase = false } = {}) {
	const q = String(query ?? "");
	if (q.trim() === "" || q.length > MAX_QUERY) return null;
	return new RegExp(escapeRe(q), matchCase ? "g" : "gi");
}
/** The fields of a block that hold words: [{ name, type, fields? }] (fields: a repeater's own). */
function textFields(fields = []) {
	return fields.flatMap((f) => {
		if (f.options?.findable === false) return [];
		if (f.type === "repeater") {
			const inner = textFields(f.fields ?? []);
			return inner.length ? [{
				name: f.name,
				type: "repeater",
				fields: inner
			}] : [];
		}
		return TEXT_TYPES.has(f.type) ? [{
			name: f.name,
			type: f.type
		}] : [];
	});
}
var ENTITIES = {
	amp: "&",
	lt: "<",
	gt: ">",
	quot: "\"",
	apos: "'",
	"#39": "'",
	nbsp: "\xA0"
};
var decode = (s) => s.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+\d*);/gi, (m, e) => {
	const k = e.toLowerCase();
	if (k in ENTITIES) return ENTITIES[k];
	if (k.startsWith("#x")) return String.fromCodePoint(parseInt(k.slice(2), 16));
	if (k.startsWith("#")) return String.fromCodePoint(Number(k.slice(1)));
	return m;
});
var encode = (s) => s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
/** Replace (or, with replacement null, count) in plain text. */
function inPlain(text, re, replacement) {
	re.lastIndex = 0;
	const count = (text.match(re) ?? []).length;
	if (!count || replacement === null) return {
		text,
		count
	};
	return {
		text: text.replace(re, () => replacement),
		count
	};
}
/** Replace (or count) in rich text: only text between tags changes; a segment without a match is kept as it was. */
function inHtml(html, re, replacement) {
	let count = 0;
	return {
		text: html.split(/(<[^>]*>)/).map((part, i) => {
			if (i % 2 === 1 || part === "") return part;
			const r = inPlain(decode(part), re, replacement);
			count += r.count;
			return r.count && replacement !== null ? encode(r.text) : part;
		}).join(""),
		count
	};
}
/** One field's value: counted, or replaced. Values that aren't text (bound to data, for one) are left as they are. */
function inValue(value, field, re, replacement) {
	if (field.type === "repeater") {
		if (!Array.isArray(value)) return {
			value,
			count: 0
		};
		let count = 0;
		const items = value.map((item) => {
			if (!item || typeof item !== "object") return item;
			const r = inProps(item, field.fields, re, replacement);
			count += r.count;
			return r.count ? r.props : item;
		});
		return {
			value: count ? items : value,
			count
		};
	}
	if (field.type === "table") {
		if (!value || !Array.isArray(value.rows)) return {
			value,
			count: 0
		};
		let count = 0;
		const rows = value.rows.map((row) => Array.isArray(row) ? row.map((cell) => {
			if (typeof cell !== "string") return cell;
			const r = inPlain(cell, re, replacement);
			count += r.count;
			return r.text;
		}) : row);
		return {
			value: count ? {
				...value,
				rows
			} : value,
			count
		};
	}
	if (typeof value !== "string") return {
		value,
		count: 0
	};
	const r = field.type === "rich_text" ? inHtml(value, re, replacement) : inPlain(value, re, replacement);
	return {
		value: r.text,
		count: r.count
	};
}
function inProps(props, fields, re, replacement) {
	let count = 0;
	const next = { ...props };
	const where = [];
	for (const f of fields) {
		if (!(f.name in (props ?? {}))) continue;
		const r = inValue(props[f.name], f, re, replacement);
		if (r.count) {
			count += r.count;
			where.push(f.name);
			next[f.name] = r.value;
		}
	}
	return {
		props: count ? next : props,
		count,
		where
	};
}
/**
* Every block with a match, in the order of the page.
* @param {object[]} list            the document
* @param {(node: object) => object|null} defOf  a block's definition
* @returns {{ id: string, count: number, fields: string[], snippet: string }[]}
*/
function findMatches(list, defOf, query, options = {}) {
	const re = searchPattern(query, options);
	if (!re) return [];
	const out = [];
	const walk = (nodes) => {
		for (const node of nodes ?? []) {
			const fields = textFields(defOf(node)?.fields);
			const r = fields.length ? inProps(node.props ?? {}, fields, re, null) : { count: 0 };
			if (r.count) out.push({
				id: node.id,
				count: r.count,
				fields: r.where,
				snippet: snippet(node.props[r.where[0]], fields.find((f) => f.name === r.where[0]), re)
			});
			walk(node.children);
		}
	};
	walk(list);
	return out;
}
/**
* The document with every match replaced (in the blocks named, or all), and how many were.
* @param {(node: object) => boolean} [skip]  blocks to leave alone (locked by the layout)
*/
function replaceMatches(list, defOf, query, replacement, { ids = null, skip = () => false, ...options } = {}) {
	const re = searchPattern(query, options);
	if (!re) return {
		list,
		count: 0
	};
	let count = 0;
	const walk = (nodes) => {
		let changed = false;
		const next = nodes.map((node) => {
			let n = node;
			if ((!ids || ids.includes(node.id)) && !skip(node)) {
				const fields = textFields(defOf(node)?.fields);
				const r = fields.length ? inProps(node.props ?? {}, fields, re, String(replacement ?? "")) : { count: 0 };
				if (r.count) {
					count += r.count;
					n = {
						...node,
						props: r.props
					};
				}
			}
			if (node.children?.length) {
				const kids = walk(node.children);
				if (kids !== node.children) n = {
					...n,
					children: kids
				};
			}
			if (n !== node) changed = true;
			return n;
		});
		return changed ? next : nodes;
	};
	return {
		list: walk(list),
		count
	};
}
/** A few words around the first match, as plain text, for the results list. */
function snippet(value, field, re, room = 32) {
	let text = "";
	if (field?.type === "repeater" && Array.isArray(value)) text = value.map((item) => field.fields.map((f) => typeof item?.[f.name] === "string" ? item[f.name] : "").join(" ")).join(" · ");
	else if (field?.type === "table") text = (value?.rows ?? []).flat().filter((c) => typeof c === "string").join(" · ");
	else if (typeof value === "string") text = field?.type === "rich_text" ? decode(value.replace(/<[^>]*>/g, " ")) : value;
	text = text.replace(/\s+/g, " ").trim();
	re.lastIndex = 0;
	const m = re.exec(text);
	if (!m) return text.slice(0, room * 2);
	const start = Math.max(0, m.index - room);
	const end = Math.min(text.length, m.index + m[0].length + room);
	return `${start > 0 ? "…" : ""}${text.slice(start, end)}${end < text.length ? "…" : ""}`;
}
/** A snippet in parts, the matches marked: [{ text, hit }] (drawn as text, never as HTML). */
function highlight(text, query, options = {}) {
	const re = searchPattern(query, options);
	if (!re || !text) return [{
		text: text ?? "",
		hit: false
	}];
	const parts = [];
	let at = 0;
	for (const m of text.matchAll(re)) {
		if (m.index > at) parts.push({
			text: text.slice(at, m.index),
			hit: false
		});
		parts.push({
			text: m[0],
			hit: true
		});
		at = m.index + m[0].length;
	}
	if (at < text.length) parts.push({
		text: text.slice(at),
		hit: false
	});
	return parts;
}
// core/resources/js/ui/builder/useFind.js
/**
* Find and replace (the left panel's third view, BuilderFind.vue): what is searched for,
* the blocks that match, and replacing in one block or all at once (one undo step each).
* The searching itself is find.js.
*/
function useFind(b, { root }) {
	const findQuery = ref("");
	const replaceWith = ref("");
	const matchCase = ref(false);
	const findResults = computed(() => findMatches(b.list.value, b.defOf, findQuery.value, { matchCase: matchCase.value }));
	const findTotal = computed(() => findResults.value.reduce((n, r) => n + r.count, 0));
	function openFind() {
		b.left.value = "find";
		nextTick(() => {
			const input = root.value?.querySelector(".k-find__query");
			input?.focus();
			input?.select();
		});
	}
	function replaceFound(ids = null) {
		if (b.props.disabled) return;
		const { list, count } = replaceMatches(b.list.value, b.defOf, findQuery.value, replaceWith.value, {
			ids,
			matchCase: matchCase.value,
			skip: (node) => b.locked(node, "edit")
		});
		if (!count) {
			b.announce(__("Nothing was replaced. Blocks locked by the layout are left as they are."));
			return;
		}
		b.commit(list);
		b.announceUndoable(count === 1 ? __("1 replaced.") : __("{n} replaced.", { n: count }));
	}
	return {
		findQuery,
		replaceWith,
		matchCase,
		findResults,
		findTotal,
		openFind,
		replaceFound
	};
}
// core/resources/js/ui/builder/useLibrary.js
/**
* The block library: blocks by category, and patterns, filtered by a search.
*/
function useLibrary(b, { props }) {
	const query = ref("");
	const CATEGORIES = [
		["text", __("Text")],
		["media", __("Media")],
		["layout", __("Layout")],
		["data", __("Entries and data")],
		["widgets", __("Widgets")]
	];
	const matches = (text) => {
		const q = query.value.trim().toLowerCase();
		return !q || text.toLowerCase().includes(q);
	};
	const library = computed(() => {
		const usable = b.definitions.value.filter((d) => !b.hidden.value.includes(d.name) && d.parents === null && (!props.allowed || props.allowed.includes(d.name) || !!b.selected.value) && matches(`${d.label} ${d.description} ${d.name}`));
		return CATEGORIES.map(([key, label]) => ({
			key,
			label,
			blocks: usable.filter((d) => d.category === key)
		})).filter((g) => g.blocks.length);
	});
	const usablePatterns = computed(() => b.patterns.value.filter((p) => (!p.synced || !b.hidden.value.includes("core/pattern")) && !(props.kind === "pattern" && p.key === props.docKey)));
	return {
		query,
		library,
		usablePatterns,
		patternChoices: computed(() => query.value.trim() ? usablePatterns.value.filter((p) => matches(`${p.title} ${p.description} ${p.category}`)) : usablePatterns.value.filter((p) => p.source !== "core" && !p.layout))
	};
}
// core/resources/js/ui/builder/multi.js
/**
* Several blocks selected at once (Shift+click for a run, Mod+click to add or take one
* away). They are always siblings, side by side under one parent, as in most editors:
* that keeps grouping, removing and duplicating them simple and predictable.
* Pure: a tree and ids in, ids or a new tree out.
*/
var parentOf = (list, id) => {
	const at = locate(list, id);
	return at ? at.parent?.id ?? null : void 0;
};
/** The ids in page order, those not found (or not siblings of the first) left out. */
function inOrder(list, ids) {
	const first = ids.map((id) => locate(list, id)).find(Boolean);
	if (!first) return [];
	const want = new Set(ids);
	return first.list.filter((n) => want.has(n.id)).map((n) => n.id);
}
/** Shift+click: every sibling from the anchor to the one clicked. Just the clicked one when they aren't siblings. */
function rangeBetween(list, anchor, target) {
	const a = locate(list, anchor), t = locate(list, target);
	if (!t) return [];
	if (!a || a.list !== t.list) return [target];
	const [from, to] = a.index <= t.index ? [a.index, t.index] : [t.index, a.index];
	return a.list.slice(from, to + 1).map((n) => n.id);
}
/** Mod+click: the one clicked added or taken away; a block elsewhere starts afresh. */
function toggled(list, picked, id) {
	if (picked.includes(id)) return picked.filter((x) => x !== id);
	const p = picked.length ? parentOf(list, picked[0]) : void 0;
	const q = parentOf(list, id);
	if (q === void 0) return picked;
	return p === q ? inOrder(list, [...picked, id]) : [id];
}
/** The siblings as copies (fresh ids), placed after the last of them. Returns the tree and the copies' ids. */
function duplicateMany(list, ids, insert) {
	const order = inOrder(list, ids);
	if (!order.length) return {
		list,
		ids: []
	};
	const last = locate(list, order[order.length - 1]);
	const copies = order.map((id) => reid(locate(list, id).node));
	let next = list;
	copies.forEach((c, i) => {
		next = insert(next, last.parent?.id ?? null, last.index + 1 + i, c);
	});
	return {
		list: next,
		ids: copies.map((c) => c.id)
	};
}
/** The siblings moved into a new group where the first of them was. */
function groupMany(list, ids, group, { insert, remove }) {
	const order = inOrder(list, ids);
	if (!order.length) return list;
	const first = locate(list, order[0]);
	const nodes = order.map((id) => locate(list, id).node);
	let next = list;
	for (const id of order) next = remove(next, id);
	return insert(next, first.parent?.id ?? null, first.index, {
		...group,
		children: nodes
	});
}
// core/resources/js/ui/builder/useMulti.js
/**
* Several blocks selected at once: Shift+click picks a run of siblings, Mod+click adds
* or takes one away (in the layers and on the page). The selected block stays the one
* the run started from; the rest are in picked. Removing, duplicating, grouping and
* pasting styles then act on all of them, each one undo step. The rules are multi.js.
*/
function useMulti(b) {
	const picked = ref([]);
	const multi = computed(() => picked.value.length > 1);
	/** The blocks actions apply to: all picked, or the one selected. */
	const pickedIds = computed(() => multi.value ? picked.value : b.selected.value ? [b.selected.value] : []);
	const pickedNodes = computed(() => pickedIds.value.map((id) => locate(b.list.value, id)?.node).filter(Boolean));
	watch(b.list, (list) => {
		if (!picked.value.length) return;
		const left = inOrder(list, picked.value);
		picked.value = left.length > 1 ? left : [];
	});
	function setPicked(ids, anchor) {
		picked.value = ids;
		b.selected.value = anchor;
		b.sendMarks?.(false);
		b.announce(__("{n} blocks selected.", { n: ids.length }));
	}
	/** Shift+click (range) or Mod+click (toggle). */
	function selectMore(id, mode) {
		const anchor = b.selected.value;
		if (!anchor || !locate(b.list.value, id) || !locate(b.list.value, anchor)) {
			b.select(id);
			return;
		}
		const next = mode === "range" ? rangeBetween(b.list.value, anchor, id) : toggled(b.list.value, multi.value ? picked.value : [anchor], id);
		if (next.length < 2) {
			b.select(next[0] ?? null);
			return;
		}
		setPicked(next, next.includes(anchor) ? anchor : next[0]);
	}
	const canGroupPicked = computed(() => {
		const g = b.catalog.value.get("core/group");
		const at = multi.value ? locate(b.list.value, picked.value[0]) : null;
		return !!g && !!at && b.fitsIn(g, at.parent) && pickedNodes.value.every((n) => b.fitsIn(b.defOf(n), { type: "core/group" }) && !b.locked(n, "move"));
	});
	function removePicked() {
		const ids = pickedIds.value.filter((id) => !b.locked(locate(b.list.value, id)?.node, "remove"));
		if (!ids.length) {
			b.announce(__("These blocks are locked by the layout and can't be removed."));
			return;
		}
		const first = locate(b.list.value, ids[0]), last = locate(b.list.value, ids[ids.length - 1]);
		const after = last.list[last.index + 1] ?? first.list[first.index - 1] ?? first.parent;
		let next = b.list.value;
		for (const id of ids) next = remove(next, id);
		b.commit(next);
		b.announceUndoable(__("{n} blocks removed.", { n: ids.length }));
		b.select(after?.id ?? null, { focus: true });
	}
	function duplicatePicked() {
		const { list, ids } = duplicateMany(b.list.value, pickedIds.value, insert);
		if (!ids.length) return;
		b.commit(list);
		setPicked(ids, ids[0]);
		b.announce(__("{n} blocks duplicated.", { n: ids.length }));
	}
	function groupPicked() {
		if (!canGroupPicked.value) return;
		const group = b.make("core/group");
		const n = picked.value.length;
		b.commit(groupMany(b.list.value, picked.value, group, tree_exports));
		b.select(group.id);
		b.announce(__("{n} blocks put in a group.", { n }));
	}
	return {
		picked,
		multi,
		pickedIds,
		pickedNodes,
		selectMore,
		canGroupPicked,
		removePicked,
		duplicatePicked,
		groupPicked
	};
}
// core/resources/js/ui/builder/panels.js
/**
* The side panels on a narrower screen (usePanels): one at a time, so the page keeps its
* room instead of being covered. The one just opened stays; when both were open already
* (the window got narrower), the settings panel stays.
*
* @param {{left:string, right:boolean}} prev  before the change
* @param {{left:string, right:boolean}} next  after it
* @param {boolean} narrow                     the screen has room for one panel only
* @returns {{left:string, right:boolean}}
*/
function onePanel(prev, next, narrow) {
	if (!narrow || !next.left || !next.right) return next;
	const leftJustOpened = !prev.left || prev.left !== next.left;
	const rightJustOpened = !prev.right;
	if (leftJustOpened && !rightJustOpened) return {
		left: next.left,
		right: false
	};
	return {
		left: "",
		right: true
	};
}
/** The width below which only one side panel fits beside the page (matches builder.css). */
var ONE_PANEL_BELOW = 1180;
// core/resources/js/ui/builder/usePanels.js
/**
* The panels around the canvas (which are open, how wide; remembered in this
* browser) and the menus of the bar and the toolbar (one open at a time).
*/
var STORE = "kestello.builder.panels";
var RIGHT = {
	min: 280,
	max: 640,
	initial: 320
};
var BELOW = {
	min: 140,
	max: 900,
	initial: 320
};
var clamp = (n, { min, max, initial }) => Math.min(max, Math.max(min, Number(n) || initial));
function stored() {
	try {
		return JSON.parse(localStorage.getItem(STORE) ?? "{}") ?? {};
	} catch {
		return {};
	}
}
function usePanels(b, { root }) {
	const saved = stored();
	const roomy = typeof window === "undefined" || window.innerWidth >= 1360;
	const left = ref([
		"layers",
		"add",
		"find",
		""
	].includes(saved.left) ? saved.left : roomy ? "layers" : "");
	const right = ref(typeof saved.right === "boolean" ? saved.right : true);
	const rightWidth = ref(clamp(saved.rightWidth, RIGHT));
	const docked = ref(saved.docked === true);
	const belowOpen = ref(saved.below === true);
	const belowHeight = ref(clamp(saved.belowHeight, BELOW));
	const dockEl = ref(null);
	const narrowQuery = typeof window !== "undefined" && window.matchMedia ? window.matchMedia(`(max-width: ${ONE_PANEL_BELOW}px)`) : null;
	const narrow = ref(!!narrowQuery?.matches);
	const fit = (prev) => {
		const next = onePanel(prev, {
			left: left.value,
			right: right.value
		}, narrow.value);
		if (next.left !== left.value) left.value = next.left;
		if (next.right !== right.value) right.value = next.right;
	};
	watch([left, right], ([l, r], [pl, pr]) => fit({
		left: pl,
		right: pr
	}));
	const onNarrow = (e) => {
		narrow.value = e.matches;
		fit({
			left: left.value,
			right: right.value
		});
	};
	narrowQuery?.addEventListener("change", onNarrow);
	onBeforeUnmount(() => narrowQuery?.removeEventListener("change", onNarrow));
	fit({
		left: left.value,
		right: right.value
	});
	watch([
		left,
		right,
		rightWidth,
		docked,
		belowOpen,
		belowHeight
	], () => {
		try {
			localStorage.setItem(STORE, JSON.stringify({
				left: left.value,
				right: right.value,
				rightWidth: rightWidth.value,
				docked: docked.value,
				below: belowOpen.value,
				belowHeight: belowHeight.value
			}));
		} catch {}
	});
	function toggleLeft(mode) {
		left.value = left.value === mode ? "" : mode;
		if (left.value === "add") nextTick(() => root.value?.querySelector(".k-builder__search")?.focus());
	}
	function showRight(tab) {
		right.value = true;
		if (tab) b.rightTab.value = tab;
	}
	let before = null;
	const focusMode = computed(() => !left.value && !right.value);
	function toggleFocus() {
		if (!focusMode.value) {
			before = {
				left: left.value,
				right: right.value
			};
			left.value = "";
			right.value = false;
		} else {
			left.value = before?.left || "layers";
			right.value = before?.right ?? true;
		}
	}
	/** Drag a panel's edge; the frame would swallow the pointer, so it stops listening meanwhile. */
	function resize(event, what) {
		event.preventDefault();
		const x0 = event.clientX, y0 = event.clientY, w0 = rightWidth.value, h0 = belowHeight.value;
		const move = (e) => {
			if (what === "right") rightWidth.value = clamp(w0 - (e.clientX - x0), RIGHT);
			else belowHeight.value = Math.round(Math.min(window.innerHeight * .75, clamp(h0 - (e.clientY - y0), BELOW)));
		};
		const up = () => {
			window.removeEventListener("pointermove", move);
			window.removeEventListener("pointerup", up);
			document.body.classList.remove("k-resizing");
		};
		window.addEventListener("pointermove", move);
		window.addEventListener("pointerup", up);
		document.body.classList.add("k-resizing");
	}
	function resizeKey(event, what) {
		const d = {
			ArrowLeft: 1,
			ArrowRight: -1,
			ArrowUp: 1,
			ArrowDown: -1
		}[event.key];
		if (!d) return;
		event.preventDefault();
		const step = (event.shiftKey ? 64 : 16) * d;
		if (what === "right") rightWidth.value = clamp(rightWidth.value + step, RIGHT);
		else belowHeight.value = clamp(belowHeight.value + step, BELOW);
	}
	const menu = ref("");
	const toggleMenu = (name) => {
		menu.value = menu.value === name ? "" : name;
	};
	const closeMenu = () => {
		menu.value = "";
	};
	function onDocDown(event) {
		if (menu.value && !event.target.closest?.(".k-menu-wrap")) menu.value = "";
	}
	function onMenuKey(event) {
		if (event.key === "Escape" && menu.value) {
			event.stopPropagation();
			menu.value = "";
		}
	}
	watch(b.selected, () => {
		if (!["details", "more"].includes(menu.value)) menu.value = "";
	});
	onMounted(() => document.addEventListener("mousedown", onDocDown));
	onBeforeUnmount(() => document.removeEventListener("mousedown", onDocDown));
	return {
		left,
		right,
		rightWidth,
		docked,
		dockEl,
		belowOpen,
		belowHeight,
		toggleLeft,
		showRight,
		focusMode,
		toggleFocus,
		resize,
		resizeKey,
		menu,
		toggleMenu,
		closeMenu,
		onMenuKey
	};
}
// core/resources/js/ui/builder/patterns.js
/**
* The pattern browser's logic (BuilderPatterns.vue): patterns grouped by category and
* found by a search, and a whole-page layout turned into blocks. Patterns arrive from
* GET /design/patterns, fitted to this site already.
*/
/** Core's categories, in the order people look for them; a theme's own come after, A to Z. */
var CATEGORY_ORDER = [
	"Pages",
	"Headers",
	"Features",
	"Text",
	"Calls to action",
	"Testimonials",
	"Pricing",
	"Questions",
	"About",
	"Contact",
	"Lists and tables"
];
var words = (q) => String(q ?? "").toLowerCase().split(/\s+/).filter(Boolean);
/** Does a pattern match every word searched for (in its title, description or category)? */
function matchesPattern(p, query) {
	const text = `${p.title} ${p.description ?? ""} ${p.category ?? ""}`.toLowerCase();
	return words(query).every((w) => text.includes(w));
}
/**
* The patterns to show, in their categories, in order.
* @param {object[]} patterns
* @param {{ query?: string, layouts?: boolean }} options  layouts: only whole pages
* @returns {{ name: string, patterns: object[] }[]}
*/
function groupPatterns(patterns, { query = "", layouts = false } = {}) {
	const shown = patterns.filter((p) => (!layouts || p.layout) && matchesPattern(p, query));
	const names = [...new Set(shown.map((p) => p.category || "General"))];
	const rank = (n) => {
		const i = CATEGORY_ORDER.indexOf(n);
		return i === -1 ? CATEGORY_ORDER.length : i;
	};
	names.sort((a, b) => rank(a) - rank(b) || a.localeCompare(b));
	return names.map((name) => ({
		name,
		patterns: shown.filter((p) => (p.category || "General") === name)
	}));
}
/** A whole-page layout as fresh blocks for the page itself (not wrapped in a group, as a section is). */
function layoutBlocks(pattern, catalog) {
	return (pattern.blocks ?? []).filter((b) => catalog.has(b.type)).map(reid);
}
/** Does choosing this pattern need a choice first: a whole-page layout onto a page that has blocks? */
var needsLayoutChoice = (pattern, list) => !!pattern?.layout && !pattern.synced && list.length > 0;
/**
* The page after a layout goes onto it.
* @param {object[]} list    the page's blocks now
* @param {object[]} blocks  the layout's (layoutBlocks)
* @param {'replace'|'append'} how
*/
function withLayout(list, blocks, how) {
	if (how !== "replace" && how !== "append") throw new Error(`withLayout: how must be "replace" or "append", got ${JSON.stringify(how)}`);
	return how === "replace" ? [...blocks] : [...list, ...blocks];
}
// core/resources/js/ui/builder/usePatterns.js
/**
* The pattern browser: open for adding a section, or for starting an empty page from a
* layout. A layout's blocks become the page's own (one undo step); any other pattern is
* placed like a block (next to the selection), a synced one as its reference. A layout
* chosen for a page that has blocks first asks: replace them, or add after them.
*/
function usePatterns(b) {
	/** null: closed; 'insert': every pattern; 'layouts': whole pages, for an empty page */
	const browsing = ref(null);
	/** the layout waiting for "replace or add after" (the browser stays open meanwhile) */
	const layoutChoice = ref(null);
	const openPatterns = (mode = "insert") => {
		browsing.value = mode;
		layoutChoice.value = null;
	};
	const closePatterns = () => {
		browsing.value = null;
		layoutChoice.value = null;
	};
	/** @param {'replace'|'append'|null} how  for a layout onto a page with blocks */
	function choosePattern(p, how = null) {
		if (!how && needsLayoutChoice(p, b.list.value)) {
			layoutChoice.value = p;
			return;
		}
		closePatterns();
		if (p.layout && !p.synced) {
			const blocks = layoutBlocks(p, b.catalog.value);
			if (!blocks.length) return;
			b.commit(withLayout(b.list.value, blocks, how ?? "append"));
			b.select(blocks[0].id, { scroll: true });
			b.announceUndoable(how === "replace" ? __("The page is now {name}: {n} blocks.", {
				name: p.title,
				n: blocks.length
			}) : __("{name} added: {n} blocks.", {
				name: p.title,
				n: blocks.length
			}));
			return;
		}
		b.place(b.makePattern(p));
	}
	return {
		browsing,
		layoutChoice,
		openPatterns,
		closePatterns,
		choosePattern
	};
}
// core/resources/js/ui/builder/useQuickInsert.js
/**
* The quick inserter: / on the page (or + on the toolbar) opens a search for a
* block to add before, after or instead of the one the caret is in.
*/
var SHOWN = 12;
var ORDER = [
	"text",
	"media",
	"layout",
	"data",
	"widgets"
];
function useQuickInsert(b, { props, root }) {
	const quick = reactive({
		open: false,
		mode: "after",
		id: null,
		query: "",
		style: null
	});
	const active = ref(0);
	/** @param {{ mode: 'before'|'after'|'replace', id: string|null, rect?: object }} options  rect: the field typed in, as the canvas reported it */
	function openQuick({ mode, id, rect = null }) {
		if (props.disabled) return;
		quick.mode = mode;
		quick.id = id ?? null;
		quick.query = "";
		quick.style = b.under(rect) ?? (b.toolbarStyle.value && !b.toolbarDocked.value ? { ...b.toolbarStyle.value } : {
			top: "16px",
			left: "16px"
		});
		quick.open = true;
		nextTick(() => root.value?.querySelector(".k-builder__quick input")?.focus());
	}
	const parent = computed(() => quick.id ? locate(b.list.value, quick.id)?.parent ?? null : null);
	const choices = computed(() => {
		const q = quick.query.trim().toLowerCase();
		const matches = (text) => !q || text.toLowerCase().includes(q);
		const rank = (d) => (q && d.label.toLowerCase().startsWith(q) ? 0 : 10) + Math.max(0, ORDER.indexOf(d.category));
		const blocks = b.definitions.value.filter((d) => b.fitsIn(d, parent.value) && matches(`${d.label} ${d.description} ${d.name}`)).map((d, i) => ({
			d,
			i,
			r: rank(d)
		})).sort((x, y) => x.r - y.r || x.i - y.i).map(({ d }) => ({
			key: d.name,
			label: d.label,
			icon: d.icon,
			make: () => b.create(d)
		}));
		const patterns = b.patternChoices.value.filter((p) => matches(`${p.title} ${p.category}`) && b.fitsIn(b.defOfPattern(p), parent.value)).map((p) => ({
			key: `pattern:${p.key}`,
			label: p.title,
			icon: p.synced ? "star" : "bookmark",
			make: () => b.makePattern(p)
		}));
		return [...blocks, ...patterns].slice(0, SHOWN);
	});
	watch(() => quick.query, () => {
		active.value = 0;
	});
	function pick(choice) {
		if (!choice) return;
		const node = choice.make();
		const at = quick.id ? locate(b.list.value, quick.id) : null;
		quick.open = false;
		b.continueTyping(node);
		if (at && quick.mode === "replace") {
			b.commit(insert(remove(b.list.value, at.node.id), at.parent?.id ?? null, at.index, node));
			b.announce(__("{block} added.", { block: b.nameOf(node) }));
		} else if (at) b.insertAt(node, at.parent?.id ?? null, at.index + (quick.mode === "before" ? 0 : 1));
		else b.insertAt(node, null, b.list.value.length);
		b.select(node.id);
	}
	function quickKey(event) {
		const n = Math.max(1, choices.value.length);
		if (event.key === "ArrowDown") {
			event.preventDefault();
			active.value = (active.value + 1) % n;
		} else if (event.key === "ArrowUp") {
			event.preventDefault();
			active.value = (active.value - 1 + n) % n;
		} else if (event.key === "Enter") {
			event.preventDefault();
			pick(choices.value[active.value]);
		} else if (event.key === "Escape") {
			event.preventDefault();
			quick.open = false;
			if (quick.id) b.focusOn(quick.id);
		}
	}
	return {
		quick,
		quickChoices: choices,
		quickActive: active,
		openQuick,
		pickQuick: pick,
		quickKey
	};
}
// core/resources/js/ui/builder/useBuilderState.js
/**
* The visual editor's state, put together (see context.js for how the parts use it).
*/
/**
* @param {{ tree: import('vue').Ref<object[]>, props: object, emit: Function, root: import('vue').Ref<HTMLElement|null> }} env
*/
function useBuilderState(env) {
	const b = { props: env.props };
	const deps = {
		...env,
		api: useApi()
	};
	Object.assign(b, useCatalog(deps));
	Object.assign(b, useDocument(b, deps));
	Object.assign(b, useMulti(b, deps));
	Object.assign(b, useLibrary(b, deps));
	Object.assign(b, usePatterns(b, deps));
	Object.assign(b, useFind(b, deps));
	Object.assign(b, usePanels(b, deps));
	Object.assign(b, useCanvas$1(b, deps));
	Object.assign(b, useDrag(b, deps));
	Object.assign(b, useQuickInsert(b, deps));
	Object.assign(b, useKeyboard(b, deps));
	return provideBuilder(b);
}
// core/resources/js/ui/builder/BuilderDetails.vue
/** Counts and the outline of headings, from the bar. */
var _sfc_main$52 = {
	__name: "BuilderDetails",
	__ssrInlineRender: true,
	setup(__props) {
		const { menu, toggleMenu, closeMenu, stats, select } = useBuilder();
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-menu-wrap" }, _attrs))}><button type="button" class="${ssrRenderClass([{ "is-on": unref(menu) === "details" }, "k-bb"])}"${ssrRenderAttr("aria-expanded", String(unref(menu) === "details"))} aria-haspopup="dialog"${ssrRenderAttr("title", unref(__)("Details and outline"))}>`);
			_push(ssrRenderComponent(_sfc_main$14, { name: "info" }, null, _parent));
			_push(`<span class="k-sr-only">${ssrInterpolate(unref(__)("Details and outline"))}</span></button>`);
			if (unref(menu) === "details") {
				_push(`<div class="k-menu k-menu--wide" role="dialog"${ssrRenderAttr("aria-label", unref(__)("Details and outline"))}><dl class="k-stats"><div><dt>${ssrInterpolate(unref(__)("Words"))}</dt><dd>${ssrInterpolate(unref(stats).words)}</dd></div><div><dt>${ssrInterpolate(unref(__)("Characters"))}</dt><dd>${ssrInterpolate(unref(stats).chars)}</dd></div><div><dt>${ssrInterpolate(unref(__)("Reading time"))}</dt><dd>${ssrInterpolate(unref(__)("{n} min", { n: unref(stats).minutes }))}</dd></div><div><dt>${ssrInterpolate(unref(__)("Headings"))}</dt><dd>${ssrInterpolate(unref(stats).headings.length)}</dd></div><div><dt>${ssrInterpolate(unref(__)("Paragraphs"))}</dt><dd>${ssrInterpolate(unref(stats).paragraphs)}</dd></div><div><dt>${ssrInterpolate(unref(__)("Blocks"))}</dt><dd>${ssrInterpolate(unref(stats).blocks)}</dd></div></dl><p class="k-menu__head">${ssrInterpolate(unref(__)("Outline"))}</p>`);
				if (unref(stats).headings.length) {
					_push(`<ol class="k-outline"><!--[-->`);
					ssrRenderList(unref(stats).headings, (h) => {
						_push(`<li style="${ssrRenderStyle({ "--lvl": h.level })}"><button type="button" class="k-outline__item"><span class="k-outline__lvl">H${ssrInterpolate(h.level)}</span>${ssrInterpolate(h.text)}</button></li>`);
					});
					_push(`<!--]--></ol>`);
				} else _push(`<p class="k-menu__note">${ssrInterpolate(unref(__)("Headings you add show up here, in order."))}</p>`);
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$15 = _sfc_main$52.setup;
_sfc_main$52.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderDetails.vue");
	return _sfc_setup$15 ? _sfc_setup$15(props, ctx) : void 0;
};
// core/resources/js/ui/builder/BuilderBar.vue
/** The editor's one bar: the page's controls (slots) around the editor's own, and a place the block toolbar can dock. */
var _sfc_main$51 = {
	__name: "BuilderBar",
	__ssrInlineRender: true,
	setup(__props) {
		const b = useBuilder();
		const { props, left, right, toggleLeft, openFind, canUndo, canRedo, undo, redo, menu, toggleMenu, closeMenu, docked, focusMode, toggleFocus, showRight, issues, updating, ready, entry, entryOptions, DEVICES, device, K } = b;
		b.dockEl;
		const disabled = () => props.disabled;
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-builder__bar" }, _attrs))}>`);
			if (_ctx.$slots.start) {
				_push(`<div class="k-builder__start">`);
				ssrRenderSlot(_ctx.$slots, "start", {}, null, _push, _parent);
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`<div class="k-builder__group k-builder__group--editor" role="group"${ssrRenderAttr("aria-label", unref(__)("Editor"))}><button type="button" class="${ssrRenderClass([{ "is-on": unref(left) === "add" }, "k-bb k-bb--add"])}"${ssrRenderAttr("aria-pressed", String(unref(left) === "add"))}${ssrRenderAttr("title", unref(__)("Add a block"))}${ssrIncludeBooleanAttr(disabled()) ? " disabled" : ""}>`);
			_push(ssrRenderComponent(_sfc_main$14, { name: "plus" }, null, _parent));
			_push(`<span class="k-sr-only">${ssrInterpolate(unref(__)("Add a block"))}</span></button><button type="button" class="${ssrRenderClass([{ "is-on": unref(left) === "layers" }, "k-bb"])}"${ssrRenderAttr("aria-pressed", String(unref(left) === "layers"))}${ssrRenderAttr("title", `${unref(__)("Layers")} (${unref(K)("Alt+Shift+O")})`)}>`);
			_push(ssrRenderComponent(_sfc_main$14, { name: "list-dashes" }, null, _parent));
			_push(`<span class="k-sr-only">${ssrInterpolate(unref(__)("Layers"))}</span></button><button type="button" class="${ssrRenderClass([{ "is-on": unref(left) === "find" }, "k-bb"])}"${ssrRenderAttr("aria-pressed", String(unref(left) === "find"))}${ssrRenderAttr("title", `${unref(__)("Find and replace")} (${unref(K)("Mod+Shift+F")})`)}>`);
			_push(ssrRenderComponent(_sfc_main$14, { name: "magnifying-glass" }, null, _parent));
			_push(`<span class="k-sr-only">${ssrInterpolate(unref(__)("Find and replace"))}</span></button><button type="button" class="k-bb"${ssrIncludeBooleanAttr(!unref(canUndo) || disabled()) ? " disabled" : ""}${ssrRenderAttr("title", `${unref(__)("Undo")} (${unref(K)("Mod+Z")})`)}>`);
			_push(ssrRenderComponent(_sfc_main$14, { name: "arrow-counter-clockwise" }, null, _parent));
			_push(`<span class="k-sr-only">${ssrInterpolate(unref(__)("Undo"))}</span></button><button type="button" class="k-bb"${ssrIncludeBooleanAttr(!unref(canRedo) || disabled()) ? " disabled" : ""}${ssrRenderAttr("title", `${unref(__)("Redo")} (${unref(K)("Mod+Shift+Z")})`)}>`);
			_push(ssrRenderComponent(_sfc_main$14, { name: "arrow-clockwise" }, null, _parent));
			_push(`<span class="k-sr-only">${ssrInterpolate(unref(__)("Redo"))}</span></button>`);
			_push(ssrRenderComponent(_sfc_main$52, null, null, _parent));
			_push(`</div><!-- the block toolbar docks here when it is fixed to the top --><div class="k-builder__dock"></div><div class="k-builder__group"><span class="k-builder__status" aria-hidden="true">`);
			if (unref(updating) || !unref(ready)) _push(ssrRenderComponent(_sfc_main$27, { size: "sm" }, null, _parent));
			else _push(`<!---->`);
			_push(`${ssrInterpolate(!unref(ready) ? unref(__)("Loading…") : "")}</span>`);
			if (unref(props).kind === "template") {
				_push(`<div class="k-builder__entry"><label class="k-sr-only" for="k-builder-entry">${ssrInterpolate(unref(__)("Preview with"))}</label>`);
				if (unref(entryOptions).length) _push(ssrRenderComponent(_sfc_main$25, {
					id: "k-builder-entry",
					modelValue: unref(entry),
					"onUpdate:modelValue": ($event) => isRef(entry) ? entry.value = $event : null,
					options: unref(entryOptions),
					title: unref(__)("Preview with")
				}, null, _parent));
				else _push(`<!---->`);
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`<div class="k-seg" role="group"${ssrRenderAttr("aria-label", unref(__)("Preview width"))}><!--[-->`);
			ssrRenderList(unref(DEVICES), ([id, icon, label]) => {
				_push(`<button type="button" class="${ssrRenderClass([{ "is-on": unref(device) === id }, "k-seg__btn"])}"${ssrRenderAttr("aria-pressed", String(unref(device) === id))}${ssrRenderAttr("title", label)}>`);
				_push(ssrRenderComponent(_sfc_main$14, { name: icon }, null, _parent));
				_push(`<span class="k-sr-only">${ssrInterpolate(label)}</span></button>`);
			});
			_push(`<!--]--></div></div>`);
			if (_ctx.$slots.end) {
				_push(`<div class="k-builder__end">`);
				ssrRenderSlot(_ctx.$slots, "end", {}, null, _push, _parent);
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`<div class="k-builder__group"><button type="button" class="${ssrRenderClass([{ "is-on": unref(right) }, "k-bb"])}"${ssrRenderAttr("aria-pressed", String(unref(right)))}${ssrRenderAttr("title", `${unref(__)("Settings")} (${unref(K)("Mod+Shift+,")})`)}>`);
			_push(ssrRenderComponent(_sfc_main$14, {
				name: "sidebar-simple",
				class: "k-flip"
			}, null, _parent));
			_push(`<span class="k-sr-only">${ssrInterpolate(unref(__)("Settings"))}</span></button><div class="k-menu-wrap"><button type="button" class="${ssrRenderClass([{ "is-on": unref(menu) === "more" }, "k-bb"])}"${ssrRenderAttr("aria-expanded", String(unref(menu) === "more"))} aria-haspopup="menu"${ssrRenderAttr("title", unref(__)("Options"))}>`);
			_push(ssrRenderComponent(_sfc_main$14, { name: "dots-three-vertical" }, null, _parent));
			_push(`<span class="k-sr-only">${ssrInterpolate(unref(__)("Options"))}</span></button>`);
			if (unref(menu) === "more") {
				_push(`<div class="k-menu k-menu--end" role="menu"><p class="k-menu__head">${ssrInterpolate(unref(__)("View"))}</p><button type="button" class="k-menu__item" role="menuitemcheckbox"${ssrRenderAttr("aria-checked", String(unref(docked)))}>`);
				_push(ssrRenderComponent(_sfc_main$14, {
					name: unref(docked) ? "check" : "blank",
					class: "k-menu__check"
				}, null, _parent));
				_push(`${ssrInterpolate(unref(__)("Block toolbar at the top"))}</button><button type="button" class="k-menu__item" role="menuitemcheckbox"${ssrRenderAttr("aria-checked", String(unref(focusMode)))}>`);
				_push(ssrRenderComponent(_sfc_main$14, {
					name: unref(focusMode) ? "check" : "blank",
					class: "k-menu__check"
				}, null, _parent));
				_push(`${ssrInterpolate(unref(__)("Hide both panels"))}<kbd>${ssrInterpolate(unref(K)("Mod+Shift+\\"))}</kbd></button><span class="k-menu__sep" role="separator"></span><button type="button" class="k-menu__item" role="menuitem">`);
				_push(ssrRenderComponent(_sfc_main$14, { name: "check-circle" }, null, _parent));
				_push(`${ssrInterpolate(unref(__)("Accessibility checks"))}`);
				if (unref(issues).length) _push(`<span class="k-menu__count">${ssrInterpolate(unref(issues).length)}</span>`);
				else _push(`<!---->`);
				_push(`</button><button type="button" class="k-menu__item" role="menuitem">`);
				_push(ssrRenderComponent(_sfc_main$14, { name: "keyboard" }, null, _parent));
				_push(`${ssrInterpolate(unref(__)("Keyboard shortcuts"))}<kbd>?</kbd></button></div>`);
			} else _push(`<!---->`);
			_push(`</div></div></div>`);
		};
	}
};
var _sfc_setup$14 = _sfc_main$51.setup;
_sfc_main$51.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderBar.vue");
	return _sfc_setup$14 ? _sfc_setup$14(props, ctx) : void 0;
};
// core/resources/js/ui/builder/BuilderQuickInsert.vue
/** Search for a block to add; arrows choose, Enter adds, Escape goes back to the text. */
var _sfc_main$50 = {
	__name: "BuilderQuickInsert",
	__ssrInlineRender: true,
	setup(__props) {
		const { quick, quickChoices, quickActive, pickQuick, quickKey } = useBuilder();
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				class: "k-builder__quick",
				style: unref(quick).style,
				role: "dialog",
				"aria-label": unref(__)("Add a block")
			}, _attrs))}><label class="k-sr-only" for="k-builder-quick">${ssrInterpolate(unref(__)("Search blocks"))}</label><input id="k-builder-quick"${ssrRenderAttr("value", unref(quick).query)} class="k-input" type="search" autocomplete="off" role="combobox" aria-controls="k-builder-quick-list" aria-expanded="true"${ssrRenderAttr("aria-activedescendant", unref(quickChoices).length ? `k-quick-${unref(quickActive)}` : void 0)}><ul id="k-builder-quick-list" role="listbox" class="k-builder__quick-list"><!--[-->`);
			ssrRenderList(unref(quickChoices), (c, i) => {
				_push(`<li${ssrRenderAttr("id", `k-quick-${i}`)} role="option"${ssrRenderAttr("aria-selected", String(i === unref(quickActive)))} class="${ssrRenderClass({ "is-active": i === unref(quickActive) })}">`);
				_push(ssrRenderComponent(_sfc_main$14, { name: c.icon }, null, _parent));
				_push(`<span>${ssrInterpolate(c.label)}</span></li>`);
			});
			_push(`<!--]-->`);
			if (!unref(quickChoices).length) _push(`<li class="k-builder__quick-none">${ssrInterpolate(unref(__)("No block matches “{q}”.", { q: unref(quick).query }))}</li>`);
			else _push(`<!---->`);
			_push(`</ul></div>`);
		};
	}
};
var _sfc_setup$13 = _sfc_main$50.setup;
_sfc_main$50.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderQuickInsert.vue");
	return _sfc_setup$13 ? _sfc_setup$13(props, ctx) : void 0;
};
// core/resources/js/ui/builder/BuilderToolbar.vue
/**
* The toolbar over the selected block (or docked in the bar): its kind and what
* it can turn into, moving, heading level, alignment, text formats, and more.
* Its buttons keep the caret in the page.
*/
var _sfc_main$49 = {
	__name: "BuilderToolbar",
	__ssrInlineRender: true,
	setup(__props) {
		const { props, selected, selectedAt, selectedDef, select, nameOf, iconOf, toolbarDocked: docked, dockEl, toolbarStyle, toolbarSize, menu, toggleMenu, closeMenu, turnChoices, turnInto, shift, editLocked, setProp, richSelected, typingRich, formats, format, linkOpen, linkUrl, applyLink, openQuick, duplicateNode, copyNode, paste, copyStyles, pasteStyles, canGroup, canUngroup, groupNode, ungroupNode, removeNode, showRight, dragNode, endDrag, locked, K, multi, pickedIds, canGroupPicked, groupPicked, duplicatePicked, removePicked } = useBuilder();
		const HEADING_ICONS = [
			"text-h-one",
			"text-h-two",
			"text-h-three",
			"text-h-four",
			"text-h-five",
			"text-h-six"
		];
		const ALIGNS = [
			[
				"start",
				"text-align-left",
				__("Align left")
			],
			[
				"center",
				"text-align-center",
				__("Align centre")
			],
			[
				"end",
				"text-align-right",
				__("Align right")
			]
		];
		const MORE_FORMATS = [
			[
				"strike",
				"text-strikethrough",
				__("Strikethrough"),
				"Alt+Shift+D"
			],
			[
				"code",
				"code",
				__("Inline code"),
				"Alt+Shift+X"
			],
			[
				"mark",
				"highlighter",
				__("Highlight"),
				""
			],
			[
				"sub",
				"text-subscript",
				__("Subscript"),
				""
			],
			[
				"sup",
				"text-superscript",
				__("Superscript"),
				""
			]
		];
		const node = computed(() => selectedAt.value?.node);
		const shown = computed(() => !!node.value && !!selectedDef.value && !props.disabled && (docked.value || !!toolbarStyle.value));
		const isHeading = computed(() => node.value?.type === "core/heading");
		const level = computed(() => String(node.value?.props?.level ?? "2"));
		const hasAlign = computed(() => selectedDef.value?.fields.some((f) => f.name === "align" && f.type === "select" && f.options?.choices?.some((c) => c.value === "center")) && [
			"core/text",
			"core/heading",
			"core/quote"
		].includes(node.value?.type));
		const align = computed(() => ALIGNS.find((a) => a[0] === (node.value?.props?.align ?? "start")) ?? ALIGNS[0]);
		const movable = computed(() => !locked(node.value, "move"));
		/** Toolbar buttons keep the caret in the page; the link field and the grip still take the pointer. */
		const bar = ref(null);
		let observer = null;
		watch(bar, (el) => {
			observer?.disconnect();
			if (!el || typeof ResizeObserver === "undefined") return;
			observer = new ResizeObserver(() => {
				if (docked.value) return;
				const next = {
					width: el.offsetWidth,
					height: el.offsetHeight
				};
				const was = toolbarSize.value;
				if (next.width && (next.width !== was.width || next.height !== was.height)) toolbarSize.value = next;
			});
			observer.observe(el);
		});
		onBeforeUnmount(() => observer?.disconnect());
		return (_ctx, _push, _parent, _attrs) => {
			ssrRenderTeleport(_push, (_push) => {
				if (shown.value) {
					_push(`<div class="${ssrRenderClass([{ "is-docked": unref(docked) }, "k-builder__toolbar"])}" style="${ssrRenderStyle(unref(docked) ? null : unref(toolbarStyle))}" role="toolbar"${ssrRenderAttr("aria-label", unref(multi) ? unref(__)("{n} blocks", { n: unref(pickedIds).length }) : unref(__)("{block} block", { block: unref(nameOf)(node.value) }))}>`);
					if (unref(multi)) {
						_push(`<!--[--><span class="k-tb__count">${ssrInterpolate(unref(__)("{n} blocks", { n: unref(pickedIds).length }))}</span><span class="k-tb__sep" aria-hidden="true"></span>`);
						if (unref(canGroupPicked)) {
							_push(`<button type="button" class="k-tb"${ssrRenderAttr("title", unref(__)("Put in a group"))}${ssrRenderAttr("aria-label", unref(__)("Put in a group"))}>`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "selection-plus" }, null, _parent));
							_push(`</button>`);
						} else _push(`<!---->`);
						_push(`<button type="button" class="k-tb"${ssrRenderAttr("title", `${unref(__)("Duplicate")} (${unref(K)("Mod+Shift+D")})`)}${ssrRenderAttr("aria-label", unref(__)("Duplicate"))}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "copy" }, null, _parent));
						_push(`</button><button type="button" class="k-tb"${ssrRenderAttr("title", `${unref(__)("Paste styles")} (${unref(K)("Mod+Alt+V")})`)}${ssrRenderAttr("aria-label", unref(__)("Paste styles"))}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "paint-roller" }, null, _parent));
						_push(`</button><button type="button" class="k-tb"${ssrRenderAttr("title", `${unref(__)("Remove")} (${unref(K)("Alt+Shift+Z")})`)}${ssrRenderAttr("aria-label", unref(__)("Remove"))}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "trash" }, null, _parent));
						_push(`</button><!--]-->`);
					} else {
						_push(`<!--[-->`);
						if (unref(selectedAt).parent) {
							_push(`<button type="button" class="k-tb"${ssrRenderAttr("title", unref(__)("Select {block}", { block: unref(nameOf)(unref(selectedAt).parent) }))}>`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "arrow-elbow-left-up" }, null, _parent));
							_push(`</button>`);
						} else _push(`<!---->`);
						if (movable.value) {
							_push(`<button type="button" class="k-tb k-tb--grip" draggable="true"${ssrRenderAttr("title", unref(__)("Drag to move. Drop at a block’s side to put them side by side."))}${ssrRenderAttr("aria-label", unref(__)("Drag to move"))}>`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "dots-six-vertical" }, null, _parent));
							_push(`</button>`);
						} else _push(`<!---->`);
						_push(`<div class="k-menu-wrap"><button type="button" class="k-tb k-tb--type"${ssrIncludeBooleanAttr(!unref(turnChoices).length) ? " disabled" : ""} aria-haspopup="menu"${ssrRenderAttr("aria-expanded", String(unref(menu) === "turn"))}${ssrRenderAttr("title", unref(turnChoices).length ? unref(__)("Turn into another block") : unref(selectedDef).label)}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: unref(iconOf)(node.value) }, null, _parent));
						_push(`<span class="k-tb__label">${ssrInterpolate(unref(selectedDef).label)}</span>`);
						if (unref(turnChoices).length) _push(ssrRenderComponent(_sfc_main$14, {
							name: "caret-down",
							class: "k-tb__caret"
						}, null, _parent));
						else _push(`<!---->`);
						_push(`</button>`);
						if (unref(menu) === "turn") {
							_push(`<div class="k-menu" role="menu"><p class="k-menu__head">${ssrInterpolate(unref(__)("Turn into"))}</p><!--[-->`);
							ssrRenderList(unref(turnChoices), (c) => {
								_push(`<button type="button" class="k-menu__item" role="menuitem">`);
								_push(ssrRenderComponent(_sfc_main$14, { name: c.icon }, null, _parent));
								_push(`${ssrInterpolate(c.label)}</button>`);
							});
							_push(`<!--]--></div>`);
						} else _push(`<!---->`);
						_push(`</div>`);
						if (movable.value) {
							_push(`<span class="k-tb__pair"><button type="button" class="k-tb k-tb--move"${ssrIncludeBooleanAttr(unref(selectedAt).index === 0) ? " disabled" : ""}${ssrRenderAttr("title", unref(__)("Move up (Alt+Up in layers)"))}${ssrRenderAttr("aria-label", unref(__)("Move up"))}>`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "caret-up" }, null, _parent));
							_push(`</button><button type="button" class="k-tb k-tb--move"${ssrIncludeBooleanAttr(unref(selectedAt).index === unref(selectedAt).list.length - 1) ? " disabled" : ""}${ssrRenderAttr("title", unref(__)("Move down (Alt+Down in layers)"))}${ssrRenderAttr("aria-label", unref(__)("Move down"))}>`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "caret-down" }, null, _parent));
							_push(`</button></span>`);
						} else _push(`<!---->`);
						if (isHeading.value || hasAlign.value) {
							_push(`<!--[--><span class="k-tb__sep" aria-hidden="true"></span>`);
							if (isHeading.value) {
								_push(`<div class="k-menu-wrap"><button type="button" class="k-tb k-tb--text"${ssrIncludeBooleanAttr(unref(editLocked)) ? " disabled" : ""} aria-haspopup="menu"${ssrRenderAttr("aria-expanded", String(unref(menu) === "level"))}${ssrRenderAttr("title", unref(__)("Heading level"))}> H${ssrInterpolate(level.value)}`);
								_push(ssrRenderComponent(_sfc_main$14, {
									name: "caret-down",
									class: "k-tb__caret"
								}, null, _parent));
								_push(`</button>`);
								if (unref(menu) === "level") {
									_push(`<div class="k-menu" role="menu"><!--[-->`);
									ssrRenderList(HEADING_ICONS, (icon, i) => {
										_push(`<button type="button" class="k-menu__item" role="menuitemradio"${ssrRenderAttr("aria-checked", String(level.value === String(i + 1)))}>`);
										_push(ssrRenderComponent(_sfc_main$14, { name: icon }, null, _parent));
										_push(`${ssrInterpolate(unref(__)("Heading {n}", { n: i + 1 }))}`);
										if (i === 0) _push(`<span class="k-menu__hint">${ssrInterpolate(unref(__)("page title"))}</span>`);
										else _push(`<!---->`);
										_push(`</button>`);
									});
									_push(`<!--]--></div>`);
								} else _push(`<!---->`);
								_push(`</div>`);
							} else _push(`<!---->`);
							if (hasAlign.value) {
								_push(`<div class="k-menu-wrap"><button type="button" class="k-tb"${ssrIncludeBooleanAttr(unref(editLocked)) ? " disabled" : ""} aria-haspopup="menu"${ssrRenderAttr("aria-expanded", String(unref(menu) === "align"))}${ssrRenderAttr("title", unref(__)("Alignment"))}>`);
								_push(ssrRenderComponent(_sfc_main$14, { name: align.value[1] }, null, _parent));
								_push(`</button>`);
								if (unref(menu) === "align") {
									_push(`<div class="k-menu" role="menu"><!--[-->`);
									ssrRenderList(ALIGNS, ([v, icon, label]) => {
										_push(`<button type="button" class="k-menu__item" role="menuitemradio"${ssrRenderAttr("aria-checked", String(align.value[0] === v))}>`);
										_push(ssrRenderComponent(_sfc_main$14, { name: icon }, null, _parent));
										_push(`${ssrInterpolate(label)}</button>`);
									});
									_push(`<!--]--></div>`);
								} else _push(`<!---->`);
								_push(`</div>`);
							} else _push(`<!---->`);
							_push(`<!--]-->`);
						} else _push(`<!---->`);
						if (unref(richSelected)) {
							_push(`<!--[--><span class="k-tb__sep" aria-hidden="true"></span><!-- formatting works on text being typed: until then the buttons say why they are off --><span id="k-tb-format-why" class="k-sr-only">${ssrInterpolate(unref(__)("Click into the text to format it."))}</span><button type="button" class="${ssrRenderClass([{ "is-on": unref(formats).bold }, "k-tb"])}"${ssrRenderAttr("aria-pressed", String(!!unref(formats).bold))}${ssrIncludeBooleanAttr(!unref(typingRich)) ? " disabled" : ""}${ssrRenderAttr("aria-describedby", unref(typingRich) ? void 0 : "k-tb-format-why")}${ssrRenderAttr("title", `${unref(__)("Bold")} (${unref(K)("Mod+B")})`)}${ssrRenderAttr("aria-label", unref(__)("Bold"))}>`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "text-b" }, null, _parent));
							_push(`</button><button type="button" class="${ssrRenderClass([{ "is-on": unref(formats).italic }, "k-tb"])}"${ssrRenderAttr("aria-pressed", String(!!unref(formats).italic))}${ssrIncludeBooleanAttr(!unref(typingRich)) ? " disabled" : ""}${ssrRenderAttr("aria-describedby", unref(typingRich) ? void 0 : "k-tb-format-why")}${ssrRenderAttr("title", `${unref(__)("Italic")} (${unref(K)("Mod+I")})`)}${ssrRenderAttr("aria-label", unref(__)("Italic"))}>`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "text-italic" }, null, _parent));
							_push(`</button><button type="button" class="${ssrRenderClass([{ "is-on": unref(formats).link || unref(linkOpen) }, "k-tb"])}"${ssrIncludeBooleanAttr(!unref(typingRich)) ? " disabled" : ""}${ssrRenderAttr("aria-describedby", unref(typingRich) ? void 0 : "k-tb-format-why")}${ssrRenderAttr("title", unref(formats).link ? unref(__)("Remove link") : `${unref(__)("Link")} (${unref(K)("Mod+K")})`)}${ssrRenderAttr("aria-label", unref(formats).link ? unref(__)("Remove link") : unref(__)("Link"))}${ssrRenderAttr("aria-expanded", String(unref(linkOpen)))}>`);
							_push(ssrRenderComponent(_sfc_main$14, { name: unref(formats).link ? "link-break" : "link" }, null, _parent));
							_push(`</button><div class="k-menu-wrap"><button type="button" class="k-tb k-tb--narrow"${ssrIncludeBooleanAttr(!unref(typingRich)) ? " disabled" : ""}${ssrRenderAttr("aria-describedby", unref(typingRich) ? void 0 : "k-tb-format-why")} aria-haspopup="menu"${ssrRenderAttr("aria-expanded", String(unref(menu) === "format"))}${ssrRenderAttr("title", unref(__)("More formatting"))}>`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "caret-down" }, null, _parent));
							_push(`</button>`);
							if (unref(menu) === "format") {
								_push(`<div class="k-menu" role="menu"><!--[-->`);
								ssrRenderList(MORE_FORMATS, ([cmd, icon, label, keys]) => {
									_push(`<button type="button" role="menuitemcheckbox"${ssrRenderAttr("aria-checked", String(!!unref(formats)[cmd]))} class="${ssrRenderClass([{ "is-on": unref(formats)[cmd] }, "k-menu__item"])}">`);
									_push(ssrRenderComponent(_sfc_main$14, { name: icon }, null, _parent));
									_push(`${ssrInterpolate(label)}`);
									if (keys) _push(`<kbd>${ssrInterpolate(unref(K)(keys))}</kbd>`);
									else _push(`<!---->`);
									_push(`</button>`);
								});
								_push(`<!--]--><span class="k-menu__sep" role="separator"></span><button type="button" class="k-menu__item" role="menuitem">`);
								_push(ssrRenderComponent(_sfc_main$14, { name: "eraser" }, null, _parent));
								_push(`${ssrInterpolate(unref(__)("Clear formatting"))}</button></div>`);
							} else _push(`<!---->`);
							_push(`</div><!--]-->`);
						} else _push(`<!---->`);
						_push(`<span class="k-tb__sep" aria-hidden="true"></span><button type="button" class="k-tb"${ssrRenderAttr("title", `${unref(__)("Add a block after")} (${unref(K)("Mod+Alt+Y")})`)}${ssrRenderAttr("aria-label", unref(__)("Add a block after"))}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "plus" }, null, _parent));
						_push(`</button><div class="k-menu-wrap"><button type="button" class="k-tb" aria-haspopup="menu"${ssrRenderAttr("aria-expanded", String(unref(menu) === "options"))}${ssrRenderAttr("title", unref(__)("More"))}${ssrRenderAttr("aria-label", unref(__)("More"))}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "dots-three-vertical" }, null, _parent));
						_push(`</button>`);
						if (unref(menu) === "options") {
							_push(`<div class="k-menu k-menu--end" role="menu"><button type="button" class="k-menu__item" role="menuitem">`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "arrow-line-up" }, null, _parent));
							_push(`${ssrInterpolate(unref(__)("Add before"))}<kbd>${ssrInterpolate(unref(K)("Mod+Alt+T"))}</kbd></button><button type="button" class="k-menu__item" role="menuitem">`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "arrow-line-down" }, null, _parent));
							_push(`${ssrInterpolate(unref(__)("Add after"))}<kbd>${ssrInterpolate(unref(K)("Mod+Alt+Y"))}</kbd></button><span class="k-menu__sep" role="separator"></span><button type="button" class="k-menu__item" role="menuitem">`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "copy" }, null, _parent));
							_push(`${ssrInterpolate(unref(__)("Duplicate"))}<kbd>${ssrInterpolate(unref(K)("Mod+Shift+D"))}</kbd></button><button type="button" class="k-menu__item" role="menuitem">`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "clipboard" }, null, _parent));
							_push(`${ssrInterpolate(unref(__)("Copy"))}</button><button type="button" class="k-menu__item" role="menuitem">`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "clipboard-text" }, null, _parent));
							_push(`${ssrInterpolate(unref(__)("Paste after"))}</button><button type="button" class="k-menu__item" role="menuitem">`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "paint-brush" }, null, _parent));
							_push(`${ssrInterpolate(unref(__)("Copy styles"))}<kbd>${ssrInterpolate(unref(K)("Mod+Alt+C"))}</kbd></button><button type="button" class="k-menu__item" role="menuitem">`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "paint-roller" }, null, _parent));
							_push(`${ssrInterpolate(unref(__)("Paste styles"))}<kbd>${ssrInterpolate(unref(K)("Mod+Alt+V"))}</kbd></button>`);
							if (unref(canGroup)) {
								_push(`<button type="button" class="k-menu__item" role="menuitem">`);
								_push(ssrRenderComponent(_sfc_main$14, { name: "selection-plus" }, null, _parent));
								_push(`${ssrInterpolate(unref(__)("Put in a group"))}</button>`);
							} else _push(`<!---->`);
							if (unref(canUngroup)) {
								_push(`<button type="button" class="k-menu__item" role="menuitem">`);
								_push(ssrRenderComponent(_sfc_main$14, { name: "selection-slash" }, null, _parent));
								_push(`${ssrInterpolate(unref(__)("Ungroup"))}</button>`);
							} else _push(`<!---->`);
							_push(`<button type="button" class="k-menu__item" role="menuitem">`);
							_push(ssrRenderComponent(_sfc_main$14, { name: "sliders-horizontal" }, null, _parent));
							_push(`${ssrInterpolate(unref(__)("Block settings"))}</button>`);
							if (!unref(locked)(node.value, "remove")) {
								_push(`<!--[--><span class="k-menu__sep" role="separator"></span><button type="button" class="k-menu__item k-menu__item--danger" role="menuitem">`);
								_push(ssrRenderComponent(_sfc_main$14, { name: "trash" }, null, _parent));
								_push(`${ssrInterpolate(unref(__)("Remove"))}<kbd>${ssrInterpolate(unref(K)("Alt+Shift+Z"))}</kbd></button><!--]-->`);
							} else _push(`<!---->`);
							_push(`</div>`);
						} else _push(`<!---->`);
						_push(`</div>`);
						if (unref(linkOpen)) {
							_push(`<form class="k-builder__link"><label class="k-builder__link-label" for="k-builder-link">${ssrInterpolate(unref(__)("Link address"))}</label><input id="k-builder-link"${ssrRenderAttr("value", unref(linkUrl))} class="k-input" type="text" inputmode="url" autocomplete="off" aria-describedby="k-builder-link-help">`);
							_push(ssrRenderComponent(_sfc_main$6, {
								size: "sm",
								type: "submit",
								variant: "primary"
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Link"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Link")), 1)];
								}),
								_: 1
							}, _parent));
							_push(`<p id="k-builder-link-help" class="k-builder__link-help">${ssrInterpolate(unref(__)("A web address, or a page of this site such as /about."))}</p></form>`);
						} else _push(`<!---->`);
						_push(`<!--]-->`);
					}
					_push(`</div>`);
				} else _push(`<!---->`);
			}, unref(dockEl) || "body", !unref(docked) || !unref(dockEl), _parent);
		};
	}
};
var _sfc_setup$12 = _sfc_main$49.setup;
_sfc_main$49.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderToolbar.vue");
	return _sfc_setup$12 ? _sfc_setup$12(props, ctx) : void 0;
};
// core/resources/js/ui/builder/BuilderCanvas.vue
/** The real page in a frame, at the chosen width, with the block toolbar and the quick inserter over it. */
var _sfc_main$48 = {
	__name: "BuilderCanvas",
	__ssrInlineRender: true,
	setup(__props) {
		const { props, canvasEl, frame, device, DEVICES, width, canvasUrl, ready, list, usablePatterns, startWriting, openQuick, quick, openPatterns } = useBuilder();
		const hasLayouts = computed(() => usablePatterns.value.some((p) => p.layout));
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<section${ssrRenderAttrs(mergeProps({
				ref_key: "canvasEl",
				ref: canvasEl,
				class: ["k-builder__canvas", `is-${unref(device)}`],
				"aria-label": unref(__)("Preview")
			}, _attrs))}>`);
			if (unref(width)) _push(`<p class="k-builder__width">${ssrInterpolate(unref(__)("{device}, {n} px wide", {
				device: unref(DEVICES).find((d) => d[0] === unref(device))?.[2] ?? "",
				n: unref(width)
			}))}</p>`);
			else _push(`<!---->`);
			_push(`<div class="k-builder__frame" style="${ssrRenderStyle({ width: unref(width) ? `${unref(width)}px` : "100%" })}"><iframe${ssrRenderAttr("src", unref(canvasUrl))}${ssrRenderAttr("title", unref(props).kind === "entry" ? unref(__)("The page as visitors see it, with text you can edit") : unref(__)("Preview of {name}", { name: unref(props).docKey }))} class="k-builder__iframe"></iframe></div>`);
			if (unref(props).kind === "entry" && unref(ready) && !unref(list).length && !unref(props).disabled) {
				_push(`<div class="k-builder__start-here"><p>${ssrInterpolate(unref(__)("An empty page. Start with text, or pick what comes first."))}</p><div>`);
				_push(ssrRenderComponent(_sfc_main$6, {
					variant: "primary",
					icon: "text-align-left",
					onClick: unref(startWriting)
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Start writing"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Start writing")), 1)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(_sfc_main$6, {
					icon: "plus",
					onClick: ($event) => unref(openQuick)({
						mode: "after",
						id: null
					})
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Add a block"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Add a block")), 1)];
					}),
					_: 1
				}, _parent));
				if (hasLayouts.value) _push(ssrRenderComponent(_sfc_main$6, {
					variant: "ghost",
					icon: "layout",
					onClick: ($event) => unref(openPatterns)("layouts")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Start from a layout"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Start from a layout")), 1)];
					}),
					_: 1
				}, _parent));
				else if (unref(usablePatterns).length) _push(ssrRenderComponent(_sfc_main$6, {
					variant: "ghost",
					icon: "bookmark",
					onClick: ($event) => unref(openPatterns)()
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Start from a pattern"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Start from a pattern")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`</div></div>`);
			} else _push(`<!---->`);
			_push(ssrRenderComponent(_sfc_main$49, null, null, _parent));
			if (unref(quick).open) _push(ssrRenderComponent(_sfc_main$50, null, null, _parent));
			else _push(`<!---->`);
			_push(`</section>`);
		};
	}
};
var _sfc_setup$11 = _sfc_main$48.setup;
_sfc_main$48.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderCanvas.vue");
	return _sfc_setup$11 ? _sfc_setup$11(props, ctx) : void 0;
};
// core/resources/js/ui/builder/DesignColor.vue
/**
* One colour in the styles panel: the theme's palette as swatches, or a colour
* of one's own (picker or hex). Empty means the block's own default.
* The swatches show the colours as the page has them (resolve), since a preset
* may name a theme token the admin doesn't have.
*/
var _sfc_main$47 = {
	__name: "DesignColor",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		label: {
			type: String,
			required: true
		},
		presets: {
			type: Object,
			default: () => ({})
		},
		resolve: {
			type: Function,
			default: (key) => null
		},
		inherited: {
			type: String,
			default: null
		},
		inheritedFrom: {
			type: String,
			default: ""
		},
		disabled: Boolean
	}, {
		"modelValue": {
			type: String,
			default: null
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		const props = __props;
		const uid = `k-dc-${Math.random().toString(36).slice(2, 8)}`;
		const isPreset = (v) => v !== null && Object.hasOwn(props.presets, v);
		const custom = computed(() => model.value !== null && !isPreset(model.value) ? model.value : null);
		const hex = ref(custom.value ?? "");
		const invalid = ref(false);
		watch(custom, (v) => {
			hex.value = v ?? "";
			invalid.value = false;
		});
		const swatch = (key) => props.resolve(key) ?? props.presets[key]?.value ?? "transparent";
		const shownName = computed(() => {
			const v = model.value ?? props.inherited;
			if (v === null) return "";
			return isPreset(v) ? props.presets[v].label : v;
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: ["k-dc", { "is-set": model.value !== null }] }, _attrs))}><div class="k-ds__head"><span${ssrRenderAttr("id", `${uid}-l`)} class="k-ds__label">${ssrInterpolate(__props.label)}</span>`);
			if (shownName.value) _push(`<span class="k-ds__from">${ssrInterpolate(model.value === null ? unref(__)("{value}, as on {screen}", {
				value: shownName.value,
				screen: __props.inheritedFrom
			}) : shownName.value)}</span>`);
			else _push(`<!---->`);
			if (model.value !== null) _push(`<button type="button" class="k-ds__reset"${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}>${ssrInterpolate(__props.inheritedFrom && __props.inherited !== null ? unref(__)("Use {screen}'s", { screen: __props.inheritedFrom }) : unref(__)("Clear"))}</button>`);
			else _push(`<!---->`);
			_push(`</div><div class="k-dc__swatches" role="group"${ssrRenderAttr("aria-labelledby", `${uid}-l`)}><!--[-->`);
			ssrRenderList(__props.presets, (p, key) => {
				_push(`<button type="button" class="${ssrRenderClass([{ "is-inherited": model.value === null && __props.inherited === key }, "k-dc__swatch"])}" style="${ssrRenderStyle({ "--swatch": swatch(key) })}"${ssrRenderAttr("aria-pressed", String(model.value === key))}${ssrRenderAttr("title", p.label)}${ssrRenderAttr("aria-label", p.label)}${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}></button>`);
			});
			_push(`<!--]--><label class="${ssrRenderClass([{ "is-on": custom.value }, "k-dc__own"])}"${ssrRenderAttr("title", unref(__)("A colour of your own"))}><input type="color" class="k-dc__picker"${ssrRenderAttr("value", unref(fullHex)(custom.value) || "#000000")}${ssrRenderAttr("aria-label", unref(__)("{label}: a colour of your own", { label: __props.label }))}${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}><span class="k-dc__own-chip" style="${ssrRenderStyle(custom.value ? { "--swatch": custom.value } : null)}" aria-hidden="true"></span></label><input${ssrRenderAttr("value", hex.value)} class="k-input k-dc__hex"${ssrRenderAttr("aria-label", unref(__)("{label}, hex", { label: __props.label }))}${ssrRenderAttr("aria-invalid", invalid.value ? "true" : void 0)} spellcheck="false" maxlength="7"${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}></div>`);
			if (invalid.value) _push(`<p class="k-ds__error" role="alert">${ssrInterpolate(unref(__)("A colour such as #1e3a8a."))}</p>`);
			else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$10 = _sfc_main$47.setup;
_sfc_main$47.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/DesignColor.vue");
	return _sfc_setup$10 ? _sfc_setup$10(props, ctx) : void 0;
};
// core/resources/js/ui/builder/DesignScale.vue
/**
* One size in the styles panel: the theme's steps (S, M, L…) as buttons, or a
* length typed in. Empty means the block's own default. When a wider screen
* size set a value, it is shown as what applies here until this one is set.
*/
var _sfc_main$46 = {
	__name: "DesignScale",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		label: {
			type: String,
			required: true
		},
		presets: {
			type: Object,
			default: () => ({})
		},
		inherited: {
			type: String,
			default: null
		},
		inheritedFrom: {
			type: String,
			default: ""
		},
		units: {
			type: Array,
			default: () => [
				"px",
				"rem",
				"em",
				"%",
				"vw"
			]
		},
		disabled: Boolean
	}, {
		"modelValue": {
			type: String,
			default: null
		},
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		const props = __props;
		const uid = `k-ds-${Math.random().toString(36).slice(2, 8)}`;
		const shown = computed(() => model.value ?? props.inherited);
		const isPreset = (v) => v !== null && Object.hasOwn(props.presets, v);
		const typed = ref(isPreset(model.value) ? "" : model.value ?? "");
		const invalid = ref(false);
		watch(model, (v) => {
			if (!isPreset(v)) typed.value = v ?? "";
			invalid.value = false;
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: ["k-ds", { "is-set": model.value !== null }] }, _attrs))}><div class="k-ds__head"><span${ssrRenderAttr("id", `${uid}-l`)} class="k-ds__label">${ssrInterpolate(__props.label)}</span>`);
			if (model.value === null && __props.inherited !== null) _push(`<span class="k-ds__from">${ssrInterpolate(isPreset(__props.inherited) ? unref(__)("as on {screen}", { screen: __props.inheritedFrom }) : unref(__)("{value}, as on {screen}", {
				value: __props.inherited,
				screen: __props.inheritedFrom
			}))}</span>`);
			else _push(`<!---->`);
			if (model.value !== null) _push(`<button type="button" class="k-ds__reset"${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}>${ssrInterpolate(__props.inheritedFrom && __props.inherited !== null ? unref(__)("Use {screen}'s", { screen: __props.inheritedFrom }) : unref(__)("Clear"))}</button>`);
			else _push(`<!---->`);
			_push(`</div><div class="k-ds__row"><div class="k-ds__steps" role="group"${ssrRenderAttr("aria-labelledby", `${uid}-l`)}><!--[-->`);
			ssrRenderList(__props.presets, (p, key) => {
				_push(`<button type="button" class="${ssrRenderClass([{ "is-inherited": model.value === null && shown.value === key }, "k-ds__step"])}"${ssrRenderAttr("aria-pressed", String(model.value === key))}${ssrRenderAttr("title", `${p.label} (${p.value})`)}${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}>${ssrInterpolate(p.label)}</button>`);
			});
			_push(`<!--]--></div><input${ssrRenderAttr("value", typed.value)} class="k-input k-ds__custom"${ssrRenderAttr("aria-label", unref(__)("{label}, custom", { label: __props.label }))}${ssrRenderAttr("aria-invalid", invalid.value ? "true" : void 0)} inputmode="decimal"${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}></div>`);
			if (invalid.value) _push(`<p class="k-ds__error" role="alert">${ssrInterpolate(unref(__)("A number, or a length such as 24px or 1.5rem."))}</p>`);
			else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$9 = _sfc_main$46.setup;
_sfc_main$46.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/DesignScale.vue");
	return _sfc_setup$9 ? _sfc_setup$9(props, ctx) : void 0;
};
// core/resources/js/ui/builder/BuilderDesign.vue
/**
* The selected block's styles (the Style tab): colour, spacing, type and border,
* as far as the block supports them (BlockDefinition::supports). They are set
* for the screen size the canvas shows: desktop styles every size, tablet and
* phone change only theirs (and smaller), the way the page applies them
* (shared/block-design.js).
*/
var _sfc_main$45 = {
	__name: "BuilderDesign",
	__ssrInlineRender: true,
	setup(__props) {
		const { props, selected, selectedAt, selectedDef, designSpec, device, DEVICES, patchSelected, editLocked, frame, ready } = useBuilder();
		const screen = computed(() => screenOf(device.value));
		const supports = computed(() => selectedDef.value?.supports ?? []);
		const presets = computed(() => designSpec.value?.presets ?? {});
		const design = computed(() => selectedAt.value?.node.design ?? null);
		const disabled = computed(() => props.disabled || editLocked.value);
		const SCREEN_NAME = {
			base: __("Desktop"),
			tablet: __("Tablet"),
			phone: __("Phone")
		};
		/** This screen size's own value, and the one it shows meanwhile (from a wider size). */
		const own = (prop) => design.value?.[screen.value]?.[prop] ?? null;
		function wider(prop) {
			if (screen.value === "base") return {
				value: null,
				name: ""
			};
			const order = [
				"base",
				"tablet",
				"phone"
			];
			const above = order[order.indexOf(screen.value) - 1];
			const e = effective(design.value, prop, above);
			return {
				value: e.value,
				name: e.from ? SCREEN_NAME[e.from] : ""
			};
		}
		/** What a control for one style is given: v-bind="field('text')". */
		const field = (prop) => ({
			modelValue: own(prop),
			inherited: wider(prop).value,
			inheritedFrom: wider(prop).name,
			disabled: disabled.value
		});
		function set(prop, value) {
			if (disabled.value) return;
			patchSelected({ design: withValue(design.value, screen.value, prop, value) });
		}
		function setMany(propsList, value) {
			if (disabled.value) return;
			let next = design.value;
			for (const p of propsList) next = withValue(next, screen.value, p, value);
			patchSelected({ design: next });
		}
		const SIDES = [
			"padding-top",
			"padding-right",
			"padding-bottom",
			"padding-left"
		];
		const sidesApart = ref(false);
		watch([selected, screen], () => {
			sidesApart.value = !sameSides(SIDES.map(own));
		}, { immediate: true });
		const SIDE_LABEL = {
			"padding-top": __("Top"),
			"padding-right": __("Right"),
			"padding-bottom": __("Bottom"),
			"padding-left": __("Left")
		};
		const resolved = ref({});
		function readPalette() {
			const doc = frame.value?.contentDocument;
			if (!doc?.documentElement) return;
			const style = doc.defaultView.getComputedStyle(doc.documentElement);
			resolved.value = Object.fromEntries(Object.keys(presets.value.color ?? {}).map((k) => [k, style.getPropertyValue(`--k-color-${k}`).trim() || null]));
		}
		watch([ready, presets], readPalette, { immediate: true });
		const resolve = (key) => resolved.value[key] ?? null;
		const anyHere = computed(() => Object.keys(design.value?.[screen.value] ?? {}).length > 0);
		const lines = computed(() => [{
			value: "",
			label: __("Default")
		}, ...(designSpec.value?.lines ?? []).map((l) => ({
			value: l,
			label: {
				solid: __("Solid"),
				dashed: __("Dashed"),
				dotted: __("Dotted"),
				double: __("Double"),
				none: __("None")
			}[l] ?? l
		}))]);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-design" }, _attrs))}><div class="k-design__screen"><div class="k-design__devices" role="group"${ssrRenderAttr("aria-label", unref(__)("Styles for"))}><!--[-->`);
			ssrRenderList(unref(DEVICES), ([id, icon, label]) => {
				_push(`<button type="button" class="k-design__device"${ssrRenderAttr("aria-pressed", String(unref(device) === id))}${ssrRenderAttr("title", label)}>`);
				_push(ssrRenderComponent(_sfc_main$14, { name: icon }, null, _parent));
				_push(`<span>${ssrInterpolate(label)}</span></button>`);
			});
			_push(`<!--]--></div><p class="k-design__note">${ssrInterpolate(screen.value === "base" ? unref(__)("Styles set here apply on every screen size.") : unref(__)("Changes here apply on {screen} only; the rest follows desktop.", { screen: SCREEN_NAME[screen.value].toLowerCase() }))}</p></div>`);
			if (!supports.value.length) _push(`<p class="k-pane__empty">${ssrInterpolate(unref(__)("This block has no style settings. Its look comes from the theme."))}</p>`);
			else _push(`<!---->`);
			if (supports.value.includes("color")) {
				_push(`<section class="k-design__group"><h3 class="k-design__title">${ssrInterpolate(unref(__)("Colour"))}</h3>`);
				_push(ssrRenderComponent(_sfc_main$47, mergeProps(field("text"), {
					label: unref(__)("Text"),
					presets: presets.value.color,
					resolve,
					"onUpdate:modelValue": ($event) => set("text", $event)
				}), null, _parent));
				_push(ssrRenderComponent(_sfc_main$47, mergeProps(field("background"), {
					label: unref(__)("Background"),
					presets: presets.value.color,
					resolve,
					"onUpdate:modelValue": ($event) => set("background", $event)
				}), null, _parent));
				_push(`</section>`);
			} else _push(`<!---->`);
			if (supports.value.includes("spacing")) {
				_push(`<section class="k-design__group"><h3 class="k-design__title">${ssrInterpolate(unref(__)("Spacing"))}</h3>`);
				if (!sidesApart.value) _push(ssrRenderComponent(_sfc_main$46, mergeProps(field("padding-top"), {
					label: unref(__)("Padding"),
					presets: presets.value.space,
					"onUpdate:modelValue": ($event) => setMany(SIDES, $event)
				}), null, _parent));
				else {
					_push(`<!--[-->`);
					ssrRenderList(SIDES, (side) => {
						_push(ssrRenderComponent(_sfc_main$46, mergeProps({ key: side }, { ref_for: true }, field(side), {
							label: unref(__)("Padding: {side}", { side: SIDE_LABEL[side] }),
							presets: presets.value.space,
							"onUpdate:modelValue": ($event) => set(side, $event)
						}), null, _parent));
					});
					_push(`<!--]-->`);
				}
				_push(`<button type="button" class="k-design__link"${ssrRenderAttr("aria-pressed", String(sidesApart.value))}>`);
				_push(ssrRenderComponent(_sfc_main$14, { name: sidesApart.value ? "link-break" : "link" }, null, _parent));
				_push(`${ssrInterpolate(sidesApart.value ? unref(__)("Same padding on every side") : unref(__)("Different padding per side"))}</button>`);
				_push(ssrRenderComponent(_sfc_main$46, mergeProps(field("margin-top"), {
					label: unref(__)("Space above"),
					presets: presets.value.space,
					"onUpdate:modelValue": ($event) => set("margin-top", $event)
				}), null, _parent));
				_push(ssrRenderComponent(_sfc_main$46, mergeProps(field("margin-bottom"), {
					label: unref(__)("Space below"),
					presets: presets.value.space,
					"onUpdate:modelValue": ($event) => set("margin-bottom", $event)
				}), null, _parent));
				_push(`</section>`);
			} else _push(`<!---->`);
			if (supports.value.includes("type")) {
				_push(`<section class="k-design__group"><h3 class="k-design__title">${ssrInterpolate(unref(__)("Type"))}</h3>`);
				_push(ssrRenderComponent(_sfc_main$46, mergeProps(field("font-size"), {
					label: unref(__)("Text size"),
					presets: presets.value.font,
					units: [
						"px",
						"rem",
						"em"
					],
					"onUpdate:modelValue": ($event) => set("font-size", $event)
				}), null, _parent));
				_push(`</section>`);
			} else _push(`<!---->`);
			if (supports.value.includes("border")) {
				_push(`<section class="k-design__group"><h3 class="k-design__title">${ssrInterpolate(unref(__)("Border"))}</h3>`);
				_push(ssrRenderComponent(_sfc_main$46, mergeProps(field("border-width"), {
					label: unref(__)("Line width"),
					presets: {},
					units: ["px"],
					"onUpdate:modelValue": ($event) => set("border-width", $event)
				}), null, _parent));
				_push(`<label class="k-design__field"><span class="k-ds__label">${ssrInterpolate(unref(__)("Line"))}</span>`);
				_push(ssrRenderComponent(_sfc_main$25, {
					"model-value": own("border-style") ?? "",
					options: lines.value,
					disabled: disabled.value,
					"onUpdate:modelValue": ($event) => set("border-style", $event || null)
				}, null, _parent));
				_push(`</label>`);
				_push(ssrRenderComponent(_sfc_main$47, mergeProps(field("border-color"), {
					label: unref(__)("Line colour"),
					presets: presets.value.color,
					resolve,
					"onUpdate:modelValue": ($event) => set("border-color", $event)
				}), null, _parent));
				_push(ssrRenderComponent(_sfc_main$46, mergeProps(field("radius"), {
					label: unref(__)("Corners"),
					presets: presets.value.radius,
					units: [
						"px",
						"rem",
						"%"
					],
					"onUpdate:modelValue": ($event) => set("radius", $event)
				}), null, _parent));
				_push(`</section>`);
			} else _push(`<!---->`);
			if (anyHere.value && !disabled.value) _push(`<button type="button" class="k-design__clear">${ssrInterpolate(screen.value === "base" ? unref(__)("Clear every style of this block") : unref(__)("Clear the {screen} styles", { screen: SCREEN_NAME[screen.value].toLowerCase() }))}</button>`);
			else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$8 = _sfc_main$45.setup;
_sfc_main$45.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderDesign.vue");
	return _sfc_setup$8 ? _sfc_setup$8(props, ctx) : void 0;
};
// core/resources/js/ui/builder/BuilderInspector.vue
/** The right panel: the selected block's settings and styles, the document's own (slot #document), and the checks. */
var _sfc_main$44 = {
	__name: "BuilderInspector",
	__ssrInlineRender: true,
	setup(__props) {
		const { props, right, resize, resizeKey, rightTab, selected, selectedAt, selectedDef, select, multi, pickedNodes, nameOf, iconOf, canGroupPicked, groupPicked, duplicatePicked, removePicked, bindings, bindingLabel, patchSelected, editable, issues, runChecks } = useBuilder();
		const tabs = computed(() => [
			{
				id: "block",
				label: __("Block")
			},
			{
				id: "style",
				label: __("Style")
			},
			{
				id: "document",
				label: props.documentLabel || {
					template: __("Template"),
					part: __("Part"),
					pattern: __("Pattern"),
					entry: __("Page")
				}[props.kind]
			},
			{
				id: "checks",
				label: __("Checks"),
				count: issues.value.length
			}
		]);
		const inline = computed(() => Object.keys(editable.value[selected.value] ?? {}));
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<aside${ssrRenderAttrs(mergeProps({
				class: "k-builder__right",
				"aria-label": unref(__)("Settings")
			}, _attrs, { style: unref(right) ? null : { display: "none" } }))}><div class="k-resize k-resize--x" role="separator" aria-orientation="vertical" tabindex="0"${ssrRenderAttr("aria-label", unref(__)("Resize the settings panel"))}></div><header class="k-pane__head k-pane__head--tabs">`);
			_push(ssrRenderComponent(_sfc_main$32, {
				modelValue: unref(rightTab),
				"onUpdate:modelValue": ($event) => isRef(rightTab) ? rightTab.value = $event : null,
				tabs: tabs.value,
				label: unref(__)("Settings")
			}, null, _parent));
			_push(`<button type="button" class="k-bb k-bb--sm"${ssrRenderAttr("title", unref(__)("Close"))}>`);
			_push(ssrRenderComponent(_sfc_main$14, { name: "x" }, null, _parent));
			_push(`<span class="k-sr-only">${ssrInterpolate(unref(__)("Close"))}</span></button></header><div class="k-pane__scroll k-builder__inspector">`);
			if (unref(rightTab) === "block") {
				_push(`<!--[-->`);
				if (unref(multi)) {
					_push(`<div class="k-multi"><p class="k-multi__title">${ssrInterpolate(unref(__)("{n} blocks selected", { n: unref(pickedNodes).length }))}</p><ul class="k-multi__list"><!--[-->`);
					ssrRenderList(unref(pickedNodes), (n) => {
						_push(`<li>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: unref(iconOf)(n) }, null, _parent));
						_push(`${ssrInterpolate(unref(nameOf)(n))}</li>`);
					});
					_push(`<!--]--></ul>`);
					if (!unref(props).disabled) {
						_push(`<div class="k-multi__actions">`);
						if (unref(canGroupPicked)) _push(ssrRenderComponent(_sfc_main$6, {
							size: "sm",
							icon: "selection-plus",
							onClick: unref(groupPicked)
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Put in a group"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Put in a group")), 1)];
							}),
							_: 1
						}, _parent));
						else _push(`<!---->`);
						_push(ssrRenderComponent(_sfc_main$6, {
							size: "sm",
							icon: "copy",
							onClick: unref(duplicatePicked)
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Duplicate"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Duplicate")), 1)];
							}),
							_: 1
						}, _parent));
						_push(ssrRenderComponent(_sfc_main$6, {
							size: "sm",
							variant: "danger",
							icon: "trash",
							onClick: unref(removePicked)
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Remove"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Remove")), 1)];
							}),
							_: 1
						}, _parent));
						_push(`</div>`);
					} else _push(`<!---->`);
					_push(`<p class="k-pane__help">${ssrInterpolate(unref(__)("Settings are changed one block at a time. Press Esc to go back to one block."))}</p></div>`);
				} else if (unref(selectedAt) && unref(selectedDef)) {
					_push(`<!--[--><div class="k-blockcard">`);
					_push(ssrRenderComponent(_sfc_main$14, {
						name: unref(iconOf)(unref(selectedAt).node),
						class: "k-blockcard__icon"
					}, null, _parent));
					_push(`<div><p class="k-blockcard__name">${ssrInterpolate(unref(selectedDef).label)}</p>`);
					if (unref(selectedDef).description) _push(`<p class="k-blockcard__about">${ssrInterpolate(unref(selectedDef).description)}</p>`);
					else _push(`<!---->`);
					_push(`</div></div>`);
					_push(ssrRenderComponent(KBlockSettings_default, {
						key: unref(selected),
						node: unref(selectedAt).node,
						def: unref(selectedDef),
						bindings: unref(bindings),
						"binding-label": unref(bindingLabel),
						disabled: unref(props).disabled,
						lockable: unref(props).kind !== "entry",
						"hide-about": "",
						inline: inline.value,
						onUpdate: unref(patchSelected)
					}, null, _parent));
					_push(`<!--]-->`);
				} else _push(`<p class="k-pane__empty">${ssrInterpolate(unref(__)("Select a block on the page or in the layers to change it."))}</p>`);
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			if (unref(rightTab) === "style") {
				_push(`<!--[-->`);
				if (unref(multi)) _push(`<p class="k-pane__empty">${ssrInterpolate(unref(__)("Styles are set one block at a time. To give several blocks the same look, style one, choose Copy styles in its menu, select the others and choose Paste styles."))}</p>`);
				else if (unref(selectedAt) && unref(selectedDef)) _push(ssrRenderComponent(_sfc_main$45, { key: unref(selected) }, null, _parent));
				else _push(`<p class="k-pane__empty">${ssrInterpolate(unref(__)("Select a block on the page or in the layers to style it."))}</p>`);
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(`<div class="k-builder__doc" style="${ssrRenderStyle(unref(rightTab) === "document" ? null : { display: "none" })}">`);
			ssrRenderSlot(_ctx.$slots, "document", {}, null, _push, _parent);
			_push(`</div>`);
			if (unref(rightTab) === "checks") {
				_push(`<!--[--><p class="k-pane__help">${ssrInterpolate(unref(__)("Checked on the preview as it is now, including the theme around it."))}</p>`);
				if (!unref(issues).length) {
					_push(`<p class="k-builder__ok">`);
					_push(ssrRenderComponent(_sfc_main$14, { name: "check-circle" }, null, _parent));
					_push(`${ssrInterpolate(unref(__)("No problems found."))}</p>`);
				} else {
					_push(`<ul class="k-issues"><!--[-->`);
					ssrRenderList(unref(issues), (issue, i) => {
						_push(`<li class="${ssrRenderClass(`is-${issue.tone}`)}">`);
						_push(ssrRenderComponent(_sfc_main$14, { name: issue.tone === "danger" ? "x-circle" : "warning" }, null, _parent));
						_push(`<span>${ssrInterpolate(issue.message)}</span>`);
						if (issue.id) _push(`<button type="button" class="k-builder__crumb">${ssrInterpolate(unref(__)("Show"))}</button>`);
						else _push(`<!---->`);
						_push(`</li>`);
					});
					_push(`<!--]--></ul>`);
				}
				_push(ssrRenderComponent(_sfc_main$6, {
					size: "sm",
					variant: "ghost",
					icon: "arrows-clockwise",
					onClick: unref(runChecks)
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Check again"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Check again")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(`</div></aside>`);
		};
	}
};
var _sfc_setup$7 = _sfc_main$44.setup;
_sfc_main$44.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderInspector.vue");
	return _sfc_setup$7 ? _sfc_setup$7(props, ctx) : void 0;
};
// core/resources/js/ui/builder/BuilderFind.vue
/** Find and replace: the words on the page, block by block. Choosing a result selects its block. */
var _sfc_main$43 = {
	__name: "BuilderFind",
	__ssrInlineRender: true,
	setup(__props) {
		const { props, findQuery, replaceWith, matchCase, findResults, findTotal, replaceFound, select, selected, nameOf, iconOf, locked, list } = useBuilder();
		const results = computed(() => findResults.value.map((r) => {
			const node = locate(list.value, r.id)?.node ?? null;
			return {
				...r,
				node,
				name: nameOf(node),
				icon: node ? iconOf(node) : "square",
				parts: highlight(r.snippet, findQuery.value, { matchCase: matchCase.value })
			};
		}));
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-pane__scroll k-find" }, _attrs))}><form class="k-find__form"><label class="k-find__field"><span>${ssrInterpolate(unref(__)("Find"))}</span><input${ssrRenderAttr("value", unref(findQuery))} class="k-input k-find__query" type="search" maxlength="200" autocomplete="off" spellcheck="false"></label><label class="k-find__field"><span>${ssrInterpolate(unref(__)("Replace with"))}</span><input${ssrRenderAttr("value", unref(replaceWith))} class="k-input" type="text" maxlength="1000" autocomplete="off"${ssrIncludeBooleanAttr(unref(props).disabled) ? " disabled" : ""}></label><label class="k-find__case"><input${ssrIncludeBooleanAttr(Array.isArray(unref(matchCase)) ? ssrLooseContain(unref(matchCase), null) : unref(matchCase)) ? " checked" : ""} type="checkbox">${ssrInterpolate(unref(__)("Match upper and lower case"))}</label><div class="k-find__actions"><p class="k-find__count" role="status">`);
			if (unref(findQuery).trim()) _push(`<!--[-->${ssrInterpolate(unref(findTotal) === 0 ? unref(__)("No matches") : unref(findTotal) === 1 ? unref(__)("1 match") : unref(__)("{n} matches in {b} blocks", {
				n: unref(findTotal),
				b: unref(findResults).length
			}))}<!--]-->`);
			else _push(`<!---->`);
			_push(`</p>`);
			if (!unref(props).disabled) _push(ssrRenderComponent(_sfc_main$6, {
				type: "submit",
				size: "sm",
				variant: "primary",
				disabled: !unref(findTotal)
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("Replace all"))}`);
					else return [createTextVNode(toDisplayString(unref(__)("Replace all")), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`</div></form>`);
			if (results.value.length) {
				_push(`<ul class="k-find__results"><!--[-->`);
				ssrRenderList(results.value, (r) => {
					_push(`<li class="${ssrRenderClass([{ "is-selected": unref(selected) === r.id }, "k-find__result"])}"><button type="button" class="k-find__go"><span class="k-find__name">`);
					_push(ssrRenderComponent(_sfc_main$14, { name: r.icon }, null, _parent));
					_push(`${ssrInterpolate(r.name)}`);
					if (r.count > 1) _push(`<span class="k-find__n">${ssrInterpolate(r.count)}</span>`);
					else _push(`<!---->`);
					_push(`</span><span class="k-find__snippet"><!--[-->`);
					ssrRenderList(r.parts, (p, i) => {
						_push(`<!--[-->`);
						if (p.hit) _push(`<mark>${ssrInterpolate(p.text)}</mark>`);
						else _push(`<!--[-->${ssrInterpolate(p.text)}<!--]-->`);
						_push(`<!--]-->`);
					});
					_push(`<!--]--></span></button>`);
					if (!unref(props).disabled && !unref(locked)(r.node, "edit")) {
						_push(`<button type="button" class="k-find__one"${ssrRenderAttr("title", unref(__)("Replace in this block"))}${ssrRenderAttr("aria-label", unref(__)("Replace in {block}", { block: r.name }))}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "arrows-left-right" }, null, _parent));
						_push(`</button>`);
					} else _push(`<!---->`);
					_push(`</li>`);
				});
				_push(`<!--]--></ul>`);
			} else if (!unref(findQuery).trim()) _push(`<p class="k-pane__empty">${ssrInterpolate(unref(__)("Searches the words on this page: headings, text, lists, tables and buttons."))}</p>`);
			else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$6 = _sfc_main$43.setup;
_sfc_main$43.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderFind.vue");
	return _sfc_setup$6 ? _sfc_setup$6(props, ctx) : void 0;
};
// core/resources/js/ui/builder/BuilderLayers.vue
/** The layers: the tree as a list, one row per block. A tree for keyboards and screen readers; rows drag. */
var _sfc_main$42 = {
	__name: "BuilderLayers",
	__ssrInlineRender: true,
	setup(__props) {
		const { props, rows, selected, pickedIds, selectMore, hover, collapsed, defOf, iconOf, nameOf, summary, select, toggleCollapse, rowKey, drag, drop, dragNode, overRow, overEnd, leave, finish, endDrag } = useBuilder();
		const hasKids = (node) => (node.children ?? []).length > 0;
		const isPicked = (id) => pickedIds.value.includes(id);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-pane__scroll" }, _attrs))}>`);
			if (unref(rows).length) {
				_push(`<ul class="k-layers" role="tree"${ssrRenderAttr("aria-label", unref(__)("Layers"))} aria-multiselectable="true" aria-describedby="k-layers-help"><!--[-->`);
				ssrRenderList(unref(rows), (row, i) => {
					_push(`<li role="none"><div${ssrRenderAttr("data-row", row.node.id)} role="treeitem"${ssrRenderAttr("aria-level", row.depth + 1)}${ssrRenderAttr("aria-selected", String(isPicked(row.node.id)))}${ssrRenderAttr("aria-expanded", hasKids(row.node) ? String(!unref(collapsed).has(row.node.id)) : void 0)}${ssrRenderAttr("tabindex", unref(selected) === row.node.id || !unref(selected) && i === 0 ? 0 : -1)} class="${ssrRenderClass([{
						"is-selected": isPicked(row.node.id),
						"is-hover": unref(hover) === row.node.id,
						"is-missing": !unref(defOf)(row.node),
						"is-dragging": unref(drag)?.id === row.node.id,
						[`drop-${unref(drop)?.where}`]: unref(drop)?.id === row.node.id
					}, "k-layer"])}" style="${ssrRenderStyle({ "--depth": row.depth })}"${ssrRenderAttr("draggable", !unref(props).disabled)}>`);
					if (unref(defOf)(row.node)?.children) {
						_push(`<button type="button" class="k-layer__twisty" tabindex="-1"${ssrRenderAttr("aria-label", unref(collapsed).has(row.node.id) ? unref(__)("Expand") : unref(__)("Collapse"))}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: unref(collapsed).has(row.node.id) ? "caret-right" : "caret-down" }, null, _parent));
						_push(`</button>`);
					} else _push(`<span class="k-layer__twisty" aria-hidden="true"></span>`);
					_push(ssrRenderComponent(_sfc_main$14, {
						name: unref(iconOf)(row.node),
						class: "k-layer__icon"
					}, null, _parent));
					_push(`<span class="k-layer__name">${ssrInterpolate(unref(nameOf)(row.node))}</span><span class="k-layer__summary">${ssrInterpolate(unref(summary)(row.node))}</span>`);
					if (row.node.visibility?.hide?.length) _push(ssrRenderComponent(_sfc_main$14, {
						name: "eye-slash",
						class: "k-layer__flag",
						title: unref(__)("Hidden on {screens}", { screens: row.node.visibility.hide.join(", ") })
					}, null, _parent));
					else _push(`<!---->`);
					if (row.node.lock?.length) _push(ssrRenderComponent(_sfc_main$14, {
						name: "lock",
						class: "k-layer__flag",
						title: unref(__)("Locked for editors")
					}, null, _parent));
					else _push(`<!---->`);
					_push(`</div></li>`);
				});
				_push(`<!--]--></ul>`);
			} else _push(`<p class="k-pane__empty">${ssrInterpolate(unref(__)("Nothing here yet. Press + to add a block, or drag one here."))}</p>`);
			_push(`<div class="${ssrRenderClass([{ "is-target": unref(drop)?.end }, "k-layers__end"])}">${ssrInterpolate(unref(drag) ? unref(__)("Drop here to add at the end") : "")}</div><p id="k-layers-help" class="k-pane__help">${ssrInterpolate(unref(__)("Arrow keys move between blocks; Alt with arrows moves the block; Shift with arrows, or Shift or Ctrl with a click, selects several. Press ? for every shortcut."))}</p></div>`);
		};
	}
};
var _sfc_setup$5 = _sfc_main$42.setup;
_sfc_main$42.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderLayers.vue");
	return _sfc_setup$5 ? _sfc_setup$5(props, ctx) : void 0;
};
// core/resources/js/ui/builder/BuilderLibrary.vue
/** The block library: click to add next to the selection, or drag onto the page or the layers. */
var _sfc_main$41 = {
	__name: "BuilderLibrary",
	__ssrInlineRender: true,
	setup(__props) {
		const { props, query, library, usablePatterns, patternChoices, selected, place, create, dragNew, endDrag, openPatterns, choosePattern } = useBuilder();
		const browseText = (n) => __("{n, plural, one {Browse # pattern} other {Browse # patterns}}", { n });
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-pane__scroll k-lib" }, _attrs))}><label class="k-lib__label" for="k-lib-search">${ssrInterpolate(unref(__)("Find a block or pattern"))}</label><div class="k-lib__search">`);
			_push(ssrRenderComponent(_sfc_main$14, { name: "magnifying-glass" }, null, _parent));
			_push(`<input id="k-lib-search"${ssrRenderAttr("value", unref(query))} type="search" class="k-builder__search"></div><p class="k-pane__help k-lib__where">${ssrInterpolate(unref(selected) ? unref(__)("Adds inside or after the selected block. You can also drag a block onto the page or the layers.") : unref(__)("Adds at the end of the page. You can also drag a block onto the page or the layers."))}</p><!--[-->`);
			ssrRenderList(unref(library), (g) => {
				_push(`<section class="k-lib__group"><h3>${ssrInterpolate(g.label)}</h3><div class="k-lib__grid"><!--[-->`);
				ssrRenderList(g.blocks, (d) => {
					_push(`<button type="button" class="k-lib__tile" draggable="true"${ssrRenderAttr("title", d.description)}${ssrIncludeBooleanAttr(unref(props).disabled) ? " disabled" : ""}>`);
					_push(ssrRenderComponent(_sfc_main$14, { name: d.icon }, null, _parent));
					_push(`<span>${ssrInterpolate(d.label)}</span></button>`);
				});
				_push(`<!--]--></div></section>`);
			});
			_push(`<!--]--><!-- while searching, only when a pattern matches -->`);
			if (unref(usablePatterns).length && (!unref(query).trim() || unref(patternChoices).length)) {
				_push(`<section class="k-lib__group"><h3>${ssrInterpolate(unref(__)("Patterns"))}</h3>`);
				if (!unref(query).trim()) {
					_push(`<button type="button" class="k-lib__browse"${ssrIncludeBooleanAttr(unref(props).disabled) ? " disabled" : ""}>`);
					_push(ssrRenderComponent(_sfc_main$14, { name: "squares-four" }, null, _parent));
					_push(`<span>${ssrInterpolate(browseText(unref(usablePatterns).length))}</span>`);
					_push(ssrRenderComponent(_sfc_main$14, { name: "arrow-right" }, null, _parent));
					_push(`</button>`);
				} else _push(`<!---->`);
				_push(`<!--[-->`);
				ssrRenderList(unref(patternChoices), (p) => {
					_push(`<button type="button" class="k-lib__item"${ssrRenderAttr("draggable", !p.layout)}${ssrIncludeBooleanAttr(unref(props).disabled) ? " disabled" : ""}>`);
					_push(ssrRenderComponent(_sfc_main$14, { name: p.synced ? "star" : "bookmark" }, null, _parent));
					_push(`<span>${ssrInterpolate(p.title)}</span>`);
					if (p.synced) _push(`<span class="k-lib__note">${ssrInterpolate(unref(__)("synced"))}</span>`);
					else _push(`<!---->`);
					_push(`</button>`);
				});
				_push(`<!--]--></section>`);
			} else _push(`<!---->`);
			if (unref(query).trim() && !unref(library).length && !unref(patternChoices).length) _push(`<p class="k-pane__empty">${ssrInterpolate(unref(__)("No block matches “{q}”.", { q: unref(query) }))}</p>`);
			else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$4 = _sfc_main$41.setup;
_sfc_main$41.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderLibrary.vue");
	return _sfc_setup$4 ? _sfc_setup$4(props, ctx) : void 0;
};
// core/resources/js/ui/builder/PatternPreview.vue
/**
* A pattern as the site draws it, shrunk to fit its card: the page at 1200px wide,
* scaled down. It loads when the card scrolls into view (loading="lazy") and can't
* be focused or clicked: the card around it is the button.
*/
var WIDTH = 1200;
var _sfc_main$40 = {
	__name: "PatternPreview",
	__ssrInlineRender: true,
	props: { patternKey: {
		type: String,
		required: true
	} },
	setup(__props) {
		const props = __props;
		const box = ref(null);
		const scale = ref(.25);
		const loaded = ref(false);
		let observer = null;
		onMounted(() => {
			observer = new ResizeObserver(([e]) => {
				scale.value = e.contentRect.width / WIDTH;
			});
			observer.observe(box.value);
		});
		onBeforeUnmount(() => observer?.disconnect());
		const src = `/system/design/canvas?kind=pattern&key=${encodeURIComponent(props.patternKey)}&preview=1`;
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				ref_key: "box",
				ref: box,
				class: ["k-patterns__preview", { "is-loaded": loaded.value }],
				"aria-hidden": "true"
			}, _attrs))}><iframe${ssrRenderAttr("src", src)} loading="lazy" tabindex="-1" title=""${ssrRenderAttr("width", WIDTH)} height="900" style="${ssrRenderStyle({ transform: `scale(${scale.value})` })}"></iframe></div>`);
		};
	}
};
var _sfc_setup$3 = _sfc_main$40.setup;
_sfc_main$40.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/PatternPreview.vue");
	return _sfc_setup$3 ? _sfc_setup$3(props, ctx) : void 0;
};
// core/resources/js/ui/builder/BuilderPatterns.vue
/**
* The pattern browser: every pattern by category with a live preview, or (for an
* empty page) the whole-page layouts. Choosing one adds it and closes the browser.
*/
var _sfc_main$39 = {
	__name: "BuilderPatterns",
	__ssrInlineRender: true,
	setup(__props) {
		const { browsing, layoutChoice, closePatterns, choosePattern, usablePatterns, list } = useBuilder();
		const CORE_LABELS = {
			Pages: __("Pages"),
			Headers: __("Headers"),
			Features: __("Features"),
			Text: __("Text"),
			"Calls to action": __("Calls to action"),
			Testimonials: __("Testimonials"),
			Pricing: __("Pricing"),
			Questions: __("Questions"),
			About: __("About"),
			Contact: __("Contact"),
			"Lists and tables": __("Lists and tables")
		};
		const label = (name) => CORE_LABELS[name] ?? name;
		const layoutsOnly = computed(() => browsing.value === "layouts");
		const query = ref("");
		const category = ref("");
		const groups = computed(() => groupPatterns(usablePatterns.value, {
			query: query.value,
			layouts: layoutsOnly.value
		}));
		const shown = computed(() => category.value ? groups.value.filter((g) => g.name === category.value) : groups.value);
		const total = computed(() => groups.value.reduce((n, g) => n + g.patterns.length, 0));
		const search = ref(null);
		watch(browsing, (mode) => {
			if (mode) {
				query.value = "";
				category.value = "";
				nextTick(() => search.value?.focus());
			}
		});
		watch(groups, (g) => {
			if (category.value && !g.some((x) => x.name === category.value)) category.value = "";
		});
		const open = computed({
			get: () => browsing.value !== null,
			set: (v) => {
				if (!v) closePatterns();
			}
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$19, mergeProps({
				open: open.value,
				"onUpdate:open": ($event) => open.value = $event,
				title: layoutsOnly.value ? unref(__)("Start from a layout") : unref(__)("Patterns"),
				size: "xl"
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-patterns"${_scopeId}><aside class="k-patterns__side"${_scopeId}><label class="k-patterns__label" for="k-patterns-search"${_scopeId}>${ssrInterpolate(layoutsOnly.value ? unref(__)("Find a layout") : unref(__)("Find a pattern"))}</label><div class="k-patterns__search"${_scopeId}>`);
						_push(ssrRenderComponent(_sfc_main$14, { name: "magnifying-glass" }, null, _parent, _scopeId));
						_push(`<input id="k-patterns-search"${ssrRenderAttr("value", query.value)} type="search" class="k-input"${_scopeId}></div>`);
						if (groups.value.length > 1) {
							_push(`<nav class="k-patterns__cats"${ssrRenderAttr("aria-label", unref(__)("Categories"))}${_scopeId}><button type="button" class="k-patterns__cat"${ssrRenderAttr("aria-pressed", String(category.value === ""))}${_scopeId}>${ssrInterpolate(unref(__)("All"))}<span${_scopeId}>${ssrInterpolate(total.value)}</span></button><!--[-->`);
							ssrRenderList(groups.value, (g) => {
								_push(`<button type="button" class="k-patterns__cat"${ssrRenderAttr("aria-pressed", String(category.value === g.name))}${_scopeId}>${ssrInterpolate(label(g.name))}<span${_scopeId}>${ssrInterpolate(g.patterns.length)}</span></button>`);
							});
							_push(`<!--]--></nav>`);
						} else _push(`<!---->`);
						if (layoutsOnly.value) _push(`<p class="k-muted k-small"${_scopeId}>${ssrInterpolate(unref(__)("A layout fills the page with sections you then change: the words, the images, the order."))}</p>`);
						else _push(`<!---->`);
						_push(`</aside><div class="k-patterns__main"${_scopeId}>`);
						if (unref(layoutChoice)) {
							_push(`<div class="k-patterns__choice" role="group"${ssrRenderAttr("aria-label", unref(__)("Replace or add after"))}${_scopeId}><p${_scopeId}>${ssrInterpolate(unref(__)("{name} is a whole page, and this page already has {n} blocks.", {
								name: unref(layoutChoice).title,
								n: unref(list).length
							}))}</p><div${_scopeId}>`);
							_push(ssrRenderComponent(_sfc_main$6, {
								size: "sm",
								variant: "primary",
								onClick: ($event) => unref(choosePattern)(unref(layoutChoice), "replace")
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Replace them"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Replace them")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(_sfc_main$6, {
								size: "sm",
								onClick: ($event) => unref(choosePattern)(unref(layoutChoice), "append")
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Add after them"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Add after them")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(_sfc_main$6, {
								size: "sm",
								variant: "ghost",
								onClick: ($event) => layoutChoice.value = null
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div></div>`);
						} else _push(`<!---->`);
						_push(`<!--[-->`);
						ssrRenderList(shown.value, (g) => {
							_push(`<section class="k-patterns__group"${_scopeId}>`);
							if (shown.value.length > 1 || !layoutsOnly.value) _push(`<h3${_scopeId}>${ssrInterpolate(label(g.name))}</h3>`);
							else _push(`<!---->`);
							_push(`<ul class="k-patterns__grid"${_scopeId}><!--[-->`);
							ssrRenderList(g.patterns, (p) => {
								_push(`<li${_scopeId}><button type="button" class="k-patterns__card"${ssrRenderAttr("aria-label", p.description ? `${p.title}. ${p.description}` : p.title)}${_scopeId}>`);
								_push(ssrRenderComponent(_sfc_main$40, { "pattern-key": p.key }, null, _parent, _scopeId));
								_push(`<span class="k-patterns__name"${_scopeId}>${ssrInterpolate(p.title)}`);
								if (p.synced) _push(`<span class="k-patterns__note"${_scopeId}>${ssrInterpolate(unref(__)("synced"))}</span>`);
								else _push(`<!---->`);
								_push(`</span>`);
								if (p.description) _push(`<span class="k-patterns__about"${_scopeId}>${ssrInterpolate(p.description)}</span>`);
								else _push(`<!---->`);
								_push(`</button></li>`);
							});
							_push(`<!--]--></ul></section>`);
						});
						_push(`<!--]-->`);
						if (!shown.value.length) _push(`<p class="k-patterns__empty"${_scopeId}>${ssrInterpolate(query.value ? unref(__)("No pattern matches “{q}”.", { q: query.value }) : unref(__)("There are no patterns yet."))}</p>`);
						else _push(`<!---->`);
						_push(`</div></div>`);
					} else return [createVNode("div", { class: "k-patterns" }, [createVNode("aside", { class: "k-patterns__side" }, [
						createVNode("label", {
							class: "k-patterns__label",
							for: "k-patterns-search"
						}, toDisplayString(layoutsOnly.value ? unref(__)("Find a layout") : unref(__)("Find a pattern")), 1),
						createVNode("div", { class: "k-patterns__search" }, [createVNode(_sfc_main$14, { name: "magnifying-glass" }), withDirectives(createVNode("input", {
							id: "k-patterns-search",
							ref_key: "search",
							ref: search,
							"onUpdate:modelValue": ($event) => query.value = $event,
							type: "search",
							class: "k-input"
						}, null, 8, ["onUpdate:modelValue"]), [[vModelText, query.value]])]),
						groups.value.length > 1 ? (openBlock(), createBlock("nav", {
							key: 0,
							class: "k-patterns__cats",
							"aria-label": unref(__)("Categories")
						}, [createVNode("button", {
							type: "button",
							class: "k-patterns__cat",
							"aria-pressed": String(category.value === ""),
							onClick: ($event) => category.value = ""
						}, [createTextVNode(toDisplayString(unref(__)("All")), 1), createVNode("span", null, toDisplayString(total.value), 1)], 8, ["aria-pressed", "onClick"]), (openBlock(true), createBlock(Fragment, null, renderList(groups.value, (g) => {
							return openBlock(), createBlock("button", {
								key: g.name,
								type: "button",
								class: "k-patterns__cat",
								"aria-pressed": String(category.value === g.name),
								onClick: ($event) => category.value = g.name
							}, [createTextVNode(toDisplayString(label(g.name)), 1), createVNode("span", null, toDisplayString(g.patterns.length), 1)], 8, ["aria-pressed", "onClick"]);
						}), 128))], 8, ["aria-label"])) : createCommentVNode("v-if", true),
						layoutsOnly.value ? (openBlock(), createBlock("p", {
							key: 1,
							class: "k-muted k-small"
						}, toDisplayString(unref(__)("A layout fills the page with sections you then change: the words, the images, the order.")), 1)) : createCommentVNode("v-if", true)
					]), createVNode("div", { class: "k-patterns__main" }, [
						unref(layoutChoice) ? (openBlock(), createBlock("div", {
							key: 0,
							class: "k-patterns__choice",
							role: "group",
							"aria-label": unref(__)("Replace or add after")
						}, [createVNode("p", null, toDisplayString(unref(__)("{name} is a whole page, and this page already has {n} blocks.", {
							name: unref(layoutChoice).title,
							n: unref(list).length
						})), 1), createVNode("div", null, [
							createVNode(_sfc_main$6, {
								size: "sm",
								variant: "primary",
								onClick: ($event) => unref(choosePattern)(unref(layoutChoice), "replace")
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Replace them")), 1)]),
								_: 1
							}, 8, ["onClick"]),
							createVNode(_sfc_main$6, {
								size: "sm",
								onClick: ($event) => unref(choosePattern)(unref(layoutChoice), "append")
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Add after them")), 1)]),
								_: 1
							}, 8, ["onClick"]),
							createVNode(_sfc_main$6, {
								size: "sm",
								variant: "ghost",
								onClick: ($event) => layoutChoice.value = null
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
								_: 1
							}, 8, ["onClick"])
						])], 8, ["aria-label"])) : createCommentVNode("v-if", true),
						(openBlock(true), createBlock(Fragment, null, renderList(shown.value, (g) => {
							return openBlock(), createBlock("section", {
								key: g.name,
								class: "k-patterns__group"
							}, [shown.value.length > 1 || !layoutsOnly.value ? (openBlock(), createBlock("h3", { key: 0 }, toDisplayString(label(g.name)), 1)) : createCommentVNode("v-if", true), createVNode("ul", { class: "k-patterns__grid" }, [(openBlock(true), createBlock(Fragment, null, renderList(g.patterns, (p) => {
								return openBlock(), createBlock("li", { key: p.key }, [createVNode("button", {
									type: "button",
									class: "k-patterns__card",
									"aria-label": p.description ? `${p.title}. ${p.description}` : p.title,
									onClick: ($event) => unref(choosePattern)(p)
								}, [
									createVNode(_sfc_main$40, { "pattern-key": p.key }, null, 8, ["pattern-key"]),
									createVNode("span", { class: "k-patterns__name" }, [createTextVNode(toDisplayString(p.title), 1), p.synced ? (openBlock(), createBlock("span", {
										key: 0,
										class: "k-patterns__note"
									}, toDisplayString(unref(__)("synced")), 1)) : createCommentVNode("v-if", true)]),
									p.description ? (openBlock(), createBlock("span", {
										key: 0,
										class: "k-patterns__about"
									}, toDisplayString(p.description), 1)) : createCommentVNode("v-if", true)
								], 8, ["aria-label", "onClick"])]);
							}), 128))])]);
						}), 128)),
						!shown.value.length ? (openBlock(), createBlock("p", {
							key: 1,
							class: "k-patterns__empty"
						}, toDisplayString(query.value ? unref(__)("No pattern matches “{q}”.", { q: query.value }) : unref(__)("There are no patterns yet.")), 1)) : createCommentVNode("v-if", true)
					])])];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup$2 = _sfc_main$39.setup;
_sfc_main$39.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderPatterns.vue");
	return _sfc_setup$2 ? _sfc_setup$2(props, ctx) : void 0;
};
// core/resources/js/ui/builder/BuilderShortcuts.vue
/** Every shortcut, written for this computer (? opens it). */
var _sfc_main$38 = {
	__name: "BuilderShortcuts",
	__ssrInlineRender: true,
	setup(__props) {
		const { shortcutsOpen } = useBuilder();
		const groups = shortcutGroups(__);
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$19, mergeProps({
				open: unref(shortcutsOpen),
				title: unref(__)("Keyboard shortcuts"),
				size: "lg",
				onClose: ($event) => shortcutsOpen.value = false
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-builder-keys"${_scopeId}><!--[-->`);
						ssrRenderList(unref(groups), ([group, items]) => {
							_push(`<section${_scopeId}><h3${_scopeId}>${ssrInterpolate(group)}</h3><dl${_scopeId}><!--[-->`);
							ssrRenderList(items, ([what, keys]) => {
								_push(`<div${_scopeId}><dt${_scopeId}>${ssrInterpolate(what)}</dt><dd${_scopeId}><kbd${_scopeId}>${ssrInterpolate(unref(keyLabel)(keys))}</kbd></dd></div>`);
							});
							_push(`<!--]--></dl></section>`);
						});
						_push(`<!--]--></div>`);
					} else return [createVNode("div", { class: "k-builder-keys" }, [(openBlock(true), createBlock(Fragment, null, renderList(unref(groups), ([group, items]) => {
						return openBlock(), createBlock("section", { key: group }, [createVNode("h3", null, toDisplayString(group), 1), createVNode("dl", null, [(openBlock(true), createBlock(Fragment, null, renderList(items, ([what, keys]) => {
							return openBlock(), createBlock("div", { key: what }, [createVNode("dt", null, toDisplayString(what), 1), createVNode("dd", null, [createVNode("kbd", null, toDisplayString(unref(keyLabel)(keys)), 1)])]);
						}), 128))])]);
					}), 128))])];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup$1 = _sfc_main$38.setup;
_sfc_main$38.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/builder/BuilderShortcuts.vue");
	return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
// core/resources/js/ui/KBuilder.vue
/**
* The visual editor: block library and layers on the left, the real site in a
* frame in the middle, the selected block's settings on the right. Text is
* typed straight into the page; a toolbar follows the selected block, and /
* opens a quick inserter. Everything works from the keyboard as well as by
* dragging. The parts and their state are in builder/ (context.js explains how
* they fit together).
*
*   <KBuilder v-model="blocks" kind="template" doc-key="entry-post" :reset-key="revision">
*       <template #document>…settings of the template itself…</template>
*   </KBuilder>
*
* For an entry's blocks field (kind="entry"), the page passes where the canvas
* lives and how a tree is prepared for it:
*
*   <KBuilder v-model="form.data.body" kind="entry" doc-key="body" :canvas-src="url" :previewer="prepare" :canvas-data="{ title }" />
*
* Slots: #start and #end (the page's own controls in the bar), #document (the
* document's settings, a tab of the right panel), #below (a drawer under the canvas).
*/
var _sfc_main$5 = {
	__name: "KBuilder",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		kind: {
			type: String,
			required: true
		},
		docKey: {
			type: String,
			required: true
		},
		resetKey: {
			type: [String, Number],
			default: ""
		},
		disabled: Boolean,
		errors: {
			type: Object,
			default: () => ({})
		},
		canvasSrc: {
			type: String,
			default: ""
		},
		previewer: {
			type: Function,
			default: null
		},
		canvasData: {
			type: Object,
			default: () => ({})
		},
		allowed: {
			type: Array,
			default: null
		},
		documentLabel: {
			type: String,
			default: ""
		},
		startTab: {
			type: String,
			default: "document"
		},
		belowLabel: {
			type: String,
			default: ""
		}
	}, {
		"modelValue": {
			type: Array,
			default: () => []
		},
		"modelModifiers": {}
	}),
	emits: /*@__PURE__*/ mergeModels([
		"save",
		"ready",
		"choose-template",
		"title"
	], ["update:modelValue"]),
	setup(__props, { expose: __expose, emit: __emit }) {
		const tree = useModel(__props, "modelValue");
		const props = __props;
		const emit = __emit;
		const root = ref(null);
		const b = useBuilderState({
			tree,
			props,
			emit,
			root
		});
		const { bodyHidden, loading, failed, loadCatalog, blockErrors, left, right, rightWidth, belowOpen, belowHeight, resize, resizeKey, trail, selected, select, rightTab, nameOf, stats, live, globalKey, onMenuKey } = b;
		const paneTitle = computed(() => ({
			add: __("Add a block"),
			find: __("Find and replace")
		})[left.value] ?? __("Layers"));
		__expose({
			undo: b.undo,
			redo: b.redo,
			canUndo: b.canUndo,
			canRedo: b.canRedo,
			select: b.select
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				ref_key: "root",
				ref: root,
				class: ["k-builder", {
					"has-left": !!unref(left),
					"has-right": unref(right)
				}],
				style: { "--k-right-w": `${unref(rightWidth)}px` }
			}, _attrs))}>`);
			_push(ssrRenderComponent(_sfc_main$51, null, createSlots({ _: 2 }, [_ctx.$slots.start ? {
				name: "start",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) ssrRenderSlot(_ctx.$slots, "start", {}, null, _push, _parent, _scopeId);
					else return [renderSlot(_ctx.$slots, "start")];
				}),
				key: "0"
			} : void 0, _ctx.$slots.end ? {
				name: "end",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) ssrRenderSlot(_ctx.$slots, "end", {}, null, _push, _parent, _scopeId);
					else return [renderSlot(_ctx.$slots, "end")];
				}),
				key: "1"
			} : void 0]), _parent));
			if (unref(blockErrors).length) {
				_push(`<div class="k-builder__errors" role="alert">`);
				_push(ssrRenderComponent(_sfc_main$14, { name: "warning-circle" }, null, _parent));
				_push(`<ul><!--[-->`);
				ssrRenderList(unref(blockErrors), (e, i) => {
					_push(`<li>`);
					if (e.id) _push(`<button type="button" class="k-builder__error-link">${ssrInterpolate(e.name)}</button>`);
					else _push(`<!---->`);
					if (e.id) _push(`<span>: </span>`);
					else _push(`<!---->`);
					_push(`${ssrInterpolate(e.message)}</li>`);
				});
				_push(`<!--]--></ul></div>`);
			} else _push(`<!---->`);
			if (unref(bodyHidden) && __props.kind === "entry") {
				_push(`<div class="k-builder__notice" role="status">`);
				_push(ssrRenderComponent(_sfc_main$14, { name: "info" }, null, _parent));
				_push(`<p>${ssrInterpolate(unref(__)("This page's template has no Entry content block, so these blocks go after everything in the template, for visitors too. Add Entry content to the template to choose where they go."))}</p>`);
				_push(ssrRenderComponent(_sfc_main$6, {
					size: "sm",
					variant: "ghost",
					onClick: ($event) => {
						rightTab.value = "document";
						right.value = true;
						emit("choose-template");
					}
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Choose another template"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Choose another template")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
			} else _push(`<!---->`);
			if (unref(loading)) {
				_push(`<p class="k-builder__state">`);
				_push(ssrRenderComponent(_sfc_main$27, null, null, _parent));
				_push(`${ssrInterpolate(unref(__)("Loading blocks…"))}</p>`);
			} else if (unref(failed)) {
				_push(`<p class="k-builder__state">${ssrInterpolate(unref(__)("The block list could not be loaded."))} `);
				_push(ssrRenderComponent(_sfc_main$6, {
					size: "sm",
					variant: "ghost",
					onClick: unref(loadCatalog)
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Try again"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Try again")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</p>`);
			} else {
				_push(`<div class="k-builder__panes"><aside class="k-builder__left"${ssrRenderAttr("aria-label", paneTitle.value)} style="${ssrRenderStyle(unref(left) ? null : { display: "none" })}"><header class="k-pane__head"><h2 class="k-pane__title">${ssrInterpolate(paneTitle.value)}</h2>`);
				if (unref(left) === "layers") _push(`<span class="k-pane__count">${ssrInterpolate(unref(b).rows.value.length)}</span>`);
				else _push(`<!---->`);
				_push(`<button type="button" class="k-bb k-bb--sm"${ssrRenderAttr("title", unref(__)("Close"))}>`);
				_push(ssrRenderComponent(_sfc_main$14, { name: "x" }, null, _parent));
				_push(`<span class="k-sr-only">${ssrInterpolate(unref(__)("Close"))}</span></button></header>`);
				_push(ssrRenderComponent(_sfc_main$42, { style: unref(left) === "layers" ? null : { display: "none" } }, null, _parent));
				_push(ssrRenderComponent(_sfc_main$41, { style: unref(left) === "add" ? null : { display: "none" } }, null, _parent));
				if (unref(left) === "find") _push(ssrRenderComponent(_sfc_main$43, null, null, _parent));
				else _push(`<!---->`);
				_push(`</aside><div class="k-builder__middle">`);
				_push(ssrRenderComponent(_sfc_main$48, null, null, _parent));
				if (_ctx.$slots.below) {
					_push(`<section class="${ssrRenderClass([{ "is-open": unref(belowOpen) }, "k-builder__below"])}"${ssrRenderAttr("aria-label", __props.belowLabel || unref(__)("More fields"))}>`);
					if (unref(belowOpen)) _push(`<div class="k-resize k-resize--y" role="separator" aria-orientation="horizontal" tabindex="0"${ssrRenderAttr("aria-label", unref(__)("Resize"))}></div>`);
					else _push(`<!---->`);
					_push(`<button type="button" class="k-builder__below-head"${ssrRenderAttr("aria-expanded", String(unref(belowOpen)))}>`);
					_push(ssrRenderComponent(_sfc_main$14, { name: unref(belowOpen) ? "caret-down" : "caret-up" }, null, _parent));
					_push(`${ssrInterpolate(__props.belowLabel || unref(__)("More fields"))}</button><div class="k-builder__below-body" style="${ssrRenderStyle([{ height: `${unref(belowHeight)}px` }, unref(belowOpen) ? null : { display: "none" }])}">`);
					ssrRenderSlot(_ctx.$slots, "below", {}, null, _push, _parent);
					_push(`</div></section>`);
				} else _push(`<!---->`);
				_push(`<footer class="k-builder__foot"><nav class="k-builder__trail"${ssrRenderAttr("aria-label", unref(__)("Where this block is"))}><button type="button" class="k-builder__crumb">${ssrInterpolate(__props.documentLabel || unref(__)("Page"))}</button><!--[-->`);
				ssrRenderList(unref(trail), (n) => {
					_push(`<!--[-->`);
					_push(ssrRenderComponent(_sfc_main$14, {
						name: "caret-right",
						class: "k-builder__crumb-sep"
					}, null, _parent));
					_push(`<button type="button" class="k-builder__crumb"${ssrRenderAttr("aria-current", n.id === unref(selected) ? "true" : void 0)}>${ssrInterpolate(unref(nameOf)(n))}</button><!--]-->`);
				});
				_push(`<!--]--></nav><span class="k-builder__count">${ssrInterpolate(unref(__)("{n} words", { n: unref(stats).words }))}</span></footer></div>`);
				_push(ssrRenderComponent(_sfc_main$44, null, {
					document: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) ssrRenderSlot(_ctx.$slots, "document", {}, null, _push, _parent, _scopeId);
						else return [renderSlot(_ctx.$slots, "document")];
					}),
					_: 3
				}, _parent));
				_push(`</div>`);
			}
			_push(ssrRenderComponent(_sfc_main$38, null, null, _parent));
			_push(ssrRenderComponent(_sfc_main$39, null, null, _parent));
			_push(`<div class="k-sr-only" aria-live="polite" role="status">${ssrInterpolate(unref(live))}</div></div>`);
		};
	}
};
var _sfc_setup = _sfc_main$5.setup;
_sfc_main$5.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/ui/KBuilder.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as KAlert, _sfc_main$1 as KAvatar, _sfc_main$2 as KAvatarCore, _sfc_main$3 as KBadge, _sfc_main$4 as KBlockList, KBlockSettings_default as KBlockSettings, _sfc_main$5 as KBuilder, _sfc_main$6 as KButton, _sfc_main$7 as KCard, _sfc_main$8 as KContentField, _sfc_main$9 as KDialogs, _sfc_main$10 as KEmpty, _sfc_main$11 as KExtension, _sfc_main$12 as KField, _sfc_main$13 as KFinder, _sfc_main$14 as KIcon, _sfc_main$15 as KInput, _sfc_main$16 as KKeyValue, _sfc_main$17 as KMediaGrid, _sfc_main$18 as KMediaPicker, _sfc_main$19 as KModal, _sfc_main$20 as KPageHeader, _sfc_main$21 as KPicker, _sfc_main$22 as KRevision, _sfc_main$23 as KRichText, _sfc_main$24 as KSchemaForm, _sfc_main$25 as KSelect, _sfc_main$26 as KSlot, _sfc_main$27 as KSpinner, _sfc_main$28 as KStat, _sfc_main$29 as KStatusBadge, _sfc_main$30 as KSwitch, _sfc_main$31 as KTable, _sfc_main$32 as KTabs, _sfc_main$33 as KTermSelect, _sfc_main$34 as KTime, _sfc_main$35 as KToasts, _sfc_main$36 as KUploadZone, _sfc_main$37 as KWhen };
