import { n as _sfc_main$1, t as _sfc_main$2 } from "../../chunks/FormErrors-InYWsRvI.mjs";
import { createTextVNode, createVNode, mergeProps, toDisplayString, unref, useSSRContext, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { Link, useForm, usePage } from "@kestello/app";
import { __ } from "@kestello/sdk";
import { KButton, KField, KInput } from "@kestello/ui";
// core/resources/js/pages/auth/ForgotPassword.vue
var _sfc_main = {
	__name: "ForgotPassword",
	__ssrInlineRender: true,
	setup(__props) {
		const page = usePage();
		const form = useForm({ email: page.props.old?.email ?? "" });
		function submit() {
			form.post("/forgot-password");
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				title: unref(__)("Reset your password"),
				description: unref(__)("Enter the email address of your account. We will send you a link to choose a new password.")
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
							fields: ["email"]
						}, null, _parent, _scopeId));
						_push(`<form class="k-auth__form" novalidate${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Email address"),
							error: unref(form).errors.email
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: unref(form).email,
									"onUpdate:modelValue": ($event) => unref(form).email = $event,
									type: "email",
									invalid,
									"aria-describedby": describedBy,
									autocomplete: "email",
									required: "",
									autofocus: ""
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: unref(form).email,
									"onUpdate:modelValue": ($event) => unref(form).email = $event,
									type: "email",
									invalid,
									"aria-describedby": describedBy,
									autocomplete: "email",
									required: "",
									autofocus: ""
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
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							variant: "primary",
							icon: "paper-plane-tilt",
							block: "",
							loading: unref(form).processing
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Send reset link"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Send reset link")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</form>`);
					} else return [createVNode(_sfc_main$2, {
						errors: unref(form).errors,
						fields: ["email"]
					}, null, 8, ["errors"]), createVNode("form", {
						class: "k-auth__form",
						novalidate: "",
						onSubmit: withModifiers(submit, ["prevent"])
					}, [createVNode(unref(KField), {
						label: unref(__)("Email address"),
						error: unref(form).errors.email
					}, {
						default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
							id,
							modelValue: unref(form).email,
							"onUpdate:modelValue": ($event) => unref(form).email = $event,
							type: "email",
							invalid,
							"aria-describedby": describedBy,
							autocomplete: "email",
							required: "",
							autofocus: ""
						}, null, 8, [
							"id",
							"modelValue",
							"onUpdate:modelValue",
							"invalid",
							"aria-describedby"
						])]),
						_: 1
					}, 8, ["label", "error"]), createVNode(unref(KButton), {
						type: "submit",
						variant: "primary",
						icon: "paper-plane-tilt",
						block: "",
						loading: unref(form).processing
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Send reset link")), 1)]),
						_: 1
					}, 8, ["loading"])], 32)];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/auth/ForgotPassword.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
