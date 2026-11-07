import { t as _sfc_main$1 } from "../../chunks/InstallerLayout-puuck5dd.mjs";
import { t as _sfc_main$2 } from "../../chunks/CsrfField-DaiFH5aw.mjs";
import { createBlock, createCommentVNode, createTextVNode, createVNode, mergeProps, openBlock, ref, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderComponent } from "vue/server-renderer";
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
		const installing = ref(false);
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				step: __props.step,
				title: "Ready to install",
				description: "Check the details. Installing creates the database tables and your administrator account, which takes a few seconds."
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form method="POST" action="/install" class="k-setup__form"${_scopeId}>`);
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
						_push(`<dl class="k-setup__summary"${_scopeId}><div${_scopeId}><dt${_scopeId}>Site</dt><dd${_scopeId}>${ssrInterpolate(__props.summary.site_name)} <a href="/install?step=3" class="k-link k-small"${_scopeId}>Change</a></dd></div><div${_scopeId}><dt${_scopeId}>Database</dt><dd${_scopeId}>${ssrInterpolate(__props.summary.db_driver)} <a href="/install?step=2" class="k-link k-small"${_scopeId}>Change</a></dd></div><div${_scopeId}><dt${_scopeId}>${ssrInterpolate(__props.summary.db_driver === "SQLite" ? "File" : "Host")}</dt><dd${_scopeId}><code${_scopeId}>${ssrInterpolate(__props.summary.db_host)}</code></dd></div>`);
						if (__props.summary.db_driver !== "SQLite") _push(`<div${_scopeId}><dt${_scopeId}>Database name</dt><dd${_scopeId}><code${_scopeId}>${ssrInterpolate(__props.summary.db_name)}</code></dd></div>`);
						else _push(`<!---->`);
						_push(`<div${_scopeId}><dt${_scopeId}>Administrator</dt><dd${_scopeId}>${ssrInterpolate(__props.summary.admin_username)} <a href="/install?step=4" class="k-link k-small"${_scopeId}>Change</a></dd></div><div${_scopeId}><dt${_scopeId}>Administrator email</dt><dd${_scopeId}>${ssrInterpolate(__props.summary.admin_email)}</dd></div></dl><div class="k-setup__actions"${_scopeId}><a href="/install?step=4" class="k-btn k-btn--secondary k-btn--md"${_scopeId}>Back</a><button type="submit" class="k-btn k-btn--primary k-btn--md"${ssrIncludeBooleanAttr(installing.value) ? " disabled" : ""}${ssrRenderAttr("aria-busy", installing.value)}${_scopeId}>${ssrInterpolate(installing.value ? "Installing…" : "Install Kestello")}</button></div></form>`);
					} else return [createVNode("form", {
						method: "POST",
						action: "/install",
						class: "k-setup__form",
						onSubmit: ($event) => installing.value = true
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
						createVNode("dl", { class: "k-setup__summary" }, [
							createVNode("div", null, [createVNode("dt", null, "Site"), createVNode("dd", null, [createTextVNode(toDisplayString(__props.summary.site_name) + " ", 1), createVNode("a", {
								href: "/install?step=3",
								class: "k-link k-small"
							}, "Change")])]),
							createVNode("div", null, [createVNode("dt", null, "Database"), createVNode("dd", null, [createTextVNode(toDisplayString(__props.summary.db_driver) + " ", 1), createVNode("a", {
								href: "/install?step=2",
								class: "k-link k-small"
							}, "Change")])]),
							createVNode("div", null, [createVNode("dt", null, toDisplayString(__props.summary.db_driver === "SQLite" ? "File" : "Host"), 1), createVNode("dd", null, [createVNode("code", null, toDisplayString(__props.summary.db_host), 1)])]),
							__props.summary.db_driver !== "SQLite" ? (openBlock(), createBlock("div", { key: 0 }, [createVNode("dt", null, "Database name"), createVNode("dd", null, [createVNode("code", null, toDisplayString(__props.summary.db_name), 1)])])) : createCommentVNode("v-if", true),
							createVNode("div", null, [createVNode("dt", null, "Administrator"), createVNode("dd", null, [createTextVNode(toDisplayString(__props.summary.admin_username) + " ", 1), createVNode("a", {
								href: "/install?step=4",
								class: "k-link k-small"
							}, "Change")])]),
							createVNode("div", null, [createVNode("dt", null, "Administrator email"), createVNode("dd", null, toDisplayString(__props.summary.admin_email), 1)])
						]),
						createVNode("div", { class: "k-setup__actions" }, [createVNode("a", {
							href: "/install?step=4",
							class: "k-btn k-btn--secondary k-btn--md"
						}, "Back"), createVNode("button", {
							type: "submit",
							class: "k-btn k-btn--primary k-btn--md",
							disabled: installing.value,
							"aria-busy": installing.value
						}, toDisplayString(installing.value ? "Installing…" : "Install Kestello"), 9, ["disabled", "aria-busy"])])
					], 40, ["onSubmit"])];
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
