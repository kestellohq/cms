// core/resources/js/shared/tabs.js
/** The ids that tie a tab to its panel, from the tabs block's id. */
var tabId = (group, i) => `k-tab-${group}-${i}`;
var panelId = (group, i) => `k-panel-${group}-${i}`;
/** What the tabs block gives its panels (provide/inject). */
var TABS = Symbol("k-tabs");
export { panelId as n, tabId as r, TABS as t };
