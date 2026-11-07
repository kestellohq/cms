/* empty css                                  */
import { unref, useSSRContext } from "vue";
import { ssrRenderAttr, ssrRenderComponent } from "vue/server-renderer";
import { BlockTree, Head } from "@kestello/app";
// core/resources/js/pages/content/Template.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Site" }, {
	__name: "Template",
	__ssrInlineRender: true,
	props: {
		entry: {
			type: Object,
			required: true
		},
		template: {
			type: Object,
			required: true
		}
	},
	setup(__props) {
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: __props.entry.title }, null, _parent));
			_push(`<div class="k-template k-blocks"${ssrRenderAttr("data-template", __props.template.key)}>`);
			_push(ssrRenderComponent(unref(BlockTree), { blocks: __props.template.blocks }, null, _parent));
			_push(`</div><!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/content/Template.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
