import { n as _sfc_main$1, t as _sfc_main$2 } from "../../chunks/FormErrors-InYWsRvI.mjs";
import { t as _sfc_main$3 } from "../../chunks/PasswordInput-Ci59Qe3A.mjs";
import { Fragment, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, mergeProps, openBlock, renderList, toDisplayString, unref, useSSRContext, withCtx, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Link, useForm, usePage } from "@kestello/app";
import { __ } from "@kestello/sdk";
import { KAlert, KButton, KField, KIcon, KInput } from "@kestello/ui";
// core/resources/js/pages/auth/Login.vue
var _sfc_main = {
	__name: "Login",
	__ssrInlineRender: true,
	setup(__props) {
		const page = usePage();
		const demo = page.props.demo ?? null;
		const form = useForm({
			email: page.props.old?.email ?? demo?.username ?? "",
			password: demo?.password ?? ""
		});
		const providers = page.props.providers ?? [];
		const canRegister = page.props.canRegister === true;
		const site = page.props.site?.name || "Kestello";
		const iconPath = (icon) => typeof icon === "string" && /^\/assets\/[\w./-]+\.(svg|png)$/.test(icon) ? icon : null;
		const iconName = (icon) => typeof icon === "string" && /^[a-z0-9-]+$/.test(icon) ? icon : null;
		function submit() {
			form.post("/login", { onFinish: () => form.reset("password") });
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				title: unref(__)("Sign in"),
				description: unref(__)("Use your email address or username.")
			}, _attrs), createSlots({
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(_sfc_main$2, {
							errors: unref(form).errors,
							fields: ["email", "password"]
						}, null, _parent, _scopeId));
						if (unref(demo)) _push(ssrRenderComponent(unref(KAlert), {
							tone: "info",
							title: unref(__)("This is a demo")
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Sign in as {username} with the password {password}. Everyone shares this account, and the site resets every hour.", {
									username: unref(demo).username,
									password: unref(demo).password
								}))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Sign in as {username} with the password {password}. Everyone shares this account, and the site resets every hour.", {
									username: unref(demo).username,
									password: unref(demo).password
								})), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(`<form class="k-auth__form" novalidate${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Email or username"),
							error: unref(form).errors.email
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: unref(form).email,
									"onUpdate:modelValue": ($event) => unref(form).email = $event,
									invalid,
									"aria-describedby": describedBy,
									autocomplete: "username",
									autocapitalize: "off",
									spellcheck: "false",
									required: "",
									autofocus: ""
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: unref(form).email,
									"onUpdate:modelValue": ($event) => unref(form).email = $event,
									invalid,
									"aria-describedby": describedBy,
									autocomplete: "username",
									autocapitalize: "off",
									spellcheck: "false",
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
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Password"),
							error: unref(form).errors.password
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(_sfc_main$3, {
									id,
									modelValue: unref(form).password,
									"onUpdate:modelValue": ($event) => unref(form).password = $event,
									invalid,
									"aria-describedby": describedBy,
									autocomplete: "current-password",
									required: ""
								}, null, _parent, _scopeId));
								else return [createVNode(_sfc_main$3, {
									id,
									modelValue: unref(form).password,
									"onUpdate:modelValue": ($event) => unref(form).password = $event,
									invalid,
									"aria-describedby": describedBy,
									autocomplete: "current-password",
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
							icon: "sign-in",
							block: "",
							loading: unref(form).processing
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Sign in"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Sign in")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						if (!unref(demo)) _push(ssrRenderComponent(unref(Link), {
							href: "/forgot-password",
							class: "k-link k-auth__aside"
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Forgot your password?"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Forgot your password?")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(`</form>`);
						if (unref(providers).length) {
							_push(`<!--[--><p class="k-auth__or"${_scopeId}><span${_scopeId}>${ssrInterpolate(unref(__)("or"))}</span></p><div class="k-auth__providers"${_scopeId}><!-- Plain links: the provider redirect is a full page navigation. --><!--[-->`);
							ssrRenderList(unref(providers), (p) => {
								_push(`<a${ssrRenderAttr("href", p.url)} class="k-btn k-btn--secondary k-btn--md k-btn--block"${_scopeId}>`);
								if (iconPath(p.icon)) _push(`<img${ssrRenderAttr("src", iconPath(p.icon))} alt="" width="18" height="18"${_scopeId}>`);
								else if (iconName(p.icon)) _push(ssrRenderComponent(unref(KIcon), { name: iconName(p.icon) }, null, _parent, _scopeId));
								else _push(`<!---->`);
								_push(`<span${_scopeId}>${ssrInterpolate(unref(__)("Continue with {provider}", { provider: p.label }))}</span></a>`);
							});
							_push(`<!--]--></div><!--]-->`);
						} else _push(`<!---->`);
					} else return [
						createVNode(_sfc_main$2, {
							errors: unref(form).errors,
							fields: ["email", "password"]
						}, null, 8, ["errors"]),
						unref(demo) ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "info",
							title: unref(__)("This is a demo")
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Sign in as {username} with the password {password}. Everyone shares this account, and the site resets every hour.", {
								username: unref(demo).username,
								password: unref(demo).password
							})), 1)]),
							_: 1
						}, 8, ["title"])) : createCommentVNode("v-if", true),
						createVNode("form", {
							class: "k-auth__form",
							novalidate: "",
							onSubmit: withModifiers(submit, ["prevent"])
						}, [
							createVNode(unref(KField), {
								label: unref(__)("Email or username"),
								error: unref(form).errors.email
							}, {
								default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
									id,
									modelValue: unref(form).email,
									"onUpdate:modelValue": ($event) => unref(form).email = $event,
									invalid,
									"aria-describedby": describedBy,
									autocomplete: "username",
									autocapitalize: "off",
									spellcheck: "false",
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
							}, 8, ["label", "error"]),
							createVNode(unref(KField), {
								label: unref(__)("Password"),
								error: unref(form).errors.password
							}, {
								default: withCtx(({ id, describedBy, invalid }) => [createVNode(_sfc_main$3, {
									id,
									modelValue: unref(form).password,
									"onUpdate:modelValue": ($event) => unref(form).password = $event,
									invalid,
									"aria-describedby": describedBy,
									autocomplete: "current-password",
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
								icon: "sign-in",
								block: "",
								loading: unref(form).processing
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Sign in")), 1)]),
								_: 1
							}, 8, ["loading"]),
							!unref(demo) ? (openBlock(), createBlock(unref(Link), {
								key: 0,
								href: "/forgot-password",
								class: "k-link k-auth__aside"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Forgot your password?")), 1)]),
								_: 1
							})) : createCommentVNode("v-if", true)
						], 32),
						unref(providers).length ? (openBlock(), createBlock(Fragment, { key: 1 }, [createVNode("p", { class: "k-auth__or" }, [createVNode("span", null, toDisplayString(unref(__)("or")), 1)]), createVNode("div", { class: "k-auth__providers" }, [createCommentVNode(" Plain links: the provider redirect is a full page navigation. "), (openBlock(true), createBlock(Fragment, null, renderList(unref(providers), (p) => {
							return openBlock(), createBlock("a", {
								key: p.id,
								href: p.url,
								class: "k-btn k-btn--secondary k-btn--md k-btn--block"
							}, [iconPath(p.icon) ? (openBlock(), createBlock("img", {
								key: 0,
								src: iconPath(p.icon),
								alt: "",
								width: "18",
								height: "18"
							}, null, 8, ["src"])) : iconName(p.icon) ? (openBlock(), createBlock(unref(KIcon), {
								key: 1,
								name: iconName(p.icon)
							}, null, 8, ["name"])) : createCommentVNode("v-if", true), createVNode("span", null, toDisplayString(unref(__)("Continue with {provider}", { provider: p.label })), 1)], 8, ["href"]);
						}), 128))])], 64)) : createCommentVNode("v-if", true)
					];
				}),
				_: 2
			}, [canRegister ? {
				name: "footer",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(Link), {
							href: "/",
							class: "k-link"
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Back to {site}", { site: unref(site) }))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Back to {site}", { site: unref(site) })), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(Link), {
							href: "/register",
							class: "k-link"
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Create an account"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Create an account")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(Link), {
						href: "/",
						class: "k-link"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Back to {site}", { site: unref(site) })), 1)]),
						_: 1
					}), createVNode(unref(Link), {
						href: "/register",
						class: "k-link"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Create an account")), 1)]),
						_: 1
					})];
				}),
				key: "0"
			} : void 0]), _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/auth/Login.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
