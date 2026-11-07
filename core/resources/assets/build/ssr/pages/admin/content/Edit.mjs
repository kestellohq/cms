import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createSlots, createTextVNode, createVNode, mergeModels, mergeProps, nextTick, onBeforeUnmount, onMounted, openBlock, reactive, ref, renderList, toDisplayString, unref, useModel, useSSRContext, vModelText, watch, withCtx, withDirectives, withModifiers } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderSlot, ssrRenderStyle, ssrRenderTeleport } from "vue/server-renderer";
import { Head, Link, usePage, useRouter } from "@kestello/app";
import { ApiError, __, clone, confirm, formatDateTime, formatRelative, notify, useApi, useLeaveGuard, useResource, useUser, visibleFields } from "@kestello/sdk";
import { KAlert, KBadge, KBuilder, KButton, KCard, KContentField, KField, KIcon, KInput, KModal, KPicker, KSelect, KTermSelect, KTime } from "@kestello/ui";
// core/resources/js/components/content/RevisionDiff.vue
/**
* The changes of one revision, field by field, from
* GET …/revisions/{id}/diff. Added text is underlined on green, removed
* text struck through on red; runs of unchanged lines fold away.
*/
var CONTEXT = 2;
var _sfc_main$5 = {
	__name: "RevisionDiff",
	__ssrInlineRender: true,
	props: { changes: {
		type: Array,
		required: true
	} },
	setup(__props) {
		const props = __props;
		const open = ref({});
		/** Lines with long unchanged runs replaced by a fold. */
		function rows(change) {
			const lines = change.lines;
			const keep = lines.map((l, i) => l.op !== "equal" || lines.slice(Math.max(0, i - CONTEXT), i + CONTEXT + 1).some((n) => n.op !== "equal"));
			const out = [];
			for (let i = 0; i < lines.length; i++) {
				if (keep[i] || open.value[`${change.key}:${i}`]) {
					out.push({
						...lines[i],
						index: i
					});
					continue;
				}
				let j = i;
				while (j < lines.length && !keep[j]) j++;
				out.push({
					op: "fold",
					index: i,
					count: j - i
				});
				i = j - 1;
			}
			return out;
		}
		const list = computed(() => props.changes.map((c) => ({
			...c,
			rows: rows(c)
		})));
		const unchangedText = (n) => __("{n, plural, one {# unchanged line} other {# unchanged lines}}", { n });
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			if (!__props.changes.length) _push(`<p class="k-muted" data-v-54aa91fb>${ssrInterpolate(unref(__)("No changes to the content."))}</p>`);
			else _push(`<!---->`);
			_push(`<!--[-->`);
			ssrRenderList(list.value, (change) => {
				_push(`<section class="k-diff" data-v-54aa91fb><h3 class="k-diff__label" data-v-54aa91fb>${ssrInterpolate(change.label)}</h3><div class="k-diff__lines" data-v-54aa91fb><!--[-->`);
				ssrRenderList(change.rows, (row) => {
					_push(`<!--[-->`);
					if (row.op === "fold") _push(`<button type="button" class="k-diff__fold" data-v-54aa91fb>${ssrInterpolate(unchangedText(row.count))}</button>`);
					else if (row.op === "equal") _push(`<p class="k-diff__line" data-v-54aa91fb>${ssrInterpolate(row.text || "\xA0")}</p>`);
					else if (row.op === "insert") _push(`<p class="k-diff__line is-insert" data-v-54aa91fb><span class="k-sr-only" data-v-54aa91fb>${ssrInterpolate(unref(__)("Added:"))}</span><ins data-v-54aa91fb>${ssrInterpolate(row.text || "\xA0")}</ins></p>`);
					else if (row.op === "delete") _push(`<p class="k-diff__line is-delete" data-v-54aa91fb><span class="k-sr-only" data-v-54aa91fb>${ssrInterpolate(unref(__)("Removed:"))}</span><del data-v-54aa91fb>${ssrInterpolate(row.text || "\xA0")}</del></p>`);
					else {
						_push(`<p class="k-diff__line is-change" data-v-54aa91fb><!--[-->`);
						ssrRenderList(row.parts, (part, i) => {
							_push(`<!--[-->`);
							if (part[0] === "insert") _push(`<ins data-v-54aa91fb><span class="k-sr-only" data-v-54aa91fb>${ssrInterpolate(unref(__)("Added:"))}</span>${ssrInterpolate(part[1])}</ins>`);
							else if (part[0] === "delete") _push(`<del data-v-54aa91fb><span class="k-sr-only" data-v-54aa91fb>${ssrInterpolate(unref(__)("Removed:"))}</span>${ssrInterpolate(part[1])}</del>`);
							else _push(`<!--[-->${ssrInterpolate(part[1])}<!--]-->`);
							_push(`<!--]-->`);
						});
						_push(`<!--]--></p>`);
					}
					_push(`<!--]-->`);
				});
				_push(`<!--]--></div></section>`);
			});
			_push(`<!--]--><!--]-->`);
		};
	}
};
var _sfc_setup$5 = _sfc_main$5.setup;
_sfc_main$5.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/content/RevisionDiff.vue");
	return _sfc_setup$5 ? _sfc_setup$5(props, ctx) : void 0;
};
var RevisionDiff_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main$5, [["__scopeId", "data-v-54aa91fb"]]);
// core/resources/js/components/content/LinkedFrom.vue
/**
* "Linked from" on the entry editor: the entries that point at this one
* through a relationship field (two-way relationships). Hidden while
* nothing links here, so entries without relationships stay calm.
*/
var _sfc_main$4 = {
	__name: "LinkedFrom",
	__ssrInlineRender: true,
	props: { base: {
		type: String,
		required: true
	} },
	setup(__props) {
		const props = __props;
		const links = useResource(() => `${props.base}/linked-from`);
		const rows = computed(() => links.data.value ?? []);
		const STATUS = {
			draft: __("Draft"),
			pending: __("In review"),
			scheduled: __("Scheduled"),
			private: __("Private")
		};
		const where = (link) => __("{type} · in {fields}", {
			type: link.type_label,
			fields: link.fields.map((f) => f.label).join(", ")
		});
		return (_ctx, _push, _parent, _attrs) => {
			if (rows.value.length) _push(ssrRenderComponent(unref(KCard), mergeProps({
				title: unref(__)("Linked from"),
				flush: ""
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<ul class="k-linked"${_scopeId}><!--[-->`);
						ssrRenderList(rows.value, (link) => {
							_push(`<li class="k-linked__item"${_scopeId}>`);
							_push(ssrRenderComponent(unref(Link), {
								href: `/admin/content/${link.type}/${link.id}`,
								class: "k-linked__title"
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(link.title || unref(__)("(untitled)"))}`);
									else return [createTextVNode(toDisplayString(link.title || unref(__)("(untitled)")), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							_push(`<span class="k-muted k-small"${_scopeId}>${ssrInterpolate(where(link))}`);
							if (STATUS[link.status]) _push(`<!--[--> · ${ssrInterpolate(STATUS[link.status])}<!--]-->`);
							else _push(`<!---->`);
							_push(`</span></li>`);
						});
						_push(`<!--]--></ul>`);
					} else return [createVNode("ul", { class: "k-linked" }, [(openBlock(true), createBlock(Fragment, null, renderList(rows.value, (link) => {
						return openBlock(), createBlock("li", {
							key: link.id,
							class: "k-linked__item"
						}, [createVNode(unref(Link), {
							href: `/admin/content/${link.type}/${link.id}`,
							class: "k-linked__title"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(link.title || unref(__)("(untitled)")), 1)]),
							_: 2
						}, 1032, ["href"]), createVNode("span", { class: "k-muted k-small" }, [createTextVNode(toDisplayString(where(link)), 1), STATUS[link.status] ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(STATUS[link.status]), 1)], 64)) : createCommentVNode("v-if", true)])]);
					}), 128))])];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
		};
	}
};
var _sfc_setup$4 = _sfc_main$4.setup;
_sfc_main$4.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/content/LinkedFrom.vue");
	return _sfc_setup$4 ? _sfc_setup$4(props, ctx) : void 0;
};
// core/resources/js/components/content/ReviewPanel.vue
/**
* The review side of an entry: who it is assigned to, and its editorial
* timeline (comments, with the review steps between them). Only people
* in the admin see any of it.
*/
var _sfc_main$3 = {
	__name: "ReviewPanel",
	__ssrInlineRender: true,
	props: {
		base: {
			type: String,
			required: true
		},
		typeBase: {
			type: String,
			required: true
		},
		entry: {
			type: Object,
			required: true
		},
		canEdit: {
			type: Boolean,
			default: false
		},
		canModerate: {
			type: Boolean,
			default: false
		}
	},
	emits: ["assigned"],
	setup(__props, { expose: __expose, emit: __emit }) {
		const props = __props;
		const emit = __emit;
		const api = useApi();
		const user = useUser();
		const timeline = useResource(() => `${props.base}/comments`);
		const people = useResource(() => `${props.typeBase}/assignees`, { immediate: props.canEdit });
		const draft = ref("");
		const posting = ref(false);
		const assignee = ref(props.entry.assignee?.id ?? "");
		watch(() => props.entry.assignee?.id, (id) => {
			assignee.value = id ?? "";
		});
		const assigneeOptions = computed(() => [{
			value: "",
			label: __("Nobody")
		}, ...(people.data.value ?? []).map((p) => ({
			value: p.id,
			label: p.id === user.value?.id ? __("{name} (you)", { name: p.username }) : p.username
		}))]);
		const STEP = {
			submitted: (who) => __("{who} submitted it for review", { who }),
			changes_requested: (who) => __("{who} asked for changes", { who }),
			approved: (who) => __("{who} approved it", { who })
		};
		const name = (c) => c.user?.username ?? __("Someone");
		async function assign() {
			try {
				const { data } = await api.put(`${props.base}/assignee`, { user_id: assignee.value === "" ? null : Number(assignee.value) });
				emit("assigned", data);
				notify.success(data.assignee ? __("Assigned to {name}.", { name: data.assignee.username }) : __("No longer assigned."));
			} catch (e) {
				assignee.value = props.entry.assignee?.id ?? "";
				notify.error(e);
			}
		}
		async function post() {
			if (!draft.value.trim()) return;
			posting.value = true;
			try {
				await api.post(`${props.base}/comments`, { body: draft.value });
				draft.value = "";
				timeline.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			} finally {
				posting.value = false;
			}
		}
		async function remove(c) {
			if (!await confirm({
				title: __("Delete this comment?"),
				confirmLabel: __("Delete"),
				danger: true
			})) return;
			try {
				await api.delete(`${props.base}/comments/${c.id}`);
				timeline.reload({ silent: true });
			} catch (e) {
				notify.error(e);
			}
		}
		function onKey(event) {
			if (event.key === "Enter" && (event.metaKey || event.ctrlKey)) post();
		}
		__expose({ reload: () => timeline.reload({ silent: true }) });
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(unref(KCard), mergeProps({ title: unref(__)("Review") }, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-form" data-v-a373207e${_scopeId}>`);
						if (__props.canEdit) _push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Assigned to"),
							help: unref(__)("They are told, and a submission goes only to them.")
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KSelect), {
									id,
									modelValue: assignee.value,
									"onUpdate:modelValue": [($event) => assignee.value = $event, assign],
									options: assigneeOptions.value
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KSelect), {
									id,
									modelValue: assignee.value,
									"onUpdate:modelValue": [($event) => assignee.value = $event, assign],
									options: assigneeOptions.value
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"options"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						else if (__props.entry.assignee) _push(`<p class="k-small" data-v-a373207e${_scopeId}>${ssrInterpolate(unref(__)("Assigned to {name}", { name: __props.entry.assignee.username }))}</p>`);
						else _push(`<!---->`);
						_push(`</div>`);
						if (unref(timeline).data.value?.length) {
							_push(`<ol class="k-review__list"${ssrRenderAttr("aria-label", unref(__)("Comments"))} data-v-a373207e${_scopeId}><!--[-->`);
							ssrRenderList(unref(timeline).data.value, (c) => {
								_push(`<li class="${ssrRenderClass([{
									"is-step": c.kind !== "comment",
									[`is-${c.kind}`]: true
								}, "k-review__item"])}" data-v-a373207e${_scopeId}><p class="k-review__meta k-small" data-v-a373207e${_scopeId}><span class="k-review__who" data-v-a373207e${_scopeId}>${ssrInterpolate(c.kind === "comment" ? name(c) : STEP[c.kind]?.(name(c)) ?? name(c))}</span>`);
								_push(ssrRenderComponent(unref(KTime), {
									value: c.created_at,
									class: "k-muted"
								}, null, _parent, _scopeId));
								_push(`</p>`);
								if (c.body) _push(`<p class="k-review__body" data-v-a373207e${_scopeId}>${ssrInterpolate(c.body)}</p>`);
								else _push(`<!---->`);
								if (c.kind === "comment" && (c.user?.id === unref(user)?.id || __props.canModerate)) _push(`<button type="button" class="k-review__delete k-small"${ssrRenderAttr("aria-label", unref(__)("Delete the comment from {when}", { when: unref(formatDateTime)(c.created_at) }))} data-v-a373207e${_scopeId}>${ssrInterpolate(unref(__)("Delete"))}</button>`);
								else _push(`<!---->`);
								_push(`</li>`);
							});
							_push(`<!--]--></ol>`);
						} else if (unref(timeline).error.value) _push(`<p class="k-small k-review__empty" role="alert" data-v-a373207e${_scopeId}>${ssrInterpolate(unref(timeline).error.value.message)}</p>`);
						else if (!unref(timeline).loading.value) _push(`<p class="k-muted k-small k-review__empty" data-v-a373207e${_scopeId}>${ssrInterpolate(unref(__)("No comments yet. Only people in the admin see them."))}</p>`);
						else _push(`<!---->`);
						_push(`<form class="k-review__form" data-v-a373207e${_scopeId}><label class="k-review__label k-small" for="k-review-new" data-v-a373207e${_scopeId}>${ssrInterpolate(unref(__)("Add a comment"))}</label><textarea id="k-review-new" class="k-input k-textarea" rows="3" maxlength="5000" data-v-a373207e${_scopeId}>${ssrInterpolate(draft.value)}</textarea>`);
						_push(ssrRenderComponent(unref(KButton), {
							type: "submit",
							size: "sm",
							loading: posting.value,
							disabled: !draft.value.trim()
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Comment"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Comment")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</form>`);
					} else return [
						createVNode("div", { class: "k-form" }, [__props.canEdit ? (openBlock(), createBlock(unref(KField), {
							key: 0,
							label: unref(__)("Assigned to"),
							help: unref(__)("They are told, and a submission goes only to them.")
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: assignee.value,
								"onUpdate:modelValue": [($event) => assignee.value = $event, assign],
								options: assigneeOptions.value
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue",
								"options"
							])]),
							_: 1
						}, 8, ["label", "help"])) : __props.entry.assignee ? (openBlock(), createBlock("p", {
							key: 1,
							class: "k-small"
						}, toDisplayString(unref(__)("Assigned to {name}", { name: __props.entry.assignee.username })), 1)) : createCommentVNode("v-if", true)]),
						unref(timeline).data.value?.length ? (openBlock(), createBlock("ol", {
							key: 0,
							class: "k-review__list",
							"aria-label": unref(__)("Comments")
						}, [(openBlock(true), createBlock(Fragment, null, renderList(unref(timeline).data.value, (c) => {
							return openBlock(), createBlock("li", {
								key: c.id,
								class: ["k-review__item", {
									"is-step": c.kind !== "comment",
									[`is-${c.kind}`]: true
								}]
							}, [
								createVNode("p", { class: "k-review__meta k-small" }, [createVNode("span", { class: "k-review__who" }, toDisplayString(c.kind === "comment" ? name(c) : STEP[c.kind]?.(name(c)) ?? name(c)), 1), createVNode(unref(KTime), {
									value: c.created_at,
									class: "k-muted"
								}, null, 8, ["value"])]),
								c.body ? (openBlock(), createBlock("p", {
									key: 0,
									class: "k-review__body"
								}, toDisplayString(c.body), 1)) : createCommentVNode("v-if", true),
								c.kind === "comment" && (c.user?.id === unref(user)?.id || __props.canModerate) ? (openBlock(), createBlock("button", {
									key: 1,
									type: "button",
									class: "k-review__delete k-small",
									"aria-label": unref(__)("Delete the comment from {when}", { when: unref(formatDateTime)(c.created_at) }),
									onClick: ($event) => remove(c)
								}, toDisplayString(unref(__)("Delete")), 9, ["aria-label", "onClick"])) : createCommentVNode("v-if", true)
							], 2);
						}), 128))], 8, ["aria-label"])) : unref(timeline).error.value ? (openBlock(), createBlock("p", {
							key: 1,
							class: "k-small k-review__empty",
							role: "alert"
						}, toDisplayString(unref(timeline).error.value.message), 1)) : !unref(timeline).loading.value ? (openBlock(), createBlock("p", {
							key: 2,
							class: "k-muted k-small k-review__empty"
						}, toDisplayString(unref(__)("No comments yet. Only people in the admin see them.")), 1)) : createCommentVNode("v-if", true),
						createVNode("form", {
							class: "k-review__form",
							onSubmit: withModifiers(post, ["prevent"])
						}, [
							createVNode("label", {
								class: "k-review__label k-small",
								for: "k-review-new"
							}, toDisplayString(unref(__)("Add a comment")), 1),
							withDirectives(createVNode("textarea", {
								id: "k-review-new",
								"onUpdate:modelValue": ($event) => draft.value = $event,
								class: "k-input k-textarea",
								rows: "3",
								maxlength: "5000",
								onKeydown: onKey
							}, null, 40, ["onUpdate:modelValue"]), [[vModelText, draft.value]]),
							createVNode(unref(KButton), {
								type: "submit",
								size: "sm",
								loading: posting.value,
								disabled: !draft.value.trim()
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Comment")), 1)]),
								_: 1
							}, 8, ["loading", "disabled"])
						], 32)
					];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup$3 = _sfc_main$3.setup;
_sfc_main$3.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/content/ReviewPanel.vue");
	return _sfc_setup$3 ? _sfc_setup$3(props, ctx) : void 0;
};
var ReviewPanel_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main$3, [["__scopeId", "data-v-a373207e"]]);
// core/resources/js/components/content/SharePreviews.vue
/**
* Share links on the entry editor: a preview for someone without an
* account. The link is shown once, when made; the list only says who it
* was for and when it expires.
*/
var _sfc_main$2 = {
	__name: "SharePreviews",
	__ssrInlineRender: true,
	props: {
		base: {
			type: String,
			required: true
		},
		singular: {
			type: String,
			required: true
		}
	},
	setup(__props) {
		const props = __props;
		const api = useApi();
		const links = useResource(() => `${props.base}/previews`);
		const form = reactive({
			label: "",
			days: 7
		});
		const made = ref(null);
		const busy = ref(false);
		const DAYS = [
			{
				value: 1,
				label: __("1 day")
			},
			{
				value: 7,
				label: __("7 days")
			},
			{
				value: 14,
				label: __("14 days")
			},
			{
				value: 30,
				label: __("30 days")
			}
		];
		const absolute = computed(() => made.value ? new URL(made.value.url, window.location.origin).toString() : "");
		async function create() {
			busy.value = true;
			try {
				const { data } = await api.post(`${props.base}/previews`, {
					kind: "share",
					days: form.days,
					label: form.label.trim() || null
				});
				made.value = data;
				form.label = "";
				links.reload({ silent: true });
				copy();
			} catch (e) {
				notify.error(e);
			} finally {
				busy.value = false;
			}
		}
		function copy() {
			navigator.clipboard?.writeText(absolute.value).then(() => notify.success(__("Link copied.")), () => {});
		}
		async function revoke(link) {
			if (!await confirm({
				title: __("Revoke this link?"),
				message: __("Anyone who has it can no longer open the preview."),
				confirmLabel: __("Revoke"),
				danger: true
			})) return;
			try {
				await api.delete(`${props.base}/previews/${link.id}`);
				links.reload({ silent: true });
				notify.success(__("Link revoked."));
			} catch (e) {
				notify.error(e);
			}
		}
		const openedText = (n) => __("{n, plural, one {opened once} other {opened # times}}", { n });
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(unref(KCard), mergeProps({ title: unref(__)("Share a preview") }, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-form" data-v-1a05cca2${_scopeId}><p class="k-muted k-small" data-v-1a05cca2${_scopeId}>${ssrInterpolate(unref(__)("A link that shows this {item} as it is saved, to anyone who has it, even before it is published.", { item: __props.singular.toLowerCase() }))}</p>`);
						if (made.value) {
							_push(`<div class="k-share-made" data-v-1a05cca2${_scopeId}><label class="k-sr-only" for="k-share-url" data-v-1a05cca2${_scopeId}>${ssrInterpolate(unref(__)("Preview link"))}</label><input id="k-share-url" class="k-input k-share-made__url"${ssrRenderAttr("value", absolute.value)} readonly data-v-1a05cca2${_scopeId}><div class="k-share-made__row" data-v-1a05cca2${_scopeId}>`);
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								icon: "copy",
								onClick: copy
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Copy"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Copy")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`<span class="k-muted k-small" data-v-1a05cca2${_scopeId}>${ssrInterpolate(unref(__)("Shown only now. Expires {when}.", { when: unref(formatDateTime)(made.value.expires_at) }))}</span></div></div>`);
						} else _push(`<!---->`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Who is it for?"),
							help: unref(__)("Optional, so you know which link to revoke later.")
						}, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.label,
									"onUpdate:modelValue": ($event) => form.label = $event,
									maxlength: "100"
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.label,
									"onUpdate:modelValue": ($event) => form.label = $event,
									maxlength: "100"
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KField), { label: unref(__)("Works for") }, {
							default: withCtx(({ id }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KSelect), {
									id,
									modelValue: form.days,
									"onUpdate:modelValue": ($event) => form.days = $event,
									modelModifiers: { number: true },
									options: DAYS
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KSelect), {
									id,
									modelValue: form.days,
									"onUpdate:modelValue": ($event) => form.days = $event,
									modelModifiers: { number: true },
									options: DAYS
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							block: "",
							loading: busy.value,
							icon: "link",
							onClick: create
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Create link"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Create link")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`</div>`);
						if (unref(links).data.value?.length) {
							_push(`<ul class="k-share-list" data-v-1a05cca2${_scopeId}><!--[-->`);
							ssrRenderList(unref(links).data.value, (link) => {
								_push(`<li class="k-share-list__item" data-v-1a05cca2${_scopeId}><div class="k-share-list__main" data-v-1a05cca2${_scopeId}><span data-v-1a05cca2${_scopeId}>${ssrInterpolate(link.label || unref(__)("Unnamed link"))}</span><span class="k-muted k-small" data-v-1a05cca2${_scopeId}>${ssrInterpolate(unref(__)("Expires"))} `);
								_push(ssrRenderComponent(unref(KTime), { value: link.expires_at }, null, _parent, _scopeId));
								_push(` · ${ssrInterpolate(openedText(link.views))}</span></div>`);
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "x",
									"aria-label": unref(__)("Revoke {name}", { name: link.label || unref(__)("Unnamed link") }),
									onClick: ($event) => revoke(link)
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Revoke"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Revoke")), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								_push(`</li>`);
							});
							_push(`<!--]--></ul>`);
						} else _push(`<!---->`);
					} else return [createVNode("div", { class: "k-form" }, [
						createVNode("p", { class: "k-muted k-small" }, toDisplayString(unref(__)("A link that shows this {item} as it is saved, to anyone who has it, even before it is published.", { item: __props.singular.toLowerCase() })), 1),
						made.value ? (openBlock(), createBlock("div", {
							key: 0,
							class: "k-share-made"
						}, [
							createVNode("label", {
								class: "k-sr-only",
								for: "k-share-url"
							}, toDisplayString(unref(__)("Preview link")), 1),
							createVNode("input", {
								id: "k-share-url",
								class: "k-input k-share-made__url",
								value: absolute.value,
								readonly: "",
								onFocus: ($event) => $event.target.select()
							}, null, 40, ["value", "onFocus"]),
							createVNode("div", { class: "k-share-made__row" }, [createVNode(unref(KButton), {
								size: "sm",
								icon: "copy",
								onClick: copy
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Copy")), 1)]),
								_: 1
							}), createVNode("span", { class: "k-muted k-small" }, toDisplayString(unref(__)("Shown only now. Expires {when}.", { when: unref(formatDateTime)(made.value.expires_at) })), 1)])
						])) : createCommentVNode("v-if", true),
						createVNode(unref(KField), {
							label: unref(__)("Who is it for?"),
							help: unref(__)("Optional, so you know which link to revoke later.")
						}, {
							default: withCtx(({ id }) => [createVNode(unref(KInput), {
								id,
								modelValue: form.label,
								"onUpdate:modelValue": ($event) => form.label = $event,
								maxlength: "100"
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label", "help"]),
						createVNode(unref(KField), { label: unref(__)("Works for") }, {
							default: withCtx(({ id }) => [createVNode(unref(KSelect), {
								id,
								modelValue: form.days,
								"onUpdate:modelValue": ($event) => form.days = $event,
								modelModifiers: { number: true },
								options: DAYS
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])]),
							_: 1
						}, 8, ["label"]),
						createVNode(unref(KButton), {
							block: "",
							loading: busy.value,
							icon: "link",
							onClick: create
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Create link")), 1)]),
							_: 1
						}, 8, ["loading"])
					]), unref(links).data.value?.length ? (openBlock(), createBlock("ul", {
						key: 0,
						class: "k-share-list"
					}, [(openBlock(true), createBlock(Fragment, null, renderList(unref(links).data.value, (link) => {
						return openBlock(), createBlock("li", {
							key: link.id,
							class: "k-share-list__item"
						}, [createVNode("div", { class: "k-share-list__main" }, [createVNode("span", null, toDisplayString(link.label || unref(__)("Unnamed link")), 1), createVNode("span", { class: "k-muted k-small" }, [
							createTextVNode(toDisplayString(unref(__)("Expires")) + " ", 1),
							createVNode(unref(KTime), { value: link.expires_at }, null, 8, ["value"]),
							createTextVNode(" · " + toDisplayString(openedText(link.views)), 1)
						])]), createVNode(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "x",
							"aria-label": unref(__)("Revoke {name}", { name: link.label || unref(__)("Unnamed link") }),
							onClick: ($event) => revoke(link)
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Revoke")), 1)]),
							_: 1
						}, 8, ["aria-label", "onClick"])]);
					}), 128))])) : createCommentVNode("v-if", true)];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup$2 = _sfc_main$2.setup;
_sfc_main$2.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/content/SharePreviews.vue");
	return _sfc_setup$2 ? _sfc_setup$2(props, ctx) : void 0;
};
var SharePreviews_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main$2, [["__scopeId", "data-v-1a05cca2"]]);
// core/resources/js/components/content/timetable.js
/**
* The visual editor's publishing timetable (StudioPublish.vue): when the page goes live,
* as one row. Pure: the words come in through t, the date through format.
*
* @param {{live: boolean, future: boolean, publishedAt: string|null}} s
* @param {(text: string) => string} t
* @param {(iso: string) => string} format
* @returns {{label: string, value: string, scheduled: boolean}}
*/
function whenRow({ live, future, publishedAt }, t, format) {
	if (live) return {
		label: t("Live since"),
		value: publishedAt ? format(publishedAt) : t("Now"),
		scheduled: false
	};
	if (future && publishedAt) return {
		label: t("Goes live"),
		value: format(publishedAt),
		scheduled: true
	};
	return {
		label: t("Goes live"),
		value: t("When you publish"),
		scheduled: false
	};
}
// core/resources/js/components/content/StudioPublish.vue
/**
* The visual editor's Page tab opens with this: a small timetable of when the page goes
* live, its address, and its history, read at a glance. Changing the time or the address
* opens its field in place. The form editor keeps its cards (Edit.vue).
*/
var _sfc_main$1 = {
	__name: "StudioPublish",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		live: Boolean,
		future: Boolean,
		publishedAt: {
			type: String,
			default: null
		},
		zone: {
			type: String,
			required: true
		},
		canPublish: Boolean,
		readOnly: Boolean,
		prefix: {
			type: String,
			default: null
		},
		shownSlug: {
			type: String,
			default: ""
		},
		slugError: {
			type: String,
			default: ""
		},
		createdAt: {
			type: String,
			default: null
		},
		version: {
			type: Number,
			default: null
		}
	}, {
		"publishAt": {
			type: String,
			default: ""
		},
		"publishAtModifiers": {},
		"slug": {
			type: String,
			default: ""
		},
		"slugModifiers": {}
	}),
	emits: ["update:publishAt", "update:slug"],
	setup(__props) {
		const publishAt = useModel(__props, "publishAt");
		const slug = useModel(__props, "slug");
		const props = __props;
		const when = computed(() => whenRow(props, __, formatDateTime));
		const editingWhen = ref(false);
		const editingSlug = ref(false);
		const root = ref(null);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				ref_key: "root",
				ref: root,
				class: "k-tt"
			}, _attrs))}><dl class="k-tt__rows"><div class="k-tt__row"><dt>${ssrInterpolate(when.value.label)}</dt><dd><span class="${ssrRenderClass({ "k-tt__sched": when.value.scheduled })}">${ssrInterpolate(when.value.value)}</span>`);
			if (__props.canPublish && !__props.readOnly && !editingWhen.value) _push(`<button type="button" class="k-link-btn k-tt__change">${ssrInterpolate(__props.publishedAt ? unref(__)("Change") : unref(__)("Set a time"))}</button>`);
			else _push(`<!---->`);
			_push(`</dd>`);
			if (editingWhen.value) {
				_push(`<div class="k-tt__edit"><label class="k-tt__label" for="k-tt-when">${ssrInterpolate(__props.live ? unref(__)("Published on") : unref(__)("Publish on"))}</label><input id="k-tt-when"${ssrRenderAttr("value", publishAt.value)} type="datetime-local" class="k-input" aria-describedby="k-tt-when-help"><p id="k-tt-when-help" class="k-tt__help">${ssrInterpolate(unref(__)("Times are in {zone}. A time still to come makes Publish schedule it.", { zone: __props.zone }))}</p><div class="k-tt__actions"><button type="button" class="k-link-btn">${ssrInterpolate(unref(__)("Done"))}</button>`);
				if (publishAt.value && !__props.live) _push(`<button type="button" class="k-link-btn">${ssrInterpolate(unref(__)("Publish when I choose Publish"))}</button>`);
				else _push(`<!---->`);
				_push(`</div></div>`);
			} else _push(`<!---->`);
			_push(`</div>`);
			if (__props.prefix !== null) {
				_push(`<div class="k-tt__row"><dt>${ssrInterpolate(unref(__)("Address"))}</dt><dd><span class="k-tt__path">${ssrInterpolate(__props.prefix)}${ssrInterpolate(__props.shownSlug || "…")}</span>`);
				if (!__props.readOnly && !editingSlug.value) _push(`<button type="button" class="k-link-btn k-tt__change">${ssrInterpolate(unref(__)("Change"))}</button>`);
				else _push(`<!---->`);
				_push(`</dd>`);
				if (!editingSlug.value && !__props.live && !slug.value) _push(`<p class="k-tt__note">${ssrInterpolate(unref(__)("Follows the title until it is published."))}</p>`);
				else _push(`<!---->`);
				if (editingSlug.value) _push(`<div class="k-tt__edit"><label class="k-tt__label" for="k-tt-slug">${ssrInterpolate(unref(__)("Last part of the address"))}</label><input id="k-tt-slug"${ssrRenderAttr("value", slug.value)} class="k-input" maxlength="191" spellcheck="false" autocomplete="off" data-lpignore="true" data-1p-ignore${ssrRenderAttr("aria-invalid", __props.slugError ? "true" : void 0)}${ssrRenderAttr("aria-describedby", __props.slugError ? "k-tt-slug-error" : void 0)}><div class="k-tt__actions"><button type="button" class="k-link-btn">${ssrInterpolate(unref(__)("Done"))}</button></div></div>`);
				else _push(`<!---->`);
				if (__props.slugError) _push(`<p id="k-tt-slug-error" class="k-field__error">${ssrInterpolate(__props.slugError)}</p>`);
				else _push(`<!---->`);
				_push(`</div>`);
			} else _push(`<!---->`);
			if (__props.createdAt) _push(`<div class="k-tt__row"><dt>${ssrInterpolate(unref(__)("Created"))}</dt><dd>${ssrInterpolate(unref(formatDateTime)(__props.createdAt))}</dd></div>`);
			else _push(`<!---->`);
			if (__props.version) _push(`<div class="k-tt__row"><dt>${ssrInterpolate(unref(__)("Version"))}</dt><dd>${ssrInterpolate(__props.version)}</dd></div>`);
			else _push(`<!---->`);
			_push(`</dl>`);
			ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
			_push(`</div>`);
		};
	}
};
var _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/components/content/StudioPublish.vue");
	return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
// core/resources/js/components/content/entry-state.js
/**
* Whether the editor holds an entry not created yet. The address says "new"
* until the router has moved to the entry's own; after the first save the
* entry exists even if that move never happens (a deployment in between
* loads the page in full), so a second save must update it, never create
* another.
* @param {string} routeId the {id} of the address: a number, or 'new'
* @param {{ id?: number } | null} entry the entry as last saved
*/
var isNewEntry = (routeId, entry) => routeId === "new" && !entry?.id;
// core/resources/js/pages/admin/content/Edit.vue
var MODE = "kestello.editor.mode";
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Edit",
	__ssrInlineRender: true,
	setup(__props) {
		const page = usePage();
		const api = useApi();
		const router = useRouter();
		const typeName = computed(() => String(page.props.params?.type ?? ""));
		const routeId = computed(() => String(page.props.params?.id ?? "new"));
		const isNew = computed(() => isNewEntry(routeId.value, entry.value));
		const types = useResource("/content/types");
		const type = computed(() => (types.data.value ?? []).find((t) => t.name === typeName.value) ?? null);
		const fields = computed(() => type.value?.fields ?? []);
		const shown = computed(() => visibleFields(fields.value, form.data ?? {}));
		const mainFields = computed(() => fields.value.filter((f) => f.width !== "sidebar" && shown.value[f.name] !== false && !(studio.value && f === visualField.value)));
		const sideFields = computed(() => fields.value.filter((f) => f.width === "sidebar" && shown.value[f.name] !== false));
		const base = `/content/${typeName.value}/entries`;
		const visualField = computed(() => fields.value.find((f) => f.type === "blocks" && f.width !== "sidebar") ?? null);
		const readMode = () => {
			try {
				return localStorage.getItem(MODE);
			} catch {
				return null;
			}
		};
		const preferForm = ref(readMode() === "form");
		const studio = computed(() => !!visualField.value && !preferForm.value);
		function setMode(form) {
			preferForm.value = form;
			try {
				localStorage.setItem(MODE, form ? "form" : "visual");
			} catch {}
		}
		const docTarget = ref(null);
		const alertsTarget = ref(null);
		const belowTarget = ref(null);
		function studioMounted() {
			docTarget.value = document.getElementById("k-studio-doc");
			belowTarget.value = document.getElementById("k-studio-below");
		}
		function focusTitle() {
			const input = document.getElementById("k-studio-title");
			input?.focus();
			input?.select();
		}
		function chooseTemplate() {
			setTimeout(() => {
				const el = document.querySelector(".k-studio__template");
				el?.scrollIntoView({
					block: "center",
					behavior: "smooth"
				});
				el?.querySelector("select, button, input")?.focus({ preventScroll: true });
			}, 60);
		}
		watch(studio, (on) => {
			if (!on) {
				docTarget.value = null;
				alertsTarget.value = null;
				belowTarget.value = null;
			}
		});
		const canvasSrc = computed(() => {
			const q = new URLSearchParams({
				kind: "entry",
				type: typeName.value,
				field: visualField.value?.name ?? ""
			});
			if (!isNew.value && entry.value?.id) q.set("entry", String(entry.value.id));
			q.set("template", form.template || "");
			return `/system/design/canvas?${q}`;
		});
		async function prepareBlocks(blocks) {
			const body = {
				blocks,
				field: visualField.value.name
			};
			if (entry.value?.id) body.entry = entry.value.id;
			return (await api.post(`/content/${typeName.value}/preview-blocks`, body)).data.blocks;
		}
		let upgraded = null;
		const visualTree = computed({
			get: () => {
				const v = form.data[visualField.value?.name];
				if (Array.isArray(v)) return v;
				if (typeof v === "string" && v.trim() !== "") {
					if (upgraded?.html !== v) upgraded = {
						html: v,
						tree: [{
							type: "core/text",
							id: Array.from(crypto.getRandomValues(/* @__PURE__ */ new Uint8Array(4)), (b) => b.toString(16).padStart(2, "0")).join(""),
							props: { text: v },
							children: []
						}]
					};
					return upgraded.tree;
				}
				return [];
			},
			set: (v) => {
				form.data[visualField.value.name] = v.length ? v : null;
			}
		});
		const visualErrors = computed(() => {
			const prefix = `data.${visualField.value?.name}`;
			return Object.fromEntries(Object.entries(errors.value).filter(([k]) => k === prefix || k.startsWith(`${prefix}.`)).map(([k, v]) => [`blocks${k.slice(prefix.length)}`, v]));
		});
		const entry = ref(null);
		const loadError = ref(null);
		const form = reactive(blank());
		const errors = ref({});
		const saving = ref(false);
		const conflict = ref(false);
		const autosave = ref(null);
		const autosavedAt = ref(null);
		function blank() {
			return {
				title: "",
				slug: "",
				excerpt: "",
				data: {},
				terms: {},
				parent_id: null,
				author_id: null,
				menu_order: 0,
				published_at: null,
				status: "draft",
				template: ""
			};
		}
		function fill(e) {
			Object.assign(form, {
				title: e.title,
				slug: e.slug,
				excerpt: e.excerpt ?? "",
				data: clone(e.data ?? {}),
				terms: termIds(e),
				parent_id: e.parent_id,
				author_id: e.author?.id ?? null,
				menu_order: e.menu_order,
				published_at: e.published_at,
				status: e.status,
				template: e.template ?? ""
			});
		}
		const templateOptions = computed(() => {
			const list = type.value?.templates ?? [];
			const opts = [{
				value: "",
				label: __("Default")
			}, ...list.map((t) => ({
				value: t.key,
				label: t.title
			}))];
			if (form.template && !list.some((t) => t.key === form.template)) opts.push({
				value: form.template,
				label: __("{name} (no longer available)", { name: form.template })
			});
			return list.length || form.template ? opts : [];
		});
		const taxonomies = computed(() => (type.value?.taxonomies ?? []).filter((t) => t.can.assign));
		function termIds(e) {
			const out = {};
			for (const t of type.value?.taxonomies ?? []) out[t.name] = (e?.terms?.[t.name] ?? []).map((x) => x.id);
			return out;
		}
		const sameList = (a, b) => JSON.stringify(a ?? []) === JSON.stringify(b ?? []);
		const filled = (data) => Object.fromEntries(Object.entries(data).filter(([, v]) => v !== null && v !== "" && v !== false && !(Array.isArray(v) && !v.length) && !(v && typeof v === "object" && !Array.isArray(v) && !Object.keys(filled(v)).length)));
		function defaults() {
			const data = {};
			for (const f of fields.value) if (f.default !== null && f.default !== void 0) data[f.name] = clone(f.default);
			return data;
		}
		async function load() {
			if (routeId.value === "new") {
				entry.value = null;
				Object.assign(form, blank(), {
					data: defaults(),
					terms: termIds(null)
				});
				return;
			}
			try {
				const { data } = await api.get(`${base}/${routeId.value}`);
				entry.value = data;
				fill(data);
				autosave.value = data.autosave;
				loadError.value = null;
			} catch (e) {
				loadError.value = e;
			}
		}
		watch([routeId, () => !!type.value], ([, ready]) => {
			if (ready) load();
		}, { immediate: true });
		const changes = computed(() => {
			const e = entry.value;
			if (!e) {
				const out = {
					...form,
					excerpt: form.excerpt || null
				};
				out.terms = Object.fromEntries(Object.entries(form.terms).filter(([name, ids]) => ids.length && taxonomies.value.some((t) => t.name === name)));
				for (const key of [
					"author_id",
					"parent_id",
					"published_at"
				]) if (out[key] === null) delete out[key];
				if (!out.template) delete out.template;
				delete out.status;
				return out;
			}
			const out = {};
			if (form.title !== e.title) out.title = form.title;
			if (form.slug !== e.slug) out.slug = form.slug;
			if ((form.excerpt || null) !== (e.excerpt || null)) out.excerpt = form.excerpt || null;
			if (JSON.stringify(form.data) !== JSON.stringify(e.data ?? {})) out.data = form.data;
			const before = termIds(e);
			const terms = Object.fromEntries(Object.entries(form.terms).filter(([name, ids]) => !sameList(ids, before[name])));
			if (Object.keys(terms).length) out.terms = terms;
			if (form.parent_id !== e.parent_id) out.parent_id = form.parent_id;
			if (form.author_id !== (e.author?.id ?? null)) out.author_id = form.author_id;
			if (form.menu_order !== e.menu_order) out.menu_order = form.menu_order;
			if ((form.template || null) !== (e.template || null)) out.template = form.template || null;
			if (form.published_at !== e.published_at) out.published_at = form.published_at;
			return out;
		});
		const dirty = computed(() => isNew.value ? form.title.trim() !== "" || JSON.stringify(filled(form.data)) !== JSON.stringify(filled(defaults())) || Object.values(form.terms).some((ids) => ids.length) : Object.keys(changes.value).length > 0);
		const can = computed(() => entry.value?.can ?? {
			edit: !!type.value?.can.create,
			publish: !!type.value?.can.publish,
			delete: false
		});
		const readOnly = computed(() => !can.value.edit || !!entry.value?.trashed_at);
		const status = computed(() => entry.value?.status ?? "draft");
		const live = computed(() => status.value === "published" || status.value === "private");
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
		const future = computed(() => !!form.published_at && Date.parse(form.published_at) > Date.now());
		const studioExtras = computed(() => (live.value || status.value === "scheduled") && can.value.publish || status.value === "pending" || !can.value.publish);
		const barActions = computed(() => {
			if (readOnly.value) return [];
			if (live.value || status.value === "scheduled") return [{
				label: status.value === "scheduled" ? __("Update schedule") : __("Update"),
				primary: true,
				disabled: !dirty.value,
				run: () => save()
			}];
			if (status.value === "pending") return can.value.publish ? [{
				label: __("Save"),
				disabled: !dirty.value,
				run: () => save()
			}, {
				label: future.value ? __("Approve and schedule") : __("Approve and publish"),
				primary: true,
				run: () => save("published")
			}] : [{
				label: __("Save changes"),
				primary: true,
				disabled: !dirty.value,
				run: () => save()
			}];
			return [{
				label: __("Save draft"),
				disabled: !dirty.value && !isNew.value,
				run: () => save("draft")
			}, can.value.publish ? {
				label: future.value ? __("Schedule") : __("Publish"),
				primary: true,
				run: () => save("published")
			} : {
				label: __("Submit for review"),
				primary: true,
				run: () => save("pending")
			}];
		});
		async function save(nextStatus = null, { force = false } = {}) {
			if (saving.value || readOnly.value) return;
			const body = { ...changes.value };
			if (nextStatus && nextStatus !== status.value) body.status = nextStatus;
			if (nextStatus === "published" && future.value) body.status = "published";
			if (!isNew.value && !Object.keys(body).length) return;
			if (!isNew.value && !force) body.version = entry.value.version;
			saving.value = true;
			errors.value = {};
			try {
				if (isNew.value) {
					const { data } = await api.post(base, {
						...body,
						status: nextStatus ?? "draft"
					});
					entry.value = data;
					fill(data);
					notify.success(message(data));
					router.visit(`/admin/content/${typeName.value}/${data.id}`, {
						replace: true,
						preserveScroll: true,
						discardUnsaved: true
					});
				} else {
					const { data } = await api.patch(`${base}/${entry.value.id}`, body);
					const was = entry.value.status;
					entry.value = data;
					fill(data);
					autosave.value = null;
					conflict.value = false;
					revisions.reload({ silent: true });
					if (was !== data.status) reviewPanel.value?.reload();
					notify.success(was !== data.status ? message(data) : __("Saved."));
				}
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) {
					errors.value = e.errors ?? {};
					const n = Object.values(errors.value).reduce((sum, m) => sum + (Array.isArray(m) ? m.length : 1), 0);
					notify.error(__("{n, plural, one {One problem needs attention.} other {# problems need attention.}}", { n }));
					await nextTick();
					document.querySelector(".has-error")?.scrollIntoView({
						block: "center",
						behavior: "smooth"
					});
				} else if (e instanceof ApiError && e.status === 409) conflict.value = true;
				else {
					console.error("[kestello] save failed", e);
					notify.error(e);
				}
			} finally {
				saving.value = false;
			}
		}
		function message(e) {
			return {
				draft: __("Saved as a draft."),
				pending: __("Submitted for review."),
				scheduled: __("Scheduled for {when}.", { when: formatDateTime(e.published_at) }),
				published: __("Published."),
				private: __("Published privately.")
			}[e.status];
		}
		async function reloadTheirs() {
			conflict.value = false;
			await load();
			notify.info(__("Loaded the latest version."));
		}
		let timer = null;
		let lastSnapshot = "";
		async function autosaveNow() {
			if (isNew.value || readOnly.value || !dirty.value || saving.value) return;
			const snapshot = {
				title: form.title,
				slug: form.slug,
				excerpt: form.excerpt || null,
				data: form.data
			};
			const json = JSON.stringify(snapshot);
			if (json === lastSnapshot) return;
			try {
				const res = await api.put(`${base}/${entry.value.id}/autosave`, snapshot);
				lastSnapshot = json;
				autosavedAt.value = res.data?.saved_at ?? null;
			} catch {}
		}
		const canPreview = computed(() => !!entry.value && !entry.value.trashed_at && can.value.edit && type.value?.path !== null && type.value?.path !== void 0);
		const previewing = ref(false);
		async function preview() {
			const tab = window.open("about:blank", "_blank");
			previewing.value = true;
			try {
				await autosaveNow();
				const { data } = await api.post(`${base}/${entry.value.id}/previews`, { kind: "editor" });
				if (tab) {
					tab.opener = null;
					tab.location.href = data.url;
				} else window.location.assign(data.url);
			} catch (e) {
				tab?.close();
				notify.error(e);
			} finally {
				previewing.value = false;
			}
		}
		function restoreAutosave() {
			const s = autosave.value.snapshot;
			Object.assign(form, {
				title: s.title ?? form.title,
				slug: s.slug ?? form.slug,
				excerpt: s.excerpt ?? "",
				data: {
					...form.data,
					...s.data ?? {}
				}
			});
			autosave.value = null;
		}
		useLeaveGuard(dirty);
		function shortcut(e) {
			if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "s") {
				e.preventDefault();
				save(isNew.value ? "draft" : null);
			}
		}
		onMounted(() => {
			timer = setInterval(autosaveNow, 3e4);
			window.addEventListener("keydown", shortcut);
		});
		onBeforeUnmount(() => {
			clearInterval(timer);
			window.removeEventListener("keydown", shortcut);
		});
		const editingSlug = ref(false);
		const prefix = computed(() => {
			const p = type.value?.path;
			if (p === null || p === void 0) return null;
			return (entry.value?.parent && form.parent_id === entry.value.parent_id ? (entry.value.path ?? "").replace(/[^/]+$/, "") : `${p}/`) || "/";
		});
		const shownSlug = computed(() => form.slug || (form.title ? form.title.toLowerCase().normalize("NFKD").replace(/[^\w\s-]/g, "").trim().replace(/[\s_-]+/g, "-") : ""));
		const lookupItem = (e) => ({
			id: e.id,
			label: e.title || __("(untitled)"),
			meta: e.path ?? ""
		});
		const searchParents = async (q) => (await api.get("/content/lookup", {
			search: q || void 0,
			types: typeName.value
		})).data.filter((e) => e.id !== entry.value?.id).map(lookupItem);
		const resolveParents = async (ids) => (await api.get("/content/lookup", {
			ids: ids.join(","),
			types: typeName.value
		})).data.map(lookupItem);
		const userItem = (u) => ({
			id: u.id,
			label: u.username
		});
		const searchUsers = async (q) => (await api.get("/content/users", { search: q || void 0 })).data.map(userItem);
		const resolveUsers = async (ids) => (await api.get("/content/users", { ids: ids.join(",") })).data.map(userItem);
		const canReassign = computed(() => !!type.value?.can.edit_others);
		const pad = (n) => String(n).padStart(2, "0");
		const publishAt = computed({
			get() {
				if (!form.published_at) return "";
				const d = new Date(form.published_at);
				return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
			},
			set(v) {
				form.published_at = v ? new Date(v).toISOString().replace(/\.\d{3}Z$/, "Z") : null;
			}
		});
		const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
		const revisions = useResource(() => `${base}/${entry.value?.id ?? 0}/revisions`, { immediate: false });
		const fieldLabel = (key) => {
			if (key.startsWith("data.")) return fields.value.find((f) => f.name === key.slice(5))?.label ?? key.slice(5);
			return {
				terms: __("categories and tags"),
				title: __("title"),
				slug: __("slug"),
				status: __("status"),
				excerpt: __("excerpt"),
				parent_id: __("parent"),
				author_id: __("author"),
				published_at: __("date"),
				menu_order: __("order"),
				template: __("template")
			}[key] ?? key;
		};
		async function restoreRevision(rev) {
			if (dirty.value && !await confirm({
				title: __("Discard your unsaved changes?"),
				message: __("Restoring replaces what is in the editor."),
				confirmLabel: __("Restore revision")
			})) return;
			try {
				const { data } = await api.post(`${base}/${entry.value.id}/revisions/${rev.id}/restore`);
				entry.value = data;
				fill(data);
				revisions.reload({ silent: true });
				notify.success(__("Restored the revision from {when}.", { when: formatDateTime(rev.created_at) }));
			} catch (e) {
				notify.error(e);
			}
		}
		const reviewPanel = ref(null);
		const asking = ref(false);
		const note = ref("");
		const sending = ref(false);
		async function requestChanges() {
			if (!note.value.trim()) return;
			if (dirty.value) {
				await save();
				if (dirty.value) return;
			}
			sending.value = true;
			try {
				const { data } = await api.post(`${base}/${entry.value.id}/request-changes`, { note: note.value });
				entry.value = data;
				fill(data);
				asking.value = false;
				note.value = "";
				revisions.reload({ silent: true });
				reviewPanel.value?.reload();
				notify.success(__("Sent back with your note."));
			} catch (e) {
				notify.error(e);
			} finally {
				sending.value = false;
			}
		}
		function onAssigned(data) {
			entry.value = {
				...entry.value,
				assignee: data.assignee
			};
		}
		const comparing = ref(null);
		const compareWith = ref("previous");
		const diff = ref(null);
		const diffError = ref(null);
		async function loadDiff() {
			diff.value = null;
			diffError.value = null;
			try {
				diff.value = (await api.get(`${base}/${entry.value.id}/revisions/${comparing.value.id}/diff`, { against: compareWith.value })).data;
			} catch (e) {
				diffError.value = e;
			}
		}
		function compare(rev) {
			comparing.value = rev;
			compareWith.value = "previous";
			loadDiff();
		}
		watch(compareWith, () => {
			if (comparing.value) loadDiff();
		});
		const compareOptions = computed(() => [{
			value: "previous",
			label: __("The version before it")
		}, {
			value: "current",
			label: __("The current version")
		}]);
		async function restoreCompared() {
			const rev = comparing.value;
			comparing.value = null;
			await restoreRevision(rev);
		}
		async function moveToTrash() {
			try {
				await api.delete(`${base}/${entry.value.id}`);
				notify.success(__("Moved to the trash."));
				router.visit(`/admin/content/${typeName.value}`, { discardUnsaved: true });
			} catch (e) {
				notify.error(e);
			}
		}
		async function restoreEntry() {
			try {
				const { data } = await api.post(`${base}/${entry.value.id}/restore`);
				entry.value = data;
				fill(data);
				notify.success(__("Restored from the trash."));
			} catch (e) {
				notify.error(e);
			}
		}
		const belowLabel = computed(() => [...mainFields.value.map((f) => f.label), type.value?.excerpt ? __("Excerpt") : null].filter(Boolean).join(", ") || __("More fields"));
		const title = computed(() => isNew.value ? __("New {item}", { item: (type.value?.singular ?? "").toLowerCase() }) : entry.value?.title || __("(untitled)"));
		const err = (key) => Array.isArray(errors.value[key]) ? errors.value[key][0] : errors.value[key] ?? "";
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: title.value }, null, _parent));
			if (studio.value && type.value && (isNew.value || entry.value)) {
				_push(`<div class="k-studio" role="region"${ssrRenderAttr("aria-label", unref(__)("Visual editor"))} data-v-3959f855>`);
				if (err("title")) _push(`<p id="k-studio-title-error" class="k-studio__error" role="alert" data-v-3959f855>${ssrInterpolate(unref(__)("Title"))}: ${ssrInterpolate(err("title"))}</p>`);
				else _push(`<!---->`);
				_push(`<div class="k-studio__alerts" data-v-3959f855></div>`);
				_push(ssrRenderComponent(unref(KBuilder), {
					modelValue: visualTree.value,
					"onUpdate:modelValue": ($event) => visualTree.value = $event,
					class: "k-studio__builder",
					kind: "entry",
					"doc-key": visualField.value.name,
					"reset-key": `${entry.value?.id ?? "new"}:${entry.value?.version ?? 0}`,
					"canvas-src": canvasSrc.value,
					previewer: prepareBlocks,
					"canvas-data": { title: form.title || unref(__)("Add a title") },
					allowed: visualField.value.options?.allowed ?? null,
					"document-label": type.value.singular,
					"start-tab": isNew.value ? "block" : "document",
					"below-label": belowLabel.value,
					disabled: readOnly.value,
					errors: visualErrors.value,
					onSave: ($event) => save(isNew.value ? "draft" : null),
					onReady: studioMounted,
					onChooseTemplate: chooseTemplate,
					onTitle: focusTitle
				}, createSlots({
					start: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(ssrRenderComponent(unref(Link), {
								href: `/admin/content/${typeName.value}`,
								class: "k-studio__back",
								title: unref(__)("Back to {list}", { list: type.value.plural })
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) {
										_push(ssrRenderComponent(unref(KIcon), { name: "arrow-left" }, null, _parent, _scopeId));
										_push(`<span class="k-sr-only" data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Back to {list}", { list: type.value.plural }))}</span>`);
									} else return [createVNode(unref(KIcon), { name: "arrow-left" }), createVNode("span", { class: "k-sr-only" }, toDisplayString(unref(__)("Back to {list}", { list: type.value.plural })), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							_push(`<label class="k-sr-only" for="k-studio-title" data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Title"))}</label><input id="k-studio-title"${ssrRenderAttr("value", form.title)} class="k-studio__title"${ssrRenderAttr("placeholder", unref(__)("Add a title"))} maxlength="255" autocomplete="off" data-lpignore="true" data-1p-ignore${ssrIncludeBooleanAttr(readOnly.value) ? " readonly" : ""}${ssrRenderAttr("aria-invalid", err("title") ? "true" : void 0)}${ssrRenderAttr("aria-describedby", err("title") ? "k-studio-title-error" : void 0)} data-v-3959f855${_scopeId}>`);
						} else return [
							createVNode(unref(Link), {
								href: `/admin/content/${typeName.value}`,
								class: "k-studio__back",
								title: unref(__)("Back to {list}", { list: type.value.plural })
							}, {
								default: withCtx(() => [createVNode(unref(KIcon), { name: "arrow-left" }), createVNode("span", { class: "k-sr-only" }, toDisplayString(unref(__)("Back to {list}", { list: type.value.plural })), 1)]),
								_: 1
							}, 8, ["href", "title"]),
							createVNode("label", {
								class: "k-sr-only",
								for: "k-studio-title"
							}, toDisplayString(unref(__)("Title")), 1),
							withDirectives(createVNode("input", {
								id: "k-studio-title",
								"onUpdate:modelValue": ($event) => form.title = $event,
								class: "k-studio__title",
								placeholder: unref(__)("Add a title"),
								maxlength: "255",
								autocomplete: "off",
								"data-lpignore": "true",
								"data-1p-ignore": "",
								readonly: readOnly.value,
								"aria-invalid": err("title") ? "true" : void 0,
								"aria-describedby": err("title") ? "k-studio-title-error" : void 0
							}, null, 8, [
								"onUpdate:modelValue",
								"placeholder",
								"readonly",
								"aria-invalid",
								"aria-describedby"
							]), [[vModelText, form.title]])
						];
					}),
					end: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<!-- one quiet line: "● Draft · Saved 4 minutes ago"; the dot carries the status colour --><span class="k-studio__state" aria-live="polite" data-v-3959f855${_scopeId}>`);
							if (entry.value) _push(`<span class="${ssrRenderClass([`is-${status.value}`, "k-studio__status"])}" data-v-3959f855${_scopeId}>${ssrInterpolate(STATUS[status.value]?.label)}</span>`);
							else _push(`<!---->`);
							if (saving.value) _push(`<span class="k-studio__note" data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Saving…"))}</span>`);
							else if (dirty.value) _push(`<span class="k-studio__note is-dirty" data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Unsaved"))}</span>`);
							else if (entry.value) {
								_push(`<span class="k-studio__note" data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Saved"))}`);
								_push(ssrRenderComponent(unref(KTime), {
									class: "k-studio__wide",
									value: entry.value.updated_at
								}, null, _parent, _scopeId));
								_push(`</span>`);
							} else _push(`<!---->`);
							_push(`</span>`);
							_push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "rows",
								title: unref(__)("Edit as a form instead"),
								onClick: ($event) => setMode(true)
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(unref(__)("Form"))}`);
									else return [createTextVNode(toDisplayString(unref(__)("Form")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							if (canPreview.value) _push(ssrRenderComponent(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "eye",
								loading: previewing.value,
								title: unref(__)("Preview"),
								onClick: preview
							}, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`<span class="k-studio__wide" data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Preview"))}</span>`);
									else return [createVNode("span", { class: "k-studio__wide" }, toDisplayString(unref(__)("Preview")), 1)];
								}),
								_: 1
							}, _parent, _scopeId));
							else _push(`<!---->`);
							_push(`<!--[-->`);
							ssrRenderList(barActions.value, (a) => {
								_push(ssrRenderComponent(unref(KButton), {
									key: a.label,
									size: "sm",
									variant: a.primary ? "primary" : "secondary",
									loading: a.primary && saving.value,
									disabled: a.disabled || saving.value,
									onClick: a.run
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(a.label)}`);
										else return [createTextVNode(toDisplayString(a.label), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
							});
							_push(`<!--]-->`);
						} else return [
							createCommentVNode(" one quiet line: \"● Draft · Saved 4 minutes ago\"; the dot carries the status colour "),
							createVNode("span", {
								class: "k-studio__state",
								"aria-live": "polite"
							}, [entry.value ? (openBlock(), createBlock("span", {
								key: 0,
								class: ["k-studio__status", `is-${status.value}`]
							}, toDisplayString(STATUS[status.value]?.label), 3)) : createCommentVNode("v-if", true), saving.value ? (openBlock(), createBlock("span", {
								key: 1,
								class: "k-studio__note"
							}, toDisplayString(unref(__)("Saving…")), 1)) : dirty.value ? (openBlock(), createBlock("span", {
								key: 2,
								class: "k-studio__note is-dirty"
							}, toDisplayString(unref(__)("Unsaved")), 1)) : entry.value ? (openBlock(), createBlock("span", {
								key: 3,
								class: "k-studio__note"
							}, [createTextVNode(toDisplayString(unref(__)("Saved")), 1), createVNode(unref(KTime), {
								class: "k-studio__wide",
								value: entry.value.updated_at
							}, null, 8, ["value"])])) : createCommentVNode("v-if", true)]),
							createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "rows",
								title: unref(__)("Edit as a form instead"),
								onClick: ($event) => setMode(true)
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Form")), 1)]),
								_: 1
							}, 8, ["title", "onClick"]),
							canPreview.value ? (openBlock(), createBlock(unref(KButton), {
								key: 0,
								size: "sm",
								variant: "ghost",
								icon: "eye",
								loading: previewing.value,
								title: unref(__)("Preview"),
								onClick: preview
							}, {
								default: withCtx(() => [createVNode("span", { class: "k-studio__wide" }, toDisplayString(unref(__)("Preview")), 1)]),
								_: 1
							}, 8, ["loading", "title"])) : createCommentVNode("v-if", true),
							(openBlock(true), createBlock(Fragment, null, renderList(barActions.value, (a) => {
								return openBlock(), createBlock(unref(KButton), {
									key: a.label,
									size: "sm",
									variant: a.primary ? "primary" : "secondary",
									loading: a.primary && saving.value,
									disabled: a.disabled || saving.value,
									onClick: a.run
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(a.label), 1)]),
									_: 2
								}, 1032, [
									"variant",
									"loading",
									"disabled",
									"onClick"
								]);
							}), 128))
						];
					}),
					document: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`<div id="k-studio-doc" class="k-studio__doc" data-v-3959f855${_scopeId}></div>`);
						else return [createVNode("div", {
							id: "k-studio-doc",
							class: "k-studio__doc"
						})];
					}),
					_: 2
				}, [mainFields.value.length || type.value.excerpt ? {
					name: "below",
					fn: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`<div id="k-studio-below" class="k-studio__below" data-v-3959f855${_scopeId}></div>`);
						else return [createVNode("div", {
							id: "k-studio-below",
							class: "k-studio__below"
						})];
					}),
					key: "0"
				} : void 0]), _parent));
				_push(`</div>`);
			} else _push(`<!---->`);
			_push(`<div class="k-editor-head" style="${ssrRenderStyle(!studio.value ? null : { display: "none" })}" data-v-3959f855>`);
			_push(ssrRenderComponent(unref(Link), {
				href: `/admin/content/${typeName.value}`,
				class: "k-link k-editor-head__back"
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KIcon), { name: "arrow-left" }, null, _parent, _scopeId));
						_push(`${ssrInterpolate(type.value?.plural ?? unref(__)("Content"))}`);
					} else return [createVNode(unref(KIcon), { name: "arrow-left" }), createTextVNode(toDisplayString(type.value?.plural ?? unref(__)("Content")), 1)];
				}),
				_: 1
			}, _parent));
			_push(`<span class="k-editor-head__state" aria-live="polite" data-v-3959f855>`);
			if (entry.value) _push(ssrRenderComponent(unref(KBadge), { tone: STATUS[status.value]?.tone }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(STATUS[status.value]?.label)}`);
					else return [createTextVNode(toDisplayString(STATUS[status.value]?.label), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (dirty.value) _push(`<span class="k-muted" data-v-3959f855>${ssrInterpolate(unref(__)("Unsaved changes"))}</span>`);
			else if (entry.value) {
				_push(`<span class="k-muted" data-v-3959f855>${ssrInterpolate(unref(__)("Saved"))} `);
				_push(ssrRenderComponent(unref(KTime), { value: entry.value.updated_at }, null, _parent));
				_push(`</span>`);
			} else _push(`<!---->`);
			if (dirty.value && autosavedAt.value) _push(`<span class="k-muted" data-v-3959f855>· ${ssrInterpolate(unref(__)("backup kept {when}", { when: unref(formatRelative)(autosavedAt.value) }))}</span>`);
			else _push(`<!---->`);
			_push(`</span>`);
			if (visualField.value) _push(ssrRenderComponent(unref(KButton), {
				size: "sm",
				variant: "ghost",
				icon: "layout",
				onClick: ($event) => setMode(false)
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("Visual editor"))}`);
					else return [createTextVNode(toDisplayString(unref(__)("Visual editor")), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (canPreview.value) _push(ssrRenderComponent(unref(KButton), {
				size: "sm",
				variant: "ghost",
				icon: "eye",
				loading: previewing.value,
				onClick: preview
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("Preview"))}`);
					else return [createTextVNode(toDisplayString(unref(__)("Preview")), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (entry.value?.path && status.value === "published") _push(`<a${ssrRenderAttr("href", entry.value.path)} class="k-link" target="_blank" rel="noopener" data-v-3959f855>${ssrInterpolate(unref(__)("View on site"))}</a>`);
			else _push(`<!---->`);
			_push(`</div>`);
			if (unref(types).data.value && !type.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(__)("This content type does not exist, or you cannot edit it."))}`);
					else return [createTextVNode(toDisplayString(unref(__)("This content type does not exist, or you cannot edit it.")), 1)];
				}),
				_: 1
			}, _parent));
			else if (loadError.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(loadError.value.status === 404 ? unref(__)("This entry does not exist, or you cannot see it.") : loadError.value.message)}`);
					else return [createTextVNode(toDisplayString(loadError.value.status === 404 ? unref(__)("This entry does not exist, or you cannot see it.") : loadError.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (type.value && (isNew.value || entry.value)) {
				_push(`<!--[-->`);
				ssrRenderTeleport(_push, (_push) => {
					if (entry.value?.trashed_at) _push(ssrRenderComponent(unref(KAlert), {
						tone: "warning",
						title: unref(__)("In the trash")
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`${ssrInterpolate(unref(__)("Restore it to edit or publish it again."))} <div class="k-alert-actions" data-v-3959f855${_scopeId}>`);
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									icon: "arrow-counter-clockwise",
									disabled: !can.value.delete,
									onClick: restoreEntry
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Restore"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Restore")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div>`);
							} else return [createTextVNode(toDisplayString(unref(__)("Restore it to edit or publish it again.")) + " ", 1), createVNode("div", { class: "k-alert-actions" }, [createVNode(unref(KButton), {
								size: "sm",
								icon: "arrow-counter-clockwise",
								disabled: !can.value.delete,
								onClick: restoreEntry
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Restore")), 1)]),
								_: 1
							}, 8, ["disabled"])])];
						}),
						_: 1
					}, _parent));
					else if (!can.value.edit) _push(ssrRenderComponent(unref(KAlert), { tone: "info" }, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("You can read this {item} but not change it.", { item: type.value.singular.toLowerCase() }))}`);
							else return [createTextVNode(toDisplayString(unref(__)("You can read this {item} but not change it.", { item: type.value.singular.toLowerCase() })), 1)];
						}),
						_: 1
					}, _parent));
					else _push(`<!---->`);
					if (conflict.value) _push(ssrRenderComponent(unref(KAlert), {
						tone: "danger",
						title: unref(__)("Someone else saved this while you were editing")
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`${ssrInterpolate(unref(__)("Load their version (your changes here are lost), or save yours over it."))} <div class="k-alert-actions" data-v-3959f855${_scopeId}>`);
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									onClick: reloadTheirs
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Load their version"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Load their version")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "danger",
									onClick: ($event) => save(null, { force: true })
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Save mine anyway"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Save mine anyway")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div>`);
							} else return [createTextVNode(toDisplayString(unref(__)("Load their version (your changes here are lost), or save yours over it.")) + " ", 1), createVNode("div", { class: "k-alert-actions" }, [createVNode(unref(KButton), {
								size: "sm",
								onClick: reloadTheirs
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Load their version")), 1)]),
								_: 1
							}), createVNode(unref(KButton), {
								size: "sm",
								variant: "danger",
								onClick: ($event) => save(null, { force: true })
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save mine anyway")), 1)]),
								_: 1
							}, 8, ["onClick"])])];
						}),
						_: 1
					}, _parent));
					else _push(`<!---->`);
					if (autosave.value && !readOnly.value) _push(ssrRenderComponent(unref(KAlert), {
						tone: "info",
						title: unref(__)("You have unsaved changes from {when}", { when: unref(formatDateTime)(autosave.value.saved_at) })
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`${ssrInterpolate(unref(__)("They were kept when you left the editor without saving."))} <div class="k-alert-actions" data-v-3959f855${_scopeId}>`);
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "primary",
									onClick: restoreAutosave
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Bring them back"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Bring them back")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "ghost",
									onClick: ($event) => autosave.value = null
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Dismiss"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Dismiss")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div>`);
							} else return [createTextVNode(toDisplayString(unref(__)("They were kept when you left the editor without saving.")) + " ", 1), createVNode("div", { class: "k-alert-actions" }, [createVNode(unref(KButton), {
								size: "sm",
								variant: "primary",
								onClick: restoreAutosave
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Bring them back")), 1)]),
								_: 1
							}), createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								onClick: ($event) => autosave.value = null
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Dismiss")), 1)]),
								_: 1
							}, 8, ["onClick"])])];
						}),
						_: 1
					}, _parent));
					else _push(`<!---->`);
				}, alertsTarget.value ?? "body", !alertsTarget.value, _parent);
				_push(`<div class="k-editor" style="${ssrRenderStyle(!studio.value ? null : { display: "none" })}" data-v-3959f855><div class="k-editor__main" data-v-3959f855><div class="${ssrRenderClass([{ "has-error": err("title") }, "k-editor__title"])}" data-v-3959f855><label class="k-sr-only" for="k-entry-title" data-v-3959f855>${ssrInterpolate(unref(__)("Title"))}</label><textarea id="k-entry-title" rows="1" class="k-editor__title-input" maxlength="255" autocomplete="off" data-lpignore="true" data-1p-ignore${ssrRenderAttr("placeholder", unref(__)("Title"))}${ssrIncludeBooleanAttr(readOnly.value) ? " readonly" : ""}${ssrRenderAttr("aria-invalid", err("title") ? "true" : void 0)} data-v-3959f855>${ssrInterpolate(form.title)}</textarea>`);
				if (err("title")) _push(`<p class="k-field__error" data-v-3959f855>${ssrInterpolate(err("title"))}</p>`);
				else _push(`<!---->`);
				if (prefix.value !== null) {
					_push(`<div class="k-editor__slug" data-v-3959f855><span class="k-muted" data-v-3959f855>${ssrInterpolate(prefix.value)}</span>`);
					if (editingSlug.value) _push(`<!--[--><label class="k-sr-only" for="k-entry-slug" data-v-3959f855>${ssrInterpolate(unref(__)("Slug"))}</label><input id="k-entry-slug"${ssrRenderAttr("value", form.slug)} class="k-input k-editor__slug-input" maxlength="191" spellcheck="false" autocomplete="off" data-lpignore="true" data-1p-ignore data-v-3959f855><!--]-->`);
					else {
						_push(`<!--[--><span class="k-editor__slug-value" data-v-3959f855>${ssrInterpolate(shownSlug.value || "…")}</span>`);
						if (!readOnly.value) _push(`<button type="button" class="k-link-btn k-editor__slug-edit" data-v-3959f855>${ssrInterpolate(unref(__)("Edit"))}</button>`);
						else _push(`<!---->`);
						_push(`<!--]-->`);
					}
					if (!live.value && !editingSlug.value) _push(`<span class="k-muted k-small" data-v-3959f855>· ${ssrInterpolate(unref(__)("follows the title until published"))}</span>`);
					else _push(`<!---->`);
					_push(`</div>`);
				} else _push(`<!---->`);
				if (err("slug")) _push(`<p class="k-field__error" data-v-3959f855>${ssrInterpolate(err("slug"))}</p>`);
				else _push(`<!---->`);
				_push(`</div><div class="k-fields k-editor__fields" data-v-3959f855><!--[-->`);
				ssrRenderList(mainFields.value, (f) => {
					_push(ssrRenderComponent(unref(KContentField), {
						key: f.name,
						modelValue: form.data[f.name],
						"onUpdate:modelValue": ($event) => form.data[f.name] = $event,
						field: f,
						errors: errors.value,
						path: "data",
						disabled: readOnly.value
					}, null, _parent));
				});
				_push(`<!--]--></div>`);
				if (type.value.excerpt) _push(ssrRenderComponent(unref(KField), {
					label: unref(__)("Excerpt"),
					help: unref(__)("A sentence or two for listings and search results. Leave empty to use the start of the text."),
					error: err("excerpt"),
					class: "k-editor__excerpt"
				}, {
					default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
						if (_push) _push(`<textarea${ssrRenderAttr("id", id)} class="k-input k-textarea" rows="3" maxlength="1000"${ssrIncludeBooleanAttr(readOnly.value) ? " readonly" : ""}${ssrRenderAttr("aria-invalid", invalid ? "true" : void 0)}${ssrRenderAttr("aria-describedby", describedBy)} data-v-3959f855${_scopeId}>${ssrInterpolate(form.excerpt)}</textarea>`);
						else return [withDirectives(createVNode("textarea", {
							id,
							"onUpdate:modelValue": ($event) => form.excerpt = $event,
							class: "k-input k-textarea",
							rows: "3",
							maxlength: "1000",
							readonly: readOnly.value,
							"aria-invalid": invalid ? "true" : void 0,
							"aria-describedby": describedBy
						}, null, 8, [
							"id",
							"onUpdate:modelValue",
							"readonly",
							"aria-invalid",
							"aria-describedby"
						]), [[vModelText, form.excerpt]])];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`</div>`);
				if (belowTarget.value) ssrRenderTeleport(_push, (_push) => {
					_push(`<div class="k-fields k-studio__belowfields" data-v-3959f855><!--[-->`);
					ssrRenderList(mainFields.value, (f) => {
						_push(ssrRenderComponent(unref(KContentField), {
							key: `studio-${f.name}`,
							modelValue: form.data[f.name],
							"onUpdate:modelValue": ($event) => form.data[f.name] = $event,
							field: f,
							errors: errors.value,
							path: "data",
							disabled: readOnly.value
						}, null, _parent));
					});
					_push(`<!--]-->`);
					if (type.value.excerpt) _push(ssrRenderComponent(unref(KField), {
						label: unref(__)("Excerpt"),
						help: unref(__)("A sentence or two for listings and search results. Leave empty to use the start of the text."),
						error: err("excerpt")
					}, {
						default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
							if (_push) _push(`<textarea${ssrRenderAttr("id", id)} class="k-input k-textarea" rows="3" maxlength="1000"${ssrIncludeBooleanAttr(readOnly.value) ? " readonly" : ""}${ssrRenderAttr("aria-invalid", invalid ? "true" : void 0)}${ssrRenderAttr("aria-describedby", describedBy)} data-v-3959f855${_scopeId}>${ssrInterpolate(form.excerpt)}</textarea>`);
							else return [withDirectives(createVNode("textarea", {
								id,
								"onUpdate:modelValue": ($event) => form.excerpt = $event,
								class: "k-input k-textarea",
								rows: "3",
								maxlength: "1000",
								readonly: readOnly.value,
								"aria-invalid": invalid ? "true" : void 0,
								"aria-describedby": describedBy
							}, null, 8, [
								"id",
								"onUpdate:modelValue",
								"readonly",
								"aria-invalid",
								"aria-describedby"
							]), [[vModelText, form.excerpt]])];
						}),
						_: 1
					}, _parent));
					else _push(`<!---->`);
					_push(`</div>`);
				}, belowTarget.value, false, _parent);
				else _push(`<!---->`);
				ssrRenderTeleport(_push, (_push) => {
					if (docTarget.value) _push(ssrRenderComponent(_sfc_main$1, {
						"publish-at": publishAt.value,
						"onUpdate:publishAt": ($event) => publishAt.value = $event,
						slug: form.slug,
						"onUpdate:slug": ($event) => form.slug = $event,
						class: "k-studio__when",
						live: live.value,
						future: future.value,
						"published-at": form.published_at,
						zone: unref(zone),
						"can-publish": can.value.publish,
						"read-only": readOnly.value,
						prefix: prefix.value,
						"shown-slug": shownSlug.value,
						"slug-error": err("slug"),
						"created-at": entry.value?.created_at ?? null,
						version: entry.value?.version ?? null
					}, null, _parent));
					else _push(`<!---->`);
					_push(`<aside class="k-editor__side"${ssrRenderAttr("aria-label", unref(__)("Publishing"))} data-v-3959f855>`);
					if (!docTarget.value || studioExtras.value && !readOnly.value) _push(ssrRenderComponent(unref(KCard), { title: live.value ? unref(__)("Published") : status.value === "scheduled" ? unref(__)("Scheduled") : status.value === "pending" ? unref(__)("In review") : unref(__)("Publish") }, createSlots({
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								if (!docTarget.value) {
									_push(`<div class="k-form" data-v-3959f855${_scopeId}>`);
									_push(ssrRenderComponent(unref(KField), {
										label: live.value ? unref(__)("Published on") : unref(__)("Publish on"),
										help: future.value ? unref(__)("Goes live automatically at this time ({zone}).", { zone: unref(zone) }) : unref(__)("Leave empty to publish now. Times are in {zone}.", { zone: unref(zone) }),
										error: err("published_at")
									}, {
										default: withCtx(({ id, describedBy }, _push, _parent, _scopeId) => {
											if (_push) _push(`<input${ssrRenderAttr("id", id)}${ssrRenderAttr("value", publishAt.value)} type="datetime-local" class="k-input"${ssrIncludeBooleanAttr(readOnly.value || !can.value.publish) ? " disabled" : ""}${ssrRenderAttr("aria-describedby", describedBy)} data-v-3959f855${_scopeId}>`);
											else return [withDirectives(createVNode("input", {
												id,
												"onUpdate:modelValue": ($event) => publishAt.value = $event,
												type: "datetime-local",
												class: "k-input",
												disabled: readOnly.value || !can.value.publish,
												"aria-describedby": describedBy
											}, null, 8, [
												"id",
												"onUpdate:modelValue",
												"disabled",
												"aria-describedby"
											]), [[vModelText, publishAt.value]])];
										}),
										_: 1
									}, _parent, _scopeId));
									if (entry.value) {
										_push(`<dl class="k-facts" data-v-3959f855${_scopeId}><div data-v-3959f855${_scopeId}><dt data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Created"))}</dt><dd data-v-3959f855${_scopeId}>`);
										_push(ssrRenderComponent(unref(KTime), { value: entry.value.created_at }, null, _parent, _scopeId));
										_push(`</dd></div><div data-v-3959f855${_scopeId}><dt data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Version"))}</dt><dd class="k-num" data-v-3959f855${_scopeId}>${ssrInterpolate(entry.value.version)}</dd></div></dl>`);
									} else _push(`<!---->`);
									_push(`</div>`);
								} else _push(`<!---->`);
							} else return [!docTarget.value ? (openBlock(), createBlock("div", {
								key: 0,
								class: "k-form"
							}, [createVNode(unref(KField), {
								label: live.value ? unref(__)("Published on") : unref(__)("Publish on"),
								help: future.value ? unref(__)("Goes live automatically at this time ({zone}).", { zone: unref(zone) }) : unref(__)("Leave empty to publish now. Times are in {zone}.", { zone: unref(zone) }),
								error: err("published_at")
							}, {
								default: withCtx(({ id, describedBy }) => [withDirectives(createVNode("input", {
									id,
									"onUpdate:modelValue": ($event) => publishAt.value = $event,
									type: "datetime-local",
									class: "k-input",
									disabled: readOnly.value || !can.value.publish,
									"aria-describedby": describedBy
								}, null, 8, [
									"id",
									"onUpdate:modelValue",
									"disabled",
									"aria-describedby"
								]), [[vModelText, publishAt.value]])]),
								_: 1
							}, 8, [
								"label",
								"help",
								"error"
							]), entry.value ? (openBlock(), createBlock("dl", {
								key: 0,
								class: "k-facts"
							}, [createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("Created")), 1), createVNode("dd", null, [createVNode(unref(KTime), { value: entry.value.created_at }, null, 8, ["value"])])]), createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("Version")), 1), createVNode("dd", { class: "k-num" }, toDisplayString(entry.value.version), 1)])])) : createCommentVNode("v-if", true)])) : createCommentVNode("v-if", true)];
						}),
						_: 2
					}, [!readOnly.value && (!docTarget.value || studioExtras.value) ? {
						name: "footer",
						fn: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<!-- in the visual editor the bar has the main actions: only the others stay here --><div class="k-publish-actions" data-v-3959f855${_scopeId}>`);
								if (live.value || status.value === "scheduled") {
									_push(`<!--[-->`);
									if (!docTarget.value) _push(ssrRenderComponent(unref(KButton), {
										variant: "primary",
										block: "",
										loading: saving.value,
										disabled: !dirty.value,
										onClick: ($event) => save()
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(status.value === "scheduled" ? unref(__)("Update schedule") : unref(__)("Update"))}`);
											else return [createTextVNode(toDisplayString(status.value === "scheduled" ? unref(__)("Update schedule") : unref(__)("Update")), 1)];
										}),
										_: 1
									}, _parent, _scopeId));
									else _push(`<!---->`);
									if (can.value.publish) _push(ssrRenderComponent(unref(KButton), {
										variant: "ghost",
										size: "sm",
										disabled: saving.value,
										onClick: ($event) => save("draft")
									}, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(unref(__)("Unpublish (back to draft)"))}`);
											else return [createTextVNode(toDisplayString(unref(__)("Unpublish (back to draft)")), 1)];
										}),
										_: 1
									}, _parent, _scopeId));
									else _push(`<!---->`);
									_push(`<!--]-->`);
								} else if (status.value === "pending") {
									_push(`<!--[-->`);
									if (can.value.publish) {
										_push(`<!--[-->`);
										if (!docTarget.value) _push(ssrRenderComponent(unref(KButton), {
											variant: "primary",
											block: "",
											loading: saving.value,
											onClick: ($event) => save("published")
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(future.value ? unref(__)("Approve and schedule") : unref(__)("Approve and publish"))}`);
												else return [createTextVNode(toDisplayString(future.value ? unref(__)("Approve and schedule") : unref(__)("Approve and publish")), 1)];
											}),
											_: 1
										}, _parent, _scopeId));
										else _push(`<!---->`);
										_push(ssrRenderComponent(unref(KButton), {
											variant: "secondary",
											block: "",
											disabled: saving.value,
											onClick: ($event) => asking.value = true
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Request changes"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Request changes")), 1)];
											}),
											_: 1
										}, _parent, _scopeId));
										if (!docTarget.value) _push(ssrRenderComponent(unref(KButton), {
											variant: "ghost",
											size: "sm",
											disabled: saving.value || !dirty.value,
											onClick: ($event) => save()
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Save without deciding"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Save without deciding")), 1)];
											}),
											_: 1
										}, _parent, _scopeId));
										else _push(`<!---->`);
										_push(`<!--]-->`);
									} else {
										_push(`<!--[-->`);
										if (!docTarget.value) _push(ssrRenderComponent(unref(KButton), {
											variant: "primary",
											block: "",
											loading: saving.value,
											disabled: !dirty.value,
											onClick: ($event) => save()
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Save changes"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Save changes")), 1)];
											}),
											_: 1
										}, _parent, _scopeId));
										else _push(`<!---->`);
										_push(ssrRenderComponent(unref(KButton), {
											variant: "ghost",
											size: "sm",
											disabled: saving.value,
											onClick: ($event) => save("draft")
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Withdraw (back to draft)"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Withdraw (back to draft)")), 1)];
											}),
											_: 1
										}, _parent, _scopeId));
										_push(`<p class="k-muted k-small" data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Waiting for review. An editor publishes it or asks for changes, and you are told either way."))}</p><!--]-->`);
									}
									_push(`<!--]-->`);
								} else {
									_push(`<!--[-->`);
									if (!docTarget.value) {
										_push(`<!--[-->`);
										if (can.value.publish) _push(ssrRenderComponent(unref(KButton), {
											variant: "primary",
											block: "",
											loading: saving.value,
											onClick: ($event) => save("published")
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(future.value ? unref(__)("Schedule") : unref(__)("Publish"))}`);
												else return [createTextVNode(toDisplayString(future.value ? unref(__)("Schedule") : unref(__)("Publish")), 1)];
											}),
											_: 1
										}, _parent, _scopeId));
										else _push(ssrRenderComponent(unref(KButton), {
											variant: "primary",
											block: "",
											loading: saving.value,
											onClick: ($event) => save("pending")
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Submit for review"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Submit for review")), 1)];
											}),
											_: 1
										}, _parent, _scopeId));
										_push(ssrRenderComponent(unref(KButton), {
											variant: "secondary",
											block: "",
											disabled: saving.value || !dirty.value && !isNew.value,
											onClick: ($event) => save("draft")
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Save draft"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Save draft")), 1)];
											}),
											_: 1
										}, _parent, _scopeId));
										_push(`<!--]-->`);
									} else _push(`<!---->`);
									if (!can.value.publish) _push(`<p class="k-muted k-small" data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Submit it when it is ready. An editor reviews it and publishes it."))}</p>`);
									else _push(`<!---->`);
									_push(`<!--]-->`);
								}
								_push(`</div>`);
							} else return [createCommentVNode(" in the visual editor the bar has the main actions: only the others stay here "), createVNode("div", { class: "k-publish-actions" }, [live.value || status.value === "scheduled" ? (openBlock(), createBlock(Fragment, { key: 0 }, [!docTarget.value ? (openBlock(), createBlock(unref(KButton), {
								key: 0,
								variant: "primary",
								block: "",
								loading: saving.value,
								disabled: !dirty.value,
								onClick: ($event) => save()
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(status.value === "scheduled" ? unref(__)("Update schedule") : unref(__)("Update")), 1)]),
								_: 1
							}, 8, [
								"loading",
								"disabled",
								"onClick"
							])) : createCommentVNode("v-if", true), can.value.publish ? (openBlock(), createBlock(unref(KButton), {
								key: 1,
								variant: "ghost",
								size: "sm",
								disabled: saving.value,
								onClick: ($event) => save("draft")
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Unpublish (back to draft)")), 1)]),
								_: 1
							}, 8, ["disabled", "onClick"])) : createCommentVNode("v-if", true)], 64)) : status.value === "pending" ? (openBlock(), createBlock(Fragment, { key: 1 }, [can.value.publish ? (openBlock(), createBlock(Fragment, { key: 0 }, [
								!docTarget.value ? (openBlock(), createBlock(unref(KButton), {
									key: 0,
									variant: "primary",
									block: "",
									loading: saving.value,
									onClick: ($event) => save("published")
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(future.value ? unref(__)("Approve and schedule") : unref(__)("Approve and publish")), 1)]),
									_: 1
								}, 8, ["loading", "onClick"])) : createCommentVNode("v-if", true),
								createVNode(unref(KButton), {
									variant: "secondary",
									block: "",
									disabled: saving.value,
									onClick: ($event) => asking.value = true
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Request changes")), 1)]),
									_: 1
								}, 8, ["disabled", "onClick"]),
								!docTarget.value ? (openBlock(), createBlock(unref(KButton), {
									key: 1,
									variant: "ghost",
									size: "sm",
									disabled: saving.value || !dirty.value,
									onClick: ($event) => save()
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save without deciding")), 1)]),
									_: 1
								}, 8, ["disabled", "onClick"])) : createCommentVNode("v-if", true)
							], 64)) : (openBlock(), createBlock(Fragment, { key: 1 }, [
								!docTarget.value ? (openBlock(), createBlock(unref(KButton), {
									key: 0,
									variant: "primary",
									block: "",
									loading: saving.value,
									disabled: !dirty.value,
									onClick: ($event) => save()
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save changes")), 1)]),
									_: 1
								}, 8, [
									"loading",
									"disabled",
									"onClick"
								])) : createCommentVNode("v-if", true),
								createVNode(unref(KButton), {
									variant: "ghost",
									size: "sm",
									disabled: saving.value,
									onClick: ($event) => save("draft")
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Withdraw (back to draft)")), 1)]),
									_: 1
								}, 8, ["disabled", "onClick"]),
								createVNode("p", { class: "k-muted k-small" }, toDisplayString(unref(__)("Waiting for review. An editor publishes it or asks for changes, and you are told either way.")), 1)
							], 64))], 64)) : (openBlock(), createBlock(Fragment, { key: 2 }, [!docTarget.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [can.value.publish ? (openBlock(), createBlock(unref(KButton), {
								key: 0,
								variant: "primary",
								block: "",
								loading: saving.value,
								onClick: ($event) => save("published")
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(future.value ? unref(__)("Schedule") : unref(__)("Publish")), 1)]),
								_: 1
							}, 8, ["loading", "onClick"])) : (openBlock(), createBlock(unref(KButton), {
								key: 1,
								variant: "primary",
								block: "",
								loading: saving.value,
								onClick: ($event) => save("pending")
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Submit for review")), 1)]),
								_: 1
							}, 8, ["loading", "onClick"])), createVNode(unref(KButton), {
								variant: "secondary",
								block: "",
								disabled: saving.value || !dirty.value && !isNew.value,
								onClick: ($event) => save("draft")
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Save draft")), 1)]),
								_: 1
							}, 8, ["disabled", "onClick"])], 64)) : createCommentVNode("v-if", true), !can.value.publish ? (openBlock(), createBlock("p", {
								key: 1,
								class: "k-muted k-small"
							}, toDisplayString(unref(__)("Submit it when it is ready. An editor reviews it and publishes it.")), 1)) : createCommentVNode("v-if", true)], 64))])];
						}),
						key: "0"
					} : void 0]), _parent));
					else _push(`<!---->`);
					if (type.value.hierarchical || canReassign.value || entry.value || templateOptions.value.length > 1) _push(ssrRenderComponent(unref(KCard), { title: unref(__)("Details") }, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<div class="k-form" data-v-3959f855${_scopeId}>`);
								if (templateOptions.value.length > 1) _push(ssrRenderComponent(unref(KField), {
									class: "k-studio__template",
									label: unref(__)("Template"),
									help: unref(__)("How this page is laid out. Templates come from Appearance → Templates."),
									error: err("template")
								}, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KSelect), {
											id,
											modelValue: form.template,
											"onUpdate:modelValue": ($event) => form.template = $event,
											options: templateOptions.value,
											disabled: readOnly.value
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KSelect), {
											id,
											modelValue: form.template,
											"onUpdate:modelValue": ($event) => form.template = $event,
											options: templateOptions.value,
											disabled: readOnly.value
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue",
											"options",
											"disabled"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								else _push(`<!---->`);
								if (type.value.hierarchical) _push(ssrRenderComponent(unref(KField), {
									label: unref(__)("Parent"),
									help: unref(__)("Nests this page under another one, and its address under theirs. Empty: a top-level page."),
									error: err("parent_id")
								}, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KPicker), {
											id,
											modelValue: form.parent_id,
											"onUpdate:modelValue": ($event) => form.parent_id = $event,
											search: searchParents,
											resolve: resolveParents,
											disabled: readOnly.value
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KPicker), {
											id,
											modelValue: form.parent_id,
											"onUpdate:modelValue": ($event) => form.parent_id = $event,
											search: searchParents,
											resolve: resolveParents,
											disabled: readOnly.value
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue",
											"disabled"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								else _push(`<!---->`);
								if (type.value.hierarchical) _push(ssrRenderComponent(unref(KField), {
									label: unref(__)("Page order"),
									help: unref(__)("Where this page comes when pages are listed in order: lower numbers first."),
									error: err("menu_order")
								}, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KInput), {
											id,
											modelValue: form.menu_order,
											"onUpdate:modelValue": ($event) => form.menu_order = $event,
											modelModifiers: { number: true },
											type: "number",
											disabled: readOnly.value
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KInput), {
											id,
											modelValue: form.menu_order,
											"onUpdate:modelValue": ($event) => form.menu_order = $event,
											modelModifiers: { number: true },
											type: "number",
											disabled: readOnly.value
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue",
											"disabled"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								else _push(`<!---->`);
								if (canReassign.value) _push(ssrRenderComponent(unref(KField), {
									label: unref(__)("Author"),
									error: err("author_id")
								}, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(ssrRenderComponent(unref(KPicker), {
											id,
											modelValue: form.author_id,
											"onUpdate:modelValue": ($event) => form.author_id = $event,
											search: searchUsers,
											resolve: resolveUsers,
											disabled: readOnly.value
										}, null, _parent, _scopeId));
										else return [createVNode(unref(KPicker), {
											id,
											modelValue: form.author_id,
											"onUpdate:modelValue": ($event) => form.author_id = $event,
											search: searchUsers,
											resolve: resolveUsers,
											disabled: readOnly.value
										}, null, 8, [
											"id",
											"modelValue",
											"onUpdate:modelValue",
											"disabled"
										])];
									}),
									_: 1
								}, _parent, _scopeId));
								else if (entry.value) _push(`<dl class="k-facts" data-v-3959f855${_scopeId}><div data-v-3959f855${_scopeId}><dt data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Author"))}</dt><dd data-v-3959f855${_scopeId}>${ssrInterpolate(entry.value.author?.username ?? unref(__)("Nobody"))}</dd></div></dl>`);
								else _push(`<!---->`);
								_push(`</div>`);
							} else return [createVNode("div", { class: "k-form" }, [
								templateOptions.value.length > 1 ? (openBlock(), createBlock(unref(KField), {
									key: 0,
									class: "k-studio__template",
									label: unref(__)("Template"),
									help: unref(__)("How this page is laid out. Templates come from Appearance → Templates."),
									error: err("template")
								}, {
									default: withCtx(({ id }) => [createVNode(unref(KSelect), {
										id,
										modelValue: form.template,
										"onUpdate:modelValue": ($event) => form.template = $event,
										options: templateOptions.value,
										disabled: readOnly.value
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"options",
										"disabled"
									])]),
									_: 1
								}, 8, [
									"label",
									"help",
									"error"
								])) : createCommentVNode("v-if", true),
								type.value.hierarchical ? (openBlock(), createBlock(unref(KField), {
									key: 1,
									label: unref(__)("Parent"),
									help: unref(__)("Nests this page under another one, and its address under theirs. Empty: a top-level page."),
									error: err("parent_id")
								}, {
									default: withCtx(({ id }) => [createVNode(unref(KPicker), {
										id,
										modelValue: form.parent_id,
										"onUpdate:modelValue": ($event) => form.parent_id = $event,
										search: searchParents,
										resolve: resolveParents,
										disabled: readOnly.value
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"disabled"
									])]),
									_: 1
								}, 8, [
									"label",
									"help",
									"error"
								])) : createCommentVNode("v-if", true),
								type.value.hierarchical ? (openBlock(), createBlock(unref(KField), {
									key: 2,
									label: unref(__)("Page order"),
									help: unref(__)("Where this page comes when pages are listed in order: lower numbers first."),
									error: err("menu_order")
								}, {
									default: withCtx(({ id }) => [createVNode(unref(KInput), {
										id,
										modelValue: form.menu_order,
										"onUpdate:modelValue": ($event) => form.menu_order = $event,
										modelModifiers: { number: true },
										type: "number",
										disabled: readOnly.value
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"disabled"
									])]),
									_: 1
								}, 8, [
									"label",
									"help",
									"error"
								])) : createCommentVNode("v-if", true),
								canReassign.value ? (openBlock(), createBlock(unref(KField), {
									key: 3,
									label: unref(__)("Author"),
									error: err("author_id")
								}, {
									default: withCtx(({ id }) => [createVNode(unref(KPicker), {
										id,
										modelValue: form.author_id,
										"onUpdate:modelValue": ($event) => form.author_id = $event,
										search: searchUsers,
										resolve: resolveUsers,
										disabled: readOnly.value
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"disabled"
									])]),
									_: 1
								}, 8, ["label", "error"])) : entry.value ? (openBlock(), createBlock("dl", {
									key: 4,
									class: "k-facts"
								}, [createVNode("div", null, [createVNode("dt", null, toDisplayString(unref(__)("Author")), 1), createVNode("dd", null, toDisplayString(entry.value.author?.username ?? unref(__)("Nobody")), 1)])])) : createCommentVNode("v-if", true)
							])];
						}),
						_: 1
					}, _parent));
					else _push(`<!---->`);
					_push(`<!--[-->`);
					ssrRenderList(sideFields.value, (f) => {
						_push(ssrRenderComponent(unref(KCard), {
							key: `side-${f.name}`,
							class: "k-editor__side-field"
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KContentField), {
									modelValue: form.data[f.name],
									"onUpdate:modelValue": ($event) => form.data[f.name] = $event,
									field: f,
									errors: errors.value,
									path: "data",
									disabled: readOnly.value
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KContentField), {
									modelValue: form.data[f.name],
									"onUpdate:modelValue": ($event) => form.data[f.name] = $event,
									field: f,
									errors: errors.value,
									path: "data",
									disabled: readOnly.value
								}, null, 8, [
									"modelValue",
									"onUpdate:modelValue",
									"field",
									"errors",
									"disabled"
								])];
							}),
							_: 2
						}, _parent));
					});
					_push(`<!--]--><!--[-->`);
					ssrRenderList(taxonomies.value, (tax) => {
						_push(ssrRenderComponent(unref(KCard), {
							key: tax.name,
							title: tax.plural
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) {
									_push(ssrRenderComponent(unref(KTermSelect), {
										modelValue: form.terms[tax.name],
										"onUpdate:modelValue": ($event) => form.terms[tax.name] = $event,
										taxonomy: tax,
										disabled: readOnly.value
									}, null, _parent, _scopeId));
									if (err(`terms.${tax.name}`)) _push(`<p class="k-field__error" data-v-3959f855${_scopeId}>${ssrInterpolate(err(`terms.${tax.name}`))}</p>`);
									else _push(`<!---->`);
								} else return [createVNode(unref(KTermSelect), {
									modelValue: form.terms[tax.name],
									"onUpdate:modelValue": ($event) => form.terms[tax.name] = $event,
									taxonomy: tax,
									disabled: readOnly.value
								}, null, 8, [
									"modelValue",
									"onUpdate:modelValue",
									"taxonomy",
									"disabled"
								]), err(`terms.${tax.name}`) ? (openBlock(), createBlock("p", {
									key: 0,
									class: "k-field__error"
								}, toDisplayString(err(`terms.${tax.name}`)), 1)) : createCommentVNode("v-if", true)];
							}),
							_: 2
						}, _parent));
					});
					_push(`<!--]-->`);
					if (entry.value && !entry.value.trashed_at) _push(ssrRenderComponent(ReviewPanel_default, {
						ref_key: "reviewPanel",
						ref: reviewPanel,
						base: `${base}/${entry.value.id}`,
						"type-base": `/content/${typeName.value}`,
						entry: entry.value,
						"can-edit": can.value.edit,
						"can-moderate": !!type.value.can?.edit_others,
						onAssigned
					}, null, _parent));
					else _push(`<!---->`);
					if (canPreview.value) _push(ssrRenderComponent(SharePreviews_default, {
						base: `${base}/${entry.value.id}`,
						singular: type.value.singular
					}, null, _parent));
					else _push(`<!---->`);
					if (entry.value) _push(ssrRenderComponent(_sfc_main$4, {
						key: entry.value.id,
						base: `${base}/${entry.value.id}`
					}, null, _parent));
					else _push(`<!---->`);
					if (entry.value && type.value.revisions) _push(ssrRenderComponent(unref(KCard), {
						title: unref(__)("Revisions"),
						flush: ""
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								if (unref(revisions).data.value?.length) {
									_push(`<ol class="k-revisions" data-v-3959f855${_scopeId}><!--[-->`);
									ssrRenderList(unref(revisions).data.value, (rev) => {
										_push(`<li class="k-revisions__item" data-v-3959f855${_scopeId}><div class="k-revisions__head" data-v-3959f855${_scopeId}>`);
										_push(ssrRenderComponent(unref(KTime), { value: rev.created_at }, null, _parent, _scopeId));
										_push(`<span class="k-muted" data-v-3959f855${_scopeId}>${ssrInterpolate(rev.author?.username ?? unref(__)("someone"))}</span></div><p class="k-revisions__what k-small" data-v-3959f855${_scopeId}>`);
										if (rev.current) _push(`<!--[-->${ssrInterpolate(unref(__)("Current version"))}<!--]-->`);
										else if (rev.changed === null) _push(`<!--[-->${ssrInterpolate(unref(__)("First saved"))}<!--]-->`);
										else if (rev.changed.length) _push(`<!--[-->${ssrInterpolate(unref(__)("Changed {list}", { list: rev.changed.map(fieldLabel).join(", ") }))}<!--]-->`);
										else _push(`<!--[-->${ssrInterpolate(unref(__)("No content changes"))}<!--]-->`);
										_push(`</p><div class="k-revisions__actions" data-v-3959f855${_scopeId}>`);
										_push(ssrRenderComponent(unref(KButton), {
											size: "sm",
											variant: "ghost",
											icon: "git-diff",
											onClick: ($event) => compare(rev)
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Compare"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Compare")), 1)];
											}),
											_: 2
										}, _parent, _scopeId));
										if (!rev.current && !readOnly.value) _push(ssrRenderComponent(unref(KButton), {
											size: "sm",
											variant: "ghost",
											icon: "arrow-counter-clockwise",
											onClick: ($event) => restoreRevision(rev)
										}, {
											default: withCtx((_, _push, _parent, _scopeId) => {
												if (_push) _push(`${ssrInterpolate(unref(__)("Restore"))}`);
												else return [createTextVNode(toDisplayString(unref(__)("Restore")), 1)];
											}),
											_: 2
										}, _parent, _scopeId));
										else _push(`<!---->`);
										_push(`</div></li>`);
									});
									_push(`<!--]--></ol>`);
								} else _push(`<p class="k-card__body k-muted k-small" data-v-3959f855${_scopeId}>${ssrInterpolate(unref(revisions).loading.value ? unref(__)("Loading…") : unref(__)("Revisions appear after the first save."))}</p>`);
							} else return [unref(revisions).data.value?.length ? (openBlock(), createBlock("ol", {
								key: 0,
								class: "k-revisions"
							}, [(openBlock(true), createBlock(Fragment, null, renderList(unref(revisions).data.value, (rev) => {
								return openBlock(), createBlock("li", {
									key: rev.id,
									class: "k-revisions__item"
								}, [
									createVNode("div", { class: "k-revisions__head" }, [createVNode(unref(KTime), { value: rev.created_at }, null, 8, ["value"]), createVNode("span", { class: "k-muted" }, toDisplayString(rev.author?.username ?? unref(__)("someone")), 1)]),
									createVNode("p", { class: "k-revisions__what k-small" }, [rev.current ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(toDisplayString(unref(__)("Current version")), 1)], 64)) : rev.changed === null ? (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(toDisplayString(unref(__)("First saved")), 1)], 64)) : rev.changed.length ? (openBlock(), createBlock(Fragment, { key: 2 }, [createTextVNode(toDisplayString(unref(__)("Changed {list}", { list: rev.changed.map(fieldLabel).join(", ") })), 1)], 64)) : (openBlock(), createBlock(Fragment, { key: 3 }, [createTextVNode(toDisplayString(unref(__)("No content changes")), 1)], 64))]),
									createVNode("div", { class: "k-revisions__actions" }, [createVNode(unref(KButton), {
										size: "sm",
										variant: "ghost",
										icon: "git-diff",
										onClick: ($event) => compare(rev)
									}, {
										default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Compare")), 1)]),
										_: 1
									}, 8, ["onClick"]), !rev.current && !readOnly.value ? (openBlock(), createBlock(unref(KButton), {
										key: 0,
										size: "sm",
										variant: "ghost",
										icon: "arrow-counter-clockwise",
										onClick: ($event) => restoreRevision(rev)
									}, {
										default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Restore")), 1)]),
										_: 1
									}, 8, ["onClick"])) : createCommentVNode("v-if", true)])
								]);
							}), 128))])) : (openBlock(), createBlock("p", {
								key: 1,
								class: "k-card__body k-muted k-small"
							}, toDisplayString(unref(revisions).loading.value ? unref(__)("Loading…") : unref(__)("Revisions appear after the first save.")), 1))];
						}),
						_: 1
					}, _parent));
					else _push(`<!---->`);
					_push(ssrRenderComponent(unref(KModal), {
						open: asking.value,
						title: unref(__)("Request changes"),
						onClose: ($event) => asking.value = false
					}, {
						footer: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(ssrRenderComponent(unref(KButton), {
									variant: "ghost",
									onClick: ($event) => asking.value = false
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KButton), {
									variant: "primary",
									loading: sending.value,
									disabled: !note.value.trim(),
									onClick: requestChanges
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Send back"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Send back")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
							} else return [createVNode(unref(KButton), {
								variant: "ghost",
								onClick: ($event) => asking.value = false
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
								_: 1
							}, 8, ["onClick"]), createVNode(unref(KButton), {
								variant: "primary",
								loading: sending.value,
								disabled: !note.value.trim(),
								onClick: requestChanges
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Send back")), 1)]),
								_: 1
							}, 8, ["loading", "disabled"])];
						}),
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KField), {
								label: unref(__)("What should change?"),
								help: unref(__)("Sent to whoever submitted it, and kept with the comments. The {item} goes back to draft.", { item: type.value.singular.toLowerCase() })
							}, {
								default: withCtx(({ id, describedBy }, _push, _parent, _scopeId) => {
									if (_push) _push(`<textarea${ssrRenderAttr("id", id)} class="k-input k-textarea" rows="5" maxlength="5000"${ssrRenderAttr("aria-describedby", describedBy)} data-v-3959f855${_scopeId}>${ssrInterpolate(note.value)}</textarea>`);
									else return [withDirectives(createVNode("textarea", {
										id,
										"onUpdate:modelValue": ($event) => note.value = $event,
										class: "k-input k-textarea",
										rows: "5",
										maxlength: "5000",
										"aria-describedby": describedBy
									}, null, 8, [
										"id",
										"onUpdate:modelValue",
										"aria-describedby"
									]), [[vModelText, note.value]])];
								}),
								_: 1
							}, _parent, _scopeId));
							else return [createVNode(unref(KField), {
								label: unref(__)("What should change?"),
								help: unref(__)("Sent to whoever submitted it, and kept with the comments. The {item} goes back to draft.", { item: type.value.singular.toLowerCase() })
							}, {
								default: withCtx(({ id, describedBy }) => [withDirectives(createVNode("textarea", {
									id,
									"onUpdate:modelValue": ($event) => note.value = $event,
									class: "k-input k-textarea",
									rows: "5",
									maxlength: "5000",
									"aria-describedby": describedBy
								}, null, 8, [
									"id",
									"onUpdate:modelValue",
									"aria-describedby"
								]), [[vModelText, note.value]])]),
								_: 1
							}, 8, ["label", "help"])];
						}),
						_: 1
					}, _parent));
					_push(ssrRenderComponent(unref(KModal), {
						open: !!comparing.value,
						title: comparing.value ? unref(__)("Changes saved {when}", { when: unref(formatDateTime)(comparing.value.created_at) }) : "",
						size: "lg",
						onClose: ($event) => comparing.value = null
					}, createSlots({
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								if (comparing.value) {
									_push(`<div class="k-compare" data-v-3959f855${_scopeId}>`);
									if (!comparing.value.current) _push(ssrRenderComponent(unref(KField), { label: unref(__)("Compare with") }, {
										default: withCtx(({ id }, _push, _parent, _scopeId) => {
											if (_push) _push(ssrRenderComponent(unref(KSelect), {
												id,
												modelValue: compareWith.value,
												"onUpdate:modelValue": ($event) => compareWith.value = $event,
												options: compareOptions.value
											}, null, _parent, _scopeId));
											else return [createVNode(unref(KSelect), {
												id,
												modelValue: compareWith.value,
												"onUpdate:modelValue": ($event) => compareWith.value = $event,
												options: compareOptions.value
											}, null, 8, [
												"id",
												"modelValue",
												"onUpdate:modelValue",
												"options"
											])];
										}),
										_: 1
									}, _parent, _scopeId));
									else _push(`<!---->`);
									_push(`<p class="k-muted k-small" data-v-3959f855${_scopeId}>`);
									if (compareWith.value === "current" && !comparing.value.current) _push(`<!--[-->${ssrInterpolate(unref(__)("What changed between this version, saved by {who}, and the current version.", { who: comparing.value.author?.username ?? unref(__)("someone") }))}<!--]-->`);
									else if (diff.value && !diff.value.against) _push(`<!--[-->${ssrInterpolate(unref(__)("The first saved version, saved by {who}.", { who: comparing.value.author?.username ?? unref(__)("someone") }))}<!--]-->`);
									else _push(`<!--[-->${ssrInterpolate(unref(__)("What {who} changed in this save.", { who: comparing.value.author?.username ?? unref(__)("someone") }))}<!--]-->`);
									_push(`</p>`);
									if (diffError.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
										default: withCtx((_, _push, _parent, _scopeId) => {
											if (_push) _push(`${ssrInterpolate(diffError.value.message)}`);
											else return [createTextVNode(toDisplayString(diffError.value.message), 1)];
										}),
										_: 1
									}, _parent, _scopeId));
									else if (diff.value) _push(ssrRenderComponent(RevisionDiff_default, { changes: diff.value.changes }, null, _parent, _scopeId));
									else _push(`<p class="k-muted" data-v-3959f855${_scopeId}>${ssrInterpolate(unref(__)("Comparing…"))}</p>`);
									_push(`</div>`);
								} else _push(`<!---->`);
							} else return [comparing.value ? (openBlock(), createBlock("div", {
								key: 0,
								class: "k-compare"
							}, [
								!comparing.value.current ? (openBlock(), createBlock(unref(KField), {
									key: 0,
									label: unref(__)("Compare with")
								}, {
									default: withCtx(({ id }) => [createVNode(unref(KSelect), {
										id,
										modelValue: compareWith.value,
										"onUpdate:modelValue": ($event) => compareWith.value = $event,
										options: compareOptions.value
									}, null, 8, [
										"id",
										"modelValue",
										"onUpdate:modelValue",
										"options"
									])]),
									_: 1
								}, 8, ["label"])) : createCommentVNode("v-if", true),
								createVNode("p", { class: "k-muted k-small" }, [compareWith.value === "current" && !comparing.value.current ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(toDisplayString(unref(__)("What changed between this version, saved by {who}, and the current version.", { who: comparing.value.author?.username ?? unref(__)("someone") })), 1)], 64)) : diff.value && !diff.value.against ? (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(toDisplayString(unref(__)("The first saved version, saved by {who}.", { who: comparing.value.author?.username ?? unref(__)("someone") })), 1)], 64)) : (openBlock(), createBlock(Fragment, { key: 2 }, [createTextVNode(toDisplayString(unref(__)("What {who} changed in this save.", { who: comparing.value.author?.username ?? unref(__)("someone") })), 1)], 64))]),
								diffError.value ? (openBlock(), createBlock(unref(KAlert), {
									key: 1,
									tone: "danger"
								}, {
									default: withCtx(() => [createTextVNode(toDisplayString(diffError.value.message), 1)]),
									_: 1
								})) : diff.value ? (openBlock(), createBlock(RevisionDiff_default, {
									key: 2,
									changes: diff.value.changes
								}, null, 8, ["changes"])) : (openBlock(), createBlock("p", {
									key: 3,
									class: "k-muted"
								}, toDisplayString(unref(__)("Comparing…")), 1))
							])) : createCommentVNode("v-if", true)];
						}),
						_: 2
					}, [comparing.value && !comparing.value.current && !readOnly.value ? {
						name: "footer",
						fn: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(ssrRenderComponent(unref(KButton), {
									variant: "ghost",
									onClick: ($event) => comparing.value = null
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Close"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Close")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KButton), {
									icon: "arrow-counter-clockwise",
									onClick: restoreCompared
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Restore this version"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Restore this version")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
							} else return [createVNode(unref(KButton), {
								variant: "ghost",
								onClick: ($event) => comparing.value = null
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Close")), 1)]),
								_: 1
							}, 8, ["onClick"]), createVNode(unref(KButton), {
								icon: "arrow-counter-clockwise",
								onClick: restoreCompared
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Restore this version")), 1)]),
								_: 1
							})];
						}),
						key: "0"
					} : void 0]), _parent));
					if (entry.value && !entry.value.trashed_at && can.value.delete) {
						_push(`<div class="k-editor__trash" data-v-3959f855>`);
						_push(ssrRenderComponent(unref(KButton), {
							variant: "ghost",
							size: "sm",
							icon: "trash",
							onClick: moveToTrash
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Move to trash"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Move to trash")), 1)];
							}),
							_: 1
						}, _parent));
						_push(`</div>`);
					} else _push(`<!---->`);
					_push(`</aside>`);
				}, docTarget.value ?? "body", !docTarget.value, _parent);
				_push(`</div><!--]-->`);
			} else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/content/Edit.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Edit_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-3959f855"]]);
export { Edit_default as default };
