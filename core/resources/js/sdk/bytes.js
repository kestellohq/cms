/**
 * A size for people: "20 B", "1.5 KB", "12 MB", in the admin language's number
 * format. One decimal while the number is under 10, none after. Anything that is
 * not a size (missing, negative) shows a dash.
 */
const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

export function formatBytes(bytes, locale = 'en') {
    // database drivers may send integers as strings
    const size = typeof bytes === 'string' && bytes.trim() !== '' ? Number(bytes) : bytes;
    if (typeof size !== 'number' || !Number.isFinite(size) || size < 0) return '—';
    let n = size;
    let i = 0;
    while (n >= 1024 && i < UNITS.length - 1) {
        n /= 1024;
        i++;
    }
    const digits = i === 0 || n >= 10 ? 0 : 1;

    return `${new Intl.NumberFormat(locale, { maximumFractionDigits: digits }).format(n)} ${UNITS[i]}`;
}
