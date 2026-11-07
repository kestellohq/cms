import { t as _sfc_main$1 } from "../../chunks/InstallerLayout-puuck5dd.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, mergeProps, openBlock, renderList, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { KIcon } from "@kestello/ui";
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
		const props = __props;
		const problems = computed(() => props.checks.filter((c) => !c.passed));
		const passed = computed(() => props.checks.filter((c) => c.passed));
		const groups = computed(() => [...new Set(passed.value.map((c) => c.group))].map((group) => ({
			group,
			checks: passed.value.filter((c) => c.group === group)
		})));
		const things = (n) => n === 1 ? "1 thing to fix" : `${n} things to fix`;
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				step: __props.step,
				title: "Server check",
				description: __props.allPassed ? "Everything Kestello needs is in place on this server." : "Kestello needs a few things from your server. Each problem below says how to fix it."
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (problems.value.length) {
							_push(`<section class="k-setup__problems" aria-labelledby="problems-title"${_scopeId}><h2 id="problems-title" class="k-setup__section-title"${_scopeId}>${ssrInterpolate(things(problems.value.length))}</h2><ul class="k-setup__problem-list"${_scopeId}><!--[-->`);
							ssrRenderList(problems.value, (check) => {
								_push(`<li class="k-setup__problem"${_scopeId}>`);
								_push(ssrRenderComponent(unref(KIcon), { name: "x-circle" }, null, _parent, _scopeId));
								_push(`<span class="k-setup__problem-name"${_scopeId}>`);
								if (check.group === "Folders") _push(`<!--[-->Folder <code${_scopeId}>${ssrInterpolate(check.name)}</code><!--]-->`);
								else _push(`<!--[-->${ssrInterpolate(check.name)}<!--]-->`);
								_push(`</span><span class="k-setup__problem-found"${_scopeId}>${ssrInterpolate(check.current)}</span><p class="k-setup__problem-fix"${_scopeId}>${ssrInterpolate(check.fix)}</p></li>`);
							});
							_push(`<!--]--></ul></section>`);
						} else {
							_push(`<p class="k-setup__ready"${_scopeId}>`);
							_push(ssrRenderComponent(unref(KIcon), { name: "check-circle" }, null, _parent, _scopeId));
							_push(` Your server is ready.</p>`);
						}
						if (passed.value.length) {
							_push(`<details class="k-setup__passed"${ssrIncludeBooleanAttr(__props.allPassed) ? " open" : ""}${_scopeId}><summary${_scopeId}>`);
							_push(ssrRenderComponent(unref(KIcon), { name: "caret-right" }, null, _parent, _scopeId));
							_push(` ${ssrInterpolate(passed.value.length)} ${ssrInterpolate(passed.value.length === 1 ? "check" : "checks")} passed</summary><table class="k-setup__table"${_scopeId}><!--[-->`);
							ssrRenderList(groups.value, (g) => {
								_push(`<tbody${_scopeId}><tr${_scopeId}><th scope="rowgroup" colspan="2"${_scopeId}>${ssrInterpolate(g.group)}</th></tr><!--[-->`);
								ssrRenderList(g.checks, (check) => {
									_push(`<tr${_scopeId}><td${_scopeId}>${ssrInterpolate(check.name)}</td><td${_scopeId}>${ssrInterpolate(check.current)}</td></tr>`);
								});
								_push(`<!--]--></tbody>`);
							});
							_push(`<!--]--></table></details>`);
						} else _push(`<!---->`);
						_push(`<div class="k-setup__actions"${_scopeId}>`);
						if (__props.allPassed) _push(`<a href="/install?step=2" class="k-btn k-btn--primary k-btn--md"${_scopeId}>Continue</a>`);
						else _push(`<!--[--><span class="k-setup__note"${_scopeId}>Fixed something? Check again to continue.</span><a href="/install?step=1" class="k-btn k-btn--primary k-btn--md"${_scopeId}>Check again</a><!--]-->`);
						_push(`</div>`);
					} else return [
						problems.value.length ? (openBlock(), createBlock("section", {
							key: 0,
							class: "k-setup__problems",
							"aria-labelledby": "problems-title"
						}, [createVNode("h2", {
							id: "problems-title",
							class: "k-setup__section-title"
						}, toDisplayString(things(problems.value.length)), 1), createVNode("ul", { class: "k-setup__problem-list" }, [(openBlock(true), createBlock(Fragment, null, renderList(problems.value, (check) => {
							return openBlock(), createBlock("li", {
								key: check.group + check.name,
								class: "k-setup__problem"
							}, [
								createVNode(unref(KIcon), { name: "x-circle" }),
								createVNode("span", { class: "k-setup__problem-name" }, [check.group === "Folders" ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode("Folder "), createVNode("code", null, toDisplayString(check.name), 1)], 64)) : (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(toDisplayString(check.name), 1)], 64))]),
								createVNode("span", { class: "k-setup__problem-found" }, toDisplayString(check.current), 1),
								createVNode("p", { class: "k-setup__problem-fix" }, toDisplayString(check.fix), 1)
							]);
						}), 128))])])) : (openBlock(), createBlock("p", {
							key: 1,
							class: "k-setup__ready"
						}, [createVNode(unref(KIcon), { name: "check-circle" }), createTextVNode(" Your server is ready.")])),
						passed.value.length ? (openBlock(), createBlock("details", {
							key: 2,
							class: "k-setup__passed",
							open: __props.allPassed
						}, [createVNode("summary", null, [createVNode(unref(KIcon), { name: "caret-right" }), createTextVNode(" " + toDisplayString(passed.value.length) + " " + toDisplayString(passed.value.length === 1 ? "check" : "checks") + " passed", 1)]), createVNode("table", { class: "k-setup__table" }, [(openBlock(true), createBlock(Fragment, null, renderList(groups.value, (g) => {
							return openBlock(), createBlock("tbody", { key: g.group }, [createVNode("tr", null, [createVNode("th", {
								scope: "rowgroup",
								colspan: "2"
							}, toDisplayString(g.group), 1)]), (openBlock(true), createBlock(Fragment, null, renderList(g.checks, (check) => {
								return openBlock(), createBlock("tr", { key: check.name }, [createVNode("td", null, toDisplayString(check.name), 1), createVNode("td", null, toDisplayString(check.current), 1)]);
							}), 128))]);
						}), 128))])], 8, ["open"])) : createCommentVNode("v-if", true),
						createVNode("div", { class: "k-setup__actions" }, [__props.allPassed ? (openBlock(), createBlock("a", {
							key: 0,
							href: "/install?step=2",
							class: "k-btn k-btn--primary k-btn--md"
						}, "Continue")) : (openBlock(), createBlock(Fragment, { key: 1 }, [createVNode("span", { class: "k-setup__note" }, "Fixed something? Check again to continue."), createVNode("a", {
							href: "/install?step=1",
							class: "k-btn k-btn--primary k-btn--md"
						}, "Check again")], 64))])
					];
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
