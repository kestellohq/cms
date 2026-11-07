/**
 * Whether the editor holds an entry not created yet. The address says "new"
 * until the router has moved to the entry's own; after the first save the
 * entry exists even if that move never happens (a deployment in between
 * loads the page in full), so a second save must update it, never create
 * another.
 * @param {string} routeId the {id} of the address: a number, or 'new'
 * @param {{ id?: number } | null} entry the entry as last saved
 */
export const isNewEntry = (routeId, entry) => routeId === 'new' && !entry?.id;
