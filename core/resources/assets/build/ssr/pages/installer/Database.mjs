import { t as _sfc_main$1 } from "../../chunks/InstallerLayout-7a6f5LEx.mjs";
import { t as _sfc_main$2 } from "../../chunks/CsrfField-DaiFH5aw.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, createVNode, mergeProps, openBlock, ref, renderList, toDisplayString, unref, useSSRContext, vModelSelect, withCtx, withDirectives } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrLooseContain, ssrLooseEqual, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
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
		const driver = ref(__props.db.driver);
		const server = computed(() => driver.value !== "sqlite");
		const defaultPort = computed(() => driver.value === "pgsql" ? "5432" : "3306");
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				step: __props.step,
				title: "Database",
				description: "Choose a database and enter the details your host gave you."
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<form method="POST" action="/install" class="k-auth__form"${_scopeId}>`);
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
						_push(`<div class="k-field"${_scopeId}><label class="k-field__label" for="db_driver"${_scopeId}>Database</label><select id="db_driver" name="db_driver" class="k-input k-select"${_scopeId}><!--[-->`);
						ssrRenderList(__props.drivers, (d) => {
							_push(`<option${ssrRenderAttr("value", d.value)}${ssrIncludeBooleanAttr(Array.isArray(driver.value) ? ssrLooseContain(driver.value, d.value) : ssrLooseEqual(driver.value, d.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(d.label)}</option>`);
						});
						_push(`<!--]--></select>`);
						if (!server.value) _push(`<p class="k-field__help"${_scopeId}>A single file in storage/database/, outside the web root. Best for small sites and testing.</p>`);
						else _push(`<!---->`);
						_push(`</div>`);
						if (server.value) _push(`<div class="k-install__row"${_scopeId}><div class="k-field"${_scopeId}><label class="k-field__label" for="db_host"${_scopeId}>Host</label><input id="db_host" type="text" name="db_host" class="k-input"${ssrRenderAttr("value", __props.db.host)} required aria-describedby="db_host_help"${_scopeId}><p id="db_host_help" class="k-field__help"${_scopeId}>Usually &quot;localhost&quot;. Your host&#39;s control panel shows it if it is different.</p></div><div class="k-field k-install__narrow"${_scopeId}><label class="k-field__label" for="db_port"${_scopeId}>Port</label><input id="db_port" type="number" name="db_port" class="k-input"${ssrRenderAttr("value", __props.db.port || defaultPort.value)}${ssrRenderAttr("placeholder", defaultPort.value)}${_scopeId}></div></div>`);
						else _push(`<!---->`);
						_push(`<div class="k-field"${_scopeId}><label class="k-field__label" for="db_name"${_scopeId}>${ssrInterpolate(server.value ? "Database name" : "File name")}</label><input id="db_name" type="text" name="db_name" class="k-input"${ssrRenderAttr("value", __props.db.name)} required pattern="[A-Za-z0-9_]{1,64}" aria-describedby="db_name_help"${_scopeId}><p id="db_name_help" class="k-field__help"${_scopeId}>${ssrInterpolate(server.value ? "Created if it does not exist and the user is allowed to create it." : "Letters, numbers and underscores. The file is stored in storage/database.")}</p></div>`);
						if (server.value) _push(`<div class="k-install__row"${_scopeId}><div class="k-field"${_scopeId}><label class="k-field__label" for="db_user"${_scopeId}>Username</label><input id="db_user" type="text" name="db_user" class="k-input"${ssrRenderAttr("value", __props.db.user)} required autocomplete="off"${_scopeId}></div><div class="k-field"${_scopeId}><label class="k-field__label" for="db_pass"${_scopeId}>Password</label><input id="db_pass" type="password" name="db_pass" class="k-input" autocomplete="new-password"${ssrRenderAttr("placeholder", __props.db.passwordSet ? "Saved (leave blank to keep)" : "")}${_scopeId}></div></div>`);
						else _push(`<!---->`);
						_push(`<div class="k-install__actions"${_scopeId}><a href="/install?step=1" class="k-btn k-btn--secondary k-btn--md"${_scopeId}>Back</a><button type="submit" class="k-btn k-btn--primary k-btn--md"${ssrIncludeBooleanAttr(__props.drivers.length === 0) ? " disabled" : ""}${_scopeId}>Test and continue</button></div></form>`);
					} else return [createVNode("form", {
						method: "POST",
						action: "/install",
						class: "k-auth__form"
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
						createVNode("div", { class: "k-field" }, [
							createVNode("label", {
								class: "k-field__label",
								for: "db_driver"
							}, "Database"),
							withDirectives(createVNode("select", {
								id: "db_driver",
								"onUpdate:modelValue": ($event) => driver.value = $event,
								name: "db_driver",
								class: "k-input k-select"
							}, [(openBlock(true), createBlock(Fragment, null, renderList(__props.drivers, (d) => {
								return openBlock(), createBlock("option", {
									key: d.value,
									value: d.value
								}, toDisplayString(d.label), 9, ["value"]);
							}), 128))], 8, ["onUpdate:modelValue"]), [[vModelSelect, driver.value]]),
							!server.value ? (openBlock(), createBlock("p", {
								key: 0,
								class: "k-field__help"
							}, "A single file in storage/database/, outside the web root. Best for small sites and testing.")) : createCommentVNode("v-if", true)
						]),
						server.value ? (openBlock(), createBlock("div", {
							key: 2,
							class: "k-install__row"
						}, [createVNode("div", { class: "k-field" }, [
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
							}, "Usually \"localhost\". Your host's control panel shows it if it is different.")
						]), createVNode("div", { class: "k-field k-install__narrow" }, [createVNode("label", {
							class: "k-field__label",
							for: "db_port"
						}, "Port"), createVNode("input", {
							id: "db_port",
							type: "number",
							name: "db_port",
							class: "k-input",
							value: __props.db.port || defaultPort.value,
							placeholder: defaultPort.value
						}, null, 8, ["value", "placeholder"])])])) : createCommentVNode("v-if", true),
						createVNode("div", { class: "k-field" }, [
							createVNode("label", {
								class: "k-field__label",
								for: "db_name"
							}, toDisplayString(server.value ? "Database name" : "File name"), 1),
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
							}, toDisplayString(server.value ? "Created if it does not exist and the user is allowed to create it." : "Letters, numbers and underscores. The file is stored in storage/database."), 1)
						]),
						server.value ? (openBlock(), createBlock("div", {
							key: 3,
							class: "k-install__row"
						}, [createVNode("div", { class: "k-field" }, [createVNode("label", {
							class: "k-field__label",
							for: "db_user"
						}, "Username"), createVNode("input", {
							id: "db_user",
							type: "text",
							name: "db_user",
							class: "k-input",
							value: __props.db.user,
							required: "",
							autocomplete: "off"
						}, null, 8, ["value"])]), createVNode("div", { class: "k-field" }, [createVNode("label", {
							class: "k-field__label",
							for: "db_pass"
						}, "Password"), createVNode("input", {
							id: "db_pass",
							type: "password",
							name: "db_pass",
							class: "k-input",
							autocomplete: "new-password",
							placeholder: __props.db.passwordSet ? "Saved (leave blank to keep)" : ""
						}, null, 8, ["placeholder"])])])) : createCommentVNode("v-if", true),
						createVNode("div", { class: "k-install__actions" }, [createVNode("a", {
							href: "/install?step=1",
							class: "k-btn k-btn--secondary k-btn--md"
						}, "Back"), createVNode("button", {
							type: "submit",
							class: "k-btn k-btn--primary k-btn--md",
							disabled: __props.drivers.length === 0
						}, "Test and continue", 8, ["disabled"])])
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
