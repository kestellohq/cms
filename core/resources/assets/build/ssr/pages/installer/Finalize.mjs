import { t as _sfc_main$1 } from "../../chunks/InstallerLayout-7a6f5LEx.mjs";
import { t as _sfc_main$2 } from "../../chunks/CsrfField-DaiFH5aw.mjs";
import { createBlock, createCommentVNode, createTextVNode, createVNode, mergeProps, openBlock, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { KAlert } from "@kestello/ui";
// core/resources/js/pages/installer/Finalize.vue
var _sfc_main = {
	__name: "Finalize",
	__ssrInlineRender: true,
	props: {
		step: Number,
		summary: {
			type: Object,
			required: true
		},
		error: {
			type: String,
			default: null
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				step: __props.step,
				title: "Ready to install",
				description: "Check the details below. Installing creates the database tables and your administrator account."
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form method="POST" action="/install" class="k-auth__form"${_scopeId}>`);
						_push(ssrRenderComponent(_sfc_main$2, null, null, _parent, _scopeId));
						_push(`<input type="hidden" name="step" value="5"${_scopeId}>`);
						if (__props.error) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(__props.error)}`);
								else return [createTextVNode(toDisplayString(__props.error), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(`<dl class="k-install__summary"${_scopeId}><div${_scopeId}><dt${_scopeId}>Site name</dt><dd${_scopeId}>${ssrInterpolate(__props.summary.site_name)}</dd></div><div${_scopeId}><dt${_scopeId}>Database</dt><dd${_scopeId}>${ssrInterpolate(__props.summary.db_driver)}</dd></div><div${_scopeId}><dt${_scopeId}>Location</dt><dd${_scopeId}><code${_scopeId}>${ssrInterpolate(__props.summary.db_host)}</code></dd></div><div${_scopeId}><dt${_scopeId}>Database name</dt><dd${_scopeId}><code${_scopeId}>${ssrInterpolate(__props.summary.db_name)}</code></dd></div><div${_scopeId}><dt${_scopeId}>Administrator</dt><dd${_scopeId}>${ssrInterpolate(__props.summary.admin_username)}</dd></div><div${_scopeId}><dt${_scopeId}>Administrator email</dt><dd${_scopeId}>${ssrInterpolate(__props.summary.admin_email)}</dd></div></dl><div class="k-install__actions"${_scopeId}><a href="/install?step=4" class="k-btn k-btn--secondary k-btn--md"${_scopeId}>Back</a><button type="submit" class="k-btn k-btn--primary k-btn--md"${_scopeId}>Install Kestello</button></div></form>`);
					} else return [createVNode("form", {
						method: "POST",
						action: "/install",
						class: "k-auth__form"
					}, [
						createVNode(_sfc_main$2),
						createVNode("input", {
							type: "hidden",
							name: "step",
							value: "5"
						}),
						__props.error ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "danger"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(__props.error), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true),
						createVNode("dl", { class: "k-install__summary" }, [
							createVNode("div", null, [createVNode("dt", null, "Site name"), createVNode("dd", null, toDisplayString(__props.summary.site_name), 1)]),
							createVNode("div", null, [createVNode("dt", null, "Database"), createVNode("dd", null, toDisplayString(__props.summary.db_driver), 1)]),
							createVNode("div", null, [createVNode("dt", null, "Location"), createVNode("dd", null, [createVNode("code", null, toDisplayString(__props.summary.db_host), 1)])]),
							createVNode("div", null, [createVNode("dt", null, "Database name"), createVNode("dd", null, [createVNode("code", null, toDisplayString(__props.summary.db_name), 1)])]),
							createVNode("div", null, [createVNode("dt", null, "Administrator"), createVNode("dd", null, toDisplayString(__props.summary.admin_username), 1)]),
							createVNode("div", null, [createVNode("dt", null, "Administrator email"), createVNode("dd", null, toDisplayString(__props.summary.admin_email), 1)])
						]),
						createVNode("div", { class: "k-install__actions" }, [createVNode("a", {
							href: "/install?step=4",
							class: "k-btn k-btn--secondary k-btn--md"
						}, "Back"), createVNode("button", {
							type: "submit",
							class: "k-btn k-btn--primary k-btn--md"
						}, "Install Kestello")])
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/installer/Finalize.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
