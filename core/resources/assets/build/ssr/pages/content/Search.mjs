import { t as _plugin_vue_export_helper_default } from "../../chunks/_plugin-vue_export-helper-DjkACiAr.mjs";
import { Fragment, computed, createBlock, createCommentVNode, createTextVNode, openBlock, ref, renderList, toDisplayString, unref, useSSRContext, withCtx } from "vue";
import { ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Head, Link, useRouter } from "@kestello/app";
// core/resources/js/pages/content/Search.vue
var _sfc_main = /*@__PURE__*/ Object.assign({ layout: "Site" }, {
	__name: "Search",
	__ssrInlineRender: true,
	props: {
		title: {
			type: String,
			default: "Search"
		},
		query: {
			type: String,
			default: ""
		},
		type: {
			type: String,
			default: ""
		},
		types: {
			type: Array,
			default: () => []
		},
		results: {
			type: Object,
			default: null
		},
		limited: Boolean
	},
	setup(__props) {
		const props = __props;
		useRouter();
		const q = ref(props.query);
		const href = (params) => {
			const s = new URLSearchParams();
			const merged = {
				q: props.query,
				type: props.type,
				...params
			};
			for (const [k, v] of Object.entries(merged)) if (v && !(k === "page" && v === 1)) s.set(k, String(v));
			return `/search?${s.toString()}`;
		};
		const counts = computed(() => Object.fromEntries((props.results?.facets ?? []).map((f) => [f.value, f.count])));
		const allCount = computed(() => (props.results?.facets ?? []).reduce((n, f) => n + f.count, 0));
		const date = (iso) => iso ? new Intl.DateTimeFormat(void 0, { dateStyle: "medium" }).format(new Date(iso)) : "";
		const summary = computed(() => {
			const r = props.results;
			if (!r) return "";
			return r.total === 1 ? `1 result for “${props.query}”` : `${r.total} results for “${props.query}”`;
		});
		return (_ctx, _push, _parent, _attrs) => {
			_push(`<!--[-->`);
			_push(ssrRenderComponent(unref(Head), { title: __props.title }, null, _parent));
			_push(`<div class="k-search" data-v-92a93ef0><form class="k-search__form" role="search" action="/search" method="get" data-v-92a93ef0><label class="k-search__label" for="k-search-q" data-v-92a93ef0>Search the site</label><div class="k-search__bar" data-v-92a93ef0><input id="k-search-q"${ssrRenderAttr("value", q.value)} class="k-search__input" type="search" name="q" maxlength="200" autocomplete="off" enterkeyhint="search"${ssrIncludeBooleanAttr(!__props.query) ? " autofocus" : ""} data-v-92a93ef0>`);
			if (__props.type) _push(`<input type="hidden" name="type"${ssrRenderAttr("value", __props.type)} data-v-92a93ef0>`);
			else _push(`<!---->`);
			_push(`<button class="k-search__submit" type="submit" data-v-92a93ef0>Search</button></div></form>`);
			if (__props.limited) _push(`<p class="k-search__note" role="alert" data-v-92a93ef0>Too many searches in a short time. Please wait a minute and try again.</p>`);
			else if (__props.results) {
				_push(`<!--[--><div class="k-search__head" data-v-92a93ef0><p class="k-search__summary" role="status" data-v-92a93ef0>${ssrInterpolate(summary.value)}</p>`);
				if (allCount.value && __props.results.facets.length > 1 || __props.type) {
					_push(`<nav class="k-search__filters" aria-label="Filter results" data-v-92a93ef0>`);
					_push(ssrRenderComponent(unref(Link), {
						href: href({
							type: "",
							page: 1
						}),
						class: "k-search__filter",
						"aria-current": !__props.type ? "true" : void 0
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) {
								_push(`All`);
								if (allCount.value) _push(`<span data-v-92a93ef0${_scopeId}>${ssrInterpolate(allCount.value)}</span>`);
								else _push(`<!---->`);
							} else return [createTextVNode("All"), allCount.value ? (openBlock(), createBlock("span", { key: 0 }, toDisplayString(allCount.value), 1)) : createCommentVNode("v-if", true)];
						}),
						_: 1
					}, _parent));
					_push(`<!--[-->`);
					ssrRenderList(__props.types.filter((t) => counts.value[t.value] || t.value === __props.type), (t) => {
						_push(ssrRenderComponent(unref(Link), {
							key: t.value,
							href: href({
								type: t.value,
								page: 1
							}),
							class: "k-search__filter",
							"aria-current": __props.type === t.value ? "true" : void 0
						}, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`${ssrInterpolate(t.label)}`);
									if (counts.value[t.value]) _push(`<span data-v-92a93ef0${_scopeId}>${ssrInterpolate(counts.value[t.value])}</span>`);
									else _push(`<!---->`);
								} else return [createTextVNode(toDisplayString(t.label), 1), counts.value[t.value] ? (openBlock(), createBlock("span", { key: 0 }, toDisplayString(counts.value[t.value]), 1)) : createCommentVNode("v-if", true)];
							}),
							_: 2
						}, _parent));
					});
					_push(`<!--]--></nav>`);
				} else _push(`<!---->`);
				_push(`</div>`);
				if (__props.results.hits.length) {
					_push(`<ol class="k-search__hits" data-v-92a93ef0><!--[-->`);
					ssrRenderList(__props.results.hits, (hit) => {
						_push(`<li class="k-search__hit" data-v-92a93ef0><div class="k-search__text" data-v-92a93ef0><p class="k-search__meta" data-v-92a93ef0><span data-v-92a93ef0>${ssrInterpolate(hit.type_label)}</span>`);
						if (hit.published_at && hit.type !== "page") _push(`<time${ssrRenderAttr("datetime", hit.published_at)} data-v-92a93ef0>${ssrInterpolate(date(hit.published_at))}</time>`);
						else _push(`<!---->`);
						_push(`</p><h2 class="k-search__title" data-v-92a93ef0>`);
						if (hit.url) _push(ssrRenderComponent(unref(Link), { href: hit.url }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) {
									_push(`<!--[-->`);
									ssrRenderList(hit.title_parts, (part, i) => {
										_push(`<!--[-->`);
										if (part.match) _push(`<mark data-v-92a93ef0${_scopeId}>${ssrInterpolate(part.text)}</mark>`);
										else _push(`<!--[-->${ssrInterpolate(part.text)}<!--]-->`);
										_push(`<!--]-->`);
									});
									_push(`<!--]-->`);
								} else return [(openBlock(true), createBlock(Fragment, null, renderList(hit.title_parts, (part, i) => {
									return openBlock(), createBlock(Fragment, { key: i }, [part.match ? (openBlock(), createBlock("mark", { key: 0 }, toDisplayString(part.text), 1)) : (openBlock(), createBlock(Fragment, { key: 1 }, [createTextVNode(toDisplayString(part.text), 1)], 64))], 64);
								}), 128))];
							}),
							_: 2
						}, _parent));
						else _push(`<!---->`);
						_push(`</h2>`);
						if (hit.snippet.length) {
							_push(`<p class="k-search__snippet" data-v-92a93ef0><!--[-->`);
							ssrRenderList(hit.snippet, (part, i) => {
								_push(`<!--[-->`);
								if (part.match) _push(`<mark data-v-92a93ef0>${ssrInterpolate(part.text)}</mark>`);
								else _push(`<!--[-->${ssrInterpolate(part.text)}<!--]-->`);
								_push(`<!--]-->`);
							});
							_push(`<!--]--></p>`);
						} else _push(`<!---->`);
						_push(`</div>`);
						if (hit.image) _push(`<img class="k-search__thumb"${ssrRenderAttr("src", hit.image)} alt="" loading="lazy" decoding="async" width="96" height="96" data-v-92a93ef0>`);
						else _push(`<!---->`);
						_push(`</li>`);
					});
					_push(`<!--]--></ol>`);
				} else {
					_push(`<div class="k-search__empty" data-v-92a93ef0><p data-v-92a93ef0>Nothing matches “${ssrInterpolate(__props.query)}”${ssrInterpolate(__props.type ? " here" : "")}.</p><p data-v-92a93ef0> Try fewer or different words`);
					if (__props.type) {
						_push(`<!--[-->, or `);
						_push(ssrRenderComponent(unref(Link), { href: href({
							type: "",
							page: 1
						}) }, {
							default: withCtx((_, _push, _parent, _scopeId) => {
								if (_push) _push(`search everything`);
								else return [createTextVNode("search everything")];
							}),
							_: 1
						}, _parent));
						_push(`<!--]-->`);
					} else _push(`<!---->`);
					_push(`. </p></div>`);
				}
				if (__props.results.last_page > 1) {
					_push(`<nav class="k-search__pages" aria-label="Pages" data-v-92a93ef0>`);
					if (__props.results.page > 1) _push(ssrRenderComponent(unref(Link), {
						href: href({ page: __props.results.page - 1 }),
						rel: "prev"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`Previous`);
							else return [createTextVNode("Previous")];
						}),
						_: 1
					}, _parent));
					else _push(`<!---->`);
					_push(`<span data-v-92a93ef0>Page ${ssrInterpolate(__props.results.page)} of ${ssrInterpolate(__props.results.last_page)}</span>`);
					if (__props.results.page < __props.results.last_page) _push(ssrRenderComponent(unref(Link), {
						href: href({ page: __props.results.page + 1 }),
						rel: "next"
					}, {
						default: withCtx((_, _push, _parent, _scopeId) => {
							if (_push) _push(`Next`);
							else return [createTextVNode("Next")];
						}),
						_: 1
					}, _parent));
					else _push(`<!---->`);
					_push(`</nav>`);
				} else _push(`<!---->`);
				_push(`<!--]-->`);
			} else _push(`<!---->`);
			_push(`</div><!--]-->`);
		};
	}
});
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
	const ssrContext = useSSRContext();
	(ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/pages/content/Search.vue");
	return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var Search_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-92a93ef0"]]);
export { Search_default as default };
