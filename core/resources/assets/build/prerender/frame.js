import { renderToString as e } from "vue/server-renderer";
import { createKestelloApp as t } from "@kestello/app";
// core/resources/js/prerender/queue.js
function n(e, t) {
	return (e ?? []).find((e) => e && typeof e.key == "string" && !t.has(e.key)) ?? null;
}
function r(e) {
	let t = {};
	for (let [n, r] of Object.entries(e ?? {})) typeof r == "string" && r.startsWith("/system/prerender/modules/") && (t[n] = {
		file: r,
		css: []
	});
	return t;
}
// core/resources/js/prerender/frame.js
var i = JSON.parse(document.getElementById("kestello-prerender")?.textContent ?? "{}");
async function a(e, t, n) {
	let r = await fetch("/api/v1" + t, {
		method: e,
		credentials: "same-origin",
		headers: {
			Accept: "application/json",
			"X-Requested-With": "XMLHttpRequest",
			...n ? {
				"Content-Type": "application/json",
				"X-CSRF-TOKEN": i.csrf ?? ""
			} : {}
		},
		body: n ? JSON.stringify(n) : void 0
	});
	if (!r.ok) {
		let n = await r.json().catch(() => null), i = Object.values(n?.errors ?? {}).flat()[0] ?? n?.detail ?? "";
		throw Error(`${e} ${t}: ${r.status}${i ? ` (${i})` : ""}`);
	}
	return (await r.json())?.data;
}
async function o(n) {
	let { app: i, head: a } = await t({
		page: n.page,
		registry: r(n.modules),
		ssr: !0,
		importer: (e) => import(e.file),
		messages: n.messages ?? {}
	});
	return {
		html: await e(i),
		title: a.title ?? ""
	};
}
async function s() {
	let e = /* @__PURE__ */ new Set(), t = 0, r = 0;
	for (; e.size < 25;) {
		let i = n((await a("GET", "/system/prerender?limit=10"))?.pages, e);
		if (!i) break;
		e.add(i.key);
		try {
			await a("POST", `/system/prerender/${i.key}`, await o(i)), t++;
		} catch (e) {
			r++, console.warn("[kestello] could not save the HTML of", i.url, e);
		}
	}
	return {
		saved: t,
		failed: r
	};
}
s().catch((e) => (console.warn("[kestello] saving page HTML stopped", e), {
	saved: 0,
	failed: 1
})).then((e) => window.parent?.postMessage({
	type: "kestello:prerender-done",
	...e
}, window.location.origin));
