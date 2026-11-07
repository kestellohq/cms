import { t as _plugin_vue_export_helper_default } from "../../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, openBlock, reactive, ref, renderList, toDisplayString, unref, useSSRContext, vModelText, watch, withCtx, withDirectives, withModifiers } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { usePage } from "@kestello/app";
import { ApiError, __, confirm, notify, useApi } from "@kestello/sdk";
import { KAlert, KBadge, KButton, KCard, KEmpty, KField, KInput, KPageHeader, KSpinner } from "@kestello/ui";
// core/resources/js/pages/admin/system/Api.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Admin" }, {
	__name: "Api",
	__ssrInlineRender: true,
	setup(__props) {
		/**
		* The API explorer: every endpoint the signed-in person may call (the
		* OpenAPI document is filtered by their role), with a form to try one and
		* the matching TypeScript client and curl calls.
		*/
		const page = usePage();
		const api = useApi();
		const base = computed(() => page.props.admin?.api ?? "/api/v1");
		const doc = ref(null);
		const loading = ref(true);
		const search = ref("");
		const selected = ref(null);
		api.get("/openapi.json").then((d) => {
			doc.value = d;
		}, (e) => notify.error(e)).finally(() => {
			loading.value = false;
		});
		const operations = computed(() => {
			const out = [];
			for (const [path, methods] of Object.entries(doc.value?.paths ?? {})) for (const [method, op] of Object.entries(methods)) out.push({
				...op,
				method: method.toUpperCase(),
				path,
				key: `${method} ${path}`,
				tag: op.tags?.[0] ?? "api"
			});
			return out;
		});
		const groups = computed(() => {
			const q = search.value.trim().toLowerCase();
			const map = /* @__PURE__ */ new Map();
			for (const op of operations.value) {
				if (q && !`${op.method} ${op.path} ${op.summary ?? ""} ${op.operationId}`.toLowerCase().includes(q)) continue;
				if (!map.has(op.tag)) map.set(op.tag, []);
				map.get(op.tag).push(op);
			}
			return [...map.entries()].sort((a, b) => a[0].localeCompare(b[0]));
		});
		const shown = computed(() => groups.value.reduce((n, [, ops]) => n + ops.length, 0));
		const values = reactive({
			path: {},
			query: {}
		});
		const bodyText = ref("");
		const result = ref(null);
		const sending = ref(false);
		const pathParams = computed(() => (selected.value?.parameters ?? []).filter((p) => p.in === "path"));
		const queryParams = computed(() => (selected.value?.parameters ?? []).filter((p) => p.in === "query"));
		const bodySchema = computed(() => selected.value?.requestBody?.content?.["application/json"]?.schema ?? null);
		function pick(op) {
			selected.value = op;
			values.path = Object.fromEntries((op.parameters ?? []).filter((p) => p.in === "path").map((p) => [p.name, ""]));
			values.query = Object.fromEntries((op.parameters ?? []).filter((p) => p.in === "query").map((p) => [p.name, p.schema?.default !== void 0 ? String(p.schema.default) : ""]));
			const schema = op.requestBody?.content?.["application/json"]?.schema;
			bodyText.value = schema ? JSON.stringify(example(schema), null, 2) : "";
			result.value = null;
		}
		/** A starting value for a JSON schema, so the body has the right keys. */
		function example(s, depth = 0) {
			if (!s || depth > 4) return null;
			if (s.default !== void 0) return s.default;
			if (s.enum) return s.enum[0];
			switch (Array.isArray(s.type) ? s.type[0] : s.type) {
				case "object": return Object.fromEntries(Object.entries(s.properties ?? {}).map(([k, v]) => [k, example(v, depth + 1)]));
				case "array": return [];
				case "integer":
				case "number": return s.minimum ?? 0;
				case "boolean": return false;
				case "string": return "";
				default: return null;
			}
		}
		const target = computed(() => {
			if (!selected.value) return "";
			const path = selected.value.path.replace(/\{([^}]+)\}/g, (_, name) => values.path[name] ? encodeURIComponent(values.path[name]) : `{${name}}`);
			const params = new URLSearchParams();
			for (const p of queryParams.value) {
				const v = String(values.query[p.name] ?? "").trim();
				if (v === "") continue;
				if (p.schema?.type === "array") v.split(",").map((x) => x.trim()).filter(Boolean).forEach((x) => params.append(`${p.name}[]`, x));
				else params.append(p.name, v);
			}
			const qs = params.toString();
			return path + (qs ? `?${qs}` : "");
		});
		const missing = computed(() => pathParams.value.some((p) => !String(values.path[p.name] ?? "").trim()));
		const unsafe = computed(() => selected.value && selected.value.method !== "GET");
		async function send() {
			let body;
			if (bodySchema.value) try {
				body = bodyText.value.trim() ? JSON.parse(bodyText.value) : void 0;
			} catch {
				notify.error(__("The body is not valid JSON."));
				return;
			}
			if (unsafe.value && !await confirm({
				title: __("Send {method} {path}?", {
					method: selected.value.method,
					path: target.value
				}),
				message: __("This runs on your site with your permissions and can change or delete content."),
				confirmLabel: __("Send"),
				danger: selected.value.method === "DELETE"
			})) return;
			sending.value = true;
			const started = performance.now();
			try {
				const data = await api.request(selected.value.method, target.value, { body });
				result.value = {
					ok: true,
					status: data === null ? 204 : 200,
					ms: Math.round(performance.now() - started),
					body: data
				};
			} catch (e) {
				if (!(e instanceof ApiError)) {
					notify.error(e);
					return;
				}
				result.value = {
					ok: false,
					status: e.status,
					ms: Math.round(performance.now() - started),
					body: e.problem
				};
			} finally {
				sending.value = false;
			}
		}
		const tsCall = computed(() => {
			const op = selected.value;
			if (!op) return "";
			const parts = pathParams.value.map((p) => `${p.name}: ${JSON.stringify(values.path[p.name] || (p.schema?.type === "integer" ? 1 : p.name))}`);
			const q = queryParams.value.filter((p) => String(values.query[p.name] ?? "").trim() !== "");
			if (q.length) parts.push(`query: { ${q.map((p) => `${p.name}: ${JSON.stringify(typed(p.schema, values.query[p.name]))}`).join(", ")} }`);
			if (bodySchema.value) parts.push(`body: ${(bodyText.value.trim() || "{}").replace(/\n/g, "\n    ")}`);
			return `const { data } = await kestello.${op.operationId}(${parts.length ? `{ ${parts.join(", ")} }` : ""});`;
		});
		const curl = computed(() => {
			const op = selected.value;
			if (!op) return "";
			const url = `${window.location.origin}${base.value.replace(/\/$/, "")}${target.value}`;
			const lines = [
				`curl -X ${op.method} '${url}'`,
				`  -H "Authorization: Bearer $KESTELLO_TOKEN"`,
				`  -H 'Accept: application/json'`
			];
			if (bodySchema.value) lines.push(`  -H 'Content-Type: application/json'`, `  -d '${(bodyText.value.trim() || "{}").replace(/'/g, "'\\''").replace(/\s*\n\s*/g, " ")}'`);
			return lines.join(" \\\n");
		});
		/** A form value as the type its schema wants, for the TypeScript snippet. */
		function typed(schema, value) {
			const v = String(value).trim();
			if (schema?.type === "integer" || schema?.type === "number") return Number.isNaN(Number(v)) ? v : Number(v);
			if (schema?.type === "boolean") return v === "true" || v === "1";
			if (schema?.type === "array") return v.split(",").map((x) => x.trim()).filter(Boolean);
			return v;
		}
		function copy(text) {
			navigator.clipboard?.writeText(text).then(() => notify.success(__("Copied.")), () => {});
		}
		watch(search, () => {
			if (selected.value && !groups.value.some(([, ops]) => ops.includes(selected.value))) selected.value = null;
		});
		const endpointsText = (n) => __("{n, plural, one {# endpoint} other {# endpoints}}", { n });
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(KPageHeader), {
				title: unref(__)("API"),
				description: unref(__)("Every endpoint you may call with your role, to read and try. Programs use the same endpoints with an access token from your profile.")
			}, {
				actions: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`<a class="k-btn"${ssrRenderAttr("href", `${base.value}/openapi.ts`)} download="kestello.ts" data-v-3f872753${_scopeId}>${ssrInterpolate(unref(__)("Download TypeScript client"))}</a><a class="k-btn k-btn--ghost"${ssrRenderAttr("href", `${base.value}/openapi.json`)} target="_blank" rel="noopener" data-v-3f872753${_scopeId}>${ssrInterpolate(unref(__)("OpenAPI document"))}</a>`);
					else return [createVNode("a", {
						class: "k-btn",
						href: `${base.value}/openapi.ts`,
						download: "kestello.ts"
					}, toDisplayString(unref(__)("Download TypeScript client")), 9, ["href"]), createVNode("a", {
						class: "k-btn k-btn--ghost",
						href: `${base.value}/openapi.json`,
						target: "_blank",
						rel: "noopener"
					}, toDisplayString(unref(__)("OpenAPI document")), 9, ["href"])];
				}),
				_: 1
			}, _parent));
			if (loading.value) {
				_push(`<div class="k-api__loading" data-v-3f872753>`);
				_push(ssrRenderComponent(unref(KSpinner), null, null, _parent));
				_push(`</div>`);
			} else {
				_push(`<div class="k-api" data-v-3f872753>`);
				_push(ssrRenderComponent(unref(KCard), {
					flush: "",
					class: "k-api__index"
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) {
							_push(`<div class="k-api__search" data-v-3f872753${_scopeId}><label class="k-field__label" for="k-api-search" data-v-3f872753${_scopeId}>${ssrInterpolate(unref(__)("Find an endpoint"))}</label>`);
							_push(ssrRenderComponent(unref(KInput), {
								id: "k-api-search",
								modelValue: search.value,
								"onUpdate:modelValue": ($event) => search.value = $event,
								type: "search",
								autocomplete: "off"
							}, null, _parent, _scopeId));
							_push(`<p class="k-muted k-small" data-v-3f872753${_scopeId}>${ssrInterpolate(endpointsText(shown.value))}</p></div><nav${ssrRenderAttr("aria-label", unref(__)("Endpoints"))} class="k-api__list" data-v-3f872753${_scopeId}><!--[-->`);
							ssrRenderList(groups.value, ([tag, ops]) => {
								_push(`<section data-v-3f872753${_scopeId}><h2 class="k-api__tag" data-v-3f872753${_scopeId}>${ssrInterpolate(tag)}</h2><!--[-->`);
								ssrRenderList(ops, (op) => {
									_push(`<button type="button" class="k-api__op"${ssrRenderAttr("aria-current", selected.value === op ? "true" : void 0)} data-v-3f872753${_scopeId}><span class="k-api__method"${ssrRenderAttr("data-method", op.method)} data-v-3f872753${_scopeId}>${ssrInterpolate(op.method)}</span><span class="k-api__op-text" data-v-3f872753${_scopeId}><span class="k-api__path" data-v-3f872753${_scopeId}>${ssrInterpolate(op.path)}</span>`);
									if (op.summary) _push(`<span class="k-muted k-small" data-v-3f872753${_scopeId}>${ssrInterpolate(op.summary)}</span>`);
									else _push(`<!---->`);
									_push(`</span></button>`);
								});
								_push(`<!--]--></section>`);
							});
							_push(`<!--]-->`);
							if (!shown.value) _push(`<p class="k-muted k-small k-api__none" data-v-3f872753${_scopeId}>${ssrInterpolate(unref(__)("No endpoint matches."))}</p>`);
							else _push(`<!---->`);
							_push(`</nav>`);
						} else return [createVNode("div", { class: "k-api__search" }, [
							createVNode("label", {
								class: "k-field__label",
								for: "k-api-search"
							}, toDisplayString(unref(__)("Find an endpoint")), 1),
							createVNode(unref(KInput), {
								id: "k-api-search",
								modelValue: search.value,
								"onUpdate:modelValue": ($event) => search.value = $event,
								type: "search",
								autocomplete: "off"
							}, null, 8, ["modelValue", "onUpdate:modelValue"]),
							createVNode("p", { class: "k-muted k-small" }, toDisplayString(endpointsText(shown.value)), 1)
						]), createVNode("nav", {
							"aria-label": unref(__)("Endpoints"),
							class: "k-api__list"
						}, [(openBlock(true), createBlock(Fragment, null, renderList(groups.value, ([tag, ops]) => {
							return openBlock(), createBlock("section", { key: tag }, [createVNode("h2", { class: "k-api__tag" }, toDisplayString(tag), 1), (openBlock(true), createBlock(Fragment, null, renderList(ops, (op) => {
								return openBlock(), createBlock("button", {
									key: op.key,
									type: "button",
									class: "k-api__op",
									"aria-current": selected.value === op ? "true" : void 0,
									onClick: ($event) => pick(op)
								}, [createVNode("span", {
									class: "k-api__method",
									"data-method": op.method
								}, toDisplayString(op.method), 9, ["data-method"]), createVNode("span", { class: "k-api__op-text" }, [createVNode("span", { class: "k-api__path" }, toDisplayString(op.path), 1), op.summary ? (openBlock(), createBlock("span", {
									key: 0,
									class: "k-muted k-small"
								}, toDisplayString(op.summary), 1)) : createCommentVNode("v-if", true)])], 8, ["aria-current", "onClick"]);
							}), 128))]);
						}), 128)), !shown.value ? (openBlock(), createBlock("p", {
							key: 0,
							class: "k-muted k-small k-api__none"
						}, toDisplayString(unref(__)("No endpoint matches.")), 1)) : createCommentVNode("v-if", true)], 8, ["aria-label"])];
					}),
					_: 1
				}, _parent));
				if (!selected.value) {
					_push(`<div class="k-api__detail" data-v-3f872753>`);
					_push(ssrRenderComponent(unref(KEmpty), {
						icon: "plugs-connected",
						title: unref(__)("Pick an endpoint")
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`${ssrInterpolate(unref(__)("Its parameters, a form to try it and the code to call it appear here."))}`);
							else return [createTextVNode(toDisplayString(unref(__)("Its parameters, a form to try it and the code to call it appear here.")), 1)];
						}),
						_: 1
					}, _parent));
					_push(`</div>`);
				} else {
					_push(`<div class="k-api__detail" data-v-3f872753>`);
					_push(ssrRenderComponent(unref(KCard), null, {
						title: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`<span class="k-api__method"${ssrRenderAttr("data-method", selected.value.method)} data-v-3f872753${_scopeId}>${ssrInterpolate(selected.value.method)}</span><code class="k-api__title-path" data-v-3f872753${_scopeId}>${ssrInterpolate(selected.value.path)}</code>`);
							else return [createVNode("span", {
								class: "k-api__method",
								"data-method": selected.value.method
							}, toDisplayString(selected.value.method), 9, ["data-method"]), createVNode("code", { class: "k-api__title-path" }, toDisplayString(selected.value.path), 1)];
						}),
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								if (selected.value.summary) _push(`<p class="k-api__summary" data-v-3f872753${_scopeId}>${ssrInterpolate(selected.value.summary)}</p>`);
								else _push(`<!---->`);
								_push(`<ul class="k-api__facts k-small" data-v-3f872753${_scopeId}>`);
								if (selected.value["x-kestello-capability"]) {
									_push(`<li data-v-3f872753${_scopeId}>${ssrInterpolate(unref(__)("Needs the permission"))} <code data-v-3f872753${_scopeId}>${ssrInterpolate(selected.value["x-kestello-capability"])}</code>`);
									if (selected.value.security?.[1]?.bearer?.length) {
										_push(`<!--[-->; ${ssrInterpolate(unref(__)("tokens need the scope"))} <!--[-->`);
										ssrRenderList(selected.value.security[1].bearer, (s) => {
											_push(`<code data-v-3f872753${_scopeId}>${ssrInterpolate(s)}</code>`);
										});
										_push(`<!--]--><!--]-->`);
									} else _push(`<!---->`);
									_push(`</li>`);
								} else _push(`<li data-v-3f872753${_scopeId}>${ssrInterpolate(unref(__)("Public: anyone can call it."))}</li>`);
								if (selected.value["x-kestello-recent-auth"]) _push(`<li data-v-3f872753${_scopeId}>${ssrInterpolate(unref(__)("Asks for your password again if you have not entered it recently."))}</li>`);
								else _push(`<!---->`);
								if (selected.value["x-kestello-owner"]) _push(`<li data-v-3f872753${_scopeId}>${ssrInterpolate(unref(__)("Added by {owner}.", { owner: selected.value["x-kestello-owner"] }))}</li>`);
								else _push(`<!---->`);
								_push(`</ul><form class="k-form" data-v-3f872753${_scopeId}><!--[-->`);
								ssrRenderList(pathParams.value, (p) => {
									_push(ssrRenderComponent(unref(KField), {
										key: `p-${p.name}`,
										label: p.name,
										required: ""
									}, {
										default: withCtx(({ id }, _push, _parent, _scopeId) => {
											if (_push) _push(ssrRenderComponent(unref(KInput), {
												id,
												modelValue: values.path[p.name],
												"onUpdate:modelValue": ($event) => values.path[p.name] = $event,
												inputmode: p.schema?.type === "integer" ? "numeric" : void 0,
												autocomplete: "off"
											}, null, _parent, _scopeId));
											else return [createVNode(unref(KInput), {
												id,
												modelValue: values.path[p.name],
												"onUpdate:modelValue": ($event) => values.path[p.name] = $event,
												inputmode: p.schema?.type === "integer" ? "numeric" : void 0,
												autocomplete: "off"
											}, null, 8, [
												"id",
												"modelValue",
												"onUpdate:modelValue",
												"inputmode"
											])];
										}),
										_: 2
									}, _parent, _scopeId));
								});
								_push(`<!--]--><!--[-->`);
								ssrRenderList(queryParams.value, (p) => {
									_push(ssrRenderComponent(unref(KField), {
										key: `q-${p.name}`,
										label: p.name,
										required: p.required && p.schema?.default === void 0,
										help: [
											p.schema?.description,
											p.schema?.enum ? unref(__)("One of: {values}", { values: p.schema.enum.join(", ") }) : "",
											p.schema?.type === "array" ? unref(__)("Separate several with commas.") : ""
										].filter(Boolean).join(" ")
									}, {
										default: withCtx(({ id }, _push, _parent, _scopeId) => {
											if (_push) _push(ssrRenderComponent(unref(KInput), {
												id,
												modelValue: values.query[p.name],
												"onUpdate:modelValue": ($event) => values.query[p.name] = $event,
												autocomplete: "off"
											}, null, _parent, _scopeId));
											else return [createVNode(unref(KInput), {
												id,
												modelValue: values.query[p.name],
												"onUpdate:modelValue": ($event) => values.query[p.name] = $event,
												autocomplete: "off"
											}, null, 8, [
												"id",
												"modelValue",
												"onUpdate:modelValue"
											])];
										}),
										_: 2
									}, _parent, _scopeId));
								});
								_push(`<!--]-->`);
								if (bodySchema.value) _push(ssrRenderComponent(unref(KField), { label: unref(__)("Body (JSON)") }, {
									default: withCtx(({ id }, _push, _parent, _scopeId) => {
										if (_push) _push(`<textarea${ssrRenderAttr("id", id)} class="k-input k-textarea k-api__body" rows="8" spellcheck="false" data-v-3f872753${_scopeId}>${ssrInterpolate(bodyText.value)}</textarea>`);
										else return [withDirectives(createVNode("textarea", {
											id,
											"onUpdate:modelValue": ($event) => bodyText.value = $event,
											class: "k-input k-textarea k-api__body",
											rows: "8",
											spellcheck: "false"
										}, null, 8, ["id", "onUpdate:modelValue"]), [[vModelText, bodyText.value]])];
									}),
									_: 1
								}, _parent, _scopeId));
								else _push(`<!---->`);
								_push(`<div class="k-api__send" data-v-3f872753${_scopeId}><code class="k-api__target k-truncate" data-v-3f872753${_scopeId}>${ssrInterpolate(selected.value.method)} ${ssrInterpolate(target.value)}</code>`);
								_push(ssrRenderComponent(unref(KButton), {
									type: "submit",
									variant: unsafe.value ? "danger" : "primary",
									loading: sending.value,
									disabled: missing.value
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(unref(__)("Send"))}`);
										else return [createTextVNode(toDisplayString(unref(__)("Send")), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`</div></form>`);
							} else return [
								selected.value.summary ? (openBlock(), createBlock("p", {
									key: 0,
									class: "k-api__summary"
								}, toDisplayString(selected.value.summary), 1)) : createCommentVNode("v-if", true),
								createVNode("ul", { class: "k-api__facts k-small" }, [
									selected.value["x-kestello-capability"] ? (openBlock(), createBlock("li", { key: 0 }, [
										createTextVNode(toDisplayString(unref(__)("Needs the permission")) + " ", 1),
										createVNode("code", null, toDisplayString(selected.value["x-kestello-capability"]), 1),
										selected.value.security?.[1]?.bearer?.length ? (openBlock(), createBlock(Fragment, { key: 0 }, [createTextVNode("; " + toDisplayString(unref(__)("tokens need the scope")) + " ", 1), (openBlock(true), createBlock(Fragment, null, renderList(selected.value.security[1].bearer, (s) => {
											return openBlock(), createBlock("code", { key: s }, toDisplayString(s), 1);
										}), 128))], 64)) : createCommentVNode("v-if", true)
									])) : (openBlock(), createBlock("li", { key: 1 }, toDisplayString(unref(__)("Public: anyone can call it.")), 1)),
									selected.value["x-kestello-recent-auth"] ? (openBlock(), createBlock("li", { key: 2 }, toDisplayString(unref(__)("Asks for your password again if you have not entered it recently.")), 1)) : createCommentVNode("v-if", true),
									selected.value["x-kestello-owner"] ? (openBlock(), createBlock("li", { key: 3 }, toDisplayString(unref(__)("Added by {owner}.", { owner: selected.value["x-kestello-owner"] })), 1)) : createCommentVNode("v-if", true)
								]),
								createVNode("form", {
									class: "k-form",
									onSubmit: withModifiers(send, ["prevent"])
								}, [
									(openBlock(true), createBlock(Fragment, null, renderList(pathParams.value, (p) => {
										return openBlock(), createBlock(unref(KField), {
											key: `p-${p.name}`,
											label: p.name,
											required: ""
										}, {
											default: withCtx(({ id }) => [createVNode(unref(KInput), {
												id,
												modelValue: values.path[p.name],
												"onUpdate:modelValue": ($event) => values.path[p.name] = $event,
												inputmode: p.schema?.type === "integer" ? "numeric" : void 0,
												autocomplete: "off"
											}, null, 8, [
												"id",
												"modelValue",
												"onUpdate:modelValue",
												"inputmode"
											])]),
											_: 2
										}, 1032, ["label"]);
									}), 128)),
									(openBlock(true), createBlock(Fragment, null, renderList(queryParams.value, (p) => {
										return openBlock(), createBlock(unref(KField), {
											key: `q-${p.name}`,
											label: p.name,
											required: p.required && p.schema?.default === void 0,
											help: [
												p.schema?.description,
												p.schema?.enum ? unref(__)("One of: {values}", { values: p.schema.enum.join(", ") }) : "",
												p.schema?.type === "array" ? unref(__)("Separate several with commas.") : ""
											].filter(Boolean).join(" ")
										}, {
											default: withCtx(({ id }) => [createVNode(unref(KInput), {
												id,
												modelValue: values.query[p.name],
												"onUpdate:modelValue": ($event) => values.query[p.name] = $event,
												autocomplete: "off"
											}, null, 8, [
												"id",
												"modelValue",
												"onUpdate:modelValue"
											])]),
											_: 2
										}, 1032, [
											"label",
											"required",
											"help"
										]);
									}), 128)),
									bodySchema.value ? (openBlock(), createBlock(unref(KField), {
										key: 0,
										label: unref(__)("Body (JSON)")
									}, {
										default: withCtx(({ id }) => [withDirectives(createVNode("textarea", {
											id,
											"onUpdate:modelValue": ($event) => bodyText.value = $event,
											class: "k-input k-textarea k-api__body",
											rows: "8",
											spellcheck: "false"
										}, null, 8, ["id", "onUpdate:modelValue"]), [[vModelText, bodyText.value]])]),
										_: 1
									}, 8, ["label"])) : createCommentVNode("v-if", true),
									createVNode("div", { class: "k-api__send" }, [createVNode("code", { class: "k-api__target k-truncate" }, toDisplayString(selected.value.method) + " " + toDisplayString(target.value), 1), createVNode(unref(KButton), {
										type: "submit",
										variant: unsafe.value ? "danger" : "primary",
										loading: sending.value,
										disabled: missing.value
									}, {
										default: withCtx(() => [createTextVNode(toDisplayString(unref(__)("Send")), 1)]),
										_: 1
									}, 8, [
										"variant",
										"loading",
										"disabled"
									])])
								], 32)
							];
						}),
						_: 1
					}, _parent));
					if (result.value) _push(ssrRenderComponent(unref(KCard), { title: unref(__)("Answer") }, {
						actions: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(ssrRenderComponent(unref(KBadge), {
									tone: result.value.ok ? "success" : "danger",
									dot: ""
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) _push(`${ssrInterpolate(result.value.status)}`);
										else return [createTextVNode(toDisplayString(result.value.status), 1)];
									}),
									_: 1
								}, _parent, _scopeId));
								_push(`<span class="k-muted k-small" data-v-3f872753${_scopeId}>${ssrInterpolate(result.value.ms)} ms</span>`);
							} else return [createVNode(unref(KBadge), {
								tone: result.value.ok ? "success" : "danger",
								dot: ""
							}, {
								default: withCtx(() => [createTextVNode(toDisplayString(result.value.status), 1)]),
								_: 1
							}, 8, ["tone"]), createVNode("span", { class: "k-muted k-small" }, toDisplayString(result.value.ms) + " ms", 1)];
						}),
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								if (!result.value.ok && result.value.body?.errors) _push(ssrRenderComponent(unref(KAlert), {
									tone: "danger",
									title: result.value.body.detail ?? result.value.body.title
								}, {
									default: withCtx((_, _push, _parent, _scopeId) => {
										if (_push) {
											_push(`<ul class="k-api__errors" data-v-3f872753${_scopeId}><!--[-->`);
											ssrRenderList(result.value.body.errors, (messages, field) => {
												_push(`<li data-v-3f872753${_scopeId}><code data-v-3f872753${_scopeId}>${ssrInterpolate(field)}</code>: ${ssrInterpolate(messages.join(" "))}</li>`);
											});
											_push(`<!--]--></ul>`);
										} else return [createVNode("ul", { class: "k-api__errors" }, [(openBlock(true), createBlock(Fragment, null, renderList(result.value.body.errors, (messages, field) => {
											return openBlock(), createBlock("li", { key: field }, [createVNode("code", null, toDisplayString(field), 1), createTextVNode(": " + toDisplayString(messages.join(" ")), 1)]);
										}), 128))])];
									}),
									_: 1
								}, _parent, _scopeId));
								else _push(`<!---->`);
								_push(`<pre class="k-pre k-api__pre" data-v-3f872753${_scopeId}>${ssrInterpolate(result.value.body === null ? unref(__)("(no content)") : JSON.stringify(result.value.body, null, 2))}</pre>`);
							} else return [!result.value.ok && result.value.body?.errors ? (openBlock(), createBlock(unref(KAlert), {
								key: 0,
								tone: "danger",
								title: result.value.body.detail ?? result.value.body.title
							}, {
								default: withCtx(() => [createVNode("ul", { class: "k-api__errors" }, [(openBlock(true), createBlock(Fragment, null, renderList(result.value.body.errors, (messages, field) => {
									return openBlock(), createBlock("li", { key: field }, [createVNode("code", null, toDisplayString(field), 1), createTextVNode(": " + toDisplayString(messages.join(" ")), 1)]);
								}), 128))])]),
								_: 1
							}, 8, ["title"])) : createCommentVNode("v-if", true), createVNode("pre", { class: "k-pre k-api__pre" }, toDisplayString(result.value.body === null ? unref(__)("(no content)") : JSON.stringify(result.value.body, null, 2)), 1)];
						}),
						_: 1
					}, _parent));
					else _push(`<!---->`);
					_push(ssrRenderComponent(unref(KCard), { title: unref(__)("Call it from code") }, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`<h3 class="k-api__h" data-v-3f872753${_scopeId}>${ssrInterpolate(unref(__)("TypeScript client"))}</h3><div class="k-api__code" data-v-3f872753${_scopeId}><pre class="k-pre" data-v-3f872753${_scopeId}>${ssrInterpolate(tsCall.value)}</pre>`);
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "clipboard",
									"aria-label": unref(__)("Copy"),
									onClick: ($event) => copy(tsCall.value)
								}, null, _parent, _scopeId));
								_push(`</div><h3 class="k-api__h" data-v-3f872753${_scopeId}>curl</h3><div class="k-api__code" data-v-3f872753${_scopeId}><pre class="k-pre" data-v-3f872753${_scopeId}>${ssrInterpolate(curl.value)}</pre>`);
								_push(ssrRenderComponent(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "clipboard",
									"aria-label": unref(__)("Copy"),
									onClick: ($event) => copy(curl.value)
								}, null, _parent, _scopeId));
								_push(`</div><p class="k-muted k-small" data-v-3f872753${_scopeId}>${ssrInterpolate(unref(__)("Create the access token under Your profile, with the scopes listed above."))}</p>`);
							} else return [
								createVNode("h3", { class: "k-api__h" }, toDisplayString(unref(__)("TypeScript client")), 1),
								createVNode("div", { class: "k-api__code" }, [createVNode("pre", { class: "k-pre" }, toDisplayString(tsCall.value), 1), createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "clipboard",
									"aria-label": unref(__)("Copy"),
									onClick: ($event) => copy(tsCall.value)
								}, null, 8, ["aria-label", "onClick"])]),
								createVNode("h3", { class: "k-api__h" }, "curl"),
								createVNode("div", { class: "k-api__code" }, [createVNode("pre", { class: "k-pre" }, toDisplayString(curl.value), 1), createVNode(unref(KButton), {
									size: "sm",
									variant: "ghost",
									icon: "clipboard",
									"aria-label": unref(__)("Copy"),
									onClick: ($event) => copy(curl.value)
								}, null, 8, ["aria-label", "onClick"])]),
								createVNode("p", { class: "k-muted k-small" }, toDisplayString(unref(__)("Create the access token under Your profile, with the scopes listed above.")), 1)
							];
						}),
						_: 1
					}, _parent));
					_push(`</div>`);
				}
				_push(`</div>`);
			}
			_push(`<!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/admin/system/Api.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Api_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-3f872753"]]);
export { Api_default as default };
