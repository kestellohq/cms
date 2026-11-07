import { t as _sfc_main$1 } from "../../chunks/InstallerLayout-puuck5dd.mjs";
import { Fragment, createBlock, createCommentVNode, createTextVNode, createVNode, mergeProps, openBlock, ref, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderComponent } from "vue/server-renderer";
import { KIcon } from "@kestello/ui";
// core/resources/js/pages/installer/Complete.vue
var _sfc_main = {
	__name: "Complete",
	__ssrInlineRender: true,
	props: {
		site: {
			type: Object,
			default: () => ({
				name: "",
				url: ""
			})
		},
		cron: {
			type: String,
			default: ""
		}
	},
	setup(__props) {
		const props = __props;
		const copied = ref(false);
		async function copy() {
			try {
				await navigator.clipboard.writeText(props.cron);
				copied.value = true;
				setTimeout(() => copied.value = false, 2e3);
			} catch {
				copied.value = false;
			}
		}
		return (_ctx, _push, _parent, _attrs) => {
			_push(ssrRenderComponent(_sfc_main$1, mergeProps({
				step: 6,
				title: "Kestello is installed",
				description: "Three things to do now, and your site is ready for visitors."
			}, _attrs), {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) {
						_push(`<div class="k-setup__done"${_scopeId}>`);
						_push(ssrRenderComponent(unref(KIcon), { name: "check-circle" }, null, _parent, _scopeId));
						_push(`<p${_scopeId}>`);
						if (__props.site.name) _push(`<span${_scopeId}>${ssrInterpolate(__props.site.name)} is up</span>`);
						else _push(`<span${_scopeId}>Your site is up</span>`);
						if (__props.site.url) _push(`<!--[--> at <a${ssrRenderAttr("href", __props.site.url)} class="k-link k-setup__address"${_scopeId}>${ssrInterpolate(__props.site.url)}</a><!--]-->`);
						else _push(`<!---->`);
						_push(`. </p></div><ol class="k-setup__next"${_scopeId}><li${_scopeId}><div${_scopeId}><strong${_scopeId}>Sign in</strong><p${_scopeId}>Use the administrator account you just made. The dashboard shows what to set up first, such as email.</p></div></li><li${_scopeId}><div${_scopeId}><strong${_scopeId}>Add a cron job</strong><p${_scopeId}> Background work (email, image sizes, scheduled posts, backups) runs on time with a cron job every minute. Add this in your control panel under Cron Jobs: </p>`);
						if (__props.cron) _push(`<div class="k-setup__command"${_scopeId}><code${_scopeId}>${ssrInterpolate(__props.cron)}</code><button type="button" class="k-btn k-btn--secondary k-btn--md"${_scopeId}>${ssrInterpolate(copied.value ? "Copied" : "Copy")}</button></div>`);
						else _push(`<!---->`);
						_push(`</div></li><li${_scopeId}><div${_scopeId}><strong${_scopeId}>Before you go live</strong><p${_scopeId}>Turn on HTTPS for the domain, open System → Site Health, and make a first backup under System → Backups.</p></div></li></ol><div class="k-setup__actions"${_scopeId}><a href="/login" class="k-btn k-btn--primary k-btn--md"${_scopeId}>Sign in</a></div>`);
					} else return [
						createVNode("div", { class: "k-setup__done" }, [createVNode(unref(KIcon), { name: "check-circle" }), createVNode("p", null, [
							__props.site.name ? (openBlock(), createBlock("span", { key: 0 }, toDisplayString(__props.site.name) + " is up", 1)) : (openBlock(), createBlock("span", { key: 1 }, "Your site is up")),
							__props.site.url ? (openBlock(), createBlock(Fragment, { key: 2 }, [createTextVNode(" at "), createVNode("a", {
								href: __props.site.url,
								class: "k-link k-setup__address"
							}, toDisplayString(__props.site.url), 9, ["href"])], 64)) : createCommentVNode("v-if", true),
							createTextVNode(". ")
						])]),
						createVNode("ol", { class: "k-setup__next" }, [
							createVNode("li", null, [createVNode("div", null, [createVNode("strong", null, "Sign in"), createVNode("p", null, "Use the administrator account you just made. The dashboard shows what to set up first, such as email.")])]),
							createVNode("li", null, [createVNode("div", null, [
								createVNode("strong", null, "Add a cron job"),
								createVNode("p", null, " Background work (email, image sizes, scheduled posts, backups) runs on time with a cron job every minute. Add this in your control panel under Cron Jobs: "),
								__props.cron ? (openBlock(), createBlock("div", {
									key: 0,
									class: "k-setup__command"
								}, [createVNode("code", null, toDisplayString(__props.cron), 1), createVNode("button", {
									type: "button",
									class: "k-btn k-btn--secondary k-btn--md",
									onClick: copy
								}, toDisplayString(copied.value ? "Copied" : "Copy"), 1)])) : createCommentVNode("v-if", true)
							])]),
							createVNode("li", null, [createVNode("div", null, [createVNode("strong", null, "Before you go live"), createVNode("p", null, "Turn on HTTPS for the domain, open System → Site Health, and make a first backup under System → Backups.")])])
						]),
						createVNode("div", { class: "k-setup__actions" }, [createVNode("a", {
							href: "/login",
							class: "k-btn k-btn--primary k-btn--md"
						}, "Sign in")])
					];
				}),
				_: 1
			}, _parent));
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/installer/Complete.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
