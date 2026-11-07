import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { computed, mergeProps, useSSRContext } from "vue";
import { ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderList } from "vue/server-renderer";
// core/resources/js/pages/blocks/Table.vue
var _sfc_main = {
	__name: "Table",
	__ssrInlineRender: true,
	props: {
		table: {
			type: Object,
			default: null
		},
		caption: {
			type: String,
			default: ""
		},
		striped: Boolean,
		side: Boolean,
		width: {
			type: String,
			default: "content"
		}
	},
	setup(__props) {
		const props = __props;
		const rows = computed(() => Array.isArray(props.table?.rows) ? props.table.rows.filter(Array.isArray) : []);
		const head = computed(() => props.table?.head && rows.value.length ? rows.value[0] : null);
		const body = computed(() => head.value ? rows.value.slice(1) : rows.value);
		return (_ctx, _push, _parent, _attrs) => {
			if (rows.value.length) {
				_push(`<div${ssrRenderAttrs(mergeProps({
					class: ["k-b-table", __props.striped && "is-striped"],
					"data-width": __props.width
				}, _attrs))} data-v-2b7736c2><div class="k-b-table__scroll" role="region"${ssrRenderAttr("aria-label", __props.caption || "Table")} tabindex="0" data-v-2b7736c2><table data-v-2b7736c2>`);
				if (__props.caption) _push(`<caption data-v-2b7736c2>${ssrInterpolate(__props.caption)}</caption>`);
				else _push(`<!---->`);
				if (head.value) {
					_push(`<thead data-v-2b7736c2><tr data-v-2b7736c2><!--[-->`);
					ssrRenderList(head.value, (cell, c) => {
						_push(`<th scope="col" data-v-2b7736c2>${ssrInterpolate(cell)}</th>`);
					});
					_push(`<!--]--></tr></thead>`);
				} else _push(`<!---->`);
				_push(`<tbody data-v-2b7736c2><!--[-->`);
				ssrRenderList(body.value, (row, r) => {
					_push(`<tr data-v-2b7736c2><!--[-->`);
					ssrRenderList(row, (cell, c) => {
						_push(`<!--[-->`);
						if (__props.side && c === 0) _push(`<th scope="row" data-v-2b7736c2>${ssrInterpolate(cell)}</th>`);
						else _push(`<td data-v-2b7736c2>${ssrInterpolate(cell)}</td>`);
						_push(`<!--]-->`);
					});
					_push(`<!--]--></tr>`);
				});
				_push(`<!--]--></tbody></table></div></div>`);
			} else _push(`<!---->`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/blocks/Table.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Table_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-2b7736c2"]]);
export { Table_default as default };
