import { t as _sfc_main$1 } from "../../chunks/InstallerLayout-7a6f5LEx.mjs";
import { Fragment, createBlock, createTextVNode, createVNode, mergeProps, openBlock, renderList, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { KBadge, KButton } from "@kestello/ui";
// core/resources/js/pages/installer/Requirements.vue
var _sfc_main = {
	__name: "Requirements",
	__ssrInlineRender: true,
	props: {
		checks: {
			type: Array,
			default: () => []
		},
		allPassed: Boolean,
		step: Number
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				step: __props.step,
				title: "Server check",
				description: "Kestello needs these on your server. Ask your host to fix anything marked as missing."
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<ul class="k-list k-install__checks"${_scopeId}><!--[-->`);
						ssrRenderList(__props.checks, (check) => {
							_push(`<li class="k-list__item"${_scopeId}><div class="k-list__main"${_scopeId}><strong${_scopeId}>${ssrInterpolate(check.name)}</strong><p class="k-muted k-small"${_scopeId}>Required: ${ssrInterpolate(check.required)} · Current: ${ssrInterpolate(check.current)}</p></div>`);
							_push(ssrRenderComponent(unref(KBadge), { tone: check.passed ? "success" : "danger" }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(check.passed ? "OK" : "Missing")}`);
									else return [createTextVNode(toDisplayString(check.passed ? "OK" : "Missing"), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							_push(`</li>`);
						});
						_push(`<!--]--></ul><div class="k-install__actions"${_scopeId}>`);
						if (!__props.allPassed) _push(`<span class="k-muted k-small"${_scopeId}>Fix the items marked as missing, then reload this page.</span>`);
						else _push(`<span${_scopeId}></span>`);
						if (__props.allPassed) _push(`<a href="/install?step=2" class="k-btn k-btn--primary k-btn--md"${_scopeId}>Continue</a>`);
						else _push(ssrRenderComponent(unref(KButton), { disabled: "" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`Continue`);
								else return [createTextVNode("Continue")];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</div>`);
					} else return [createVNode("ul", { class: "k-list k-install__checks" }, [(openBlock(true), createBlock(Fragment, null, renderList(__props.checks, (check) => {
						return openBlock(), createBlock("li", {
							key: check.name,
							class: "k-list__item"
						}, [createVNode("div", { class: "k-list__main" }, [createVNode("strong", null, toDisplayString(check.name), 1), createVNode("p", { class: "k-muted k-small" }, "Required: " + toDisplayString(check.required) + " · Current: " + toDisplayString(check.current), 1)]), createVNode(unref(KBadge), { tone: check.passed ? "success" : "danger" }, {
							default: withCtx(() => [createTextVNode(toDisplayString(check.passed ? "OK" : "Missing"), 1)]),
							_: 2
						}, 1032, ["tone"])]);
					}), 128))]), createVNode("div", { class: "k-install__actions" }, [!__props.allPassed ? (openBlock(), createBlock("span", {
						key: 0,
						class: "k-muted k-small"
					}, "Fix the items marked as missing, then reload this page.")) : (openBlock(), createBlock("span", { key: 1 })), __props.allPassed ? (openBlock(), createBlock("a", {
						key: 2,
						href: "/install?step=2",
						class: "k-btn k-btn--primary k-btn--md"
					}, "Continue")) : (openBlock(), createBlock(unref(KButton), {
						key: 3,
						disabled: ""
					}, {
						default: withCtx(() => [createTextVNode("Continue")]),
						_: 1
					}))])];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/installer/Requirements.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
