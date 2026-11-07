import { t as _sfc_main$1 } from "../../chunks/InstallerLayout-7a6f5LEx.mjs";
import { t as _sfc_main$2 } from "../../chunks/CsrfField-DaiFH5aw.mjs";
import { t as _sfc_main$3 } from "../../chunks/PasswordInput-Ci59Qe3A.mjs";
import { createBlock, createCommentVNode, createTextVNode, createVNode, mergeProps, openBlock, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { KAlert } from "@kestello/ui";
// core/resources/js/pages/installer/Admin.vue
var _sfc_main = {
	__name: "Admin",
	__ssrInlineRender: true,
	props: {
		step: Number,
		error: {
			type: String,
			default: null
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				step: __props.step,
				title: "Administrator account",
				description: "This account can do everything. You can add other people with smaller roles later."
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form method="POST" action="/install" class="k-auth__form"${_scopeId}>`);
						_push(ssrRenderComponent(_sfc_main$2, null, null, _parent, _scopeId));
						_push(`<input type="hidden" name="step" value="4"${_scopeId}>`);
						if (__props.error) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(__props.error)}`);
								else return [createTextVNode(toDisplayString(__props.error), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(`<div class="k-install__row"${_scopeId}><div class="k-field"${_scopeId}><label class="k-field__label" for="admin_username"${_scopeId}>Username</label><input id="admin_username" type="text" name="admin_username" class="k-input" required minlength="3" autocomplete="username" autocapitalize="off" spellcheck="false"${_scopeId}></div><div class="k-field"${_scopeId}><label class="k-field__label" for="admin_email"${_scopeId}>Email address</label><input id="admin_email" type="email" name="admin_email" class="k-input" required autocomplete="email"${_scopeId}></div></div><div class="k-field"${_scopeId}><label class="k-field__label" for="admin_password"${_scopeId}>Password</label>`);
						_push(ssrRenderComponent(_sfc_main$3, {
							id: "admin_password",
							name: "admin_password",
							required: "",
							minlength: "12",
							autocomplete: "new-password",
							"aria-describedby": "admin_password_help"
						}, null, _parent, _scopeId));
						_push(`<p id="admin_password_help" class="k-field__help"${_scopeId}> At least 12 characters. Common passwords are refused. A few unrelated words make a strong password you can remember. </p></div><div class="k-field"${_scopeId}><label class="k-field__label" for="admin_password_confirm"${_scopeId}>Repeat the password</label>`);
						_push(ssrRenderComponent(_sfc_main$3, {
							id: "admin_password_confirm",
							name: "admin_password_confirm",
							required: "",
							autocomplete: "new-password"
						}, null, _parent, _scopeId));
						_push(`</div><div class="k-install__actions"${_scopeId}><a href="/install?step=3" class="k-btn k-btn--secondary k-btn--md"${_scopeId}>Back</a><button type="submit" class="k-btn k-btn--primary k-btn--md"${_scopeId}>Continue</button></div></form>`);
					} else return [createVNode("form", {
						method: "POST",
						action: "/install",
						class: "k-auth__form"
					}, [
						createVNode(_sfc_main$2),
						createVNode("input", {
							type: "hidden",
							name: "step",
							value: "4"
						}),
						__props.error ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "danger"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(__props.error), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true),
						createVNode("div", { class: "k-install__row" }, [createVNode("div", { class: "k-field" }, [createVNode("label", {
							class: "k-field__label",
							for: "admin_username"
						}, "Username"), createVNode("input", {
							id: "admin_username",
							type: "text",
							name: "admin_username",
							class: "k-input",
							required: "",
							minlength: "3",
							autocomplete: "username",
							autocapitalize: "off",
							spellcheck: "false"
						})]), createVNode("div", { class: "k-field" }, [createVNode("label", {
							class: "k-field__label",
							for: "admin_email"
						}, "Email address"), createVNode("input", {
							id: "admin_email",
							type: "email",
							name: "admin_email",
							class: "k-input",
							required: "",
							autocomplete: "email"
						})])]),
						createVNode("div", { class: "k-field" }, [
							createVNode("label", {
								class: "k-field__label",
								for: "admin_password"
							}, "Password"),
							createVNode(_sfc_main$3, {
								id: "admin_password",
								name: "admin_password",
								required: "",
								minlength: "12",
								autocomplete: "new-password",
								"aria-describedby": "admin_password_help"
							}),
							createVNode("p", {
								id: "admin_password_help",
								class: "k-field__help"
							}, " At least 12 characters. Common passwords are refused. A few unrelated words make a strong password you can remember. ")
						]),
						createVNode("div", { class: "k-field" }, [createVNode("label", {
							class: "k-field__label",
							for: "admin_password_confirm"
						}, "Repeat the password"), createVNode(_sfc_main$3, {
							id: "admin_password_confirm",
							name: "admin_password_confirm",
							required: "",
							autocomplete: "new-password"
						})]),
						createVNode("div", { class: "k-install__actions" }, [createVNode("a", {
							href: "/install?step=3",
							class: "k-btn k-btn--secondary k-btn--md"
						}, "Back"), createVNode("button", {
							type: "submit",
							class: "k-btn k-btn--primary k-btn--md"
						}, "Continue")])
					])];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/installer/Admin.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
