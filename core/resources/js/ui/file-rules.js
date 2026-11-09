/**
 * Which file a form field takes, read from an `accept` value as written on
 * <input type="file"> (".zip,application/zip"). Pure, so it is tested
 * without a browser; KFileInput turns the answers into words.
 */

/** The accepted extensions and media types, lower case. */
export function acceptedKinds(accept) {
    return String(accept ?? '')
        .split(',')
        .map((a) => a.trim().toLowerCase())
        .filter(Boolean);
}

/** The extensions to show a person, such as "ZIP" or "CSV, TXT". */
export function acceptedLabel(accept) {
    return [
        ...new Set(
            acceptedKinds(accept)
                .filter((k) => k.startsWith('.'))
                .map((k) => k.slice(1).toUpperCase()),
        ),
    ].join(', ');
}

/**
 * Why a file can't be used, or null when it can.
 * @param {{ name: string, type?: string, size: number }} file
 * @returns {null | { reason: 'type' } | { reason: 'size', size: number, max: number }}
 */
export function fileProblem(file, accept = '', maxBytes = 0) {
    const kinds = acceptedKinds(accept);
    const dot = file.name.lastIndexOf('.');
    const ext = dot > 0 ? file.name.slice(dot).toLowerCase() : '';
    const type = (file.type || '').toLowerCase();
    if (kinds.length && !kinds.some((k) => (k.startsWith('.') ? k === ext : k === type))) {
        return { reason: 'type' };
    }
    if (maxBytes > 0 && file.size > maxBytes) {
        return { reason: 'size', size: file.size, max: maxBytes };
    }
    return null;
}
