import { __ } from '@kestello/sdk';

/**
 * What a plugin declares it may do, as label and value rows for a <dl>: from its manifest
 * ("permissions", and the core services it "replaces"), shown before installing and in the list.
 *
 *   permissionRows({ http: ['api.example.com'] }, ['search'])
 */
export function permissionRows(permissions, replaces = []) {
    const labels = {
        http: __('Connects to'),
        network: __('Connects to'),
        database: __('Database'),
        filesystem: __('Files'),
        mail: __('Sends email'),
        events: __('Listens to'),
        capabilities: __('Adds permissions'),
    };
    const rows = Object.entries(permissions ?? {}).map(([area, value]) => ({
        label: labels[area] ?? area,
        value: Array.isArray(value)
            ? value.join(', ')
            : typeof value === 'object' && value !== null
              ? Object.entries(value)
                    .map(([k, v]) => `${k}: ${v}`)
                    .join(', ')
              : String(value),
    }));
    if (replaces.length) rows.push({ label: __('Replaces core services'), value: replaces.join(', ') });
    return rows;
}
