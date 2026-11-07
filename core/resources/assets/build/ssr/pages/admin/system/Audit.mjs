import { n as auditLabel, t as AUDIT_WARN } from "../../../chunks/events-DFJBVCyy.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, openBlock, ref, renderList, toDisplayString, unref, useSSRContext, watch, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { usePage } from "@kestello/app";
import { __, notify, useApi } from "@kestello/sdk";
import { KBadge, KButton, KCard, KField, KModal, KPageHeader, KSelect, KTable, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/system/Audit.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Audit",
	__ssrInlineRender: true,
	setup(__props) {
		const api = useApi();
		const AREAS = [
			{
				value: "",
				label: __("Everything")
			},
			{
				value: "auth.*",
				label: __("Sign-in and accounts")
			},
			{
				value: "users.*",
				label: __("People")
			},
			{
				value: "role.*",
				label: __("Roles")
			},
			{
				value: "settings.*",
				label: __("Settings")
			},
			{
				value: "plugin.*",
				label: __("Plugins")
			},
			{
				value: "theme.*",
				label: __("Themes and design")
			},
			{
				value: "backup.*",
				label: __("Backups")
			},
			{
				value: "core.*",
				label: __("Kestello updates")
			},
			{
				value: "media.*",
				label: __("Media")
			},
			{
				value: "menu.*",
				label: __("Menus")
			},
			{
				value: "form.*",
				label: __("Forms")
			},
			{
				value: "redirect.*",
				label: __("Redirects")
			},
			{
				value: "content.*",
				label: __("Content")
			},
			{
				value: "webhook.*",
				label: __("Webhooks")
			},
			{
				value: "content_model.*",
				label: __("Content types")
			},
			{
				value: "platform.*",
				label: __("Infrastructure")
			}
		];
		const fromUrl = new URLSearchParams(usePage().url.split("?")[1] ?? "").get("area") ?? "";
		const area = ref(AREAS.some((x) => x.value === fromUrl) ? fromUrl : "");
		const actor = ref(null);
		const rows = ref([]);
		const nextBefore = ref(null);
		const loading = ref(false);
		const shown = ref(null);
		async function load(more = false) {
			loading.value = true;
			try {
				const body = await api.get("/audit", {
					limit: 50,
					event: area.value || void 0,
					actor_id: actor.value?.id,
					before_id: more ? nextBefore.value : void 0
				});
				rows.value = more ? [...rows.value, ...body.data] : body.data;
				nextBefore.value = body.meta.next_before_id;
			} catch (e) {
				notify.error(e);
			} finally {
				loading.value = false;
			}
		}
		watch([area, actor], () => load());
		load();
		const label = (row) => auditLabel(row.event);
		const subject = (row) => row.subject_type ? `${row.subject_type} ${row.subject_id}` : "";
		const who = (row) => row.actor ? row.actor.username ?? __("Deleted person #{id}", { id: row.actor.id }) : row.owner !== "core" ? row.owner : __("The system");
		const details = computed(() => shown.value ? Object.entries(shown.value.data ?? {}) : []);
		const show = (value) => typeof value === "string" ? value : JSON.stringify(value);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Audit log"),
				description: unref(__)("Who changed what, and when: sign-ins, people and roles, settings, plugins, themes, backups and updates. Passwords and other secrets are never recorded.")
			}, null, _parent));
			_push(`<div class="k-toolbar" role="search">`);
			_push(ssrRenderComponent(unref(KField), { label: unref(__)("Show") }, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KSelect), {
						id,
						modelValue: area.value,
						"onUpdate:modelValue": ($event) => area.value = $event,
						options: AREAS
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KSelect), {
						id,
						modelValue: area.value,
						"onUpdate:modelValue": ($event) => area.value = $event,
						options: AREAS
					}, null, 8, [
						"id",
						"modelValue",
						"onUpdate:modelValue"
					])];
				}),
				_: 1
			}, _parent));
			if (actor.value) _push(ssrRenderComponent(unref(KButton), {
				variant: "ghost",
				icon: "x",
				"aria-label": unref(__)("Only {name}: show everyone again", { name: actor.value.username }),
				onClick: ($event) => actor.value = null
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("Only {name}", { name: actor.value.username }))}`);
					else return [createTextVNode(toDisplayString(unref(__)("Only {name}", { name: actor.value.username })), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`</div>`);
			_push(ssrRenderComponent(unref(KCard), { flush: "" }, createSlots({
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: [
							{
								key: "occurred_at",
								label: unref(__)("When"),
								width: "1%"
							},
							{
								key: "who",
								label: unref(__)("Who")
							},
							{
								key: "event",
								label: unref(__)("What")
							},
							{
								key: "ip",
								label: unref(__)("From")
							},
							{
								key: "actions",
								label: "",
								align: "end",
								width: "1%"
							}
						],
						rows: rows.value,
						loading: loading.value,
						empty: unref(__)("Nothing recorded yet.")
					}, {
						"cell-occurred_at": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KTime), {
								value: row.occurred_at,
								class: "k-nowrap"
							}, null, _parent, _scopeId));
							else return [createVNode(unref(KTime), {
								value: row.occurred_at,
								class: "k-nowrap"
							}, null, 8, ["value"])];
						}),
						"cell-who": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (row.actor && row.actor.username) _push(`<button type="button" class="k-link-btn"${ssrRenderAttr("title", unref(__)("Show only this person"))}${_scopeId}>${ssrInterpolate(row.actor.username)}</button>`);
								else _push(`<span class="k-muted"${_scopeId}>${ssrInterpolate(who(row))}</span>`);
							} else return [row.actor && row.actor.username ? (openBlock(), createBlock("button", {
								key: 0,
								type: "button",
								class: "k-link-btn",
								title: unref(__)("Show only this person"),
								onClick: ($event) => actor.value = row.actor
							}, toDisplayString(row.actor.username), 9, ["title", "onClick"])) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-muted"
							}, toDisplayString(who(row)), 1))];
						}),
						"cell-event": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (unref(AUDIT_WARN).has(row.event)) _push(ssrRenderComponent(unref(KBadge), { tone: "warning" }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(label(row))}`);
										else return [createTextVNode(toDisplayString(label(row)), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else _push(`<!--[-->${ssrInterpolate(label(row))}<!--]-->`);
								if (subject(row)) _push(`<div class="k-muted k-small"${_scopeId}><code${_scopeId}>${ssrInterpolate(subject(row))}</code></div>`);
								else _push(`<!---->`);
							} else return [unref(AUDIT_WARN).has(row.event) ? (openBlock(), createBlock(unref(KBadge), {
								key: 0,
								tone: "warning"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(label(row)), 1)]),
								_: 2
							}, 1024)) : (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(toDisplayString(label(row)), 1)], 64)), subject(row) ? (openBlock(), createBlock("div", {
								key: 2,
								class: "k-muted k-small"
							}, [createVNode("code", null, toDisplayString(subject(row)), 1)])) : createCommentVNode("v-if", true)];
						}),
						"cell-ip": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(`<span class="k-muted k-small"${_scopeId}>${ssrInterpolate(row.ip ?? "")}</span>`);
							else return [createVNode("span", { class: "k-muted k-small" }, toDisplayString(row.ip ?? ""), 1)];
						}),
						"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "info",
								"aria-label": unref(__)("Details"),
								onClick: ($event) => shown.value = row
							}, null, _parent, _scopeId));
							else return [createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "info",
								"aria-label": unref(__)("Details"),
								onClick: ($event) => shown.value = row
							}, null, 8, ["aria-label", "onClick"])];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: [
							{
								key: "occurred_at",
								label: unref(__)("When"),
								width: "1%"
							},
							{
								key: "who",
								label: unref(__)("Who")
							},
							{
								key: "event",
								label: unref(__)("What")
							},
							{
								key: "ip",
								label: unref(__)("From")
							},
							{
								key: "actions",
								label: "",
								align: "end",
								width: "1%"
							}
						],
						rows: rows.value,
						loading: loading.value,
						empty: unref(__)("Nothing recorded yet.")
					}, {
						"cell-occurred_at": withCtx(({ row }) => [createVNode(unref(KTime), {
							value: row.occurred_at,
							class: "k-nowrap"
						}, null, 8, ["value"])]),
						"cell-who": withCtx(({ row }) => [row.actor && row.actor.username ? (openBlock(), createBlock("button", {
							key: 0,
							type: "button",
							class: "k-link-btn",
							title: unref(__)("Show only this person"),
							onClick: ($event) => actor.value = row.actor
						}, toDisplayString(row.actor.username), 9, ["title", "onClick"])) : (openBlock(), createBlock("span", {
							key: 1,
							class: "k-muted"
						}, toDisplayString(who(row)), 1))]),
						"cell-event": withCtx(({ row }) => [unref(AUDIT_WARN).has(row.event) ? (openBlock(), createBlock(unref(KBadge), {
							key: 0,
							tone: "warning"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(label(row)), 1)]),
							_: 2
						}, 1024)) : (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(toDisplayString(label(row)), 1)], 64)), subject(row) ? (openBlock(), createBlock("div", {
							key: 2,
							class: "k-muted k-small"
						}, [createVNode("code", null, toDisplayString(subject(row)), 1)])) : createCommentVNode("v-if", true)]),
						"cell-ip": withCtx(({ row }) => [createVNode("span", { class: "k-muted k-small" }, toDisplayString(row.ip ?? ""), 1)]),
						"cell-actions": withCtx(({ row }) => [createVNode(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "info",
							"aria-label": unref(__)("Details"),
							onClick: ($event) => shown.value = row
						}, null, 8, ["aria-label", "onClick"])]),
						_: 1
					}, 8, [
						"columns",
						"rows",
						"loading",
						"empty"
					])];
				}),
				_: 2
			}, [nextBefore.value ? {
				name: "footer",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						loading: loading.value,
						onClick: ($event) => load(true)
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Load more"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Load more")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						size: "sm",
						loading: loading.value,
						onClick: ($event) => load(true)
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Load more")), 1)]),
						_: 1
					}, 8, ["loading", "onClick"])];
				}),
				key: "0"
			} : void 0]), _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: !!shown.value,
				title: shown.value ? label(shown.value) : "",
				size: "lg",
				onClose: ($event) => shown.value = null
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (shown.value) {
							_push(`<!--[--><dl class="k-kv"${_scopeId}><dt${_scopeId}>${ssrInterpolate(unref(__)("When"))}</dt><dd${_scopeId}>`);
							_push(ssrRenderComponent(unref(KTime), { value: shown.value.occurred_at }, null, _parent, _scopeId));
							_push(`</dd><dt${_scopeId}>${ssrInterpolate(unref(__)("Who"))}</dt><dd${_scopeId}>${ssrInterpolate(who(shown.value))}</dd><dt${_scopeId}>${ssrInterpolate(unref(__)("Event"))}</dt><dd${_scopeId}><code${_scopeId}>${ssrInterpolate(shown.value.event)}</code></dd>`);
							if (subject(shown.value)) _push(`<!--[--><dt${_scopeId}>${ssrInterpolate(unref(__)("About"))}</dt><dd${_scopeId}><code${_scopeId}>${ssrInterpolate(subject(shown.value))}</code></dd><!--]-->`);
							else _push(`<!---->`);
							if (shown.value.ip) _push(`<!--[--><dt${_scopeId}>${ssrInterpolate(unref(__)("Address"))}</dt><dd${_scopeId}>${ssrInterpolate(shown.value.ip)}</dd><!--]-->`);
							else _push(`<!---->`);
							if (shown.value.user_agent) _push(`<!--[--><dt${_scopeId}>${ssrInterpolate(unref(__)("Browser"))}</dt><dd class="k-small"${_scopeId}>${ssrInterpolate(shown.value.user_agent)}</dd><!--]-->`);
							else _push(`<!---->`);
							if (shown.value.owner !== "core") _push(`<!--[--><dt${_scopeId}>${ssrInterpolate(unref(__)("Recorded by"))}</dt><dd${_scopeId}><code${_scopeId}>${ssrInterpolate(shown.value.owner)}</code></dd><!--]-->`);
							else _push(`<!---->`);
							if (shown.value.request_id) _push(`<!--[--><dt${_scopeId}>${ssrInterpolate(unref(__)("Request"))}</dt><dd${_scopeId}><code${_scopeId}>${ssrInterpolate(shown.value.request_id)}</code></dd><!--]-->`);
							else _push(`<!---->`);
							_push(`</dl>`);
							if (details.value.length) {
								_push(`<!--[--><h3 class="k-subheading"${_scopeId}>${ssrInterpolate(unref(__)("Details"))}</h3><dl class="k-kv"${_scopeId}><!--[-->`);
								ssrRenderList(details.value, ([key, value]) => {
									_push(`<!--[--><dt${_scopeId}><code${_scopeId}>${ssrInterpolate(key)}</code></dt><dd${_scopeId}>${ssrInterpolate(show(value))}</dd><!--]-->`);
								});
								_push(`<!--]--></dl><!--]-->`);
							} else _push(`<!---->`);
							_push(`<!--]-->`);
						} else _push(`<!---->`);
					} else return [shown.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode("dl", { class: "k-kv" }, [
						createVNode("dt", null, toDisplayString(unref(__)("When")), 1),
						createVNode("dd", null, [createVNode(unref(KTime), { value: shown.value.occurred_at }, null, 8, ["value"])]),
						createVNode("dt", null, toDisplayString(unref(__)("Who")), 1),
						createVNode("dd", null, toDisplayString(who(shown.value)), 1),
						createVNode("dt", null, toDisplayString(unref(__)("Event")), 1),
						createVNode("dd", null, [createVNode("code", null, toDisplayString(shown.value.event), 1)]),
						subject(shown.value) ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode("dt", null, toDisplayString(unref(__)("About")), 1), createVNode("dd", null, [createVNode("code", null, toDisplayString(subject(shown.value)), 1)])], 64)) : createCommentVNode("v-if", true),
						shown.value.ip ? (openBlock(), createBlock(Fragment, { key: 1 }, [createVNode("dt", null, toDisplayString(unref(__)("Address")), 1), createVNode("dd", null, toDisplayString(shown.value.ip), 1)], 64)) : createCommentVNode("v-if", true),
						shown.value.user_agent ? (openBlock(), createBlock(Fragment, { key: 2 }, [createVNode("dt", null, toDisplayString(unref(__)("Browser")), 1), createVNode("dd", { class: "k-small" }, toDisplayString(shown.value.user_agent), 1)], 64)) : createCommentVNode("v-if", true),
						shown.value.owner !== "core" ? (openBlock(), createBlock(Fragment, { key: 3 }, [createVNode("dt", null, toDisplayString(unref(__)("Recorded by")), 1), createVNode("dd", null, [createVNode("code", null, toDisplayString(shown.value.owner), 1)])], 64)) : createCommentVNode("v-if", true),
						shown.value.request_id ? (openBlock(), createBlock(Fragment, { key: 4 }, [createVNode("dt", null, toDisplayString(unref(__)("Request")), 1), createVNode("dd", null, [createVNode("code", null, toDisplayString(shown.value.request_id), 1)])], 64)) : createCommentVNode("v-if", true)
					]), details.value.length ? (openBlock(), createBlock(Fragment, { key: 0 }, [createVNode("h3", { class: "k-subheading" }, toDisplayString(unref(__)("Details")), 1), createVNode("dl", { class: "k-kv" }, [(openBlock(true), createBlock(Fragment, null, renderList(details.value, ([key, value]) => {
						return openBlock(), createBlock(Fragment, { key }, [createVNode("dt", null, [createVNode("code", null, toDisplayString(key), 1)]), createVNode("dd", null, toDisplayString(show(value)), 1)], 64);
					}), 128))])], 64)) : createCommentVNode("v-if", true)], 64)) : createCommentVNode("v-if", true)];
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Audit.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
