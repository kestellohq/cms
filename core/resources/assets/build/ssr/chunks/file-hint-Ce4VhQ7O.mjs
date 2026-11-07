var whole = (v, fallback) => Number.isFinite(Number(v)) && Number(v) >= 1 && v !== "" && v !== null ? Math.floor(Number(v)) : fallback;
/**
* @param {{max_size?: unknown, multiple?: unknown, max?: unknown}} options
* @param {{one: (p: {size: number}) => string, many: (p: {size: number, count: number}) => string}} words
*/
function fileHint(options, words) {
	const size = whole(options?.max_size, 5);
	const count = options?.multiple ? whole(options?.max, 5) : 1;
	return count > 1 ? words.many({
		size,
		count
	}) : words.one({ size });
}
/** Put {name} values into a sentence translated elsewhere; unknown names stay as written. */
function fill(sentence, params = {}) {
	return String(sentence).replace(/\{(\w+)\}/g, (all, name) => name in params ? String(params[name]) : all);
}
export { fill as n, fileHint as t };
