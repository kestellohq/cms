/**
 * The visual editor's publishing timetable (StudioPublish.vue): when the page goes live,
 * as one row. Pure: the words come in through t, the date through format.
 *
 * @param {{live: boolean, future: boolean, publishedAt: string|null}} s
 * @param {(text: string) => string} t
 * @param {(iso: string) => string} format
 * @returns {{label: string, value: string, scheduled: boolean}}
 */
export function whenRow({ live, future, publishedAt }, t, format) {
    if (live) return { label: t('Live since'), value: publishedAt ? format(publishedAt) : t('Now'), scheduled: false };
    if (future && publishedAt) return { label: t('Goes live'), value: format(publishedAt), scheduled: true };
    return { label: t('Goes live'), value: t('When you publish'), scheduled: false };
}
