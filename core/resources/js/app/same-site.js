/**
 * Did a navigation end on another site? fetch() follows redirects, so a link
 * to /shop that the site redirects to https://shop.example answers from
 * there. That answer is never read as a page of this site (its component and
 * props would be drawn here): the browser goes there instead.
 *
 * @param {{ redirected?: boolean, url?: string }} response
 * @param {string} origin this site's origin (window.location.origin)
 */
export function endedElsewhere(response, origin) {
    if (!response?.redirected || !response.url) return false;
    try {
        return new URL(response.url).origin !== origin;
    } catch {
        return true;
    }
}
