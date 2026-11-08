import { t as _sfc_main$1 } from "../../chunks/InstallerLayout-puuck5dd.mjs";
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
				description: "The basics. You can change all of it later under Settings."
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form method="POST" action="/install" class="k-setup__form"${_scopeId}>`);
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
						_push(`<div class="k-field"${_scopeId}><label class="k-field__label" for="site_name"${_scopeId}>Site name</label><input id="site_name" type="text" name="site_name" class="k-input"${ssrRenderAttr("value", __props.site.name)} required${_scopeId}></div><div class="k-field"${_scopeId}><label class="k-field__label" for="site_description"${_scopeId}>Tagline <span class="k-muted"${_scopeId}>(optional)</span></label><input id="site_description" type="text" name="site_description" class="k-input"${ssrRenderAttr("value", __props.site.description)} aria-describedby="site_description_help"${_scopeId}><p id="site_description_help" class="k-field__help"${_scopeId}>One short sentence about the site.</p></div><div class="k-setup__row"${_scopeId}><div class="k-field"${_scopeId}><label class="k-field__label" for="site_url"${_scopeId}>Site address</label><input id="site_url" type="url" name="site_url" class="k-input"${ssrRenderAttr("value", __props.site.url)} required aria-describedby="site_url_help"${_scopeId}><p id="site_url_help" class="k-field__help"${_scopeId}>The full address visitors use, with https://</p></div><div class="k-field"${_scopeId}><label class="k-field__label" for="timezone"${_scopeId}>Time zone</label><select id="timezone" name="timezone" class="k-input k-select" aria-describedby="timezone_help"${_scopeId}><!--[-->`);
						ssrRenderList(__props.timezones, (tz) => {
							_push(`<option${ssrRenderAttr("value", tz)}${ssrIncludeBooleanAttr(tz === __props.site.timezone) ? " selected" : ""}${_scopeId}>${ssrInterpolate(tz)}</option>`);
						});
						_push(`<!--]--></select><p id="timezone_help" class="k-field__help"${_scopeId}>For scheduled posts and dates.</p></div></div><fieldset class="k-setup__choices"${_scopeId}><legend${_scopeId}>What is this site for?</legend><label class="k-setup__choice"${_scopeId}><input type="radio" name="site_kind" value="site"${ssrIncludeBooleanAttr(!__props.site.demo) ? " checked" : ""}${_scopeId}><strong${_scopeId}>A website</strong><span${_scopeId}>Your own site. You make the administrator account in the next step.</span></label><label class="k-setup__choice"${_scopeId}><input type="radio" name="site_kind" value="demo"${ssrIncludeBooleanAttr(__props.site.demo) ? " checked" : ""}${_scopeId}><strong${_scopeId}>A public demo</strong><span${_scopeId}>For showing Kestello to others. It starts with a sample bakery site, anyone can sign in as demo with the password demo, and it resets every hour. Accounts, plugins, updates, backups and settings are locked, and nothing is emailed. You manage it on the server with bin/cms.</span></label></fieldset><div class="k-setup__actions"${_scopeId}><a href="/install?step=2" class="k-btn k-btn--secondary k-btn--md"${_scopeId}>Back</a><button type="submit" class="k-btn k-btn--primary k-btn--md"${_scopeId}>Continue</button></div></form>`);
					} else return [createVNode("form", {
						method: "POST",
						action: "/install",
						class: "k-setup__form"
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
							}, [createTextVNode("Tagline "), createVNode("span", { class: "k-muted" }, "(optional)")]),
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
							}, "One short sentence about the site.")
						]),
						createVNode("div", { class: "k-setup__row" }, [createVNode("div", { class: "k-field" }, [
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
							}, "The full address visitors use, with https://")
						]), createVNode("div", { class: "k-field" }, [
							createVNode("label", {
								class: "k-field__label",
								for: "timezone"
							}, "Time zone"),
							createVNode("select", {
								id: "timezone",
								name: "timezone",
								class: "k-input k-select",
								"aria-describedby": "timezone_help"
							}, [(openBlock(true), createBlock(Fragment, null, renderList(__props.timezones, (tz) => {
								return openBlock(), createBlock("option", {
									key: tz,
									value: tz,
									selected: tz === __props.site.timezone
								}, toDisplayString(tz), 9, ["value", "selected"]);
							}), 128))]),
							createVNode("p", {
								id: "timezone_help",
								class: "k-field__help"
							}, "For scheduled posts and dates.")
						])]),
						createVNode("fieldset", { class: "k-setup__choices" }, [
							createVNode("legend", null, "What is this site for?"),
							createVNode("label", { class: "k-setup__choice" }, [
								createVNode("input", {
									type: "radio",
									name: "site_kind",
									value: "site",
									checked: !__props.site.demo
								}, null, 8, ["checked"]),
								createVNode("strong", null, "A website"),
								createVNode("span", null, "Your own site. You make the administrator account in the next step.")
							]),
							createVNode("label", { class: "k-setup__choice" }, [
								createVNode("input", {
									type: "radio",
									name: "site_kind",
									value: "demo",
									checked: __props.site.demo
								}, null, 8, ["checked"]),
								createVNode("strong", null, "A public demo"),
								createVNode("span", null, "For showing Kestello to others. It starts with a sample bakery site, anyone can sign in as demo with the password demo, and it resets every hour. Accounts, plugins, updates, backups and settings are locked, and nothing is emailed. You manage it on the server with bin/cms.")
							])
						]),
						createVNode("div", { class: "k-setup__actions" }, [createVNode("a", {
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
