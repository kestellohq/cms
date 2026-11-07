/**
 * Translations per domain ("core", a plugin's or theme's slug), as the SDK keeps
 * them. Pure: the store is handed in (the SDK passes a reactive object, so
 * pages re-render when messages arrive).
 */

/** @param {Record<string, Record<string, string>>} [store] */
export function createCatalogue(store = {}) {
    return {
        /** Merge messages into a domain. */
        add(dictionary, domain = 'core') {
            const clean = {};
            for (const [key, value] of Object.entries(dictionary ?? {})) {
                if (typeof value === 'string' && value !== '') clean[key] = value;
            }
            store[domain] = { ...(store[domain] ?? {}), ...clean };
        },

        /** Keep only these messages ({ domain: { text: translation } }), dropping all others. */
        replace(data) {
            for (const domain of Object.keys(store)) delete store[domain];
            for (const [domain, dictionary] of Object.entries(data ?? {})) {
                if (dictionary && typeof dictionary === 'object') this.add(dictionary, domain);
            }
        },

        /** The domain's translation, else core's, else the source text itself. */
        lookup(text, domain = 'core') {
            return store[domain]?.[text] ?? (domain !== 'core' ? store.core?.[text] : undefined) ?? text;
        },

        /**
         * Fetch a catalogue ({ domain: { text: translation } }) and add it.
         * Throws with the reason when it can't (the caller decides what to say).
         */
        async load(url, fetcher) {
            const response = await fetcher(url, { credentials: 'same-origin' });
            if (!response.ok) throw new Error(`HTTP ${response.status} for ${url}`);
            const data = await response.json();
            if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error(`${url} is not a catalogue`);
            for (const [domain, dictionary] of Object.entries(data)) {
                if (dictionary && typeof dictionary === 'object') this.add(dictionary, domain);
            }
        },
    };
}
