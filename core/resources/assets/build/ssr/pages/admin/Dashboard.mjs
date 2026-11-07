import { n as auditLabel } from "../../chunks/events-DFJBVCyy.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, renderList, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head, Link } from "@kestello/app";
import { __, useAdmin, useCan, useExtensions, useResource, useUser } from "@kestello/sdk";
import { KBadge, KButton, KCard, KEmpty, KExtension, KRevision, KStat, KStatusBadge, KTable, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/Dashboard.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Dashboard",
	__ssrInlineRender: true,
	setup(__props) {
		const can = useCan();
		const user = useUser();
		const admin = useAdmin();
		const system = can("system.manage");
		const health = system ? useResource("/system/health") : null;
		const queue = system ? useResource("/system/queue", { poll: 30 }) : null;
		const schedule = system ? useResource("/system/schedule") : null;
		const users = can("users.read") ? useResource("/users", { query: { limit: 5 } }) : null;
		const audit = can("audit.read") ? useResource("/audit", { query: { limit: 7 } }) : null;
		const notifications = useResource("/me/notifications", { query: { limit: 5 } });
		const problems = computed(() => (health?.data.value?.checks ?? []).filter((c) => c.status !== "good").sort((a, b) => (a.status === "critical" ? 0 : 1) - (b.status === "critical" ? 0 : 1)));
		const lead = computed(() => problems.value[0] ?? null);
		const pending = computed(() => (queue?.data.value?.queues ?? []).reduce((sum, q) => sum + q.pending, 0));
		const heartbeat = computed(() => schedule?.meta.value?.heartbeat ?? null);
		const name = computed(() => user.value?.username ?? "");
		const greeting = computed(() => {
			const hour = (/* @__PURE__ */ new Date()).getHours();
			return hour < 12 ? __("Good morning, {name}", { name: name.value }) : hour < 18 ? __("Good afternoon, {name}", { name: name.value }) : __("Good evening, {name}", { name: name.value });
		});
		const status = computed(() => {
			if (!system) return {
				text: __("Here is what changed since you were last here."),
				mark: ""
			};
			const h = health.data.value;
			if (!h) return {
				text: __("Checking your site…"),
				mark: ""
			};
			if (h.counts.critical) return {
				text: __("Your site has a problem."),
				mark: h.counts.critical === 1 ? __("One thing needs you now.") : __("{n} things need you now.", { n: h.counts.critical })
			};
			if (h.counts.recommended) return {
				text: __("Your site is running."),
				mark: h.counts.recommended === 1 ? __("One improvement is recommended.") : __("{n} improvements are recommended.", { n: h.counts.recommended })
			};
			return {
				text: __("Your site is running well. Nothing needs you."),
				mark: ""
			};
		});
		const healthTone = computed(() => ({
			critical: "danger",
			recommended: "warning",
			good: "success"
		})[health?.data.value?.status] ?? "neutral");
		const healthValue = computed(() => {
			const h = health?.data.value;
			if (!h) return "…";
			if (h.status === "critical") return __("{n} critical", { n: h.counts.critical });
			if (h.status === "recommended") return __("{n} to review", { n: h.counts.recommended });
			return __("All clear");
		});
		const rest = computed(() => problems.value.slice(1, 6));
		const SUBSYSTEMS = {
			cache: __("the cache"),
			session: __("sign-in sessions"),
			queue: __("background jobs"),
			mail: __("email"),
			storage: __("file storage")
		};
		function describe(row) {
			const who = row.actor ? row.actor.username ?? __("A deleted account") : __("Kestello");
			const d = row.data ?? {};
			switch (row.event) {
				case "auth.login": return __("{who} signed in", { who });
				case "auth.logout": return __("{who} signed out", { who });
				case "auth.registered": return __("{who} created an account", { who });
				case "auth.password_changed": return __("{who} changed their password", { who });
				case "auth.password_reset_requested": return __("A password reset was requested");
				case "auth.2fa_enabled": return __("{who} turned on two-step sign-in", { who });
				case "auth.2fa_disabled": return __("{who} turned off two-step sign-in", { who });
				case "auth.session_revoked": return __("{who} signed out a device", { who });
				case "auth.token_created": return __("{who} created an API token", { who });
				case "auth.token_revoked": return __("{who} revoked an API token", { who });
				case "auth.identity_linked": return __("{who} linked a sign-in account", { who });
				case "auth.identity_unlinked": return __("{who} unlinked a sign-in account", { who });
				case "platform.changed": return d.reset ? __("{who} reset {what} to its defaults", {
					who,
					what: SUBSYSTEMS[row.subject_id] ?? row.subject_id
				}) : __("{who} switched {what} to {to}", {
					who,
					what: SUBSYSTEMS[row.subject_id] ?? row.subject_id,
					to: d.to
				});
				case "queue.retried": return __("{who} retried failed jobs", { who });
				case "queue.forgotten": return __("{who} deleted failed jobs", { who });
				case "schedule.run": return __("{who} ran a scheduled task", { who });
				case "settings.updated": return __("{who} changed the site settings", { who });
				case "users.created": return __("{who} added {name}", {
					who,
					name: d.username ?? __("a person")
				});
				case "users.updated": return __("{who} updated an account", { who });
				case "users.deleted": return __("{who} deleted the account of {name}", {
					who,
					name: d.username ?? __("a person")
				});
				case "profile.updated": return __("{who} updated their profile", { who });
				default: return __("{who}: {what}", {
					who,
					what: auditLabel(row.event)
				});
			}
		}
		const SPAN = {
			quarter: 1,
			half: 2,
			full: 4
		};
		const widgetList = useExtensions("widgets", "dashboard");
		const widgets = computed(() => {
			let row = 4;
			let col = 0;
			return widgetList.value.map((w) => {
				const span = SPAN[w.size] ?? 2;
				if (col + span > 4) {
					row += 1;
					col = 0;
				}
				const coord = `${"ABCD"[col]}${row}`;
				col += span;
				return {
					...w,
					span,
					coord
				};
			});
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("Overview") }, null, _parent));
			_push(`<header class="k-page-header"><div><h1 class="k-page-header__title">${ssrInterpolate(greeting.value)}</h1><p class="k-status-line">${ssrInterpolate(status.value.text)} `);
			if (status.value.mark) {
				_push(`<!--[-->`);
				if (unref(system)) _push(ssrRenderComponent(unref(Link), {
					href: "/admin/system/health",
					class: "k-status-line__mark"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(status.value.mark)}`);
						else return [createTextVNode(toDisplayString(status.value.mark), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<strong>${ssrInterpolate(status.value.mark)}</strong>`);
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(`</p></div><div class="k-page-header__actions">`);
			_push(ssrRenderComponent(unref(KButton), {
				href: "/",
				icon: "arrow-square-out"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("View site"))}`);
					else return [createTextVNode(toDisplayString(unref(__)("View site")), 1)];
				}),
				_: 1
			}, _parent));
			_push(`</div></header>`);
			if (lead.value) _push(ssrRenderComponent(unref(KRevision), { number: 1 }, {
				action: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						variant: lead.value.status === "critical" ? "primary" : "secondary",
						href: "/admin/system/health"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Review"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Review")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						variant: lead.value.status === "critical" ? "primary" : "secondary",
						href: "/admin/system/health"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Review")), 1)]),
						_: 1
					}, 8, ["variant"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`<strong${_scopeId}>${ssrInterpolate(lead.value.label)}</strong><span${_scopeId}>${ssrInterpolate(lead.value.message)}</span>`);
					else return [createVNode("strong", null, toDisplayString(lead.value.label), 1), createVNode("span", null, toDisplayString(lead.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (unref(system)) {
				_push(`<div class="k-cells" style="--k-cols:4;">`);
				_push(ssrRenderComponent(unref(KStat), {
					coord: "A1",
					label: unref(__)("Site health"),
					tone: healthTone.value,
					value: healthValue.value
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(health).data.value ? unref(__)("{good} passed · {recommended} recommended · {critical} critical", unref(health).data.value.counts) : unref(__)("Checking…"))}`);
						else return [createTextVNode(toDisplayString(unref(health).data.value ? unref(__)("{good} passed · {recommended} recommended · {critical} critical", unref(health).data.value.counts) : unref(__)("Checking…")), 1)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KStat), {
					coord: "B1",
					label: unref(__)("Background jobs"),
					tone: unref(queue).data.value?.failed ? "warning" : "success",
					value: unref(queue).data.value ? pending.value === 0 ? unref(__)("Idle") : unref(__)("{n} waiting", { n: pending.value }) : "…"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(queue).data.value ? unref(queue).data.value.sync ? unref(__)("Run right away (no queue)") : unref(__)("{count} failed", { count: unref(queue).data.value.failed }) : "")}`);
						else return [createTextVNode(toDisplayString(unref(queue).data.value ? unref(queue).data.value.sync ? unref(__)("Run right away (no queue)") : unref(__)("{count} failed", { count: unref(queue).data.value.failed }) : ""), 1)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KStat), {
					coord: "C1",
					label: unref(__)("Scheduler"),
					value: heartbeat.value ? unref(__)("Running") : unref(__)("Not running"),
					tone: heartbeat.value ? "success" : "danger"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							if (heartbeat.value) {
								_push(`<!--[-->${ssrInterpolate(unref(__)("Last run"))} `);
								_push(ssrRenderComponent(unref(KTime), { value: heartbeat.value.at }, null, _parent, _scopeId));
								_push(`<!--]-->`);
							} else _push(`<!--[-->${ssrInterpolate(unref(__)("Set up cron or the web runner"))}<!--]-->`);
						} else return [heartbeat.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(toDisplayString(unref(__)("Last run")) + " ", 1), createVNode(unref(KTime), { value: heartbeat.value.at }, null, 8, ["value"])], 64)) : (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(toDisplayString(unref(__)("Set up cron or the web runner")), 1)], 64))];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KStat), {
					coord: "D1",
					label: unref(__)("Kestello"),
					value: unref(admin).version
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Core version"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Core version")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`<div class="k-cells" style="--k-cols:4;">`);
			if (unref(system)) _push(ssrRenderComponent(unref(KCard), {
				coord: "A2",
				class: "k-span-2",
				title: unref(__)("Needs attention")
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(Link), {
						href: "/admin/system/health",
						class: "k-link"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Site health"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Site health")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(Link), {
						href: "/admin/system/health",
						class: "k-link"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Site health")), 1)]),
						_: 1
					})];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (rest.value.length) {
							_push(`<ul class="k-list"${_scopeId}><!--[-->`);
							ssrRenderList(rest.value, (check) => {
								_push(`<li class="k-list__item"${_scopeId}><div class="k-list__main"${_scopeId}><strong${_scopeId}>${ssrInterpolate(check.label)}</strong><p class="k-muted"${_scopeId}>${ssrInterpolate(check.message)}</p></div>`);
								_push(ssrRenderComponent(unref(KStatusBadge), { status: check.status }, null, _parent, _scopeId));
								_push(`</li>`);
							});
							_push(`<!--]--></ul>`);
						} else if (unref(health).data.value) _push(ssrRenderComponent(unref(KEmpty), {
							icon: "check-circle",
							title: lead.value ? unref(__)("Nothing else needs you.") : unref(__)("Nothing needs you.")
						}, null, _parent, _scopeId));
						else _push(`<p class="k-muted"${_scopeId}>${ssrInterpolate(unref(__)("Running checks…"))}</p>`);
					} else return [rest.value.length ? (openBlock(), createBlock("ul", {
						key: 0,
						class: "k-list"
					}, [(openBlock(true), createBlock(Fragment, null, renderList(rest.value, (check) => {
						return openBlock(), createBlock("li", {
							key: check.id,
							class: "k-list__item"
						}, [createVNode("div", { class: "k-list__main" }, [createVNode("strong", null, toDisplayString(check.label), 1), createVNode("p", { class: "k-muted" }, toDisplayString(check.message), 1)]), createVNode(unref(KStatusBadge), { status: check.status }, null, 8, ["status"])]);
					}), 128))])) : unref(health).data.value ? (openBlock(), createBlock(unref(KEmpty), {
						key: 1,
						icon: "check-circle",
						title: lead.value ? unref(__)("Nothing else needs you.") : unref(__)("Nothing needs you.")
					}, null, 8, ["title"])) : (openBlock(), createBlock("p", {
						key: 2,
						class: "k-muted"
					}, toDisplayString(unref(__)("Running checks…")), 1))];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KCard), {
				coord: unref(system) ? "C2" : "A2",
				class: "k-span-2",
				title: unref(audit) ? unref(__)("Recent activity") : unref(__)("Notifications")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (unref(audit) && unref(audit).data.value?.length) {
							_push(`<ul class="k-list"${_scopeId}><!--[-->`);
							ssrRenderList(unref(audit).data.value, (row) => {
								_push(`<li class="k-list__item"${_scopeId}><div class="k-list__main"${_scopeId}>${ssrInterpolate(describe(row))}</div>`);
								_push(ssrRenderComponent(unref(KTime), {
									value: row.occurred_at,
									class: "k-list__aside"
								}, null, _parent, _scopeId));
								_push(`</li>`);
							});
							_push(`<!--]--></ul>`);
						} else if (!unref(audit) && unref(notifications).data.value?.length) {
							_push(`<ul class="k-list"${_scopeId}><!--[-->`);
							ssrRenderList(unref(notifications).data.value, (item) => {
								_push(`<li class="k-list__item"${_scopeId}><div class="k-list__main"${_scopeId}><strong${_scopeId}>${ssrInterpolate(item.data?.title ?? item.type)}</strong>`);
								if (item.data?.body) _push(`<p class="k-muted"${_scopeId}>${ssrInterpolate(item.data.body)}</p>`);
								else _push(`<!---->`);
								_push(`</div>`);
								_push(ssrRenderComponent(unref(KTime), {
									value: item.created_at,
									class: "k-list__aside"
								}, null, _parent, _scopeId));
								_push(`</li>`);
							});
							_push(`<!--]--></ul>`);
						} else _push(ssrRenderComponent(unref(KEmpty), {
							icon: "clock-counter-clockwise",
							title: unref(__)("Nothing has happened yet.")
						}, null, _parent, _scopeId));
					} else return [unref(audit) && unref(audit).data.value?.length ? (openBlock(), createBlock("ul", {
						key: 0,
						class: "k-list"
					}, [(openBlock(true), createBlock(Fragment, null, renderList(unref(audit).data.value, (row) => {
						return openBlock(), createBlock("li", {
							key: row.id,
							class: "k-list__item"
						}, [createVNode("div", { class: "k-list__main" }, toDisplayString(describe(row)), 1), createVNode(unref(KTime), {
							value: row.occurred_at,
							class: "k-list__aside"
						}, null, 8, ["value"])]);
					}), 128))])) : !unref(audit) && unref(notifications).data.value?.length ? (openBlock(), createBlock("ul", {
						key: 1,
						class: "k-list"
					}, [(openBlock(true), createBlock(Fragment, null, renderList(unref(notifications).data.value, (item) => {
						return openBlock(), createBlock("li", {
							key: item.id,
							class: "k-list__item"
						}, [createVNode("div", { class: "k-list__main" }, [createVNode("strong", null, toDisplayString(item.data?.title ?? item.type), 1), item.data?.body ? (openBlock(), createBlock("p", {
							key: 0,
							class: "k-muted"
						}, toDisplayString(item.data.body), 1)) : createCommentVNode("v-if", true)]), createVNode(unref(KTime), {
							value: item.created_at,
							class: "k-list__aside"
						}, null, 8, ["value"])]);
					}), 128))])) : (openBlock(), createBlock(unref(KEmpty), {
						key: 2,
						icon: "clock-counter-clockwise",
						title: unref(__)("Nothing has happened yet.")
					}, null, 8, ["title"]))];
				}),
				_: 1
			}, _parent));
			if (unref(users)) _push(ssrRenderComponent(unref(KCard), {
				coord: "A3",
				class: "k-span-2",
				title: unref(__)("Newest people"),
				flush: ""
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(Link), {
						href: "/admin/users",
						class: "k-link"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("All people"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("All people")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(Link), {
						href: "/admin/users",
						class: "k-link"
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("All people")), 1)]),
						_: 1
					})];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns: [
							{
								key: "username",
								label: unref(__)("Name")
							},
							{
								key: "role",
								label: unref(__)("Role")
							},
							{
								key: "created_at",
								label: unref(__)("Joined"),
								align: "end"
							}
						],
						rows: unref(users).data.value ?? [],
						loading: unref(users).loading.value,
						empty: unref(__)("No one yet.")
					}, {
						"cell-username": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(`<strong${_scopeId}>${ssrInterpolate(row.username)}</strong><div class="k-muted k-small"${_scopeId}>${ssrInterpolate(row.email)}</div>`);
							else return [createVNode("strong", null, toDisplayString(row.username), 1), createVNode("div", { class: "k-muted k-small" }, toDisplayString(row.email), 1)];
						}),
						"cell-role": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KBadge), null, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(row.role_label ?? row.role)}`);
									else return [createTextVNode(toDisplayString(row.role_label ?? row.role), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else return [createVNode(unref(KBadge), null, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.role_label ?? row.role), 1)]),
								_: 2
							}, 1024)];
						}),
						"cell-created_at": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(`<span class="k-muted"${_scopeId}>${ssrInterpolate(row.created_at ? String(row.created_at).slice(0, 10) : "—")}</span>`);
							else return [createVNode("span", { class: "k-muted" }, toDisplayString(row.created_at ? String(row.created_at).slice(0, 10) : "—"), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns: [
							{
								key: "username",
								label: unref(__)("Name")
							},
							{
								key: "role",
								label: unref(__)("Role")
							},
							{
								key: "created_at",
								label: unref(__)("Joined"),
								align: "end"
							}
						],
						rows: unref(users).data.value ?? [],
						loading: unref(users).loading.value,
						empty: unref(__)("No one yet.")
					}, {
						"cell-username": withCtx(({ row }) => [createVNode("strong", null, toDisplayString(row.username), 1), createVNode("div", { class: "k-muted k-small" }, toDisplayString(row.email), 1)]),
						"cell-role": withCtx(({ row }) => [createVNode(unref(KBadge), null, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.role_label ?? row.role), 1)]),
							_: 2
						}, 1024)]),
						"cell-created_at": withCtx(({ row }) => [createVNode("span", { class: "k-muted" }, toDisplayString(row.created_at ? String(row.created_at).slice(0, 10) : "—"), 1)]),
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
			else _push(`<!---->`);
			if (unref(audit)) _push(ssrRenderComponent(unref(KCard), {
				coord: "C3",
				class: "k-span-2",
				title: unref(__)("Notifications")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (unref(notifications).data.value?.length) {
							_push(`<ul class="k-list"${_scopeId}><!--[-->`);
							ssrRenderList(unref(notifications).data.value, (item) => {
								_push(`<li class="k-list__item"${_scopeId}><div class="k-list__main"${_scopeId}><strong${_scopeId}>${ssrInterpolate(item.data?.title ?? item.type)}</strong>`);
								if (item.data?.body) _push(`<p class="k-muted"${_scopeId}>${ssrInterpolate(item.data.body)}</p>`);
								else _push(`<!---->`);
								_push(`</div>`);
								_push(ssrRenderComponent(unref(KTime), {
									value: item.created_at,
									class: "k-list__aside"
								}, null, _parent, _scopeId));
								_push(`</li>`);
							});
							_push(`<!--]--></ul>`);
						} else _push(ssrRenderComponent(unref(KEmpty), {
							icon: "bell-slash",
							title: unref(__)("No notifications yet.")
						}, null, _parent, _scopeId));
					} else return [unref(notifications).data.value?.length ? (openBlock(), createBlock("ul", {
						key: 0,
						class: "k-list"
					}, [(openBlock(true), createBlock(Fragment, null, renderList(unref(notifications).data.value, (item) => {
						return openBlock(), createBlock("li", {
							key: item.id,
							class: "k-list__item"
						}, [createVNode("div", { class: "k-list__main" }, [createVNode("strong", null, toDisplayString(item.data?.title ?? item.type), 1), item.data?.body ? (openBlock(), createBlock("p", {
							key: 0,
							class: "k-muted"
						}, toDisplayString(item.data.body), 1)) : createCommentVNode("v-if", true)]), createVNode(unref(KTime), {
							value: item.created_at,
							class: "k-list__aside"
						}, null, 8, ["value"])]);
					}), 128))])) : (openBlock(), createBlock(unref(KEmpty), {
						key: 1,
						icon: "bell-slash",
						title: unref(__)("No notifications yet.")
					}, null, 8, ["title"]))];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(`</div>`);
			if (widgets.value.length) {
				_push(`<div class="k-cells" style="--k-cols:4;"><!--[-->`);
				ssrRenderList(widgets.value, (w) => {
					_push(ssrRenderComponent(unref(KCard), {
						key: w.id,
						coord: w.coord,
						title: w.title,
						class: w.span === 4 ? "k-span-all" : w.span === 2 ? "k-span-2" : null
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KExtension), {
								name: w.component,
								owner: w.owner,
								context: {}
							}, null, _parent, _scopeId));
							else return [createVNode(unref(KExtension), {
								name: w.component,
								owner: w.owner,
								context: {}
							}, null, 8, ["name", "owner"])];
						}),
						_: 2
					}, _parent));
				});
				_push(`<!--]--></div>`);
			} else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/Dashboard.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
