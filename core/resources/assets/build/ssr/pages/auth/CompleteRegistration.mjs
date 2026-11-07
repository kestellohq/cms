import { n as _sfc_main$1, t as _sfc_main$2 } from "../../chunks/FormErrors-InYWsRvI.mjs";
import { t as _sfc_main$3 } from "../../chunks/PasswordInput-Ci59Qe3A.mjs";
import { Fragment, createBlock, createCommentVNode, createTextVNode, createVNode, mergeProps, openBlock, toDisplayString, unref, useSSRContext, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { Link, useForm } from "@kestello/app";
import { __ } from "@kestello/sdk";
import { KAlert, KButton, KField, KInput } from "@kestello/ui";
// core/resources/js/pages/auth/CompleteRegistration.vue
var _sfc_main = {
	__name: "CompleteRegistration",
	__ssrInlineRender: true,
	props: {
		token: {
			type: String,
			default: ""
		},
		email: {
			type: String,
			default: ""
		},
		valid: {
			type: Boolean,
			default: false
		},
		minLength: {
			type: Number,
			default: 12
		}
	},
	setup(__props) {
		const form = useForm({
			token: __props.token,
			password: "",
			password_confirmation: ""
		});
		function submit() {
			form.post("/register/complete", { onFinish: () => form.reset("password", "password_confirmation") });
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({ title: unref(__)("Choose your password") }, _attrs), {
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
						if (__props.valid) {
							_push(`<!--[-->`);
							_push(ssrRenderComponent(_sfc_main$2, {
								errors: unref(form).errors,
								fields: ["password", "password_confirmation"]
							}, null, _parent, _scopeId));
							_push(`<form class="k-auth__form" novalidate${_scopeId}><!-- Shown, and named "username", so password managers save the password for this address. -->`);
							_push(ssrRenderComponent(unref(KField), { label: unref(__)("Email address") }, {
								default: withCtx(({ id }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(unref(KInput), {
										id,
										"model-value": __props.email,
										type: "email",
										autocomplete: "username",
										readonly: ""
									}, null, _parent, _scopeId));
									else return [createVNode(unref(KInput), {
										id,
										"model-value": __props.email,
										type: "email",
										autocomplete: "username",
										readonly: ""
									}, null, 8, ["id", "model-value"])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Password"),
								help: unref(__)("At least {n} characters. A few unrelated words make a strong password you can remember.", { n: __props.minLength }),
								error: unref(form).errors.password
							}, {
								default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(_sfc_main$3, {
										id,
										modelValue: unref(form).password,
										"onUpdate:modelValue": ($event) => unref(form).password = $event,
										invalid,
										"aria-describedby": describedBy,
										minlength: __props.minLength,
										autocomplete: "new-password",
										required: "",
										autofocus: ""
									}, null, _parent, _scopeId));
									else return [createVNode(_sfc_main$3, {
										id,
										modelValue: unref(form).password,
										"onUpdate:modelValue": ($event) => unref(form).password = $event,
										invalid,
										"aria-describedby": describedBy,
										minlength: __props.minLength,
										autocomplete: "new-password",
										required: "",
										autofocus: ""
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"invalid",
										"aria-describedby",
										"minlength"
									])];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KField), {
								label: unref(__)("Repeat password"),
								error: unref(form).errors.password_confirmation
							}, {
								default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
									if (_push) _push(ssrRenderComponent(_sfc_main$3, {
										id,
										modelValue: unref(form).password_confirmation,
										"onUpdate:modelValue": ($event) => unref(form).password_confirmation = $event,
										invalid,
										"aria-describedby": describedBy,
										autocomplete: "new-password",
										required: ""
									}, null, _parent, _scopeId));
									else return [createVNode(_sfc_main$3, {
										id,
										modelValue: unref(form).password_confirmation,
										"onUpdate:modelValue": ($event) => unref(form).password_confirmation = $event,
										invalid,
										"aria-describedby": describedBy,
										autocomplete: "new-password",
										required: ""
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
								icon: "check",
								block: "",
								loading: unref(form).processing
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Create account"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Create account")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</form><!--]-->`);
						} else {
							_push(`<!--[-->`);
							_push(ssrRenderComponent(_sfc_main$2, { errors: unref(form).errors }, null, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KAlert), { tone: "warning" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("This link is invalid or has expired."))}`);
									else return [createTextVNode(toDisplayString(unref(__)("This link is invalid or has expired.")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KButton), {
								href: "/register",
								variant: "primary",
								icon: "paper-plane-tilt",
								block: ""
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Get a new link"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Get a new link")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`<!--]-->`);
						}
					} else return [__props.valid ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode(_sfc_main$2, {
						errors: unref(form).errors,
						fields: ["password", "password_confirmation"]
					}, null, 8, ["errors"]), createVNode("form", {
						class: "k-auth__form",
						novalidate: "",
						onSubmit: withModifiers(submit, ["prevent"])
					}, [
						createCommentVNode(" Shown, and named \"username\", so password managers save the password for this address. "),
						createVNode(unref(KField), { label: unref(__)("Email address") }, {
							default: withCtx(({ id }) => [createVNode(unref(KInput), {
								id,
								"model-value": __props.email,
								type: "email",
								autocomplete: "username",
								readonly: ""
							}, null, 8, ["id", "model-value"])]),
							_: 1
						}, 8, ["label"]),
						createVNode(unref(KField), {
							label: unref(__)("Password"),
							help: unref(__)("At least {n} characters. A few unrelated words make a strong password you can remember.", { n: __props.minLength }),
							error: unref(form).errors.password
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(_sfc_main$3, {
								id,
								modelValue: unref(form).password,
								"onUpdate:modelValue": ($event) => unref(form).password = $event,
								invalid,
								"aria-describedby": describedBy,
								minlength: __props.minLength,
								autocomplete: "new-password",
								required: "",
								autofocus: ""
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"invalid",
								"aria-describedby",
								"minlength"
							])]),
							_: 1
						}, 8, [
							"label",
							"help",
							"error"
						]),
						createVNode(unref(KField), {
							label: unref(__)("Repeat password"),
							error: unref(form).errors.password_confirmation
						}, {
							default: withCtx(({ id, describedBy, invalid }) => [createVNode(_sfc_main$3, {
								id,
								modelValue: unref(form).password_confirmation,
								"onUpdate:modelValue": ($event) => unref(form).password_confirmation = $event,
								invalid,
								"aria-describedby": describedBy,
								autocomplete: "new-password",
								required: ""
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"invalid",
								"aria-describedby"
							])]),
							_: 1
						}, 8, ["label", "error"]),
						createVNode(unref(KButton), {
							type: "submit",
							variant: "primary",
							icon: "check",
							block: "",
							loading: unref(form).processing
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Create account")), 1)]),
							_: 1
						}, 8, ["loading"])
					], 32)], 64)) : (openBlock(), createBlock(Fragment, { key: 1 }, [
						createVNode(_sfc_main$2, { errors: unref(form).errors }, null, 8, ["errors"]),
						createVNode(unref(KAlert), { tone: "warning" }, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("This link is invalid or has expired.")), 1)]),
							_: 1
						}),
						createVNode(unref(KButton), {
							href: "/register",
							variant: "primary",
							icon: "paper-plane-tilt",
							block: ""
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Get a new link")), 1)]),
							_: 1
						})
					], 64))];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/auth/CompleteRegistration.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
