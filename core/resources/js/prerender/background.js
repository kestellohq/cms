/**
 * Saved page HTML, from the admin's side: while an administrator has the
 * admin open, now and then ask whether pages are waiting for their HTML and,
 * when some are, open the hidden frame (/system/prerender) that draws them.
 * The frame says when it is done; it is removed then, or after a while.
 */

/** Wait this long after the admin opens, so the frame never slows the screen being opened. */
export const FIRST_CHECK = 20_000;
/** Then check this often. */
export const EVERY = 10 * 60_000;
/** Remove a frame that has not said it is done by then. */
export const FRAME_TIMEOUT = 2 * 60_000;

/** Whether a check found work for the frame (GET /system/prerender). */
export function hasWork(status) {
    return Boolean(status?.enabled) && Number(status?.waiting ?? 0) > 0;
}

/**
 * @param {{ get: Function }} api the admin's API client
 * @returns {() => void} stops checking and removes any frame
 */
export function startPrerender(api) {
    let frame = null;
    let removeTimer = null;

    function close() {
        clearTimeout(removeTimer);
        frame?.remove();
        frame = null;
    }

    function onMessage(event) {
        if (event.origin === window.location.origin && event.source === frame?.contentWindow && event.data?.type === 'kestello:prerender-done') close();
    }

    async function check() {
        if (frame || document.visibilityState !== 'visible') return;
        let status;
        try {
            status = (await api.get('/system/prerender', { limit: 1 }))?.data;
        } catch {
            return; // a site without it, or the network: try again later
        }
        if (!hasWork(status) || frame) return;
        frame = document.createElement('iframe');
        frame.src = '/system/prerender';
        frame.title = 'Saving page HTML';
        frame.setAttribute('aria-hidden', 'true');
        frame.tabIndex = -1;
        frame.style.cssText = 'position:absolute;width:0;height:0;border:0;visibility:hidden';
        document.body.append(frame);
        removeTimer = setTimeout(close, FRAME_TIMEOUT);
    }

    window.addEventListener('message', onMessage);
    const first = setTimeout(check, FIRST_CHECK);
    const timer = setInterval(check, EVERY);

    return () => {
        clearTimeout(first);
        clearInterval(timer);
        window.removeEventListener('message', onMessage);
        close();
    };
}
