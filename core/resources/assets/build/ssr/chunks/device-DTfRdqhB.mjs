// core/resources/js/components/account/device.js
/**
* "Chrome on macOS" from a user agent, for lists of signed-in sessions.
* Pure (pass the translator) so it can be tested in Node.
*
* @param {string} agent
* @param {(text: string, values?: object) => string} t the translator, __()
*/
function deviceLabel(agent, t) {
	const a = String(agent ?? "");
	const browser = /Edg\//.test(a) ? "Edge" : /Firefox\//.test(a) ? "Firefox" : /Chrome\//.test(a) ? "Chrome" : /Safari\//.test(a) ? "Safari" : t("Unknown browser");
	const os = /iPhone|iPad/.test(a) ? "iOS" : /Android/.test(a) ? "Android" : /Mac OS X/.test(a) ? "macOS" : /Windows/.test(a) ? "Windows" : /Linux/.test(a) ? "Linux" : "";
	return os ? t("{browser} on {os}", {
		browser,
		os
	}) : browser;
}
export { deviceLabel as t };
