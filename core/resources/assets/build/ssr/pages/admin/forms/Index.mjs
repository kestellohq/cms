import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, reactive, ref, toDisplayString, unref, useSSRContext, vModelRadio, withCtx, withDirectives, withModifiers } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrLooseEqual, ssrRenderComponent } from "vue/server-renderer";
import { Head, Link, useRouter } from "@kestello/app";
import { ApiError, __, notify, useApi, useResource } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KField, KInput, KModal, KPageHeader, KTable, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/forms/Index.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Index",
	__ssrInlineRender: true,
	setup(__props) {
		/** Content → Forms: the site's forms and what came in. */
		const api = useApi();
		const router = useRouter();
		const list = useResource("/forms");
		const forms = computed(() => list.data.value ?? []);
		const columns = [
			{
				key: "title",
				label: __("Form")
			},
			{
				key: "status",
				label: __("State")
			},
			{
				key: "submissions",
				label: __("Received"),
				align: "end"
			},
			{
				key: "updated_at",
				label: __("Changed")
			},
			{
				key: "actions",
				label: "",
				align: "end"
			}
		];
		const STARTERS = {
			contact: () => [
				{
					name: "name",
					type: "text",
					label: __("Your name"),
					required: true,
					width: "half",
					options: { max: 100 }
				},
				{
					name: "email",
					type: "email",
					label: __("Email"),
					required: true,
					width: "half",
					options: {}
				},
				{
					name: "message",
					type: "textarea",
					label: __("Message"),
					required: true,
					options: {
						rows: 6,
						max: 5e3
					}
				}
			],
			blank: () => [{
				name: "name",
				type: "text",
				label: __("Your name"),
				required: true,
				options: {}
			}]
		};
		const creating = ref(false);
		const form = reactive({
			title: "",
			starter: "contact"
		});
		const errors = ref({});
		const saving = ref(false);
		async function create() {
			saving.value = true;
			errors.value = {};
			const definition = {
				fields: STARTERS[form.starter](),
				notifications: []
			};
			try {
				const { data } = await api.post("/forms", {
					title: form.title.trim(),
					definition
				});
				creating.value = false;
				router.visit(`/admin/forms/${data.id}`);
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) errors.value = e.fieldErrors();
				else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		const fieldsText = (n) => __("{n, plural, one {# field} other {# fields}}", { n });
		const newText = (n) => __("{n, plural, one {# new} other {# new}}", { n });
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("Forms") }, null, _parent));
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("Forms"),
				description: unref(__)("Contact forms, sign-ups and applications. Put one on any page with the Form block; what people send arrives here and, if you like, by email.")
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KButton), {
						variant: "primary",
						icon: "plus",
						onClick: ($event) => {
							form.title = "";
							form.starter = "contact";
							errors.value = {};
							creating.value = true;
						}
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("New form"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("New form")), 1)];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KButton), {
						variant: "primary",
						icon: "plus",
						onClick: ($event) => {
							form.title = "";
							form.starter = "contact";
							errors.value = {};
							creating.value = true;
						}
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("New form")), 1)]),
						_: 1
					}, 8, ["onClick"])];
				}),
				_: 1
			}, _parent));
			if (unref(list).error.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(unref(list).error.value.message)}`);
					else return [createTextVNode(toDisplayString(unref(list).error.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			_push(ssrRenderComponent(unref(KCard), {
				coord: "F1",
				title: unref(__)("Forms"),
				flush: ""
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(ssrRenderComponent(unref(KTable), {
						columns,
						rows: forms.value,
						loading: unref(list).loading.value && !unref(list).data.value,
						empty: unref(__)("No forms yet"),
						"empty-text": unref(__)("Start with a contact form; you can change every field.")
					}, {
						"cell-title": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(ssrRenderComponent(unref(Link), {
									href: `/admin/forms/${row.id}`,
									class: "k-link k-forms__title"
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(row.title)}`);
										else return [createTextVNode(toDisplayString(row.title), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								_push(`<div class="k-muted k-small" data-v-5fa12738${_scopeId}>${ssrInterpolate(fieldsText(row.definition.fields.length))}`);
								if (row.definition.notifications.length) _push(`<!--[--> · ${ssrInterpolate(unref(__)("emails {to}", { to: row.definition.notifications.flatMap((n) => n.to).slice(0, 2).join(", ") }))}<!--]-->`);
								else _push(`<!---->`);
								_push(`</div>`);
							} else return [createVNode(unref(Link), {
								href: `/admin/forms/${row.id}`,
								class: "k-link k-forms__title"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.title), 1)]),
								_: 2
							}, 1032, ["href"]), createVNode("div", { class: "k-muted k-small" }, [createTextVNode(toDisplayString(fieldsText(row.definition.fields.length)), 1), row.definition.notifications.length ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(unref(__)("emails {to}", { to: row.definition.notifications.flatMap((n) => n.to).slice(0, 2).join(", ") })), 1)], 64)) : createCommentVNode("v-if", true)])];
						}),
						"cell-status": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KBadge), { tone: row.status === "open" ? "success" : void 0 }, {
								default: withCtx((_, _push, _parent, _scopeId) => {
									if (_push) _push(`${ssrInterpolate(row.status === "open" ? unref(__)("Open") : unref(__)("Closed"))}`);
									else return [createTextVNode(toDisplayString(row.status === "open" ? unref(__)("Open") : unref(__)("Closed")), 1)];
								}),
								_: 2
							}, _parent, _scopeId));
							else return [createVNode(unref(KBadge), { tone: row.status === "open" ? "success" : void 0 }, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.status === "open" ? unref(__)("Open") : unref(__)("Closed")), 1)]),
								_: 2
							}, 1032, ["tone"])];
						}),
						"cell-submissions": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(ssrRenderComponent(unref(Link), {
									href: `/admin/forms/${row.id}/submissions`,
									class: "k-link k-num"
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(row.submissions.total.toLocaleString())}`);
										else return [createTextVNode(toDisplayString(row.submissions.total.toLocaleString()), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								if (row.submissions.new) _push(ssrRenderComponent(unref(KBadge), {
									tone: "info",
									class: "k-forms__new"
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(newText(row.submissions.new))}`);
										else return [createTextVNode(toDisplayString(newText(row.submissions.new)), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								else _push(`<!---->`);
							} else return [createVNode(unref(Link), {
								href: `/admin/forms/${row.id}/submissions`,
								class: "k-link k-num"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(row.submissions.total.toLocaleString()), 1)]),
								_: 2
							}, 1032, ["href"]), row.submissions.new ? (openBlock(), createBlock(unref(KBadge), {
								key: 0,
								tone: "info",
								class: "k-forms__new"
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(newText(row.submissions.new)), 1)]),
								_: 2
							}, 1024)) : createCommentVNode("v-if", true)];
						}),
						"cell-updated_at": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								if (row.updated_at) _push(ssrRenderComponent(unref(KTime), { value: row.updated_at }, null, _parent, _scopeId));
								else _push(`<!---->`);
							} else return [row.updated_at ? (openBlock(), createBlock(unref(KTime), {
								key: 0,
								value: row.updated_at
							}, null, 8, ["value"])) : createCommentVNode("v-if", true)];
						}),
						"cell-actions": withCtx(({ row }, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<div class="k-row-actions" data-v-5fa12738${_scopeId}>`);
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "tray",
									href: `/admin/forms/${row.id}/submissions`
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Submissions"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Submissions")), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "pencil",
									href: `/admin/forms/${row.id}`
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Edit"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Edit")), 1)];
									}),
									_: 2
								}, _parent, _scopeId));
								_push(`</div>`);
							} else return [createVNode("div", { class: "k-row-actions" }, [createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "tray",
								href: `/admin/forms/${row.id}/submissions`
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Submissions")), 1)]),
								_: 1
							}, 8, ["href"]), createVNode(unref(KButton), {
								size: "sm",
								variant: "ghost",
								icon: "pencil",
								href: `/admin/forms/${row.id}`
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Edit")), 1)]),
								_: 1
							}, 8, ["href"])])];
						}),
						_: 1
					}, _parent, _scopeId));
					else return [createVNode(unref(KTable), {
						columns,
						rows: forms.value,
						loading: unref(list).loading.value && !unref(list).data.value,
						empty: unref(__)("No forms yet"),
						"empty-text": unref(__)("Start with a contact form; you can change every field.")
					}, {
						"cell-title": withCtx(({ row }) => [createVNode(unref(Link), {
							href: `/admin/forms/${row.id}`,
							class: "k-link k-forms__title"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.title), 1)]),
							_: 2
						}, 1032, ["href"]), createVNode("div", { class: "k-muted k-small" }, [createTextVNode(toDisplayString(fieldsText(row.definition.fields.length)), 1), row.definition.notifications.length ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode(" · " + toDisplayString(unref(__)("emails {to}", { to: row.definition.notifications.flatMap((n) => n.to).slice(0, 2).join(", ") })), 1)], 64)) : createCommentVNode("v-if", true)])]),
						"cell-status": withCtx(({ row }) => [createVNode(unref(KBadge), { tone: row.status === "open" ? "success" : void 0 }, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.status === "open" ? unref(__)("Open") : unref(__)("Closed")), 1)]),
							_: 2
						}, 1032, ["tone"])]),
						"cell-submissions": withCtx(({ row }) => [createVNode(unref(Link), {
							href: `/admin/forms/${row.id}/submissions`,
							class: "k-link k-num"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(row.submissions.total.toLocaleString()), 1)]),
							_: 2
						}, 1032, ["href"]), row.submissions.new ? (openBlock(), createBlock(unref(KBadge), {
							key: 0,
							tone: "info",
							class: "k-forms__new"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(newText(row.submissions.new)), 1)]),
							_: 2
						}, 1024)) : createCommentVNode("v-if", true)]),
						"cell-updated_at": withCtx(({ row }) => [row.updated_at ? (openBlock(), createBlock(unref(KTime), {
							key: 0,
							value: row.updated_at
						}, null, 8, ["value"])) : createCommentVNode("v-if", true)]),
						"cell-actions": withCtx(({ row }) => [createVNode("div", { class: "k-row-actions" }, [createVNode(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "tray",
							href: `/admin/forms/${row.id}/submissions`
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Submissions")), 1)]),
							_: 1
						}, 8, ["href"]), createVNode(unref(KButton), {
							size: "sm",
							variant: "ghost",
							icon: "pencil",
							href: `/admin/forms/${row.id}`
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Edit")), 1)]),
							_: 1
						}, 8, ["href"])])]),
						_: 2
					}, 1032, [
						"rows",
						"loading",
						"empty",
						"empty-text"
					])];
				}),
				_: 1
			}, _parent));
			_push(ssrRenderComponent(unref(KModal), {
				open: creating.value,
				title: unref(__)("New form"),
				onClose: ($event) => creating.value = false
			}, {
				footer: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(ssrRenderComponent(unref(KButton), { onClick: ($event) => creating.value = false }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Cancel"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(ssrRenderComponent(unref(KButton), {
							variant: "primary",
							type: "submit",
							form: "k-form-create",
							loading: saving.value
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Create"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Create")), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
					} else return [createVNode(unref(KButton), { onClick: ($event) => creating.value = false }, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Cancel")), 1)]),
						_: 1
					}, 8, ["onClick"]), createVNode(unref(KButton), {
						variant: "primary",
						type: "submit",
						form: "k-form-create",
						loading: saving.value
					}, {
						default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Create")), 1)]),
						_: 1
					}, 8, ["loading"])];
				}),
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form id="k-form-create" class="k-form" data-v-5fa12738${_scopeId}>`);
						_push(ssrRenderComponent(unref(KField), {
							label: unref(__)("Name"),
							help: unref(__)("For you and in notification emails. Visitors don't see it."),
							error: errors.value.title ?? "",
							required: ""
						}, {
							default: withCtx(({ id, describedBy, invalid }, _push, _parent, _scopeId) => {
								if (_push) _push(ssrRenderComponent(unref(KInput), {
									id,
									modelValue: form.title,
									"onUpdate:modelValue": ($event) => form.title = $event,
									maxlength: "200",
									invalid,
									"aria-describedby": describedBy
								}, null, _parent, _scopeId));
								else return [createVNode(unref(KInput), {
									id,
									modelValue: form.title,
									"onUpdate:modelValue": ($event) => form.title = $event,
									maxlength: "200",
									invalid,
									"aria-describedby": describedBy
								}, null, 8, [
									"id",
									"modelValue",
									"onUpdate:modelValue",
									"invalid",
									"aria-describedby"
								])];
							}),
							_: 1
						}, _parent, _scopeId));
						_push(`<fieldset class="k-forms__starters" data-v-5fa12738${_scopeId}><legend data-v-5fa12738${_scopeId}>${ssrInterpolate(unref(__)("Start with"))}</legend><label class="k-forms__starter" data-v-5fa12738${_scopeId}><input${ssrIncludeBooleanAttr(ssrLooseEqual(form.starter, "contact")) ? " checked" : ""} type="radio" value="contact" data-v-5fa12738${_scopeId}><span data-v-5fa12738${_scopeId}><strong data-v-5fa12738${_scopeId}>${ssrInterpolate(unref(__)("A contact form"))}</strong><span class="k-muted k-small" data-v-5fa12738${_scopeId}>${ssrInterpolate(unref(__)("Name, email and message"))}</span></span></label><label class="k-forms__starter" data-v-5fa12738${_scopeId}><input${ssrIncludeBooleanAttr(ssrLooseEqual(form.starter, "blank")) ? " checked" : ""} type="radio" value="blank" data-v-5fa12738${_scopeId}><span data-v-5fa12738${_scopeId}><strong data-v-5fa12738${_scopeId}>${ssrInterpolate(unref(__)("Almost nothing"))}</strong><span class="k-muted k-small" data-v-5fa12738${_scopeId}>${ssrInterpolate(unref(__)("One field to build on"))}</span></span></label></fieldset></form>`);
					} else return [createVNode("form", {
						id: "k-form-create",
						class: "k-form",
						onSubmit: withModifiers(create, ["prevent"])
					}, [createVNode(unref(KField), {
						label: unref(__)("Name"),
						help: unref(__)("For you and in notification emails. Visitors don't see it."),
						error: errors.value.title ?? "",
						required: ""
					}, {
						default: withCtx(({ id, describedBy, invalid }) => [createVNode(unref(KInput), {
							id,
							modelValue: form.title,
							"onUpdate:modelValue": ($event) => form.title = $event,
							maxlength: "200",
							invalid,
							"aria-describedby": describedBy
						}, null, 8, [
							"id",
							"modelValue",
							"onUpdate:modelValue",
							"invalid",
							"aria-describedby"
						])]),
						_: 1
					}, 8, [
						"label",
						"help",
						"error"
					]), createVNode("fieldset", { class: "k-forms__starters" }, [
						createVNode("legend", null, toDisplayString(unref(__)("Start with")), 1),
						createVNode("label", { class: "k-forms__starter" }, [withDirectives(createVNode("input", {
							"onUpdate:modelValue": ($event) => form.starter = $event,
							type: "radio",
							value: "contact"
						}, null, 8, ["onUpdate:modelValue"]), [[vModelRadio, form.starter]]), createVNode("span", null, [createVNode("strong", null, toDisplayString(unref(__)("A contact form")), 1), createVNode("span", { class: "k-muted k-small" }, toDisplayString(unref(__)("Name, email and message")), 1)])]),
						createVNode("label", { class: "k-forms__starter" }, [withDirectives(createVNode("input", {
							"onUpdate:modelValue": ($event) => form.starter = $event,
							type: "radio",
							value: "blank"
						}, null, 8, ["onUpdate:modelValue"]), [[vModelRadio, form.starter]]), createVNode("span", null, [createVNode("strong", null, toDisplayString(unref(__)("Almost nothing")), 1), createVNode("span", { class: "k-muted k-small" }, toDisplayString(unref(__)("One field to build on")), 1)])])
					])], 32)];
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
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/forms/Index.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Index_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-5fa12738"]]);
export { Index_default as default };
