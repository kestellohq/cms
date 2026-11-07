import { t as _sfc_main$1 } from "../../chunks/InstallerLayout-7a6f5LEx.mjs";
import { t as _sfc_main$2 } from "../../chunks/CsrfField-DaiFH5aw.mjs";
import { Fragment, createBlock, createCommentVNode, createTextVNode, createVNode, mergeProps, openBlock, renderList, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { KAlert } from "@kestello/ui";
// core/resources/js/pages/installer/Site.vue
var _sfc_main = {
	__name: "Site",
	__ssrInlineRender: true,
	props: {
		step: Number,
		error: {
			type: String,
			default: null
		},
		site: {
			type: Object,
			required: true
		},
		timezones: {
			type: Array,
			default: () => []
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				step: __props.step,
				title: "Your site",
				description: "You can change all of this later under Settings."
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form method="POST" action="/install" class="k-auth__form"${_scopeId}>`);
						_push(ssrRenderComponent(_sfc_main$2, null, null, _parent, _scopeId));
						_push(`<input type="hidden" name="step" value="3"${_scopeId}>`);
						if (__props.error) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(__props.error)}`);
								else return [createTextVNode(toDisplayString(__props.error), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(`<div class="k-field"${_scopeId}><label class="k-field__label" for="site_name"${_scopeId}>Site name</label><input id="site_name" type="text" name="site_name" class="k-input"${ssrRenderAttr("value", __props.site.name)} required${_scopeId}></div><div class="k-field"${_scopeId}><label class="k-field__label" for="site_description"${_scopeId}>Tagline</label><input id="site_description" type="text" name="site_description" class="k-input"${ssrRenderAttr("value", __props.site.description)} aria-describedby="site_description_help"${_scopeId}><p id="site_description_help" class="k-field__help"${_scopeId}>One short sentence about the site. Optional.</p></div><div class="k-field"${_scopeId}><label class="k-field__label" for="site_url"${_scopeId}>Site address</label><input id="site_url" type="url" name="site_url" class="k-input"${ssrRenderAttr("value", __props.site.url)} required aria-describedby="site_url_help"${_scopeId}><p id="site_url_help" class="k-field__help"${_scopeId}>The full address visitors use, starting with https://</p></div><div class="k-field"${_scopeId}><label class="k-field__label" for="timezone"${_scopeId}>Time zone</label><select id="timezone" name="timezone" class="k-input k-select"${_scopeId}><!--[-->`);
						ssrRenderList(__props.timezones, (tz) => {
							_push(`<option${ssrRenderAttr("value", tz)}${ssrIncludeBooleanAttr(tz === __props.site.timezone) ? " selected" : ""}${_scopeId}>${ssrInterpolate(tz)}</option>`);
						});
						_push(`<!--]--></select></div><div class="k-install__actions"${_scopeId}><a href="/install?step=2" class="k-btn k-btn--secondary k-btn--md"${_scopeId}>Back</a><button type="submit" class="k-btn k-btn--primary k-btn--md"${_scopeId}>Continue</button></div></form>`);
					} else return [createVNode("form", {
						method: "POST",
						action: "/install",
						class: "k-auth__form"
					}, [
						createVNode(_sfc_main$2),
						createVNode("input", {
							type: "hidden",
							name: "step",
							value: "3"
						}),
						__props.error ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "danger"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(__props.error), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true),
						createVNode("div", { class: "k-field" }, [createVNode("label", {
							class: "k-field__label",
							for: "site_name"
						}, "Site name"), createVNode("input", {
							id: "site_name",
							type: "text",
							name: "site_name",
							class: "k-input",
							value: __props.site.name,
							required: ""
						}, null, 8, ["value"])]),
						createVNode("div", { class: "k-field" }, [
							createVNode("label", {
								class: "k-field__label",
								for: "site_description"
							}, "Tagline"),
							createVNode("input", {
								id: "site_description",
								type: "text",
								name: "site_description",
								class: "k-input",
								value: __props.site.description,
								"aria-describedby": "site_description_help"
							}, null, 8, ["value"]),
							createVNode("p", {
								id: "site_description_help",
								class: "k-field__help"
							}, "One short sentence about the site. Optional.")
						]),
						createVNode("div", { class: "k-field" }, [
							createVNode("label", {
								class: "k-field__label",
								for: "site_url"
							}, "Site address"),
							createVNode("input", {
								id: "site_url",
								type: "url",
								name: "site_url",
								class: "k-input",
								value: __props.site.url,
								required: "",
								"aria-describedby": "site_url_help"
							}, null, 8, ["value"]),
							createVNode("p", {
								id: "site_url_help",
								class: "k-field__help"
							}, "The full address visitors use, starting with https://")
						]),
						createVNode("div", { class: "k-field" }, [createVNode("label", {
							class: "k-field__label",
							for: "timezone"
						}, "Time zone"), createVNode("select", {
							id: "timezone",
							name: "timezone",
							class: "k-input k-select"
						}, [(openBlock(true), createBlock(Fragment, null, renderList(__props.timezones, (tz) => {
							return openBlock(), createBlock("option", {
								key: tz,
								value: tz,
								selected: tz === __props.site.timezone
							}, toDisplayString(tz), 9, ["value", "selected"]);
						}), 128))])]),
						createVNode("div", { class: "k-install__actions" }, [createVNode("a", {
							href: "/install?step=2",
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/installer/Site.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
