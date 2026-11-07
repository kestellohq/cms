import { t as _sfc_main$1 } from "../../chunks/FormField-1Z6RCO7g.mjs";
import { n as fill } from "../../chunks/file-hint-Ce4VhQ7O.mjs";
import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, mergeProps, onMounted, reactive, ref, unref, useId, useSSRContext } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { visibleFields } from "@kestello/sdk";
// core/resources/js/pages/blocks/Form.vue
/**
* core/form: one of the site's forms. Pages are cached, so the form has
* no session: it fetches a short-lived token when someone starts filling
* it in, and the server checks that, a honeypot, the visitor's rate and
* (when switched on) a captcha. Fields hidden by their conditions are not
* rendered, so they are never sent.
*/
var _sfc_main = {
	__name: "Form",
	__ssrInlineRender: true,
	props: {
		form: {
			type: [String, Number],
			default: ""
		},
		definition: {
			type: Object,
			default: null
		}
	},
	setup(__props) {
		const props = __props;
		const uid = useId();
		ref(null);
		const values = reactive({});
		const errors = ref({});
		const state = ref("idle");
		const message = ref("");
		const formError = ref("");
		const def = computed(() => props.definition);
		const fields = computed(() => def.value?.fields ?? []);
		for (const f of props.definition?.fields ?? []) values[f.name] = f.type === "multi_select" ? [] : f.type === "boolean" ? f.default === true : f.default ?? "";
		const shown = computed(() => visibleFields(fields.value, values));
		const visible = computed(() => fields.value.filter((f) => shown.value[f.name] !== false));
		const id = (f) => `k-b-form-${uid}-${f.name}`;
		onMounted(() => {
			const c = def.value?.captcha;
			if (c?.script && !document.querySelector(`script[src="${c.script}"]`)) document.head.appendChild(Object.assign(document.createElement("script"), {
				src: c.script,
				async: true,
				defer: true
			}));
		});
		const ENGLISH = {
			sending: "Sending…",
			honeypot: "Leave this empty",
			choose: "Choose…",
			check: "Please check the form and try again.",
			too_many: "Too many tries from here. Please wait a while and send it again.",
			failed: "Something went wrong. Please reload the page and try again.",
			offline: "The form could not be sent. Check your connection and try again.",
			file_one: "Up to {size} MB.",
			file_many: "Up to {count} files, {size} MB each."
		};
		const text = (key) => def.value?.texts?.[key] ?? ENGLISH[key];
		const fileWords = {
			one: (p) => fill(text("file_one"), p),
			many: (p) => fill(text("file_many"), p)
		};
		return (_ctx, _push, _parent, _attrs) => {
			if (def.value) {
				_push(`<div${ssrRenderAttrs(mergeProps({ class: "k-b-form" }, _attrs))} data-v-743da933>`);
				if (!def.value.open) _push(`<p class="k-b-form__closed" data-v-743da933>${ssrInterpolate(def.value.closed_message)}</p>`);
				else if (state.value === "sent") _push(`<div class="k-b-form__done" tabindex="-1" role="status" data-v-743da933>${ssrInterpolate(message.value)}</div>`);
				else {
					_push(`<form class="k-b-form__form" novalidate${ssrRenderAttr("aria-label", def.value.title)} data-v-743da933>`);
					if (formError.value) _push(`<p class="k-b-form__alert" role="alert" data-v-743da933>${ssrInterpolate(formError.value)}</p>`);
					else _push(`<!---->`);
					_push(`<!-- people never see this; bots that fill every field do --><div class="k-b-form__hp" aria-hidden="true" data-v-743da933><label${ssrRenderAttr("for", `k-b-form-${unref(uid)}-hp`)} data-v-743da933>${ssrInterpolate(text("honeypot"))}</label><input${ssrRenderAttr("id", `k-b-form-${unref(uid)}-hp`)} type="text" name="_hp" tabindex="-1" autocomplete="off" data-v-743da933></div><div class="k-b-form__grid" data-v-743da933><!--[-->`);
					ssrRenderList(visible.value, (f) => {
						_push(`<div class="${ssrRenderClass([[`k-b-form__field--${f.width}`, { "has-error": errors.value[f.name] }], "k-b-form__field"])}" data-v-743da933>`);
						_push(ssrRenderComponent(_sfc_main$1, {
							modelValue: values[f.name],
							"onUpdate:modelValue": ($event) => values[f.name] = $event,
							field: f,
							"input-id": id(f),
							error: errors.value[f.name],
							"choose-text": text("choose"),
							"file-words": fileWords
						}, null, _parent));
						_push(`</div>`);
					});
					_push(`<!--]--></div>`);
					if (def.value.captcha) _push(`<div class="k-b-form__captcha"${ssrRenderAttr("data-sitekey", def.value.captcha.site_key)} data-v-743da933></div>`);
					else _push(`<!---->`);
					_push(`<button type="submit" class="k-b-form__submit"${ssrIncludeBooleanAttr(state.value === "sending") ? " disabled" : ""}${ssrRenderAttr("aria-busy", state.value === "sending" ? "true" : void 0)} data-v-743da933>${ssrInterpolate(state.value === "sending" ? text("sending") : def.value.submit_label)}</button></form>`);
				}
				_push(`</div>`);
			} else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Form.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Form_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-743da933"]]);
export { Form_default as default };
