import { computed, createTextVNode, mergeModels, mergeProps, nextTick, ref, toDisplayString, unref, useModel, useSSRContext, withCtx } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderAttrs, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { __ } from "@kestello/sdk";
import { KButton, KSwitch } from "@kestello/ui";
/** A new table: a heading row and two rows, three columns, all empty. */
var blankTable = (columns = 3, rows = 3) => ({
	head: true,
	rows: Array.from({ length: rows }, () => Array(columns).fill(""))
});
/** Any value as a well-formed table: missing cells filled, long rows cut, at least one cell. */
function tidy(value) {
	const rows = Array.isArray(value?.rows) ? value.rows.filter(Array.isArray) : [];
	if (!rows.length) return blankTable();
	const width = Math.min(12, Math.max(1, ...rows.map((r) => r.length)));
	return {
		head: !!value.head,
		rows: rows.slice(0, 200).map((r) => Array.from({ length: width }, (_, i) => String(r[i] ?? "")))
	};
}
var width = (t) => t.rows[0]?.length ?? 0;
/** A row inserted at `at` (default: the end). */
function addRow(t, at = t.rows.length) {
	if (t.rows.length >= 200) return t;
	const rows = [...t.rows];
	rows.splice(at, 0, Array(width(t)).fill(""));
	return {
		...t,
		rows
	};
}
/** A column inserted at `at` (default: the end). */
function addColumn(t, at = width(t)) {
	if (width(t) >= 12) return t;
	return {
		...t,
		rows: t.rows.map((row) => {
			const r = [...row];
			r.splice(at, 0, "");
			return r;
		})
	};
}
// core/resources/js/pages/fields/Table.vue
/**
* The table field (Fields\TableField): a grid of cells. Rows and columns are added and
* removed with the buttons; text copied from a spreadsheet pastes in as cells.
* The logic is shared/table.js; this only draws it.
*/
var _sfc_main = {
	__name: "Table",
	__ssrInlineRender: true,
	props: /*@__PURE__*/ mergeModels({
		field: {
			type: Object,
			required: true
		},
		id: {
			type: String,
			default: ""
		},
		invalid: Boolean,
		disabled: Boolean,
		errors: {
			type: Object,
			default: () => ({})
		},
		path: {
			type: String,
			default: ""
		}
	}, {
		"modelValue": { default: null },
		"modelModifiers": {}
	}),
	emits: ["update:modelValue"],
	setup(__props) {
		const model = useModel(__props, "modelValue");
		const props = __props;
		const table = computed(() => tidy(model.value ?? blankTable()));
		const cols = computed(() => width(table.value));
		const grid = ref(null);
		const put = (next) => {
			if (!props.disabled) model.value = next;
		};
		function focusCell(r, c) {
			nextTick(() => grid.value?.querySelector(`[data-cell="${r}-${c}"]`)?.focus());
		}
		const cellLabel = (r, c) => __("Row {row}, column {column}", {
			row: r + 1,
			column: c + 1
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<div${ssrRenderAttrs(mergeProps({
				id: __props.id,
				class: "k-tablefield",
				"aria-invalid": __props.invalid ? "true" : void 0
			}, _attrs))}>`);
			_push(ssrRenderComponent(unref(KSwitch), {
				"model-value": table.value.head,
				label: unref(__)("First row is headings"),
				disabled: __props.disabled,
				"onUpdate:modelValue": ($event) => put({
					...table.value,
					head: $event
				})
			}, null, _parent));
			_push(`<div class="k-tablefield__scroll"><table class="k-tablefield__grid"><thead><tr><!--[-->`);
			ssrRenderList(cols.value, (_, c) => {
				_push(`<td class="k-tablefield__coltools">`);
				if (cols.value > 1 && !__props.disabled) _push(`<button type="button" class="k-tablefield__tool"${ssrRenderAttr("title", unref(__)("Remove column {n}", { n: c + 1 }))}${ssrRenderAttr("aria-label", unref(__)("Remove column {n}", { n: c + 1 }))}> × </button>`);
				else _push(`<!---->`);
				_push(`</td>`);
			});
			_push(`<!--]--><td></td></tr></thead><tbody><!--[-->`);
			ssrRenderList(table.value.rows, (row, r) => {
				_push(`<tr class="${ssrRenderClass({ "is-head": table.value.head && r === 0 })}"><!--[-->`);
				ssrRenderList(row, (cell, c) => {
					_push(`<td><input class="k-input k-tablefield__cell"${ssrRenderAttr("value", cell)}${ssrRenderAttr("data-cell", `${r}-${c}`)}${ssrRenderAttr("aria-label", cellLabel(r, c))}${ssrRenderAttr("maxlength", 1e3)}${ssrIncludeBooleanAttr(__props.disabled) ? " disabled" : ""}></td>`);
				});
				_push(`<!--]--><td class="k-tablefield__rowtools">`);
				if (!__props.disabled) {
					_push(`<!--[--><button type="button" class="k-tablefield__tool"${ssrIncludeBooleanAttr(r === 0) ? " disabled" : ""}${ssrRenderAttr("aria-label", unref(__)("Move row {n} up", { n: r + 1 }))}${ssrRenderAttr("title", unref(__)("Move up"))}> ↑ </button>`);
					if (table.value.rows.length > 1) _push(`<button type="button" class="k-tablefield__tool"${ssrRenderAttr("aria-label", unref(__)("Remove row {n}", { n: r + 1 }))}${ssrRenderAttr("title", unref(__)("Remove row"))}> × </button>`);
					else _push(`<!---->`);
					_push(`<!--]-->`);
				} else _push(`<!---->`);
				_push(`</td></tr>`);
			});
			_push(`<!--]--></tbody></table></div>`);
			if (!__props.disabled) {
				_push(`<div class="k-tablefield__add">`);
				_push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					icon: "plus",
					disabled: table.value.rows.length >= unref(200),
					onClick: ($event) => {
						put(unref(addRow)(table.value));
						focusCell(table.value.rows.length, 0);
					}
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Row"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Row")), 1)];
					}),
					_: 1
				}, _parent));
				_push(ssrRenderComponent(unref(KButton), {
					size: "sm",
					icon: "plus",
					disabled: cols.value >= unref(12),
					onClick: ($event) => put(unref(addColumn)(table.value))
				}, {
					default: withCtx((_, _push, _parent, _scopeId) => {
						if (_push) _push(`${ssrInterpolate(unref(__)("Column"))}`);
						else return [createTextVNode(toDisplayString(unref(__)("Column")), 1)];
					}),
					_: 1
				}, _parent));
				_push(`<span class="k-muted k-small">${ssrInterpolate(unref(__)("Paste from a spreadsheet to fill several cells."))}</span></div>`);
			} else _push(`<!---->`);
			_push(`</div>`);
		};
	}
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/fields/Table.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export { _sfc_main as default };
