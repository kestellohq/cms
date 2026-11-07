import { t as _sfc_main$1 } from "../../chunks/InstallerLayout-puuck5dd.mjs";
import { t as _sfc_main$2 } from "../../chunks/CsrfField-DaiFH5aw.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, mergeProps, openBlock, ref, renderList, toDisplayString, unref, useSSRContext, vModelRadio, withCtx, withDirectives } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrLooseEqual, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { KAlert } from "@kestello/ui";
// core/resources/js/pages/installer/Database.vue
var _sfc_main = {
	__name: "Database",
	__ssrInlineRender: true,
	props: {
		step: Number,
		db: {
			type: Object,
			required: true
		},
		drivers: {
			type: Array,
			default: () => []
		},
		error: {
			type: String,
			default: null
		}
	},
	setup(__props) {
		const props = __props;
		const NOTES = {
			mysql: "What most hosting offers.",
			pgsql: "If your host or server has it.",
			sqlite: "One file, no database server. For small sites and testing."
		};
		const driver = ref(props.db.driver);
		const server = computed(() => driver.value !== "sqlite");
		const defaultPort = computed(() => driver.value === "pgsql" ? "5432" : "3306");
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				step: __props.step,
				title: "Database",
				description: "Choose the kind of database, then enter the details from your hosting control panel."
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form method="POST" action="/install" class="k-setup__form"${_scopeId}>`);
						_push(ssrRenderComponent(_sfc_main$2, null, null, _parent, _scopeId));
						_push(`<input type="hidden" name="step" value="2"${_scopeId}>`);
						if (__props.error) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`${ssrInterpolate(__props.error)}`);
								else return [createTextVNode(toDisplayString(__props.error), 1)];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						if (__props.drivers.length === 0) _push(ssrRenderComponent(unref(KAlert), { tone: "danger" }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`No supported PDO driver is installed (pdo_mysql, pdo_pgsql or pdo_sqlite).`);
								else return [createTextVNode("No supported PDO driver is installed (pdo_mysql, pdo_pgsql or pdo_sqlite).")];
							}),
							_: 1
						}, _parent, _scopeId));
						else _push(`<!---->`);
						_push(`<fieldset class="k-setup__choices"${_scopeId}><legend${_scopeId}>Database</legend><!--[-->`);
						ssrRenderList(__props.drivers, (d) => {
							_push(`<label class="k-setup__choice"${_scopeId}><input${ssrIncludeBooleanAttr(ssrLooseEqual(driver.value, d.value)) ? " checked" : ""} type="radio" name="db_driver"${ssrRenderAttr("value", d.value)}${_scopeId}><strong${_scopeId}>${ssrInterpolate(d.label)}</strong><span${_scopeId}>${ssrInterpolate(NOTES[d.value])}</span></label>`);
						});
						_push(`<!--]--></fieldset>`);
						if (server.value) {
							_push(`<!--[--><div class="k-setup__row k-setup__row--port"${_scopeId}><div class="k-field"${_scopeId}><label class="k-field__label" for="db_host"${_scopeId}>Host</label><input id="db_host" type="text" name="db_host" class="k-input"${ssrRenderAttr("value", __props.db.host)} required aria-describedby="db_host_help"${_scopeId}><p id="db_host_help" class="k-field__help"${_scopeId}>Usually localhost. Your control panel shows it when it is something else.</p></div><div class="k-field"${_scopeId}><label class="k-field__label" for="db_port"${_scopeId}>Port</label><input id="db_port" type="number" name="db_port" class="k-input"${ssrRenderAttr("value", __props.db.port || defaultPort.value)}${_scopeId}></div></div><div class="k-field"${_scopeId}><label class="k-field__label" for="db_name"${_scopeId}>Database name</label><input id="db_name" type="text" name="db_name" class="k-input"${ssrRenderAttr("value", __props.db.name)} required pattern="[A-Za-z0-9_]{1,64}" aria-describedby="db_name_help"${_scopeId}><p id="db_name_help" class="k-field__help"${_scopeId}>Kestello creates it if it does not exist and the user is allowed to.</p></div><div class="k-setup__row"${_scopeId}><div class="k-field"${_scopeId}><label class="k-field__label" for="db_user"${_scopeId}>Database user</label><input id="db_user" type="text" name="db_user" class="k-input"${ssrRenderAttr("value", __props.db.user)} required autocomplete="off"${_scopeId}></div><div class="k-field"${_scopeId}><label class="k-field__label" for="db_pass"${_scopeId}>Password</label><input id="db_pass" type="password" name="db_pass" class="k-input" autocomplete="new-password"${ssrRenderAttr("aria-describedby", __props.db.passwordSet ? "db_pass_help" : void 0)}${_scopeId}>`);
							if (__props.db.passwordSet) _push(`<p id="db_pass_help" class="k-field__help"${_scopeId}>Saved. Leave it empty to keep it.</p>`);
							else _push(`<!---->`);
							_push(`</div></div><!--]-->`);
						} else _push(`<div class="k-field"${_scopeId}><label class="k-field__label" for="db_name"${_scopeId}>File name</label><input id="db_name" type="text" name="db_name" class="k-input"${ssrRenderAttr("value", __props.db.name)} required pattern="[A-Za-z0-9_]{1,64}" aria-describedby="db_name_help"${_scopeId}><p id="db_name_help" class="k-field__help"${_scopeId}>Letters, numbers and underscores. The file is kept in storage/database, out of reach of visitors.</p></div>`);
						_push(`<div class="k-setup__actions"${_scopeId}><a href="/install?step=1" class="k-btn k-btn--secondary k-btn--md"${_scopeId}>Back</a><button type="submit" class="k-btn k-btn--primary k-btn--md"${ssrIncludeBooleanAttr(__props.drivers.length === 0) ? " disabled" : ""}${_scopeId}>Connect and continue</button></div></form>`);
					} else return [createVNode("form", {
						method: "POST",
						action: "/install",
						class: "k-setup__form"
					}, [
						createVNode(_sfc_main$2),
						createVNode("input", {
							type: "hidden",
							name: "step",
							value: "2"
						}),
						__props.error ? (openBlock(), createBlock(unref(KAlert), {
							key: 0,
							tone: "danger"
						}, {
							default: withCtx(() => [createTextVNode(toDisplayString(__props.error), 1)]),
							_: 1
						})) : createCommentVNode("v-if", true),
						__props.drivers.length === 0 ? (openBlock(), createBlock(unref(KAlert), {
							key: 1,
							tone: "danger"
						}, {
							default: withCtx(() => [createTextVNode("No supported PDO driver is installed (pdo_mysql, pdo_pgsql or pdo_sqlite).")]),
							_: 1
						})) : createCommentVNode("v-if", true),
						createVNode("fieldset", { class: "k-setup__choices" }, [createVNode("legend", null, "Database"), (openBlock(true), createBlock(Fragment, null, renderList(__props.drivers, (d) => {
							return openBlock(), createBlock("label", {
								key: d.value,
								class: "k-setup__choice"
							}, [
								withDirectives(createVNode("input", {
									"onUpdate:modelValue": ($event) => driver.value = $event,
									type: "radio",
									name: "db_driver",
									value: d.value
								}, null, 8, ["onUpdate:modelValue", "value"]), [[vModelRadio, driver.value]]),
								createVNode("strong", null, toDisplayString(d.label), 1),
								createVNode("span", null, toDisplayString(NOTES[d.value]), 1)
							]);
						}), 128))]),
						server.value ? (openBlock(), createBlock(Fragment, { key: 2 }, [
							createVNode("div", { class: "k-setup__row k-setup__row--port" }, [createVNode("div", { class: "k-field" }, [
								createVNode("label", {
									class: "k-field__label",
									for: "db_host"
								}, "Host"),
								createVNode("input", {
									id: "db_host",
									type: "text",
									name: "db_host",
									class: "k-input",
									value: __props.db.host,
									required: "",
									"aria-describedby": "db_host_help"
								}, null, 8, ["value"]),
								createVNode("p", {
									id: "db_host_help",
									class: "k-field__help"
								}, "Usually localhost. Your control panel shows it when it is something else.")
							]), createVNode("div", { class: "k-field" }, [createVNode("label", {
								class: "k-field__label",
								for: "db_port"
							}, "Port"), (openBlock(), createBlock("input", {
								id: "db_port",
								key: defaultPort.value,
								type: "number",
								name: "db_port",
								class: "k-input",
								value: __props.db.port || defaultPort.value
							}, null, 8, ["value"]))])]),
							createVNode("div", { class: "k-field" }, [
								createVNode("label", {
									class: "k-field__label",
									for: "db_name"
								}, "Database name"),
								createVNode("input", {
									id: "db_name",
									type: "text",
									name: "db_name",
									class: "k-input",
									value: __props.db.name,
									required: "",
									pattern: "[A-Za-z0-9_]{1,64}",
									"aria-describedby": "db_name_help"
								}, null, 8, ["value"]),
								createVNode("p", {
									id: "db_name_help",
									class: "k-field__help"
								}, "Kestello creates it if it does not exist and the user is allowed to.")
							]),
							createVNode("div", { class: "k-setup__row" }, [createVNode("div", { class: "k-field" }, [createVNode("label", {
								class: "k-field__label",
								for: "db_user"
							}, "Database user"), createVNode("input", {
								id: "db_user",
								type: "text",
								name: "db_user",
								class: "k-input",
								value: __props.db.user,
								required: "",
								autocomplete: "off"
							}, null, 8, ["value"])]), createVNode("div", { class: "k-field" }, [
								createVNode("label", {
									class: "k-field__label",
									for: "db_pass"
								}, "Password"),
								createVNode("input", {
									id: "db_pass",
									type: "password",
									name: "db_pass",
									class: "k-input",
									autocomplete: "new-password",
									"aria-describedby": __props.db.passwordSet ? "db_pass_help" : void 0
								}, null, 8, ["aria-describedby"]),
								__props.db.passwordSet ? (openBlock(), createBlock("p", {
									key: 0,
									id: "db_pass_help",
									class: "k-field__help"
								}, "Saved. Leave it empty to keep it.")) : createCommentVNode("v-if", true)
							])])
						], 64)) : (openBlock(), createBlock("div", {
							key: 3,
							class: "k-field"
						}, [
							createVNode("label", {
								class: "k-field__label",
								for: "db_name"
							}, "File name"),
							createVNode("input", {
								id: "db_name",
								type: "text",
								name: "db_name",
								class: "k-input",
								value: __props.db.name,
								required: "",
								pattern: "[A-Za-z0-9_]{1,64}",
								"aria-describedby": "db_name_help"
							}, null, 8, ["value"]),
							createVNode("p", {
								id: "db_name_help",
								class: "k-field__help"
							}, "Letters, numbers and underscores. The file is kept in storage/database, out of reach of visitors.")
						])),
						createVNode("div", { class: "k-setup__actions" }, [createVNode("a", {
							href: "/install?step=1",
							class: "k-btn k-btn--secondary k-btn--md"
						}, "Back"), createVNode("button", {
							type: "submit",
							class: "k-btn k-btn--primary k-btn--md",
							disabled: __props.drivers.length === 0
						}, "Connect and continue", 8, ["disabled"])])
					])];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/installer/Database.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
