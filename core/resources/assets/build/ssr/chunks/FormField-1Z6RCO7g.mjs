import { n as fill, t as fileHint } from "./file-hint-Ce4VhQ7O.mjs";
import { computed, mergeModels, unref, useModel, useSSRContext } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrLooseContain, ssrLooseEqual, ssrRenderAttr, ssrRenderDynamicModel, ssrRenderList } from "vue/server-renderer";
// core/resources/js/shared/choices.js
/**
* What a visitor sees for a choice: its label, or its value when it has
* none (as the server stores it: Forms::sanitize falls back the same way).
* @param {{ value: string, label?: string }} choice
*/
var choiceLabel = (choice) => String(choice.label ?? "").trim() !== "" ? choice.label : choice.value;
// core/resources/js/shared/FormField.vue
/**
* One field of a site form, as visitors see it: label, input, help and error. Drawn by
* the Form block (pages/blocks/Form.vue) and by the form builder's canvas in the admin,
* so both look the same. The words around it ("Choose…", "Up to 5 MB.") come from the caller,
* in its language: the site's on a page, the admin's in the builder.
*/
var _sfc_main = {
	__name: "FormField",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		field: {
			type: Object,
			required: true
		},
		inputId: {
			type: String,
			required: true
		},
		error: {
			type: String,
			default: ""
		},
		chooseText: {
			type: String,
			default: "Choose…"
		},
		fileWords: {
			type: Object,
			default: () => ({
				one: (p) => fill("Up to {size} MB.", p),
				many: (p) => fill("Up to {count} files, {size} MB each.", p)
			})
		}
	}, {
		"modelValue": { default: "" },
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const value = useModel(__props, "modelValue");
		const props = __props;
		const help = computed(() => [props.field.help, props.field.type === "file" && fileHint(props.field.options, props.fileWords)].filter(Boolean).join(" "));
		const describedBy = computed(() => [help.value && `${props.inputId}-help`, props.error && `${props.inputId}-error`].filter(Boolean).join(" ") || void 0);
		const inputType = computed(() => ({
			email: "email",
			url: "url",
			number: "number",
			date: "date"
		})[props.field.type] ?? "text");
		const autocomplete = computed(() => props.field.type === "email" ? "email" : /(^|_)name$/.test(props.field.name) ? "name" : /phone|tel/.test(props.field.name) ? "tel" : void 0);
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			if (__props.field.type === "boolean") {
				_push(`<label class="k-b-form__check"><input${ssrRenderAttr("id", __props.inputId)}${ssrIncludeBooleanAttr(Array.isArray(value.value) ? ssrLooseContain(value.value, "1") : value.value) ? " checked" : ""} type="checkbox"${ssrRenderAttr("name", __props.field.name)} value="1"${ssrIncludeBooleanAttr(__props.field.required) ? " required" : ""}${ssrRenderAttr("aria-invalid", __props.error ? "true" : void 0)}${ssrRenderAttr("aria-describedby", describedBy.value)}><span>${ssrInterpolate(__props.field.label)}`);
				if (__props.field.required) _push(`<span class="k-b-form__req" aria-hidden="true"> *</span>`);
				else _push(`<!---->`);
				_push(`</span></label>`);
			} else if (__props.field.type === "multi_select" || __props.field.type === "select" && __props.field.options.display === "radios") {
				_push(`<fieldset class="k-b-form__set"${ssrRenderAttr("aria-describedby", describedBy.value)}><legend class="k-b-form__label">${ssrInterpolate(__props.field.label)}`);
				if (__props.field.required) _push(`<span class="k-b-form__req" aria-hidden="true"> *</span>`);
				else _push(`<!---->`);
				_push(`</legend><!--[-->`);
				ssrRenderList(__props.field.options.choices, (c, ci) => {
					_push(`<label class="k-b-form__check">`);
					if (__props.field.type === "multi_select") _push(`<input${ssrRenderAttr("id", ci === 0 ? __props.inputId : void 0)}${ssrIncludeBooleanAttr(Array.isArray(value.value) ? ssrLooseContain(value.value, c.value) : value.value) ? " checked" : ""} type="checkbox"${ssrRenderAttr("name", `${__props.field.name}[]`)}${ssrRenderAttr("value", c.value)}>`);
					else _push(`<input${ssrRenderAttr("id", ci === 0 ? __props.inputId : void 0)}${ssrIncludeBooleanAttr(ssrLooseEqual(value.value, c.value)) ? " checked" : ""} type="radio"${ssrRenderAttr("name", __props.field.name)}${ssrRenderAttr("value", c.value)}${ssrIncludeBooleanAttr(__props.field.required) ? " required" : ""}>`);
					_push(`<span>${ssrInterpolate(unref(choiceLabel)(c))}</span></label>`);
				});
				_push(`<!--]--></fieldset>`);
			} else {
				_push(`<!--[--><label class="k-b-form__label"${ssrRenderAttr("for", __props.inputId)}>${ssrInterpolate(__props.field.label)}`);
				if (__props.field.required) _push(`<span class="k-b-form__req" aria-hidden="true"> *</span>`);
				else _push(`<!---->`);
				_push(`</label>`);
				if (__props.field.type === "select") {
					_push(`<select${ssrRenderAttr("id", __props.inputId)} class="k-b-form__input"${ssrRenderAttr("name", __props.field.name)}${ssrIncludeBooleanAttr(__props.field.required) ? " required" : ""}${ssrRenderAttr("aria-invalid", __props.error ? "true" : void 0)}${ssrRenderAttr("aria-describedby", describedBy.value)}><option value=""${ssrIncludeBooleanAttr(Array.isArray(value.value) ? ssrLooseContain(value.value, "") : ssrLooseEqual(value.value, "")) ? " selected" : ""}>${ssrInterpolate(__props.chooseText)}</option><!--[-->`);
					ssrRenderList(__props.field.options.choices, (c) => {
						_push(`<option${ssrRenderAttr("value", c.value)}${ssrIncludeBooleanAttr(Array.isArray(value.value) ? ssrLooseContain(value.value, c.value) : ssrLooseEqual(value.value, c.value)) ? " selected" : ""}>${ssrInterpolate(unref(choiceLabel)(c))}</option>`);
					});
					_push(`<!--]--></select>`);
				} else if (__props.field.type === "textarea") _push(`<textarea${ssrRenderAttr("id", __props.inputId)} class="k-b-form__input"${ssrRenderAttr("name", __props.field.name)}${ssrRenderAttr("rows", __props.field.options.rows || 5)}${ssrRenderAttr("maxlength", __props.field.options.max)}${ssrRenderAttr("placeholder", __props.field.options.placeholder)}${ssrIncludeBooleanAttr(__props.field.required) ? " required" : ""}${ssrRenderAttr("aria-invalid", __props.error ? "true" : void 0)}${ssrRenderAttr("aria-describedby", describedBy.value)}>${ssrInterpolate(value.value)}</textarea>`);
				else if (__props.field.type === "file") _push(`<input${ssrRenderAttr("id", __props.inputId)} class="k-b-form__file" type="file"${ssrRenderAttr("name", __props.field.options.multiple ? `${__props.field.name}[]` : __props.field.name)}${ssrIncludeBooleanAttr(__props.field.options.multiple) ? " multiple" : ""}${ssrRenderAttr("accept", __props.field.options.accept)}${ssrIncludeBooleanAttr(__props.field.required) ? " required" : ""}${ssrRenderAttr("aria-invalid", __props.error ? "true" : void 0)}${ssrRenderAttr("aria-describedby", describedBy.value)}>`);
				else _push(`<input${ssrRenderAttr("id", __props.inputId)}${ssrRenderDynamicModel(inputType.value, value.value, null)} class="k-b-form__input"${ssrRenderAttr("type", inputType.value)}${ssrRenderAttr("name", __props.field.name)}${ssrRenderAttr("maxlength", __props.field.options.max)}${ssrRenderAttr("min", __props.field.type === "number" ? __props.field.options.min : void 0)}${ssrRenderAttr("max", __props.field.type === "number" ? __props.field.options.max : void 0)}${ssrRenderAttr("step", __props.field.type === "number" ? __props.field.options.step || (__props.field.options.integer ? 1 : "any") : void 0)}${ssrRenderAttr("placeholder", __props.field.options.placeholder)}${ssrRenderAttr("autocomplete", autocomplete.value)}${ssrIncludeBooleanAttr(__props.field.required) ? " required" : ""}${ssrRenderAttr("aria-invalid", __props.error ? "true" : void 0)}${ssrRenderAttr("aria-describedby", describedBy.value)}>`);
				_push(`<!--]-->`);
			}
			if (help.value) _push(`<p${ssrRenderAttr("id", `${__props.inputId}-help`)} class="k-b-form__help">${ssrInterpolate(help.value)}</p>`);
			else _push(`<!---->`);
			if (__props.error) _push(`<p${ssrRenderAttr("id", `${__props.inputId}-error`)} class="k-b-form__error">${ssrInterpolate(__props.error)}</p>`);
			else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/shared/FormField.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as t };
