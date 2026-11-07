import { n as _sfc_main$1, t as _sfc_main$2 } from "../../chunks/FormErrors-BKXF8p1e.mjs";
import { createBlock, createTextVNode, createVNode, mergeProps, openBlock, ref, toDisplayString, unref, useSSRContext, vModelText, withCtx, withDirectives, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent } from "vue/server-renderer";
import { Link, useForm } from "@kestello/app";
import { __ } from "@kestello/sdk";
import { KButton, KField } from "@kestello/ui";
// core/resources/js/pages/auth/TwoFactorChallenge.vue
var _sfc_main = {
	__name: "TwoFactorChallenge",
	__ssrInlineRender: true,
	setup(__props) {
		const recovery = ref(false);
		const form = useForm({ code: "" });
		function submit() {
			form.post("/login/two-factor", { onFinish: () => form.reset("code") });
		}
		function toggle() {
			recovery.value = !recovery.value;
			form.reset("code");
			form.clearErrors();
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				title: unref(__)("Two-step sign-in"),
				description: recovery.value ? unref(__)("Enter one of your recovery codes. Each code works once.") : unref(__)("Enter the six-digit code from your authenticator app."),
				code: "K-04"
			}, _attrs), {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(Link), {
						href: "/login",
						class: "k-link"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Back to sign in"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Back to sign in")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(Link), {
						href: "/login",
						class: "k-link"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Back to sign in")), 1)]),
						_: 1
					})];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(_sfc_main$2, {
							errors: unref(form).errors,
							fields: ["code"]
						}, null, _parent, _scopeId));
						_push(`<form class="k-auth__form" novalidate${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: recovery.value ? unref(__)("Recovery code") : unref(__)("Code"),
							error: unref(form).errors.code
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) {
									if (!recovery.value) _push(`<input${ssrRenderAttr("id", id)}${ssrRenderAttr("value", unref(form).code)} class="k-input k-auth__code"${ssrRenderAttr("aria-invalid", invalid ? "true" : void 0)}${ssrRenderAttr("aria-describedby", describedBy)} inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus${_scopeId}>`);
									else _push(`<input${ssrRenderAttr("id", id)}${ssrRenderAttr("value", unref(form).code)} class="k-input k-auth__code is-recovery"${ssrRenderAttr("aria-invalid", invalid ? "true" : void 0)}${ssrRenderAttr("aria-describedby", describedBy)} maxlength="11" autocomplete="off" autocapitalize="characters" spellcheck="false" required autofocus${_scopeId}>`);
								} else return [!recovery.value ? withDirectives((openBlock(), createBlock("input", {
									key: 0,
									id,
									"onUpdate:modelValue": ($event) => unref(form).code = $event,
									class: "k-input k-auth__code",
									"aria-invalid": invalid ? "true" : void 0,
									"aria-describedby": describedBy,
									inputmode: "numeric",
									pattern: "[0-9 ]*",
									maxlength: "7",
									autocomplete: "one-time-code",
									required: "",
									autofocus: ""
								}, null, 8, [
									"id",
									"onUpdate:modelValue",
									"aria-invalid",
									"aria-describedby"
								])), [[vModelText, unref(form).code]]) : withDirectives((openBlock(), createBlock("input", {
									key: 1,
									id,
									"onUpdate:modelValue": ($event) => unref(form).code = $event,
									class: "k-input k-auth__code is-recovery",
									"aria-invalid": invalid ? "true" : void 0,
									"aria-describedby": describedBy,
									maxlength: "11",
									autocomplete: "off",
									autocapitalize: "characters",
									spellcheck: "false",
									required: "",
									autofocus: ""
								}, null, 8, [
									"id",
									"onUpdate:modelValue",
									"aria-invalid",
									"aria-describedby"
								])), [[vModelText, unref(form).code]])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							variant: "primary",
							icon: "shield-check",
							block: "",
							loading: unref(form).processing
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Verify"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Verify")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`<button type="button" class="k-link-btn k-auth__aside"${_scopeId}>${ssrInterpolate(recovery.value ? unref(__)("Use the authenticator app instead") : unref(__)("Use a recovery code"))}</button></form>`);
					} else return [createVNode(_sfc_main$2, {
						errors: unref(form).errors,
						fields: ["code"]
					}, null, 8, ["errors"]), createVNode("form", {
						class: "k-auth__form",
						novalidate: "",
						onSubmit: withModifiers(submit, ["prevent"])
					}, [
						createVNode(unref(KField), {
							label: recovery.value ? unref(__)("Recovery code") : unref(__)("Code"),
							error: unref(form).errors.code
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [!recovery.value ? withDirectives((openBlock(), createBlock("input", {
								key: 0,
								id,
								"onUpdate:modelValue": ($event) => unref(form).code = $event,
								class: "k-input k-auth__code",
								"aria-invalid": invalid ? "true" : void 0,
								"aria-describedby": describedBy,
								inputmode: "numeric",
								pattern: "[0-9 ]*",
								maxlength: "7",
								autocomplete: "one-time-code",
								required: "",
								autofocus: ""
							}, null, 8, [
								"id",
								"onUpdate:modelValue",
								"aria-invalid",
								"aria-describedby"
							])), [[vModelText, unref(form).code]]) : withDirectives((openBlock(), createBlock("input", {
								key: 1,
								id,
								"onUpdate:modelValue": ($event) => unref(form).code = $event,
								class: "k-input k-auth__code is-recovery",
								"aria-invalid": invalid ? "true" : void 0,
								"aria-describedby": describedBy,
								maxlength: "11",
								autocomplete: "off",
								autocapitalize: "characters",
								spellcheck: "false",
								required: "",
								autofocus: ""
							}, null, 8, [
								"id",
								"onUpdate:modelValue",
								"aria-invalid",
								"aria-describedby"
							])), [[vModelText, unref(form).code]])]),
							_: 1
						}, 8, ["label", "error"]),
						createVNode(unref(KButton), {
							type: "submit",
							variant: "primary",
							icon: "shield-check",
							block: "",
							loading: unref(form).processing
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Verify")), 1)]),
							_: 1
						}, 8, ["loading"]),
						createVNode("button", {
							type: "button",
							class: "k-link-btn k-auth__aside",
							onClick: toggle
						}, toDisplayString(recovery.value ? unref(__)("Use the authenticator app instead") : unref(__)("Use a recovery code")), 1)
					], 32)];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/auth/TwoFactorChallenge.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
