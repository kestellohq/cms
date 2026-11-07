import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, createTextVNode, createVNode, onBeforeUnmount, onMounted, ref, toDisplayString, unref, useSSRContext, watch, withCtx } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderComponent, ssrRenderList, ssrRenderStyle } from "vue/server-renderer";
import { Head } from "@kestello/app";
import { ApiError, __, clone, confirm, formatBytes, notify, useApi, useLeaveGuard } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KField, KIcon, KInput, KSelect, KSwitch, KTime } from "@kestello/ui";
// core/resources/js/pages/admin/appearance/Styles.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Styles",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* Appearance → Styles: the theme's design tokens with a live preview of the
		* real site. Nothing changes for visitors until Publish.
		*/
		const api = useApi();
		const data = ref(null);
		const loadError = ref(null);
		const choice = ref({
			variation: null,
			values: {},
			custom_css: ""
		});
		const errors = ref({});
		const saving = ref(false);
		function adopt(d) {
			data.value = d;
			choice.value = clone({
				variation: d.published.variation,
				values: d.published.values,
				custom_css: d.published.custom_css
			});
			errors.value = {};
		}
		async function load() {
			try {
				adopt((await api.get("/design/styles")).data);
			} catch (e) {
				loadError.value = e;
			}
		}
		load();
		const def = computed(() => data.value?.definition);
		const dirty = computed(() => data.value && JSON.stringify(choice.value) !== JSON.stringify({
			variation: data.value.published.variation,
			values: data.value.published.values,
			custom_css: data.value.published.custom_css
		}));
		const resolved = computed(() => {
			if (!def.value) return {};
			const out = Object.fromEntries(Object.entries(def.value.tokens).map(([k, t]) => [k, t.value]));
			Object.assign(out, def.value.variations[choice.value.variation]?.values ?? {});
			return Object.assign(out, choice.value.values);
		});
		const groups = computed(() => {
			if (!def.value) return [];
			const by = {};
			for (const [name, t] of Object.entries(def.value.tokens)) (by[t.group] ??= []).push({
				name,
				...t
			});
			return Object.entries(by).map(([id, tokens]) => ({
				id,
				label: def.value.groups[id] ?? __("Other"),
				tokens
			}));
		});
		const num = (n) => String(Math.round(Number(n) * 1e4) / 1e4);
		function cssValue(t, v) {
			if (t.type === "font") return def.value.fonts[v]?.stack;
			if (t.type === "size") return num(v) + t.unit;
			return t.type === "number" ? num(v) : String(v);
		}
		function setValue(name, value) {
			const t = def.value.tokens[name];
			const base = def.value.variations[choice.value.variation]?.values?.[name] ?? t.value;
			const values = { ...choice.value.values };
			if (String(value).toLowerCase() === String(base).toLowerCase()) delete values[name];
			else values[name] = t.type === "size" || t.type === "number" ? Number(value) : value;
			choice.value = {
				...choice.value,
				values
			};
		}
		function rgb(hex) {
			let h = String(hex).replace("#", "");
			if (h.length === 3) h = h.split("").map((c) => c + c).join("");
			return [
				0,
				2,
				4
			].map((i) => parseInt(h.slice(i, i + 2), 16) / 255);
		}
		const lum = (hex) => {
			const [r, g, b] = rgb(hex).map((c) => c <= .03928 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4);
			return .2126 * r + .7152 * g + .0722 * b;
		};
		const ratio = (a, b) => {
			const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m);
			return (x + .05) / (y + .05);
		};
		const contrast = computed(() => (def.value?.contrast ?? []).map((p) => {
			const r = ratio(resolved.value[p.fg], resolved.value[p.bg]);
			const need = p.large ? 3 : 4.5;
			return {
				...p,
				ratio: r,
				need,
				ok: r >= need
			};
		}));
		const failing = computed(() => contrast.value.filter((c) => !c.ok));
		/** Darken or lighten the text colour, keeping its hue, until it passes. */
		function fixContrast(pair) {
			const bg = resolved.value[pair.bg];
			const towardsDark = lum(bg) > .18;
			let [r, g, b] = rgb(resolved.value[pair.fg]);
			for (let i = 0; i < 60; i++) {
				const hex = "#" + [
					r,
					g,
					b
				].map((c) => Math.round(Math.min(1, Math.max(0, c)) * 255).toString(16).padStart(2, "0")).join("");
				if (ratio(hex, bg) >= pair.need + .1) return setValue(pair.fg, hex);
				[r, g, b] = [
					r,
					g,
					b
				].map((c) => towardsDark ? c * .94 : c + (1 - c) * .08);
			}
			setValue(pair.fg, towardsDark ? "#000000" : "#ffffff");
		}
		const frame = ref(null);
		const device = ref("desktop");
		const DEVICES = [
			{
				id: "phone",
				icon: "device-mobile",
				label: __("Phone"),
				width: 390
			},
			{
				id: "tablet",
				icon: "device-tablet",
				label: __("Tablet"),
				width: 820
			},
			{
				id: "desktop",
				icon: "monitor",
				label: __("Desktop"),
				width: null
			}
		];
		const path = ref("/");
		const pathInput = ref("/");
		const frameSrc = computed(() => path.value);
		function send() {
			const win = frame.value?.contentWindow;
			if (!win || !def.value) return;
			const vars = {};
			for (const [name, v] of Object.entries(resolved.value)) {
				const css = cssValue(def.value.tokens[name], v);
				if (css !== void 0) vars[`--${name}`] = css;
			}
			const chosen = new Set(Object.entries(resolved.value).filter(([n]) => def.value.tokens[n]?.type === "font").map(([, v]) => v));
			const faces = (data.value.hosted_fonts ?? []).filter((f) => chosen.has(f.slug)).map((f) => f.faces).join("\n");
			const custom = data.value.can.custom_css ? choice.value.custom_css : data.value.published.custom_css;
			win.postMessage({
				type: "kestello:styles",
				vars,
				css: faces ? `${faces}\n${custom}` : custom
			}, window.location.origin);
		}
		let raf = 0;
		watch([resolved, () => choice.value.custom_css], () => {
			cancelAnimationFrame(raf);
			raf = requestAnimationFrame(send);
		}, { deep: true });
		function onMessage(event) {
			if (event.origin !== window.location.origin || event.source !== frame.value?.contentWindow) return;
			if (event.data?.type === "kestello:preview-ready") {
				pathInput.value = event.data.path;
				send();
			}
		}
		useLeaveGuard(dirty);
		onMounted(() => window.addEventListener("message", onMessage));
		onBeforeUnmount(() => {
			window.removeEventListener("message", onMessage);
			cancelAnimationFrame(raf);
		});
		async function publish() {
			saving.value = true;
			errors.value = {};
			try {
				const body = {
					variation: choice.value.variation,
					values: choice.value.values
				};
				if (data.value.can.custom_css) body.custom_css = choice.value.custom_css;
				adopt((await api.put("/design/styles", body)).data);
				notify.success(__("Published. Every page uses the new styles now."));
			} catch (e) {
				if (e instanceof ApiError && e.status === 422) {
					errors.value = e.fieldErrors();
					notify.error(__("Some values need attention."));
				} else notify.error(e);
			} finally {
				saving.value = false;
			}
		}
		async function discard() {
			if (!await confirm({
				title: __("Discard your changes?"),
				message: __("The preview goes back to what visitors see now."),
				confirmLabel: __("Discard")
			})) return;
			adopt(data.value);
		}
		async function resetAll() {
			if (!await confirm({
				title: __("Go back to the theme's styles?"),
				message: __("Every page uses {theme} as it was designed. You can restore the current version from the history below.", { theme: data.value.theme.name }),
				confirmLabel: __("Reset")
			})) return;
			try {
				adopt((await api.delete("/design/styles")).data);
				notify.success(__("Back to the theme's styles."));
			} catch (e) {
				notify.error(e);
			}
		}
		async function restore(rev) {
			if (dirty.value && !await confirm({
				title: __("Restore this version?"),
				message: __("Your unpublished changes are discarded, and the version is published at once."),
				confirmLabel: __("Restore")
			})) return;
			try {
				adopt((await api.post(`/design/styles/revisions/${rev.id}/restore`)).data);
				notify.success(__("Restored and published."));
			} catch (e) {
				notify.error(e);
			}
		}
		const isChanged = (name) => name in choice.value.values;
		const swatches = (v) => Object.entries(def.value?.tokens ?? {}).filter(([, t]) => t.type === "color").slice(0, 4).map(([k]) => v.values?.[k]).filter(Boolean);
		const WEIGHTS = [
			300,
			400,
			500,
			600,
			700,
			800
		];
		const CATEGORIES = [
			{
				value: "sans-serif",
				label: __("Sans-serif")
			},
			{
				value: "serif",
				label: __("Serif")
			},
			{
				value: "display",
				label: __("Display")
			},
			{
				value: "handwriting",
				label: __("Handwriting")
			},
			{
				value: "monospace",
				label: __("Monospace")
			}
		];
		const fontForm = ref({
			family: "",
			weights: [400, 700],
			italic: false,
			category: "sans-serif"
		});
		const fontErrors = ref({});
		const addingFont = ref(false);
		const fontOptions = computed(() => Object.entries(def.value?.fonts ?? {}).map(([value, f]) => ({
			value,
			label: f.label
		})));
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: unref(__)("Styles") }, null, _parent));
			if (loadError.value) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`${ssrInterpolate(loadError.value.message)}`);
					else return [createTextVNode(toDisplayString(loadError.value.message), 1)];
				}),
				_: 1
			}, _parent));
			else _push(`<!---->`);
			if (data.value) {
				_push(`<div class="k-styles" data-v-9b62ea71><header class="k-styles__head" data-v-9b62ea71><div data-v-9b62ea71><h1 class="k-styles__title" data-v-9b62ea71>${ssrInterpolate(unref(__)("Styles"))}</h1><p class="k-muted k-small" data-v-9b62ea71>${ssrInterpolate(unref(__)("Colours, type and shapes of {theme}. Try anything: visitors see it only after you publish.", { theme: data.value.theme.name }))}</p></div><div class="k-styles__actions" data-v-9b62ea71>`);
				if (dirty.value) _push(ssrRenderComponent(unref(KBadge), { tone: "warning" }, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Not published"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Not published")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				if (dirty.value) _push(ssrRenderComponent(unref(KButton), {
					variant: "ghost",
					onClick: discard
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Discard"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Discard")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(ssrRenderComponent(unref(KButton), {
					variant: "primary",
					icon: "check",
					disabled: !dirty.value,
					loading: saving.value,
					onClick: publish
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Publish"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Publish")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</div></header><div class="k-styles__body" data-v-9b62ea71><aside class="k-styles__panel"${ssrRenderAttr("aria-label", unref(__)("Style settings"))} data-v-9b62ea71>`);
				if (!Object.keys(def.value.tokens).length) _push(`<p class="k-muted k-small" data-v-9b62ea71>${ssrInterpolate(unref(__)("This theme declares no styles to change (no styles.json)."))}</p>`);
				else _push(`<!---->`);
				if (Object.keys(def.value.variations).length) {
					_push(`<section class="k-styles__section" data-v-9b62ea71><h2 data-v-9b62ea71>${ssrInterpolate(unref(__)("Style"))}</h2><div class="k-variations" role="radiogroup"${ssrRenderAttr("aria-label", unref(__)("Style"))} data-v-9b62ea71><button type="button" role="radio" class="${ssrRenderClass([{ "is-on": !choice.value.variation }, "k-variation"])}"${ssrRenderAttr("aria-checked", !choice.value.variation)} data-v-9b62ea71><span class="k-variation__chips" data-v-9b62ea71><!--[-->`);
					ssrRenderList(swatches({ values: Object.fromEntries(Object.entries(def.value.tokens).map(([k, t]) => [k, t.value])) }), (c) => {
						_push(`<span class="k-variation__chip" style="${ssrRenderStyle({ background: c })}" data-v-9b62ea71></span>`);
					});
					_push(`<!--]--></span> ${ssrInterpolate(unref(__)("As designed"))}</button><!--[-->`);
					ssrRenderList(def.value.variations, (v, id) => {
						_push(`<button type="button" role="radio" class="${ssrRenderClass([{ "is-on": choice.value.variation === id }, "k-variation"])}"${ssrRenderAttr("aria-checked", choice.value.variation === id)} data-v-9b62ea71><span class="k-variation__chips" data-v-9b62ea71><!--[-->`);
						ssrRenderList(swatches({ values: {
							...Object.fromEntries(Object.entries(def.value.tokens).map(([k, t]) => [k, t.value])),
							...v.values
						} }), (c) => {
							_push(`<span class="k-variation__chip" style="${ssrRenderStyle({ background: c })}" data-v-9b62ea71></span>`);
						});
						_push(`<!--]--></span> ${ssrInterpolate(v.label)}</button>`);
					});
					_push(`<!--]--></div></section>`);
				} else _push(`<!---->`);
				_push(`<!--[-->`);
				ssrRenderList(groups.value, (g) => {
					_push(`<section class="k-styles__section" data-v-9b62ea71><h2 data-v-9b62ea71>${ssrInterpolate(g.label)}</h2><!--[-->`);
					ssrRenderList(g.tokens, (t) => {
						_push(`<div class="${ssrRenderClass([{ "is-changed": isChanged(t.name) }, "k-token"])}" data-v-9b62ea71><label class="k-token__label"${ssrRenderAttr("for", `tok-${t.name}`)} data-v-9b62ea71>${ssrInterpolate(t.label)}</label><div class="k-token__control" data-v-9b62ea71>`);
						if (t.type === "color") _push(`<!--[--><input${ssrRenderAttr("id", `tok-${t.name}`)} type="color" class="k-token__swatch"${ssrRenderAttr("value", String(resolved.value[t.name]).slice(0, 7))} data-v-9b62ea71><input type="text" class="k-input k-token__hex"${ssrRenderAttr("value", resolved.value[t.name])} maxlength="9" spellcheck="false"${ssrRenderAttr("aria-label", unref(__)("{name}, as a hex code", { name: t.label }))} data-v-9b62ea71><!--]-->`);
						else if (t.type === "font") _push(ssrRenderComponent(unref(KSelect), {
							id: `tok-${t.name}`,
							"model-value": resolved.value[t.name],
							options: fontOptions.value,
							"onUpdate:modelValue": (v) => setValue(t.name, v)
						}, null, _parent));
						else _push(`<!--[--><input${ssrRenderAttr("id", `tok-${t.name}`)} type="range" class="k-token__range"${ssrRenderAttr("min", t.min)}${ssrRenderAttr("max", t.max)}${ssrRenderAttr("step", t.step)}${ssrRenderAttr("value", resolved.value[t.name])} data-v-9b62ea71><output class="k-token__out"${ssrRenderAttr("for", `tok-${t.name}`)} data-v-9b62ea71>${ssrInterpolate(num(resolved.value[t.name]))}${ssrInterpolate(t.unit ?? "")}</output><!--]-->`);
						if (isChanged(t.name)) {
							_push(`<button type="button" class="k-token__reset"${ssrRenderAttr("aria-label", unref(__)("Reset {name}", { name: t.label }))}${ssrRenderAttr("title", unref(__)("Back to the style's value"))} data-v-9b62ea71>`);
							_push(ssrRenderComponent(unref(KIcon), { name: "arrow-counter-clockwise" }, null, _parent));
							_push(`</button>`);
						} else _push(`<!---->`);
						_push(`</div>`);
						if (t.help) _push(`<p class="k-muted k-small k-token__help" data-v-9b62ea71>${ssrInterpolate(t.help)}</p>`);
						else _push(`<!---->`);
						if (errors.value[`values.${t.name}`]) _push(`<p class="k-field__error" role="alert" data-v-9b62ea71>${ssrInterpolate(errors.value[`values.${t.name}`])}</p>`);
						else _push(`<!---->`);
						_push(`</div>`);
					});
					_push(`<!--]--></section>`);
				});
				_push(`<!--]-->`);
				if (Object.values(def.value.tokens).some((t) => t.type === "font")) {
					_push(`<section class="k-styles__section" data-v-9b62ea71><h2 data-v-9b62ea71>${ssrInterpolate(unref(__)("Fonts on this site"))}</h2><p class="k-muted k-small" data-v-9b62ea71>${ssrInterpolate(unref(__)("Fonts from Google Fonts are downloaded once and served from this site, so visitors never contact Google."))}</p>`);
					if (data.value.hosted_fonts.length) {
						_push(`<ul class="k-fonts" data-v-9b62ea71><!--[-->`);
						ssrRenderList(data.value.hosted_fonts, (f) => {
							_push(`<li data-v-9b62ea71><span class="k-fonts__name" data-v-9b62ea71>${ssrInterpolate(f.family)}</span><span class="k-muted k-small k-num" data-v-9b62ea71>${ssrInterpolate(unref(formatBytes)(f.bytes))}</span><button type="button" class="k-token__reset"${ssrRenderAttr("aria-label", unref(__)("Remove {family}", { family: f.family }))}${ssrRenderAttr("title", unref(__)("Remove"))} data-v-9b62ea71>`);
							_push(ssrRenderComponent(unref(KIcon), { name: "trash" }, null, _parent));
							_push(`</button></li>`);
						});
						_push(`<!--]--></ul>`);
					} else _push(`<!---->`);
					_push(`<form class="k-font-add" data-v-9b62ea71>`);
					_push(ssrRenderComponent(unref(KField), {
						label: unref(__)("Google font"),
						help: unref(__)("Its name on Google Fonts, such as Inter or Playfair Display."),
						error: fontErrors.value.family ?? ""
					}, {
						default: withCtx(({ id }, _push, _parent, _scopeId) => {
							if (_push) _push(ssrRenderComponent(unref(KInput), {
								id,
								modelValue: fontForm.value.family,
								"onUpdate:modelValue": ($event) => fontForm.value.family = $event,
								maxlength: "61",
								required: ""
							}, null, _parent, _scopeId));
							else return [createVNode(unref(KInput), {
								id,
								modelValue: fontForm.value.family,
								"onUpdate:modelValue": ($event) => fontForm.value.family = $event,
								maxlength: "61",
								required: ""
							}, null, 8, [
								"id",
								"modelValue",
								"onUpdate:modelValue"
							])];
						}),
						_: 1
					}, _parent));
					_push(`<fieldset class="k-font-add__weights" data-v-9b62ea71><legend class="k-field__label" data-v-9b62ea71>${ssrInterpolate(unref(__)("Weights"))}</legend><!--[-->`);
					ssrRenderList(WEIGHTS, (w) => {
						_push(`<label class="k-font-add__weight" data-v-9b62ea71><input type="checkbox"${ssrIncludeBooleanAttr(fontForm.value.weights.includes(w)) ? " checked" : ""} data-v-9b62ea71> <span class="k-num" data-v-9b62ea71>${ssrInterpolate(w)}</span></label>`);
					});
					_push(`<!--]-->`);
					if (fontErrors.value.weights) _push(`<p class="k-field__error" role="alert" data-v-9b62ea71>${ssrInterpolate(fontErrors.value.weights)}</p>`);
					else _push(`<!---->`);
					_push(`</fieldset><div class="k-font-add__row" data-v-9b62ea71>`);
					_push(ssrRenderComponent(unref(KSelect), {
						modelValue: fontForm.value.category,
						"onUpdate:modelValue": ($event) => fontForm.value.category = $event,
						options: CATEGORIES,
						"aria-label": unref(__)("Kind of font")
					}, null, _parent));
					_push(ssrRenderComponent(unref(KSwitch), {
						modelValue: fontForm.value.italic,
						"onUpdate:modelValue": ($event) => fontForm.value.italic = $event,
						label: unref(__)("Italics")
					}, null, _parent));
					_push(`</div>`);
					_push(ssrRenderComponent(unref(KButton), {
						type: "submit",
						size: "sm",
						icon: "download",
						loading: addingFont.value,
						disabled: !fontForm.value.family.trim() || !fontForm.value.weights.length
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Add font"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Add font")), 1)];
						}),
						_: 1
					}, _parent));
					_push(`</form></section>`);
				} else _push(`<!---->`);
				if (contrast.value.length) {
					_push(`<section class="k-styles__section" data-v-9b62ea71><h2 data-v-9b62ea71>${ssrInterpolate(unref(__)("Readability"))}</h2><p class="k-muted k-small" data-v-9b62ea71>${ssrInterpolate(failing.value.length ? unref(__)("Some text may be hard to read. WCAG AA asks for 4.5:1, or 3:1 for large lettering.") : unref(__)("Every pairing meets WCAG AA."))}</p><ul class="k-contrast" data-v-9b62ea71><!--[-->`);
					ssrRenderList(contrast.value, (c) => {
						_push(`<li class="${ssrRenderClass({ "is-bad": !c.ok })}" data-v-9b62ea71><span class="k-contrast__sample" style="${ssrRenderStyle({
							color: resolved.value[c.fg],
							background: resolved.value[c.bg]
						})}" aria-hidden="true" data-v-9b62ea71>Aa</span><span class="k-contrast__label" data-v-9b62ea71>${ssrInterpolate(c.label)}</span><span class="k-contrast__ratio k-num" data-v-9b62ea71>${ssrInterpolate(c.ratio.toFixed(1))}:1</span>`);
						if (c.ok) _push(ssrRenderComponent(unref(KIcon), {
							name: "check",
							label: unref(__)("Readable")
						}, null, _parent));
						else _push(ssrRenderComponent(unref(KButton), {
							size: "sm",
							variant: "ghost",
							onClick: ($event) => fixContrast(c)
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(unref(__)("Fix"))}`);
								else return [createTextVNode(toDisplayString(unref(__)("Fix")), 1)];
							}),
							_: 2
						}, _parent));
						_push(`</li>`);
					});
					_push(`<!--]--></ul></section>`);
				} else _push(`<!---->`);
				if (data.value.can.custom_css || data.value.published.custom_css) {
					_push(`<section class="k-styles__section" data-v-9b62ea71><h2 data-v-9b62ea71><label for="k-custom-css" data-v-9b62ea71>${ssrInterpolate(unref(__)("Custom CSS"))}</label></h2><p class="k-muted k-small" data-v-9b62ea71>${ssrInterpolate(unref(__)("Added after the theme's styles. Images must come from this site; scripts and imports are refused."))}</p><textarea id="k-custom-css" class="k-input k-custom-css" rows="8" spellcheck="false"${ssrIncludeBooleanAttr(!data.value.can.custom_css) ? " readonly" : ""}${ssrRenderAttr("aria-invalid", errors.value.custom_css ? "true" : void 0)} data-v-9b62ea71>${ssrInterpolate(choice.value.custom_css)}</textarea>`);
					if (errors.value.custom_css) _push(`<p class="k-field__error" role="alert" data-v-9b62ea71>${ssrInterpolate(errors.value.custom_css)}</p>`);
					else _push(`<!---->`);
					_push(`</section>`);
				} else _push(`<!---->`);
				_push(`<section class="k-styles__section" data-v-9b62ea71><h2 data-v-9b62ea71>${ssrInterpolate(unref(__)("History"))}</h2>`);
				if (!data.value.revisions.length) _push(`<p class="k-muted k-small" data-v-9b62ea71>${ssrInterpolate(unref(__)("Nothing published yet: the site uses the theme as designed."))}</p>`);
				else _push(`<!---->`);
				_push(`<ol class="k-history" data-v-9b62ea71><!--[-->`);
				ssrRenderList(data.value.revisions, (r, i) => {
					_push(`<li data-v-9b62ea71><span data-v-9b62ea71>`);
					_push(ssrRenderComponent(unref(KTime), { value: r.created_at }, null, _parent));
					_push(`<span class="k-muted k-small" data-v-9b62ea71> · ${ssrInterpolate(r.reset ? unref(__)("Theme's styles") : r.author_name ?? unref(__)("Someone"))}</span></span>`);
					if (i === 0) _push(ssrRenderComponent(unref(KBadge), { tone: "success" }, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Live"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Live")), 1)];
						}),
						_: 2
					}, _parent));
					else _push(ssrRenderComponent(unref(KButton), {
						size: "sm",
						variant: "ghost",
						onClick: ($event) => restore(r)
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Restore"))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Restore")), 1)];
						}),
						_: 2
					}, _parent));
					_push(`</li>`);
				});
				_push(`<!--]--></ol>`);
				if (data.value.info) _push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					variant: "ghost",
					icon: "arrow-counter-clockwise",
					onClick: resetAll
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Back to the theme's styles"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Back to the theme's styles")), 1)];
					}),
					_: 1
				}, _parent));
				else _push(`<!---->`);
				_push(`</section></aside><section class="k-styles__preview"${ssrRenderAttr("aria-label", unref(__)("Preview"))} data-v-9b62ea71><div class="k-preview-bar" data-v-9b62ea71><div class="k-preview-bar__devices" role="radiogroup"${ssrRenderAttr("aria-label", unref(__)("Screen size"))} data-v-9b62ea71><!--[-->`);
				ssrRenderList(DEVICES, (d) => {
					_push(`<button type="button" role="radio"${ssrRenderAttr("aria-checked", device.value === d.id)} class="${ssrRenderClass({ "is-on": device.value === d.id })}"${ssrRenderAttr("title", d.label)} data-v-9b62ea71>`);
					_push(ssrRenderComponent(unref(KIcon), {
						name: d.icon,
						label: d.label
					}, null, _parent));
					_push(`</button>`);
				});
				_push(`<!--]--></div><form class="k-preview-bar__path" data-v-9b62ea71><label class="k-sr-only" for="k-preview-path" data-v-9b62ea71>${ssrInterpolate(unref(__)("Page to preview"))}</label><input id="k-preview-path"${ssrRenderAttr("value", pathInput.value)} class="k-input k-mono-input" spellcheck="false" autocomplete="off" data-v-9b62ea71>`);
				_push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					type: "submit"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Show"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Show")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`</form></div><div class="${ssrRenderClass([`is-${device.value}`, "k-preview-stage"])}" data-v-9b62ea71><iframe${ssrRenderAttr("src", frameSrc.value)}${ssrRenderAttr("title", unref(__)("Preview of the site"))} class="k-preview-frame" data-v-9b62ea71></iframe></div></section></div></div>`);
			} else _push(`<!---->`);
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/appearance/Styles.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Styles_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-9b62ea71"]]);
export { Styles_default as default };
