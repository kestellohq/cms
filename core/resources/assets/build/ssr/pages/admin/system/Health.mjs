import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, ref, renderList, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { __, notify, useApi, useCan, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KEmpty, KIcon, KKeyValue, KPageHeader, KStatusBadge, KTabs, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/system/Health.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Health",
	__ssrInlineRender: true,
	setup(__props) {
		const api = useApi();
		const refresh = ref(false);
		const report = useResource("/system/health", { query: () => refresh.value ? { refresh: true } : {} });
		const filter = ref("issues");
		const copying = ref(false);
		const can = useCan();
		const clearing = ref(false);
		async function clearPageCache() {
			clearing.value = true;
			try {
				await api.post("/system/page-cache/clear");
				notify.success(__("Page cache cleared. Pages are rebuilt as visitors open them."));
			} catch (e) {
				notify.error(e);
			} finally {
				clearing.value = false;
			}
		}
		const checks = computed(() => report.data.value?.checks ?? []);
		const counts = computed(() => report.data.value?.counts ?? {
			good: 0,
			recommended: 0,
			critical: 0
		});
		const visible = computed(() => filter.value === "all" ? checks.value : filter.value === "issues" ? checks.value.filter((c) => c.status !== "good") : checks.value.filter((c) => c.status === filter.value));
		const tabs = computed(() => [
			{
				id: "issues",
				label: __("Needs attention"),
				count: counts.value.critical + counts.value.recommended
			},
			{
				id: "critical",
				label: __("Critical"),
				count: counts.value.critical
			},
			{
				id: "recommended",
				label: __("Recommended"),
				count: counts.value.recommended
			},
			{
				id: "good",
				label: __("Passed"),
				count: counts.value.good
			},
			{
				id: "all",
				label: __("All"),
				count: checks.value.length
			}
		]);
		const summary = computed(() => ({
			critical: [__("Your site has critical problems"), "danger"],
			recommended: [__("Your site works, with a few recommendations"), "warning"],
			good: [__("Your site is healthy"), "success"]
		})[report.data.value?.status] ?? [__("Running checks…"), "info"]);
		function rerun() {
			if (refresh.value) report.reload();
			else refresh.value = true;
		}
		async function copyInfo() {
			copying.value = true;
			try {
				const info = (await api.get("/system/info")).data;
				const text = JSON.stringify({
					info,
					health: checks.value.map(({ id, status, message }) => ({
						id,
						status,
						message
					}))
				}, null, 2);
				await navigator.clipboard.writeText(text);
				notify.success(__("Debug information copied. It contains no passwords or keys."));
			} catch (e) {
				notify.error(e);
			} finally {
				copying.value = false;
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Site Health"),
				description: unref(__)("Checks that keep your site secure, fast and reliable.")
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (unref(can)("settings.manage")) _push(ssrRenderComponent(unref(KButton), {
							icon: "lightning",
							loading: clearing.value,
							onClick: clearPageCache
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Clear page cache"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Clear page cache")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KButton), {
							icon: "clipboard",
							loading: copying.value,
							onClick: copyInfo
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Copy debug info"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Copy debug info")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							icon: "arrows-clockwise",
							loading: unref(report).loading.value,
							onClick: rerun
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Run checks again"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Run checks again")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [
						unref(can)("settings.manage") ? (openBlock(), createBlock(unref(KButton), {
							key: 0,
							icon: "lightning",
							loading: clearing.value,
							onClick: clearPageCache
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Clear page cache")), 1)]),
							_: 1
						}, 8, ["loading"])) : createCommentVNode("v-if", true),
						createVNode(unref(KButton), {
							icon: "clipboard",
							loading: copying.value,
							onClick: copyInfo
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Copy debug info")), 1)]),
							_: 1
						}, 8, ["loading"]),
						createVNode(unref(KButton), {
							variant: "primary",
							icon: "arrows-clockwise",
							loading: unref(report).loading.value,
							onClick: rerun
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Run checks again")), 1)]),
							_: 1
						}, 8, ["loading"])
					];
				}),
				_: 1
			}, _parent));
			if (unref(report).error.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(report).error.value.message)}`);
					else return [createTextVNode(toDisplayString(unref(report).error.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`<div class="${ssrRenderClass([`is-${summary.value[1]}`, "k-health-summary"])}">`);
			_push(ssrRenderComponent(unref(KIcon), { name: summary.value[1] === "success" ? "shield-check" : summary.value[1] === "danger" ? "shield-warning" : "shield" }, null, _parent));
			_push(`<div><strong>${ssrInterpolate(summary.value[0])}</strong>`);
			if (unref(report).data.value) {
				_push(`<p class="k-muted">${ssrInterpolate(unref(__)("Checked"))} `);
				_push(ssrRenderComponent(unref(KTime), { value: unref(report).data.value.generated_at }, null, _parent));
				_push(` · ${ssrInterpolate(unref(__)("{good} passed, {recommended} recommended, {critical} critical", counts.value))}</p>`);
			} else _push(`<!---->`);
			_push(`</div></div>`);
			_push(ssrRenderComponent(unref(KCard), { flush: "" }, {
				title: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTabs), {
						modelValue: filter.value,
						"onUpdate:modelValue": ($event) => filter.value = $event,
						tabs: tabs.value,
						label: unref(__)("Filter checks")
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KTabs), {
						modelValue: filter.value,
						"onUpdate:modelValue": ($event) => filter.value = $event,
						tabs: tabs.value,
						label: unref(__)("Filter checks")
					}, null, 8, [
						"modelValue",
						"onUpdate:modelValue",
						"tabs",
						"label"
					])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (visible.value.length) {
							_push(`<ul class="k-checks"${_scopeId}><!--[-->`);
							ssrRenderList(visible.value, (check) => {
								_push(`<li class="k-check"${_scopeId}><details${_scopeId}><summary${_scopeId}>`);
								_push(ssrRenderComponent(unref(KStatusBadge), { status: check.status }, null, _parent, _scopeId));
								_push(`<span class="k-check__label"${_scopeId}>${ssrInterpolate(check.label)}</span><span class="k-check__message"${_scopeId}>${ssrInterpolate(check.message)}</span>`);
								_push(ssrRenderComponent(unref(KBadge), { class: "k-check__category" }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(check.category)}`);
										else return [createTextVNode(toDisplayString(check.category), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								_push(`</summary><div class="k-check__details"${_scopeId}><p${_scopeId}>${ssrInterpolate(check.message)}</p>`);
								if (Object.keys(check.details ?? {}).length) _push(ssrRenderComponent(unref(KKeyValue), { items: check.details }, null, _parent, _scopeId));
								else _push(`<!---->`);
								_push(`<p class="k-muted k-small"${_scopeId}>${ssrInterpolate(unref(__)("Check"))} <code${_scopeId}>${ssrInterpolate(check.id)}</code> · ${ssrInterpolate(check.owner)} · ${ssrInterpolate(check.ms)} ms </p>`);
								if (check.action) _push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									href: check.action.url,
									icon: "arrow-right"
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(check.action.label)}`);
										else return [createTextVNode(toDisplayString(check.action.label), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else _push(`<!---->`);
								_push(`</div></details></li>`);
							});
							_push(`<!--]--></ul>`);
						} else if (unref(report).data.value) _push(ssrRenderComponent(unref(KEmpty), {
							icon: "check-circle",
							title: filter.value === "issues" ? unref(__)("Nothing needs your attention.") : unref(__)("No checks in this group.")
						}, null, _parent, _scopeId));
						else _push(`<!---->`);
					} else return [visible.value.length ? (openBlock(), createBlock("ul", {
						key: 0,
						class: "k-checks"
					}, [(openBlock(true), createBlock(Fragment, null, renderList(visible.value, (check) => {
						return openBlock(), createBlock("li", {
							key: check.id,
							class: "k-check"
						}, [createVNode("details", null, [createVNode("summary", null, [
							createVNode(unref(KStatusBadge), { status: check.status }, null, 8, ["status"]),
							createVNode("span", { class: "k-check__label" }, toDisplayString(check.label), 1),
							createVNode("span", { class: "k-check__message" }, toDisplayString(check.message), 1),
							createVNode(unref(KBadge), { class: "k-check__category" }, {
								default: withCtx(() => [createTextVNode(toDisplayString(check.category), 1)]),
								_: 2
							}, 1024)
						]), createVNode("div", { class: "k-check__details" }, [
							createVNode("p", null, toDisplayString(check.message), 1),
							Object.keys(check.details ?? {}).length ? (openBlock(), createBlock(unref(KKeyValue), {
								key: 0,
								items: check.details
							}, null, 8, ["items"])) : createCommentVNode("v-if", true),
							createVNode("p", { class: "k-muted k-small" }, [
								createTextVNode(toDisplayString(unref(__)("Check")) + " ", 1),
								createVNode("code", null, toDisplayString(check.id), 1),
								createTextVNode(" · " + toDisplayString(check.owner) + " · " + toDisplayString(check.ms) + " ms ", 1)
							]),
							check.action ? (openBlock(), createBlock(unref(KButton), {
								key: 1,
								size: "sm",
								href: check.action.url,
								icon: "arrow-right"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(check.action.label), 1)]),
								_: 2
							}, 1032, ["href"])) : createCommentVNode("v-if", true)
						])])]);
					}), 128))])) : unref(report).data.value ? (openBlock(), createBlock(unref(KEmpty), {
						key: 1,
						icon: "check-circle",
						title: filter.value === "issues" ? unref(__)("Nothing needs your attention.") : unref(__)("No checks in this group.")
					}, null, 8, ["title"])) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Health.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
