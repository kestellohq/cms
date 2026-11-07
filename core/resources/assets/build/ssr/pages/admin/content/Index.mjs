import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, openBlock, reactive, ref, renderList, toDisplayString, unref, useSSRContext, watch, withCtx } from "vue";
import { ssrInterpolate, ssrRenderClass, ssrRenderComponent } from "vue/server-renderer";
import { Head, Link, usePage } from "@kestello/app";
import { __, confirm, notify, useApi, useExtensions, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KExtension, KField, KInput, KPageHeader, KSelect, KTable, KTabs, KWhen } from "@kestello/ui";
// core/resources/js/pages/admin/content/Index.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Index",
	__ssrInlineRender: true,
	setup(__props) {
		const page = usePage();
		const api = useApi();
		const typeName = computed(() => String(page.props.params?.type ?? ""));
		const types = useResource("/content/types");
		const type = computed(() => (types.data.value ?? []).find((t) => t.name === typeName.value) ?? null);
		const filters = reactive({
			status: "any",
			search: "",
			sort: "-updated_at",
			page: 1
		});
		const rows = ref([]);
		const meta = ref(null);
		const loading = ref(false);
		const failed = ref(null);
		let seq = 0;
		async function load() {
			const mine = ++seq;
			loading.value = true;
			try {
				const body = await api.get(`/content/${typeName.value}/entries`, {
					status: filters.status,
					search: filters.search.trim() || void 0,
					sort: filters.sort,
					page: filters.page,
					per_page: 25
				});
				if (mine !== seq) return;
				if (!body.data.length && filters.page > 1 && body.meta.last_page >= 1 && filters.page > body.meta.last_page) {
					filters.page = body.meta.last_page;
					return;
				}
				rows.value = body.data;
				meta.value = body.meta;
				failed.value = null;
			} catch (e) {
				if (mine === seq) failed.value = e;
			} finally {
				if (mine === seq) loading.value = false;
			}
		}
		let debounce = null;
		watch(() => filters.search, () => {
			clearTimeout(debounce);
			debounce = setTimeout(() => {
				filters.page = 1;
				load();
			}, 250);
		});
		watch(() => [filters.status, filters.sort], () => {
			filters.page = 1;
			load();
		});
		watch(() => filters.page, load);
		watch(typeName, () => {
			Object.assign(filters, {
				status: "any",
				search: "",
				sort: "-updated_at",
				page: 1
			});
			rows.value = [];
			load();
		}, { immediate: true });
		const STATUS = {
			draft: {
				label: __("Draft"),
				tone: "neutral"
			},
			pending: {
				label: __("In review"),
				tone: "accent"
			},
			scheduled: {
				label: __("Scheduled"),
				tone: "info"
			},
			published: {
				label: __("Published"),
				tone: "success"
			},
			private: {
				label: __("Private"),
				tone: "warning"
			}
		};
		const tabs = computed(() => {
			const c = meta.value?.counts ?? {};
			return [
				{
					id: "any",
					label: __("All"),
					count: c.any
				},
				{
					id: "published",
					label: __("Published"),
					count: c.published
				},
				{
					id: "pending",
					label: __("In review"),
					count: c.pending
				},
				{
					id: "draft",
					label: __("Drafts"),
					count: c.draft
				},
				{
					id: "scheduled",
					label: __("Scheduled"),
					count: c.scheduled
				},
				{
					id: "private",
					label: __("Private"),
					count: c.private
				},
				{
					id: "trash",
					label: __("Trash"),
					count: c.trash
				}
			].filter((t) => t.id === "any" || t.id === filters.status || (t.count ?? 0) > 0);
		});
		const trash = computed(() => filters.status === "trash");
		const extColumns = useExtensions("columns", "entries.list", () => ({ type: typeName.value }));
		const columns = computed(() => [
			{
				key: "when",
				label: trash.value ? __("Trashed") : __("When"),
				class: "k-when"
			},
			{
				key: "title",
				label: __("Title")
			},
			...extColumns.value.map((c) => ({
				key: c.key,
				label: c.label,
				align: c.align
			})),
			{
				key: "author",
				label: __("Author")
			},
			{
				key: "status",
				label: __("Status")
			},
			{
				key: "actions",
				label: "",
				align: "end",
				width: trash.value ? "220px" : "56px"
			}
		]);
		const sortOptions = computed(() => [
			{
				value: "-updated_at",
				label: __("Last edited")
			},
			{
				value: "-published_at",
				label: __("Newest published")
			},
			{
				value: "title",
				label: __("Title A–Z")
			},
			...type.value?.hierarchical ? [{
				value: "menu_order",
				label: __("Page order")
			}] : []
		]);
		const singular = computed(() => type.value?.singular ?? "");
		const plural = computed(() => type.value?.plural ?? __("Content"));
		const empty = computed(() => {
			if (filters.search.trim()) return __("No {plural} match “{q}”.", {
				plural: plural.value.toLowerCase(),
				q: filters.search.trim()
			});
			if (trash.value) return __("The trash is empty.");
			if (filters.status !== "any") return __("Nothing here with this status.");
			return __("No {plural} yet.", { plural: plural.value.toLowerCase() });
		});
		const busy = ref(/* @__PURE__ */ new Set());
		async function withRow(row, work) {
			if (busy.value.has(row.id)) return;
			busy.value = /* @__PURE__ */ new Set([...busy.value, row.id]);
			try {
				await work();
			} finally {
				const next = new Set(busy.value);
				next.delete(row.id);
				busy.value = next;
			}
		}
		const moveToTrash = (row) => withRow(row, () => trashNow(row));
		async function trashNow(row) {
			try {
				await api.delete(`/content/${typeName.value}/entries/${row.id}`);
				notify.success(__("“{title}” moved to the trash.", { title: row.title || __("(untitled)") }));
				load();
			} catch (e) {
				notify.error(e);
			}
		}
		const restore = (row) => withRow(row, () => restoreNow(row));
		async function restoreNow(row) {
			try {
				await api.post(`/content/${typeName.value}/entries/${row.id}/restore`);
				notify.success(__("“{title}” restored.", { title: row.title || __("(untitled)") }));
				load();
			} catch (e) {
				notify.error(e);
			}
		}
		const destroy = (row) => withRow(row, () => destroyNow(row));
		async function destroyNow(row) {
			if (!await confirm({
				title: __("Delete “{title}” for good?", { title: row.title || __("(untitled)") }),
				message: __("Its revisions are deleted too. This cannot be undone."),
				confirmLabel: __("Delete permanently"),
				danger: true
			})) return;
			try {
				await api.delete(`/content/${typeName.value}/entries/${row.id}`, { query: { permanent: true } });
				notify.success(__("Deleted."));
				load();
			} catch (e) {
				notify.error(e);
			}
		}
		const dateOf = (row) => trash.value ? row.trashed_at : row.status === "published" || row.status === "scheduled" ? row.published_at : row.updated_at;
		const byDate = computed(() => filters.sort === "-updated_at" || filters.sort === "-published_at");
		const future = (row) => row.status === "scheduled" && !trash.value;
		const board = computed(() => {
			if (!byDate.value) return rows.value;
			return [...rows.value.filter(future).sort((a, b) => Date.parse(b.published_at) - Date.parse(a.published_at)), ...rows.value.filter((r) => !future(r))];
		});
		const nowLabel = computed(() => __("Now {time}", { time: new Intl.DateTimeFormat(void 0, {
			hour: "2-digit",
			minute: "2-digit"
		}).format(/* @__PURE__ */ new Date()) }));
		const marker = computed(() => {
			const ahead = board.value.filter(future).length;
			return byDate.value && ahead > 0 && ahead < board.value.length ? {
				before: ahead,
				label: nowLabel.value
			} : null;
		});
		const rowClass = (row) => ({
			"is-scheduled": future(row),
			"is-faded": trash.value
		});
		const dateHint = (row) => trash.value ? "" : row.status === "scheduled" ? __("Goes live") : row.status === "published" ? __("Published") : __("Edited");
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: plural.value }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: plural.value,
				description: type.value?.description ?? ""
			}, createSlots({ _: 2 }, [type.value?.can.create ? {
				name: "actions",
				fn: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						variant: "primary",
						icon: "plus",
						href: `/admin/content/${typeName.value}/new`
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("New {item}", { item: singular.value.toLowerCase() }))}`);
							else return [createTextVNode(toDisplayString(unref(__)("New {item}", { item: singular.value.toLowerCase() })), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						variant: "primary",
						icon: "plus",
						href: `/admin/content/${typeName.value}/new`
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New {item}", { item: singular.value.toLowerCase() })), 1)]),
						_: 1
					}, 8, ["href"])];
				}),
				key: "0"
			} : void 0]), _parent));
			if (unref(types).data.value && !type.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("This content type does not exist, or you cannot edit it."))}`);
					else return [createTextVNode(toDisplayString(unref(__)("This content type does not exist, or you cannot edit it.")), 1)];
				}),
				_: 1
			}, _parent));
			else if (failed.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(failed.value.message)}`);
					else return [createTextVNode(toDisplayString(failed.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (type.value) {
				_push(`<!--[--><div class="k-toolbar" role="search" data-v-856e1ca4>`);
				_push(ssrRenderComponent(unref(KField), {
					label: unref(__)("Search titles"),
					class: "k-toolbar__grow"
				}, {
					default: withCtx(({ id }, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KInput), {
							id,
							modelValue: filters.search,
							"onUpdate:modelValue": ($event) => filters.search = $event,
							type: "search",
							autocomplete: "off"
						}, null, _parent, _scopeId));
						else return [createVNode(unref(KInput), {
							id,
							modelValue: filters.search,
							"onUpdate:modelValue": ($event) => filters.search = $event,
							type: "search",
							autocomplete: "off"
						}, null, 8, [
							"id",
							"modelValue",
							"onUpdate:modelValue"
						])];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KField), { label: unref(__)("Order") }, {
					default: withCtx(({ id }, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KSelect), {
							id,
							modelValue: filters.sort,
							"onUpdate:modelValue": ($event) => filters.sort = $event,
							options: sortOptions.value
						}, null, _parent, _scopeId));
						else return [createVNode(unref(KSelect), {
							id,
							modelValue: filters.sort,
							"onUpdate:modelValue": ($event) => filters.sort = $event,
							options: sortOptions.value
						}, null, 8, [
							"id",
							"modelValue",
							"onUpdate:modelValue",
							"options"
						])];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
				_push(ssrRenderComponent(unref(KTabs), {
					modelValue: filters.status,
					"onUpdate:modelValue": ($event) => filters.status = $event,
					tabs: tabs.value,
					label: unref(__)("Status")
				}, null, _parent));
				_push(ssrRenderComponent(unref(KCard), {
					flush: "",
					class: "k-content-list"
				}, createSlots({
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(ssrRenderComponent(unref(KTable), {
							columns: columns.value,
							rows: board.value,
							"row-class": rowClass,
							marker: marker.value,
							loading: loading.value && !rows.value.length,
							empty: empty.value,
							caption: plural.value
						}, createSlots({
							"cell-title": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<div class="k-entry-title" data-v-856e1ca4${_scopeId}>`);
									if (!trash.value && row.can.edit) _push(ssrRenderComponent(unref(Link), {
										href: `/admin/content/${typeName.value}/${row.id}`,
										class: "k-link"
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(row.title || unref(__)("(untitled)"))}`);
											else return [createTextVNode(toDisplayString(row.title || unref(__)("(untitled)")), 1)];
										}),
										_: 2
									}, _parent, _scopeId));
									else _push(`<span data-v-856e1ca4${_scopeId}>${ssrInterpolate(row.title || unref(__)("(untitled)"))}</span>`);
									_push(`<span class="k-entry-title__path" data-v-856e1ca4${_scopeId}>${ssrInterpolate([dateHint(row), (row.parent ? `${row.parent.title} / ` : "") + (row.path ?? row.slug ?? "")].filter(Boolean).join(" · "))}</span></div>`);
								} else return [createVNode("div", { class: "k-entry-title" }, [!trash.value && row.can.edit ? (openBlock(), createBlock(unref(Link), {
									key: 0,
									href: `/admin/content/${typeName.value}/${row.id}`,
									class: "k-link"
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(row.title || unref(__)("(untitled)")), 1)]),
									_: 2
								}, 1032, ["href"])) : (openBlock(), createBlock("span", { key: 1 }, toDisplayString(row.title || unref(__)("(untitled)")), 1)), createVNode("span", { class: "k-entry-title__path" }, toDisplayString([dateHint(row), (row.parent ? `${row.parent.title} / ` : "") + (row.path ?? row.slug ?? "")].filter(Boolean).join(" · ")), 1)])];
							}),
							"cell-author": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<span class="${ssrRenderClass({ "k-muted": !row.author })}" data-v-856e1ca4${_scopeId}>${ssrInterpolate(row.author?.username ?? unref(__)("Nobody"))}</span>`);
									if (row.assignee) _push(`<span class="k-muted k-small k-list-assignee" data-v-856e1ca4${_scopeId}>${ssrInterpolate(unref(__)("Assigned to {name}", { name: row.assignee.username }))}</span>`);
									else _push(`<!---->`);
								} else return [createVNode("span", { class: { "k-muted": !row.author } }, toDisplayString(row.author?.username ?? unref(__)("Nobody")), 3), row.assignee ? (openBlock(), createBlock("span", {
									key: 0,
									class: "k-muted k-small k-list-assignee"
								}, toDisplayString(unref(__)("Assigned to {name}", { name: row.assignee.username })), 1)) : createCommentVNode("v-if", true)];
							}),
							"cell-status": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KBadge), { tone: row.status === "scheduled" ? "scheduled" : STATUS[row.status]?.tone }, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(STATUS[row.status]?.label ?? row.status)}`);
										else return [createTextVNode(toDisplayString(STATUS[row.status]?.label ?? row.status), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else return [createVNode(unref(KBadge), { tone: row.status === "scheduled" ? "scheduled" : STATUS[row.status]?.tone }, {
									default: withCtx(() => [createTextVNode(toDisplayString(STATUS[row.status]?.label ?? row.status), 1)]),
									_: 2
								}, 1032, ["tone"])];
							}),
							"cell-when": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KWhen), { value: dateOf(row) }, null, _parent, _scopeId));
								else return [createVNode(unref(KWhen), { value: dateOf(row) }, null, 8, ["value"])];
							}),
							"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
								if (_push) {
									if (trash.value) {
										_push(`<div class="k-row-actions" data-v-856e1ca4${_scopeId}>`);
										_push(ssrRenderComponent(unref(KButton), {
											size: "sm",
											icon: "arrow-counter-clockwise",
											loading: busy.value.has(row.id),
											disabled: !row.can.delete,
											onClick: ($event) => restore(row)
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Restore"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Restore")), 1)];
											}),
											_: 2
										}, _parent, _scopeId));
										_push(ssrRenderComponent(unref(KButton), {
											size: "sm",
											variant: "danger",
											loading: busy.value.has(row.id),
											disabled: !row.can.delete,
											onClick: ($event) => destroy(row)
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Delete"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Delete")), 1)];
											}),
											_: 2
										}, _parent, _scopeId));
										_push(`</div>`);
									} else if (row.can.delete) _push(ssrRenderComponent(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "trash",
										loading: busy.value.has(row.id),
										"aria-label": unref(__)("Move “{title}” to the trash", { title: row.title || unref(__)("(untitled)") }),
										onClick: ($event) => moveToTrash(row)
									}, null, _parent, _scopeId));
									else _push(`<!---->`);
								} else return [trash.value ? (openBlock(), createBlock("div", {
									key: 0,
									class: "k-row-actions"
								}, [createVNode(unref(KButton), {
									size: "sm",
									icon: "arrow-counter-clockwise",
									loading: busy.value.has(row.id),
									disabled: !row.can.delete,
									onClick: ($event) => restore(row)
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Restore")), 1)]),
									_: 1
								}, 8, [
									"loading",
									"disabled",
									"onClick"
								]), createVNode(unref(KButton), {
									size: "sm",
									variant: "danger",
									loading: busy.value.has(row.id),
									disabled: !row.can.delete,
									onClick: ($event) => destroy(row)
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Delete")), 1)]),
									_: 1
								}, 8, [
									"loading",
									"disabled",
									"onClick"
								])])) : row.can.delete ? (openBlock(), createBlock(unref(KButton), {
									key: 1,
									size: "sm",
									variant: "ghost",
									icon: "trash",
									loading: busy.value.has(row.id),
									"aria-label": unref(__)("Move “{title}” to the trash", { title: row.title || unref(__)("(untitled)") }),
									onClick: ($event) => moveToTrash(row)
								}, null, 8, [
									"loading",
									"aria-label",
									"onClick"
								])) : createCommentVNode("v-if", true)];
							}),
							_: 2
						}, [renderList(unref(extColumns), (col) => {
							return {
								name: `cell-${col.key}`,
								fn: withCtx(({ row }, _push, _parent, _scopeId) => {
									if (_push) {
										if (col.component) _push(ssrRenderComponent(unref(KExtension), {
											name: col.component,
											owner: col.owner,
											inline: "",
											row,
											value: row[col.key]
										}, null, _parent, _scopeId));
										else _push(`<span data-v-856e1ca4${_scopeId}>${ssrInterpolate(row[col.key] ?? "—")}</span>`);
									} else return [col.component ? (openBlock(), createBlock(unref(KExtension), {
										key: 0,
										name: col.component,
										owner: col.owner,
										inline: "",
										row,
										value: row[col.key]
									}, null, 8, [
										"name",
										"owner",
										"row",
										"value"
									])) : (openBlock(), createBlock("span", { key: 1 }, toDisplayString(row[col.key] ?? "—"), 1))];
								})
							};
						})]), _parent, _scopeId));
						else return [createVNode(unref(KTable), {
							columns: columns.value,
							rows: board.value,
							"row-class": rowClass,
							marker: marker.value,
							loading: loading.value && !rows.value.length,
							empty: empty.value,
							caption: plural.value
						}, createSlots({
							"cell-title": withCtx(({ row }) => [createVNode("div", { class: "k-entry-title" }, [!trash.value && row.can.edit ? (openBlock(), createBlock(unref(Link), {
								key: 0,
								href: `/admin/content/${typeName.value}/${row.id}`,
								class: "k-link"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.title || unref(__)("(untitled)")), 1)]),
								_: 2
							}, 1032, ["href"])) : (openBlock(), createBlock("span", { key: 1 }, toDisplayString(row.title || unref(__)("(untitled)")), 1)), createVNode("span", { class: "k-entry-title__path" }, toDisplayString([dateHint(row), (row.parent ? `${row.parent.title} / ` : "") + (row.path ?? row.slug ?? "")].filter(Boolean).join(" · ")), 1)])]),
							"cell-author": withCtx(({ row }) => [createVNode("span", { class: { "k-muted": !row.author } }, toDisplayString(row.author?.username ?? unref(__)("Nobody")), 3), row.assignee ? (openBlock(), createBlock("span", {
								key: 0,
								class: "k-muted k-small k-list-assignee"
							}, toDisplayString(unref(__)("Assigned to {name}", { name: row.assignee.username })), 1)) : createCommentVNode("v-if", true)]),
							"cell-status": withCtx(({ row }) => [createVNode(unref(KBadge), { tone: row.status === "scheduled" ? "scheduled" : STATUS[row.status]?.tone }, {
								default: withCtx(() => [createTextVNode(toDisplayString(STATUS[row.status]?.label ?? row.status), 1)]),
								_: 2
							}, 1032, ["tone"])]),
							"cell-when": withCtx(({ row }) => [createVNode(unref(KWhen), { value: dateOf(row) }, null, 8, ["value"])]),
							"cell-actions": withCtx(({ row }) => [trash.value ? (openBlock(), createBlock("div", {
								key: 0,
								class: "k-row-actions"
							}, [createVNode(unref(KButton), {
								size: "sm",
								icon: "arrow-counter-clockwise",
								loading: busy.value.has(row.id),
								disabled: !row.can.delete,
								onClick: ($event) => restore(row)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Restore")), 1)]),
								_: 1
							}, 8, [
								"loading",
								"disabled",
								"onClick"
							]), createVNode(unref(KButton), {
								size: "sm",
								variant: "danger",
								loading: busy.value.has(row.id),
								disabled: !row.can.delete,
								onClick: ($event) => destroy(row)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Delete")), 1)]),
								_: 1
							}, 8, [
								"loading",
								"disabled",
								"onClick"
							])])) : row.can.delete ? (openBlock(), createBlock(unref(KButton), {
								key: 1,
								size: "sm",
								variant: "ghost",
								icon: "trash",
								loading: busy.value.has(row.id),
								"aria-label": unref(__)("Move “{title}” to the trash", { title: row.title || unref(__)("(untitled)") }),
								onClick: ($event) => moveToTrash(row)
							}, null, 8, [
								"loading",
								"aria-label",
								"onClick"
							])) : createCommentVNode("v-if", true)]),
							_: 2
						}, [renderList(unref(extColumns), (col) => {
							return {
								name: `cell-${col.key}`,
								fn: withCtx(({ row }) => [col.component ? (openBlock(), createBlock(unref(KExtension), {
									key: 0,
									name: col.component,
									owner: col.owner,
									inline: "",
									row,
									value: row[col.key]
								}, null, 8, [
									"name",
									"owner",
									"row",
									"value"
								])) : (openBlock(), createBlock("span", { key: 1 }, toDisplayString(row[col.key] ?? "—"), 1))])
							};
						})]), 1032, [
							"columns",
							"rows",
							"marker",
							"loading",
							"empty",
							"caption"
						])];
					}),
					_: 2
				}, [meta.value && meta.value.last_page > 1 ? {
					name: "footer",
					fn: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-pager" data-v-856e1ca4${_scopeId}><span class="k-muted" data-v-856e1ca4${_scopeId}>${ssrInterpolate(unref(__)("Page {page} of {last} · {total} total", {
								page: meta.value.page,
								last: meta.value.last_page,
								total: meta.value.total
							}))}</span>`);
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								icon: "caret-left",
								disabled: meta.value.page <= 1 || loading.value,
								onClick: ($event) => filters.page--
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Previous"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Previous")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								disabled: meta.value.page >= meta.value.last_page || loading.value,
								onClick: ($event) => filters.page++
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Next"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Next")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`</div>`);
						} else return [createVNode("div", { class: "k-pager" }, [
							createVNode("span", { class: "k-muted" }, toDisplayString(unref(__)("Page {page} of {last} · {total} total", {
								page: meta.value.page,
								last: meta.value.last_page,
								total: meta.value.total
							})), 1),
							createVNode(unref(KButton), {
								size: "sm",
								icon: "caret-left",
								disabled: meta.value.page <= 1 || loading.value,
								onClick: ($event) => filters.page--
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Previous")), 1)]),
								_: 1
							}, 8, ["disabled", "onClick"]),
							createVNode(unref(KButton), {
								size: "sm",
								disabled: meta.value.page >= meta.value.last_page || loading.value,
								onClick: ($event) => filters.page++
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Next")), 1)]),
								_: 1
							}, 8, ["disabled", "onClick"])
						])];
					}),
					key: "0"
				} : void 0]), _parent));
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/content/Index.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Index_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-856e1ca4"]]);
export { Index_default as default };
