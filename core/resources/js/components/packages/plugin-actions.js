/**
 * The buttons a row on Plugins offers, by the plugin's status (GET /plugins).
 *
 * A suspended plugin (safe mode stopped it after it crashed the site) can be
 * activated again: POST /plugins/{slug}/activate boots it in a trial first and
 * lifts the suspension only when it starts (ExtensionManager::activatePlugin).
 *
 *   pluginActions({ status: 'suspended' })   { activate: true, deactivate: true, remove: false }
 *
 * @param {{ status: string }} plugin
 * @returns {{ activate: boolean, deactivate: boolean, remove: boolean }}
 */
export function pluginActions(plugin) {
    const inactive = plugin.status === 'inactive';

    return { activate: inactive || plugin.status === 'suspended', deactivate: !inactive, remove: inactive };
}
