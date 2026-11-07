import { createTextVNode, unref, useSSRContext, withCtx } from "vue";
import { ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import { Head, Link } from "@kestello/app";
// content/themes/base/resources/js/pages/About.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Site" }, {
	__name: "About",
	__ssrInlineRender: true,
	props: { kestelloVersion: {
		type: String,
		default: ""
	} },
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: "About" }, null, _parent));
			_push(`<div class="site-wrap"><div class="wall"><section class="plate plate--light page p-story" style="grid-row:1 / span 7;padding:48px 56px;" aria-labelledby="about"><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><h1 id="about">About this site</h1><div class="prose"><p class="lead">This site runs on Kestello, a self-hosted content management system you can install, run and update without a developer.</p><p> The front page shows sample content for a fictional bakery. It demonstrates the base theme: enamel-sign plates on a modular wall, coloured from a single setting. Replace it with your own words, hours and address. </p><h2>Make it yours</h2><ul><li>Sign in to the admin to manage people, settings and the health of the site.</li><li>Change the enamel colour, type and corners in Appearance → Styles, or override <code>--plate</code> in a child theme.</li><li>Add features with plugins and change the look with themes, without touching core.</li></ul></div></section><section class="plate plate--deep p-contact" style="grid-row:1 / span 5;" aria-labelledby="running"><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><i class="plate__screw"></i><h2 id="running">Running Kestello ${ssrInterpolate(__props.kestelloVersion)}</h2><p>Open the admin to check your site&#39;s health and settings.</p>`);
			_push(ssrRenderComponent(unref(Link), {
				href: "/admin",
				class: "plate-action",
				style: { "align-self": "flex-start" }
			}, {
				default: withCtx((_, _push, _parent, _scopeId) => {
					if (_push) _push(`Open the admin`);
					else return [createTextVNode("Open the admin")];
				}),
				_: 1
			}, _parent));
			_push(`</section></div></div><!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/About.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
