import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, reactive, ref, toDisplayString, unref, useSSRContext, watch, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head, usePage } from "@kestello/app";
import { ApiError, __, confirm, formatBytes, notify, useApi } from "@kestello/sdk";
import { KAlert, KButton, KEmpty, KField, KIcon, KInput, KPageHeader, KTabs, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/forms/Submissions.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Submissions",
	__ssrInlineRender: true,
	setup(__props) {
		/** What people sent through one form: an inbox with a reading pane. */
		const api = useApi();
		const page = usePage();
		const formId = computed(() => String(page.props.params?.id ?? ""));
		const state = reactive({
			rows: [],
			total: null,
			page: 1,
			lastPage: 1,
			loading: false,
			search: "",
			status: "inbox",
			counts: {
				new: 0,
				spam: 0
			},
			form: null,
			error: null
		});
		const open = ref(null);
		let seq = 0;
		async function load(p = 1) {
			const mine = ++seq;
			state.loading = true;
			try {
				const body = await api.get(`/forms/${formId.value}/submissions`, {
					page: p,
					per_page: 25,
					status: state.status,
					search: state.search.trim() || void 0
				});
				if (mine !== seq) return;
				Object.assign(state, {
					rows: body.data,
					total: body.meta.total,
					page: body.meta.page,
					lastPage: body.meta.last_page,
					counts: body.meta.counts,
					form: body.meta.form,
					error: null
				});
			} catch (e) {
				state.error = e;
			} finally {
				if (mine === seq) state.loading = false;
			}
		}
		let debounce = null;
		watch(() => state.search, () => {
			clearTimeout(debounce);
			debounce = setTimeout(() => load(), 250);
		});
		watch(() => state.status, () => {
			open.value = null;
			load();
		});
		load().then(() => {
			const wanted = Number(new URLSearchParams(window.location.search).get("open"));
			if (wanted) show({ id: wanted });
		});
		const fields = computed(() => state.form?.definition.fields ?? []);
		const tabs = computed(() => [
			{
				id: "inbox",
				label: __("Inbox"),
				count: state.status === "inbox" ? state.total : null
			},
			{
				id: "new",
				label: __("New"),
				count: state.counts.new
			},
			{
				id: "spam",
				label: __("Spam"),
				count: state.counts.spam
			}
		]);
		async function show(row) {
			try {
				const { data } = await api.get(`/form-submissions/${row.id}`);
				open.value = data;
				const listed = state.rows.find((r) => r.id === data.id);
				if (listed && listed.status === "new") {
					listed.status = "read";
					state.counts.new = Math.max(0, state.counts.new - 1);
				}
			} catch (e) {
				notify.error(e);
			}
		}
		const preview = (row) => fields.value.filter((f) => f.type !== "file" && f.type !== "boolean").slice(0, 2).map((f) => display(f, row.data[f.name])).filter(Boolean);
		function display(f, v) {
			if (v === null || v === void 0 || v === "") return "";
			if (f.type === "boolean") return v ? __("Yes") : __("No");
			if (Array.isArray(v)) return v.map((x) => f.options?.choices?.[x] ?? x).join(", ");
			if (f.type === "select") return f.options?.choices?.[v] ?? String(v);
			return String(v);
		}
		async function mark(status) {
			try {
				await api.patch(`/form-submissions/${open.value.id}`, { status });
				notify.success(status === "spam" ? __("Moved to spam.") : status === "new" ? __("Marked as new.") : __("Moved to the inbox."));
				open.value = null;
				await load(state.page);
			} catch (e) {
				notify.error(e);
			}
		}
		async function remove() {
			if (!await confirm({
				title: __("Delete this submission?"),
				message: __("Its answers and files are deleted for good."),
				confirmLabel: __("Delete"),
				danger: true
			})) return;
			try {
				await api.delete(`/form-submissions/${open.value.id}`);
				notify.success(__("Submission deleted."));
				open.value = null;
				await load(state.rows.length === 1 && state.page > 1 ? state.page - 1 : state.page);
			} catch (e) {
				notify.error(e);
			}
		}
		async function download(url, filename) {
			try {
				const response = await fetch(url, {
					credentials: "same-origin",
					headers: { "X-Requested-With": "XMLHttpRequest" }
				});
				if (!response.ok) throw new ApiError(response.status, { title: response.statusText });
				const href = URL.createObjectURL(await response.blob());
				Object.assign(document.createElement("a"), {
					href,
					download: filename
				}).click();
				setTimeout(() => URL.revokeObjectURL(href), 1e3);
			} catch (e) {
				notify.error(e);
			}
		}
		const exportCsv = () => download(`/api/v1/forms/${formId.value}/submissions/export`, `form-${formId.value}-${(/* @__PURE__ */ new Date()).toISOString().slice(0, 10)}.csv`);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: state.form ? unref(__)("{name}: submissions", { name: state.form.title }) : unref(__)("Submissions") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: state.form?.title ?? unref(__)("Submissions"),
				description: state.form ? state.form.definition.store ? state.form.definition.retention_days ? unref(__)("{n, plural, one {Submissions are deleted after # day.} other {Submissions are deleted after # days.}}", { n: state.form.definition.retention_days }) : unref(__)("Submissions are kept until you delete them.") : unref(__)("This form doesn't keep submissions; they are only emailed.") : ""
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), {
							icon: "pencil",
							href: `/admin/forms/${formId.value}`
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Edit the form"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Edit the form")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							icon: "download",
							disabled: !state.total,
							onClick: exportCsv
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Export CSV"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Export CSV")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), {
						icon: "pencil",
						href: `/admin/forms/${formId.value}`
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Edit the form")), 1)]),
						_: 1
					}, 8, ["href"]), createVNode(unref(KButton), {
						icon: "download",
						disabled: !state.total,
						onClick: exportCsv
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Export CSV")), 1)]),
						_: 1
					}, 8, ["disabled"])];
				}),
				_: 1
			}, _parent));
			if (state.error) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(state.error.message)}`);
					else return [createTextVNode(toDisplayString(state.error.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KTabs), {
				modelValue: state.status,
				"onUpdate:modelValue": ($event) => state.status = $event,
				tabs: tabs.value,
				label: unref(__)("Submissions")
			}, null, _parent));
			_push(`<div class="k-inbox" data-v-31973c68><section class="k-inbox__list"${ssrRenderAttr("aria-label", unref(__)("Submissions"))} data-v-31973c68><div class="k-inbox__search" role="search" data-v-31973c68>`);
			_push(ssrRenderComponent(unref(KField), { label: unref(__)("Search the answers") }, {
				default: withCtx(({ id }, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KInput), {
						id,
						modelValue: state.search,
						"onUpdate:modelValue": ($event) => state.search = $event,
						type: "search",
						autocomplete: "off"
					}, null, _parent, _scopeId));
					else return [createVNode(unref(KInput), {
						id,
						modelValue: state.search,
						"onUpdate:modelValue": ($event) => state.search = $event,
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
			_push(`</div>`);
			if (!state.loading && !state.rows.length) _push(ssrRenderComponent(unref(KEmpty), {
				icon: "tray",
				title: state.search ? unref(__)("Nothing matches.") : state.status === "spam" ? unref(__)("No spam.") : unref(__)("Nothing yet.")
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						if (!state.search && state.status !== "spam") _push(`<!--[-->${ssrInterpolate(unref(__)("What people send through this form shows up here."))}<!--]-->`);
						else _push(`<!---->`);
					} else return [!state.search && state.status !== "spam" ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(toDisplayString(unref(__)("What people send through this form shows up here.")), 1)], 64)) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
			else {
				_push(`<ul class="k-inbox__rows" data-v-31973c68><!--[-->`);
				ssrRenderList(state.rows, (row) => {
					_push(`<li data-v-31973c68><button type="button" class="${ssrRenderClass([{
						"is-open": open.value?.id === row.id,
						"is-new": row.status === "new"
					}, "k-inbox__row"])}"${ssrRenderAttr("aria-current", open.value?.id === row.id ? "true" : void 0)} data-v-31973c68><span class="k-inbox__who" data-v-31973c68>`);
					if (row.status === "new") _push(`<span class="k-inbox__dot"${ssrRenderAttr("aria-label", unref(__)("New"))} data-v-31973c68></span>`);
					else _push(`<!---->`);
					_push(` ${ssrInterpolate(preview(row)[0] || unref(__)("Submission {id}", { id: row.id }))}</span>`);
					_push(ssrRenderComponent(unref(KTime), {
						class: "k-inbox__when",
						value: row.created_at
					}, null, _parent));
					_push(`<span class="k-inbox__what" data-v-31973c68>${ssrInterpolate(preview(row)[1] ?? "")}</span></button></li>`);
				});
				_push(`<!--]--></ul>`);
			}
			if (state.lastPage > 1) {
				_push(`<div class="k-inbox__pager" data-v-31973c68>`);
				_push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					disabled: state.page <= 1,
					onClick: ($event) => load(state.page - 1)
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Newer"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Newer")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`<span class="k-muted k-small" data-v-31973c68>${ssrInterpolate(unref(__)("Page {page} of {last}", {
					page: state.page,
					last: state.lastPage
				}))}</span>`);
				_push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					disabled: state.page >= state.lastPage,
					onClick: ($event) => load(state.page + 1)
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Older"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Older")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`</section><article class="k-inbox__pane"${ssrRenderAttr("aria-label", unref(__)("Submission"))} data-v-31973c68>`);
			if (!open.value) _push(`<div class="k-inbox__placeholder k-muted" data-v-31973c68>${ssrInterpolate(state.rows.length ? unref(__)("Choose a submission to read it.") : "")}</div>`);
			else {
				_push(`<!--[--><header class="k-inbox__head" data-v-31973c68><div data-v-31973c68><h2 class="k-inbox__title" data-v-31973c68>${ssrInterpolate(preview(open.value)[0] || unref(__)("Submission {id}", { id: open.value.id }))}</h2><p class="k-muted k-small" data-v-31973c68>`);
				_push(ssrRenderComponent(unref(KTime), { value: open.value.created_at }, null, _parent));
				if (open.value.page) _push(`<!--[--> · ${ssrInterpolate(unref(__)("from {page}", { page: open.value.page }))}<!--]-->`);
				else _push(`<!---->`);
				_push(`</p></div><div class="k-row-actions" data-v-31973c68>`);
				if (open.value.status !== "spam") _push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					icon: "envelope",
					onClick: ($event) => mark("new")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Mark as new"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Mark as new")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				if (open.value.status !== "spam") _push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					icon: "prohibit",
					onClick: ($event) => mark("spam")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Spam"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Spam")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					icon: "tray",
					onClick: ($event) => mark("read")
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Not spam"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Not spam")), 1)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					variant: "ghost",
					icon: "trash",
					onClick: remove
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Delete"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Delete")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div></header><dl class="k-inbox__answers" data-v-31973c68><!--[-->`);
				ssrRenderList(fields.value, (f) => {
					_push(`<!--[-->`);
					if (open.value.data[f.name] !== void 0 && open.value.data[f.name] !== null) {
						_push(`<!--[--><dt data-v-31973c68>${ssrInterpolate(f.label)}</dt>`);
						if (f.type === "file") {
							_push(`<dd data-v-31973c68><ul class="k-inbox__files" data-v-31973c68><!--[-->`);
							ssrRenderList(open.value.data[f.name], (file, i) => {
								_push(`<li data-v-31973c68><button type="button" class="k-link-btn" data-v-31973c68>`);
								_push(ssrRenderComponent(unref(KIcon), { name: "paperclip" }, null, _parent));
								_push(` ${ssrInterpolate(file.name)}</button><span class="k-muted k-small" data-v-31973c68>${ssrInterpolate(unref(formatBytes)(file.size))}</span></li>`);
							});
							_push(`<!--]--></ul></dd>`);
						} else if (f.type === "email") _push(`<dd data-v-31973c68><a class="k-link"${ssrRenderAttr("href", `mailto:${open.value.data[f.name]}`)} data-v-31973c68>${ssrInterpolate(open.value.data[f.name])}</a></dd>`);
						else _push(`<dd data-v-31973c68><span class="k-inbox__text" data-v-31973c68>${ssrInterpolate(display(f, open.value.data[f.name]))}</span></dd>`);
						_push(`<!--]-->`);
					} else _push(`<!---->`);
					_push(`<!--]-->`);
				});
				_push(`<!--]--></dl><details class="k-inbox__meta" data-v-31973c68><summary data-v-31973c68>${ssrInterpolate(unref(__)("Sent from"))}</summary><p class="k-small k-muted" data-v-31973c68>${ssrInterpolate(open.value.ip || unref(__)("Unknown address"))} · ${ssrInterpolate(open.value.user_agent || unref(__)("Unknown browser"))}</p></details><!--]-->`);
			}
			_push(`</article></div><!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/forms/Submissions.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Submissions_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-31973c68"]]);
export { Submissions_default as default };
