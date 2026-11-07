import { t as deviceLabel } from "../../../chunks/device-DTfRdqhB.mjs";
import { n as auditLabel, t as AUDIT_WARN } from "../../../chunks/events-DFJBVCyy.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, renderList, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head, Link } from "@kestello/app";
import { __, confirm, notify, useApi, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KPageHeader, KStat, KTable, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/system/Security.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Security",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* System → Security: who uses two-step sign-in, failed sign-ins, who is
		* signed in where, every access token, and recent account events. Each
		* list ends sessions or tokens in one step; the audit log records it.
		*/
		const api = useApi();
		const overview = useResource("/security");
		const sessions = useResource("/security/sessions");
		const tokens = useResource("/security/tokens");
		const two = computed(() => overview.data.value?.two_factor ?? null);
		const failed = computed(() => overview.data.value?.failed_sign_ins ?? null);
		const roleLabel = (name) => overview.data.value?.role_labels?.[name] ?? name;
		const roleList = computed(() => (two.value?.roles ?? []).map(roleLabel).join(", "));
		const people = computed(() => __("{n, plural, one {# person} other {# people}}", { n: new Set((sessions.data.value ?? []).map((s) => s.user.id)).size }));
		const twoStat = computed(() => {
			const t = two.value;
			if (!t) return {
				value: "…",
				hint: "",
				tone: "neutral"
			};
			if (t.required === 0) return {
				value: `${t.everyone.covered} / ${t.everyone.total}`,
				hint: __("people use it"),
				tone: "neutral"
			};
			return {
				value: `${t.covered} / ${t.required}`,
				hint: __("expected to use it have it on"),
				tone: t.covered === t.required ? "success" : "warning"
			};
		});
		const reason = (row) => ({
			credentials: row.user ? __("Wrong password") : __("No such account"),
			throttled: __("Too many attempts"),
			two_factor: __("Wrong two-step code"),
			inactive: __("Account switched off"),
			external: __("Sign-in provider failed"),
			external_refused: __("Sign-in provider not allowed")
		})[row.reason] ?? row.reason;
		async function signOut(row) {
			if (!await confirm({
				title: __("Sign out this session?"),
				message: __("{name} is signed out on {device} and must sign in again there.", {
					name: row.user.username,
					device: deviceLabel(row.user_agent, __)
				}),
				confirmLabel: __("Sign out"),
				danger: true
			})) return;
			try {
				await api.delete(`/security/sessions/${row.user.id}/${row.id}`);
				sessions.reload({ silent: true });
				overview.reload({ silent: true });
				notify.success(__("Signed out."));
			} catch (e) {
				notify.error(e);
			}
		}
		async function signOutEverywhere(row) {
			if (!await confirm({
				title: __("Sign {name} out everywhere?", { name: row.user.username }),
				message: __("Every browser and device signed in to this account is signed out. Their access tokens keep working: revoke those below."),
				confirmLabel: __("Sign out everywhere"),
				danger: true
			})) return;
			try {
				const { data } = await api.delete(`/security/sessions/${row.user.id}`);
				sessions.reload({ silent: true });
				overview.reload({ silent: true });
				notify.success(__("{n, plural, one {# session ended.} other {# sessions ended.}}", { n: data.signed_out }));
			} catch (e) {
				notify.error(e);
			}
		}
		async function revoke(token) {
			if (!await confirm({
				title: __("Revoke “{name}”?", { name: token.name }),
				message: __("Whatever uses this token of {person} stops working at once. This cannot be undone.", { person: token.user.username ?? "?" }),
				confirmLabel: __("Revoke"),
				danger: true
			})) return;
			try {
				await api.delete(`/security/tokens/${token.id}`);
				tokens.reload({ silent: true });
				overview.reload({ silent: true });
				notify.success(__("Token revoked."));
			} catch (e) {
				notify.error(e);
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("Security") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Security"),
				description: unref(__)("Who can get in, and how: two-step sign-in, failed attempts, who is signed in where, and access tokens.")
			}, null, _parent));
			if (unref(overview).error.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(overview).error.value.message)}`);
					else return [createTextVNode(toDisplayString(unref(overview).error.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`<div class="k-grid k-grid--4">`);
			_push(ssrRenderComponent(unref(KStat), {
				label: unref(__)("Two-step sign-in"),
				icon: "fingerprint",
				value: twoStat.value.value,
				tone: twoStat.value.tone
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(twoStat.value.hint)}`);
					else return [createTextVNode(toDisplayString(twoStat.value.hint), 1)];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KStat), {
				label: unref(__)("Failed sign-ins today"),
				icon: "shield-warning",
				value: failed.value ? failed.value.day : "…",
				tone: failed.value && failed.value.day >= 10 ? "warning" : "neutral"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (failed.value) _push(`<!--[-->${ssrInterpolate(unref(__)("{n} in the last 7 days", { n: failed.value.week }))}<!--]-->`);
						else _push(`<!---->`);
					} else return [failed.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(toDisplayString(unref(__)("{n} in the last 7 days", { n: failed.value.week })), 1)], 64)) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KStat), {
				label: unref(__)("Signed-in sessions"),
				icon: "devices",
				value: unref(sessions).data.value ? unref(sessions).data.value.length : "…"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (unref(sessions).data.value) _push(`<!--[-->${ssrInterpolate(people.value)}<!--]-->`);
						else _push(`<!---->`);
					} else return [unref(sessions).data.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(toDisplayString(people.value), 1)], 64)) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KStat), {
				label: unref(__)("Access tokens"),
				icon: "key",
				value: unref(tokens).data.value ? unref(tokens).data.value.length : "…"
			}, null, _parent));
			_push(`</div>`);
			if (two.value) _push(ssrRenderComponent(unref(KCard), { title: unref(__)("Two-step sign-in") }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (two.value.required === 0) _push(`<p class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("No role is expected to use two-step sign-in. {n} of {total} people use it anyway.", {
							n: two.value.everyone.covered,
							total: two.value.everyone.total
						}))}</p>`);
						else if (two.value.missing.length) {
							_push(`<!--[--><p${_scopeId}>${ssrInterpolate(unref(__)("People with the role {roles} are expected to use two-step sign-in. These have not turned it on yet. They can, under Your profile.", { roles: roleList.value }))}</p><ul class="k-security-people"${_scopeId}><!--[-->`);
							ssrRenderList(two.value.missing, (p) => {
								_push(`<li${_scopeId}>`);
								_push(ssrRenderComponent(unref(Link), { href: `/admin/users/${p.id}` }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(p.username)}`);
										else return [createTextVNode(toDisplayString(p.username), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								_push(` <span class="k-muted k-small"${_scopeId}>${ssrInterpolate(roleLabel(p.role))}</span></li>`);
							});
							_push(`<!--]--></ul><!--]-->`);
						} else _push(`<p class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("Everyone with the role {roles} uses two-step sign-in.", { roles: roleList.value }))}</p>`);
					} else return [two.value.required === 0 ? (openBlock(), createBlock("p", {
						key: 0,
						class: "k-muted"
					}, toDisplayString(unref(__)("No role is expected to use two-step sign-in. {n} of {total} people use it anyway.", {
						n: two.value.everyone.covered,
						total: two.value.everyone.total
					})), 1)) : two.value.missing.length ? (openBlock(), createBlock(Fragment, { key: 1 }, [createVNode("p", null, toDisplayString(unref(__)("People with the role {roles} are expected to use two-step sign-in. These have not turned it on yet. They can, under Your profile.", { roles: roleList.value })), 1), createVNode("ul", { class: "k-security-people" }, [(openBlock(true), createBlock(Fragment, null, renderList(two.value.missing, (p) => {
						return openBlock(), createBlock("li", { key: p.id }, [
							createVNode(unref(Link), { href: `/admin/users/${p.id}` }, {
								default: withCtx(() => [createTextVNode(toDisplayString(p.username), 1)]),
								_: 2
							}, 1032, ["href"]),
							createTextVNode(),
							createVNode("span", { class: "k-muted k-small" }, toDisplayString(roleLabel(p.role)), 1)
						]);
					}), 128))])], 64)) : (openBlock(), createBlock("p", {
						key: 2,
						class: "k-muted"
					}, toDisplayString(unref(__)("Everyone with the role {roles} uses two-step sign-in.", { roles: roleList.value })), 1))];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (failed.value) _push(ssrRenderComponent(unref(KCard), {
				title: unref(__)("Failed sign-ins"),
				flush: ""
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(Link), {
						href: "/admin/system/audit?area=auth.*",
						class: "k-small"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Open the audit log"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Open the audit log")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(Link), {
						href: "/admin/system/audit?area=auth.*",
						class: "k-small"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Open the audit log")), 1)]),
						_: 1
					})];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-security-failed"${_scopeId}>`);
						if (failed.value.top_ips.length) {
							_push(`<section class="k-security-failed__ips"${ssrRenderAttr("aria-label", unref(__)("Where they came from"))}${_scopeId}><h3 class="k-security-sub"${_scopeId}>${ssrInterpolate(unref(__)("Most attempts, last 7 days"))}</h3><ol${_scopeId}><!--[-->`);
							ssrRenderList(failed.value.top_ips, (ip) => {
								_push(`<li${_scopeId}><span class="k-mono-input"${_scopeId}>${ssrInterpolate(ip.ip)}</span> <span class="k-muted k-num"${_scopeId}>${ssrInterpolate(ip.count)}</span></li>`);
							});
							_push(`<!--]--></ol></section>`);
						} else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KTable), {
							class: "k-security-failed__list",
							columns: [
								{
									key: "occurred_at",
									label: unref(__)("When")
								},
								{
									key: "user",
									label: unref(__)("Account")
								},
								{
									key: "reason",
									label: unref(__)("Why")
								},
								{
									key: "ip",
									label: unref(__)("Address")
								}
							],
							rows: failed.value.recent,
							empty: unref(__)("No failed sign-ins")
						}, {
							"cell-occurred_at": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.occurred_at }, null, _parent, _scopeId));
								else return [createVNode(unref(KTime), { value: row.occurred_at }, null, 8, ["value"])];
							}),
							"cell-user": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (row.user) _push(ssrRenderComponent(unref(Link), { href: `/admin/users/${row.user.id}` }, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(row.user.username)}`);
											else return [createTextVNode(toDisplayString(row.user.username), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									else _push(`<span class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("Unknown"))}</span>`);
								} else return [row.user ? (openBlock(), createBlock(unref(Link), {
									key: 0,
									href: `/admin/users/${row.user.id}`
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(row.user.username), 1)]),
									_: 2
								}, 1032, ["href"])) : (openBlock(), createBlock("span", {
									key: 1,
									class: "k-muted"
								}, toDisplayString(unref(__)("Unknown")), 1))];
							}),
							"cell-reason": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(reason(row))}`);
								else return [createTextVNode(toDisplayString(reason(row)), 1)];
							}),
							"cell-ip": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`<span class="k-mono-input"${_scopeId}>${ssrInterpolate(row.ip ?? "—")}</span>`);
								else return [createVNode("span", { class: "k-mono-input" }, toDisplayString(row.ip ?? "—"), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</div>`);
					} else return [createVNode("div", { class: "k-security-failed" }, [failed.value.top_ips.length ? (openBlock(), createBlock("section", {
						key: 0,
						class: "k-security-failed__ips",
						"aria-label": unref(__)("Where they came from")
					}, [createVNode("h3", { class: "k-security-sub" }, toDisplayString(unref(__)("Most attempts, last 7 days")), 1), createVNode("ol", null, [(openBlock(true), createBlock(Fragment, null, renderList(failed.value.top_ips, (ip) => {
						return openBlock(), createBlock("li", { key: ip.ip }, [
							createVNode("span", { class: "k-mono-input" }, toDisplayString(ip.ip), 1),
							createTextVNode(),
							createVNode("span", { class: "k-muted k-num" }, toDisplayString(ip.count), 1)
						]);
					}), 128))])], 8, ["aria-label"])) : createCommentVNode("v-if", true), createVNode(unref(KTable), {
						class: "k-security-failed__list",
						columns: [
							{
								key: "occurred_at",
								label: unref(__)("When")
							},
							{
								key: "user",
								label: unref(__)("Account")
							},
							{
								key: "reason",
								label: unref(__)("Why")
							},
							{
								key: "ip",
								label: unref(__)("Address")
							}
						],
						rows: failed.value.recent,
						empty: unref(__)("No failed sign-ins")
					}, {
						"cell-occurred_at": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.occurred_at }, null, 8, ["value"])]),
						"cell-user": withCtx(({ row }) => [row.user ? (openBlock(), createBlock(unref(Link), {
							key: 0,
							href: `/admin/users/${row.user.id}`
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.user.username), 1)]),
							_: 2
						}, 1032, ["href"])) : (openBlock(), createBlock("span", {
							key: 1,
							class: "k-muted"
						}, toDisplayString(unref(__)("Unknown")), 1))]),
						"cell-reason": withCtx(({ row }) => [createTextVNode(toDisplayString(reason(row)), 1)]),
						"cell-ip": withCtx(({ row }) => [createVNode("span", { class: "k-mono-input" }, toDisplayString(row.ip ?? "—"), 1)]),
						_: 1
					}, 8, [
						"columns",
						"rows",
						"empty"
					])])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KCard), {
				title: unref(__)("Signed-in sessions"),
				flush: ""
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (unref(sessions).meta.value?.supported === false) _push(ssrRenderComponent(unref(KAlert), { tone: "info" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("This server keeps sessions in files, which cannot be listed. Switch sessions to the database or Redis under Infrastructure to see who is signed in."))}`);
								else return [createTextVNode(toDisplayString(unref(__)("This server keeps sessions in files, which cannot be listed. Switch sessions to the database or Redis under Infrastructure to see who is signed in.")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(ssrRenderComponent(unref(KTable), {
							columns: [
								{
									key: "user",
									label: unref(__)("Person")
								},
								{
									key: "device",
									label: unref(__)("Device")
								},
								{
									key: "ip",
									label: unref(__)("Address")
								},
								{
									key: "last_active_at",
									label: unref(__)("Last active")
								},
								{
									key: "created_at",
									label: unref(__)("Signed in")
								},
								{
									key: "actions",
									label: unref(__)("Actions"),
									align: "end"
								}
							],
							rows: unref(sessions).data.value ?? [],
							loading: unref(sessions).loading.value,
							empty: unref(__)("Nobody is signed in.")
						}, {
							"cell-user": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(Link), { href: `/admin/users/${row.user.id}` }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(row.user.username)}`);
										else return [createTextVNode(toDisplayString(row.user.username), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else return [createVNode(unref(Link), { href: `/admin/users/${row.user.id}` }, {
									default: withCtx(() => [createTextVNode(toDisplayString(row.user.username), 1)]),
									_: 2
								}, 1032, ["href"])];
							}),
							"cell-device": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`${ssrInterpolate(unref(deviceLabel)(row.user_agent, unref(__)))} `);
									if (row.current) _push(ssrRenderComponent(unref(KBadge), { tone: "accent" }, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("This session"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("This session")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									else _push(`<!---->`);
								} else return [createTextVNode(toDisplayString(unref(deviceLabel)(row.user_agent, unref(__))) + " ", 1), row.current ? (openBlock(), createBlock(unref(KBadge), {
									key: 0,
									tone: "accent"
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("This session")), 1)]),
									_: 1
								})) : createCommentVNode("v-if", true)];
							}),
							"cell-ip": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(`<span class="k-mono-input"${_scopeId}>${ssrInterpolate(row.ip || "—")}</span>`);
								else return [createVNode("span", { class: "k-mono-input" }, toDisplayString(row.ip || "—"), 1)];
							}),
							"cell-last_active_at": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.last_active_at }, null, _parent, _scopeId));
								else return [createVNode(unref(KTime), { value: row.last_active_at }, null, 8, ["value"])];
							}),
							"cell-created_at": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.created_at }, null, _parent, _scopeId));
								else return [createVNode(unref(KTime), { value: row.created_at }, null, 8, ["value"])];
							}),
							"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (!row.current) {
										_push(`<span class="k-security-actions"${_scopeId}>`);
										_push(ssrRenderComponent(unref(KButton), {
											size: "sm",
											variant: "ghost",
											icon: "sign-out",
											onClick: ($event) => signOut(row)
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Sign out"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Sign out")), 1)];
											}),
											_: 2
										}, _parent, _scopeId));
										_push(ssrRenderComponent(unref(KButton), {
											size: "sm",
											variant: "ghost",
											onClick: ($event) => signOutEverywhere(row)
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Everywhere"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Everywhere")), 1)];
											}),
											_: 2
										}, _parent, _scopeId));
										_push(`</span>`);
									} else _push(`<!---->`);
								} else return [!row.current ? (openBlock(), createBlock("span", {
									key: 0,
									class: "k-security-actions"
								}, [createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "sign-out",
									onClick: ($event) => signOut(row)
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Sign out")), 1)]),
									_: 1
								}, 8, ["onClick"]), createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									onClick: ($event) => signOutEverywhere(row)
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Everywhere")), 1)]),
									_: 1
								}, 8, ["onClick"])])) : createCommentVNode("v-if", true)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [unref(sessions).meta.value?.supported === false ? (openBlock(), createBlock(unref(KAlert), {
						key: 0,
						tone: "info"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("This server keeps sessions in files, which cannot be listed. Switch sessions to the database or Redis under Infrastructure to see who is signed in.")), 1)]),
						_: 1
					})) : (openBlock(), createBlock(unref(KTable), {
						key: 1,
						columns: [
							{
								key: "user",
								label: unref(__)("Person")
							},
							{
								key: "device",
								label: unref(__)("Device")
							},
							{
								key: "ip",
								label: unref(__)("Address")
							},
							{
								key: "last_active_at",
								label: unref(__)("Last active")
							},
							{
								key: "created_at",
								label: unref(__)("Signed in")
							},
							{
								key: "actions",
								label: unref(__)("Actions"),
								align: "end"
							}
						],
						rows: unref(sessions).data.value ?? [],
						loading: unref(sessions).loading.value,
						empty: unref(__)("Nobody is signed in.")
					}, {
						"cell-user": withCtx(({ row }) => [createVNode(unref(Link), { href: `/admin/users/${row.user.id}` }, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.user.username), 1)]),
							_: 2
						}, 1032, ["href"])]),
						"cell-device": withCtx(({ row }) => [createTextVNode(toDisplayString(unref(deviceLabel)(row.user_agent, unref(__))) + " ", 1), row.current ? (openBlock(), createBlock(unref(KBadge), {
							key: 0,
							tone: "accent"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("This session")), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true)]),
						"cell-ip": withCtx(({ row }) => [createVNode("span", { class: "k-mono-input" }, toDisplayString(row.ip || "—"), 1)]),
						"cell-last_active_at": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.last_active_at }, null, 8, ["value"])]),
						"cell-created_at": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.created_at }, null, 8, ["value"])]),
						"cell-actions": withCtx(({ row }) => [!row.current ? (openBlock(), createBlock("span", {
							key: 0,
							class: "k-security-actions"
						}, [createVNode(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "sign-out",
							onClick: ($event) => signOut(row)
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Sign out")), 1)]),
							_: 1
						}, 8, ["onClick"]), createVNode(unref(KButton), {
							size: "sm",
							variant: "ghost",
							onClick: ($event) => signOutEverywhere(row)
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Everywhere")), 1)]),
							_: 1
						}, 8, ["onClick"])])) : createCommentVNode("v-if", true)]),
						_: 1
					}, 8, [
						"columns",
						"rows",
						"loading",
						"empty"
					]))];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KCard), {
				title: unref(__)("Access tokens"),
				flush: ""
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: [
							{
								key: "name",
								label: unref(__)("Name")
							},
							{
								key: "user",
								label: unref(__)("Person")
							},
							{
								key: "scopes",
								label: unref(__)("Can")
							},
							{
								key: "last_used_at",
								label: unref(__)("Last used")
							},
							{
								key: "expires_at",
								label: unref(__)("Expires")
							},
							{
								key: "actions",
								label: unref(__)("Actions"),
								align: "end"
							}
						],
						rows: unref(tokens).data.value ?? [],
						loading: unref(tokens).loading.value,
						empty: unref(__)("Nobody has made an access token.")
					}, {
						"cell-name": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(`<strong${_scopeId}>${ssrInterpolate(row.name)}</strong>`);
							else return [createVNode("strong", null, toDisplayString(row.name), 1)];
						}),
						"cell-user": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(Link), { href: `/admin/users/${row.user.id}` }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(row.user.username ?? "?")}`);
									else return [createTextVNode(toDisplayString(row.user.username ?? "?"), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else return [createVNode(unref(Link), { href: `/admin/users/${row.user.id}` }, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.user.username ?? "?"), 1)]),
								_: 2
							}, 1032, ["href"])];
						}),
						"cell-scopes": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<span class="k-security-scopes"${_scopeId}><!--[-->`);
								ssrRenderList(row.scopes, (s) => {
									_push(ssrRenderComponent(unref(KBadge), {
										key: s,
										tone: s === "admin" ? "warning" : "neutral"
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(s)}`);
											else return [createTextVNode(toDisplayString(s), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
								});
								_push(`<!--]--></span>`);
							} else return [createVNode("span", { class: "k-security-scopes" }, [(openBlock(true), createBlock(Fragment, null, renderList(row.scopes, (s) => {
								return openBlock(), createBlock(unref(KBadge), {
									key: s,
									tone: s === "admin" ? "warning" : "neutral"
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(s), 1)]),
									_: 2
								}, 1032, ["tone"]);
							}), 128))])];
						}),
						"cell-last_used_at": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (row.last_used_at) {
									_push(`<!--[-->`);
									_push(ssrRenderComponent(unref(KTime), { value: row.last_used_at }, null, _parent, _scopeId));
									_push(` <span class="k-muted k-small k-mono-input"${_scopeId}>${ssrInterpolate(row.last_used_ip)}</span><!--]-->`);
								} else _push(`<span class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("Never"))}</span>`);
							} else return [row.last_used_at ? (openBlock(), createBlock(Fragment, { key: 0 }, [
								createVNode(unref(KTime), { value: row.last_used_at }, null, 8, ["value"]),
								createTextVNode(),
								createVNode("span", { class: "k-muted k-small k-mono-input" }, toDisplayString(row.last_used_ip), 1)
							], 64)) : (openBlock(), createBlock("span", {
								key: 1,
								class: "k-muted"
							}, toDisplayString(unref(__)("Never")), 1))];
						}),
						"cell-expires_at": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (row.expired) _push(ssrRenderComponent(unref(KBadge), { tone: "neutral" }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Expired"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Expired")), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else if (row.expires_at) _push(ssrRenderComponent(unref(KTime), { value: row.expires_at }, null, _parent, _scopeId));
								else _push(`<span class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("Never"))}</span>`);
							} else return [row.expired ? (openBlock(), createBlock(unref(KBadge), {
								key: 0,
								tone: "neutral"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Expired")), 1)]),
								_: 1
							})) : row.expires_at ? (openBlock(), createBlock(unref(KTime), {
								key: 1,
								value: row.expires_at
							}, null, 8, ["value"])) : (openBlock(), createBlock("span", {
								key: 2,
								class: "k-muted"
							}, toDisplayString(unref(__)("Never")), 1))];
						}),
						"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "trash",
								onClick: ($event) => revoke(row)
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Revoke"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Revoke")), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else return [createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "trash",
								onClick: ($event) => revoke(row)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Revoke")), 1)]),
								_: 1
							}, 8, ["onClick"])];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: [
							{
								key: "name",
								label: unref(__)("Name")
							},
							{
								key: "user",
								label: unref(__)("Person")
							},
							{
								key: "scopes",
								label: unref(__)("Can")
							},
							{
								key: "last_used_at",
								label: unref(__)("Last used")
							},
							{
								key: "expires_at",
								label: unref(__)("Expires")
							},
							{
								key: "actions",
								label: unref(__)("Actions"),
								align: "end"
							}
						],
						rows: unref(tokens).data.value ?? [],
						loading: unref(tokens).loading.value,
						empty: unref(__)("Nobody has made an access token.")
					}, {
						"cell-name": withCtx(({ row }) => [createVNode("strong", null, toDisplayString(row.name), 1)]),
						"cell-user": withCtx(({ row }) => [createVNode(unref(Link), { href: `/admin/users/${row.user.id}` }, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.user.username ?? "?"), 1)]),
							_: 2
						}, 1032, ["href"])]),
						"cell-scopes": withCtx(({ row }) => [createVNode("span", { class: "k-security-scopes" }, [(openBlock(true), createBlock(Fragment, null, renderList(row.scopes, (s) => {
							return openBlock(), createBlock(unref(KBadge), {
								key: s,
								tone: s === "admin" ? "warning" : "neutral"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(s), 1)]),
								_: 2
							}, 1032, ["tone"]);
						}), 128))])]),
						"cell-last_used_at": withCtx(({ row }) => [row.last_used_at ? (openBlock(), createBlock(Fragment, { key: 0 }, [
							createVNode(unref(KTime), { value: row.last_used_at }, null, 8, ["value"]),
							createTextVNode(),
							createVNode("span", { class: "k-muted k-small k-mono-input" }, toDisplayString(row.last_used_ip), 1)
						], 64)) : (openBlock(), createBlock("span", {
							key: 1,
							class: "k-muted"
						}, toDisplayString(unref(__)("Never")), 1))]),
						"cell-expires_at": withCtx(({ row }) => [row.expired ? (openBlock(), createBlock(unref(KBadge), {
							key: 0,
							tone: "neutral"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Expired")), 1)]),
							_: 1
						})) : row.expires_at ? (openBlock(), createBlock(unref(KTime), {
							key: 1,
							value: row.expires_at
						}, null, 8, ["value"])) : (openBlock(), createBlock("span", {
							key: 2,
							class: "k-muted"
						}, toDisplayString(unref(__)("Never")), 1))]),
						"cell-actions": withCtx(({ row }) => [createVNode(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "trash",
							onClick: ($event) => revoke(row)
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Revoke")), 1)]),
							_: 1
						}, 8, ["onClick"])]),
						_: 1
					}, 8, [
						"columns",
						"rows",
						"loading",
						"empty"
					])];
				}),
				_: 1
			}, _parent));
			if (unref(overview).data.value) _push(ssrRenderComponent(unref(KCard), {
				title: unref(__)("Recent account events"),
				flush: ""
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(Link), {
						href: "/admin/system/audit?area=auth.*",
						class: "k-small"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Open the audit log"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Open the audit log")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(Link), {
						href: "/admin/system/audit?area=auth.*",
						class: "k-small"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Open the audit log")), 1)]),
						_: 1
					})];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: [
							{
								key: "occurred_at",
								label: unref(__)("When")
							},
							{
								key: "actor",
								label: unref(__)("Who")
							},
							{
								key: "event",
								label: unref(__)("What")
							},
							{
								key: "ip",
								label: unref(__)("Address")
							}
						],
						rows: unref(overview).data.value.events,
						empty: unref(__)("Nothing yet.")
					}, {
						"cell-occurred_at": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KTime), { value: row.occurred_at }, null, _parent, _scopeId));
							else return [createVNode(unref(KTime), { value: row.occurred_at }, null, 8, ["value"])];
						}),
						"cell-actor": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (row.actor?.username) _push(ssrRenderComponent(unref(Link), { href: `/admin/users/${row.actor.id}` }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(row.actor.username)}`);
										else return [createTextVNode(toDisplayString(row.actor.username), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else if (row.actor) _push(`<span class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("A deleted account"))}</span>`);
								else _push(`<span class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("The system"))}</span>`);
							} else return [row.actor?.username ? (openBlock(), createBlock(unref(Link), {
								key: 0,
								href: `/admin/users/${row.actor.id}`
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.actor.username), 1)]),
								_: 2
							}, 1032, ["href"])) : row.actor ? (openBlock(), createBlock("span", {
								key: 1,
								class: "k-muted"
							}, toDisplayString(unref(__)("A deleted account")), 1)) : (openBlock(), createBlock("span", {
								key: 2,
								class: "k-muted"
							}, toDisplayString(unref(__)("The system")), 1))];
						}),
						"cell-event": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (unref(AUDIT_WARN).has(row.event)) _push(ssrRenderComponent(unref(KBadge), { tone: "warning" }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(auditLabel)(row.event))}`);
										else return [createTextVNode(toDisplayString(unref(auditLabel)(row.event)), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else _push(`<!--[-->${ssrInterpolate(unref(auditLabel)(row.event))}<!--]-->`);
							} else return [unref(AUDIT_WARN).has(row.event) ? (openBlock(), createBlock(unref(KBadge), {
								key: 0,
								tone: "warning"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(auditLabel)(row.event)), 1)]),
								_: 2
							}, 1024)) : (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(toDisplayString(unref(auditLabel)(row.event)), 1)], 64))];
						}),
						"cell-ip": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(`<span class="k-mono-input"${_scopeId}>${ssrInterpolate(row.ip ?? "—")}</span>`);
							else return [createVNode("span", { class: "k-mono-input" }, toDisplayString(row.ip ?? "—"), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: [
							{
								key: "occurred_at",
								label: unref(__)("When")
							},
							{
								key: "actor",
								label: unref(__)("Who")
							},
							{
								key: "event",
								label: unref(__)("What")
							},
							{
								key: "ip",
								label: unref(__)("Address")
							}
						],
						rows: unref(overview).data.value.events,
						empty: unref(__)("Nothing yet.")
					}, {
						"cell-occurred_at": withCtx(({ row }) => [createVNode(unref(KTime), { value: row.occurred_at }, null, 8, ["value"])]),
						"cell-actor": withCtx(({ row }) => [row.actor?.username ? (openBlock(), createBlock(unref(Link), {
							key: 0,
							href: `/admin/users/${row.actor.id}`
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.actor.username), 1)]),
							_: 2
						}, 1032, ["href"])) : row.actor ? (openBlock(), createBlock("span", {
							key: 1,
							class: "k-muted"
						}, toDisplayString(unref(__)("A deleted account")), 1)) : (openBlock(), createBlock("span", {
							key: 2,
							class: "k-muted"
						}, toDisplayString(unref(__)("The system")), 1))]),
						"cell-event": withCtx(({ row }) => [unref(AUDIT_WARN).has(row.event) ? (openBlock(), createBlock(unref(KBadge), {
							key: 0,
							tone: "warning"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(auditLabel)(row.event)), 1)]),
							_: 2
						}, 1024)) : (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(toDisplayString(unref(auditLabel)(row.event)), 1)], 64))]),
						"cell-ip": withCtx(({ row }) => [createVNode("span", { class: "k-mono-input" }, toDisplayString(row.ip ?? "—"), 1)]),
						_: 1
					}, 8, [
						"columns",
						"rows",
						"empty"
					])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Security.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
