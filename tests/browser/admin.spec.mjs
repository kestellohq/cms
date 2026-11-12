// Install a site from the zip people download, sign in, and open every admin screen a link
// leads to. Any error in the browser (an exception, a console error, a Vue error), any server
// error and any request that fails fails the test, with the screen it happened on.
//
// It caught nothing on purpose: it exists because rc.4 shipped an entry editor that stopped as
// it opened, which no unit test saw. New screens are covered as soon as a link leads to them.
import { test, expect } from '@playwright/test';
import { randomBytes } from 'node:crypto';

const admin = { username: 'admin', email: 'admin@example.com', password: randomBytes(18).toString('base64url') };

// Links the crawl does not follow: they leave, sign out, or download.
const SKIP = [/\/logout\b/, /\/sign-?out\b/, /\/download\b/, /\/export\b/, /\.(zip|csv|json|xml)$/];
const MAX_SCREENS = 250;

/** Collects what went wrong in the browser, by the screen it happened on. */
function watch(page) {
    const problems = [];
    let screen = '(start)';
    page.on('pageerror', (e) => problems.push(`${screen}: uncaught ${e.name}: ${e.message}`));
    page.on('console', (m) => {
        // missing files are reported once, by the response handler below
        if (m.type() !== 'error' || m.text().startsWith('Failed to load resource')) return;
        const at = m.location();
        // Playwright's trace recorder tries to add its script to sandboxed frames (the email
        // preview), which the sandbox blocks, at line 0. A script in the page itself has a line.
        if (m.text().startsWith('Blocked script execution') && at.lineNumber === 0) return;
        problems.push(`${screen}: console: ${m.text()}${at.url ? ` (at ${at.url}:${at.lineNumber})` : ''}`);
    });
    page.on('response', (r) => {
        if (r.status() >= 500) problems.push(`${screen}: ${r.status()} from ${r.request().method()} ${new URL(r.url()).pathname}`);
    });
    page.on('requestfailed', (r) => {
        const why = r.failure()?.errorText ?? '';
        // a navigation away cancels what the previous screen was still loading
        if (!why.includes('ERR_ABORTED')) problems.push(`${screen}: ${r.method()} ${new URL(r.url()).pathname} failed: ${why}`);
    });
    return {
        problems,
        on(path) {
            screen = path;
        },
    };
}

/** Same-site admin links on the page, without the query and fragment, not yet seen. */
async function adminLinks(page) {
    const hrefs = await page.$$eval('a[href]', (as) => as.map((a) => a.href));
    return hrefs
        .map((h) => new URL(h))
        .filter((u) => u.origin === new URL(page.url()).origin && u.pathname.startsWith('/admin'))
        .map((u) => u.pathname)
        .filter((p) => !SKIP.some((re) => re.test(p)));
}

test.describe.configure({ mode: 'serial' });

test('the web installer sets up a site on SQLite', async ({ page }) => {
    // the health check answers before the site is installed, so a container isn't restarted mid-install
    const before = await page.request.get('/up');
    expect(before.status()).toBe(200);
    expect(await before.json()).toEqual({ status: 'ok', installed: false });

    const seen = watch(page);
    seen.on('/install');
    await page.goto('/install');
    // 1. requirements: all met on the test machine
    await page.getByRole('link', { name: /continue/i }).click();

    // 2. database
    seen.on('/install?step=2');
    // the radio is drawn as a card: choose it the way people do, by its label
    await page.getByRole('radio', { name: /sqlite/i }).check({ force: true });
    await expect(page.locator('input[name="db_driver"][value="sqlite"]')).toBeChecked();
    await page.locator('input[name="db_name"]:visible').fill('browser');
    await page.getByRole('button', { name: /connect and continue/i }).click();

    // 3. the site
    seen.on('/install?step=3');
    await page.locator('input[name="site_name"]').fill('Browser test');
    await page.locator('input[name="site_url"]').fill(new URL(page.url()).origin);
    await page.getByRole('button', { name: /continue/i }).click();

    // 4. the administrator
    seen.on('/install?step=4');
    await page.locator('input[name="admin_username"]').fill(admin.username);
    await page.locator('input[name="admin_email"]').fill(admin.email);
    await page.locator('input[name="admin_password"]').fill(admin.password);
    await page.locator('input[name="admin_password_confirm"]').fill(admin.password);
    await page.getByRole('button', { name: /continue/i }).click();

    // 5. install
    seen.on('/install?step=5');
    await page.getByRole('button', { name: /install/i }).click();
    await expect(page).toHaveURL(/\/install\/complete/);

    const after = await page.request.get('/up');
    expect(after.status()).toBe(200);
    expect(await after.json()).toEqual({ status: 'ok', database: true, maintenance: false });
    expect(seen.problems).toEqual([]);
});

test('every admin screen opens without an error', async ({ page }) => {
    const seen = watch(page);

    seen.on('/login');
    await page.goto('/login');
    await page.locator('input[autocomplete="username"]').fill(admin.username);
    await page.locator('input[autocomplete="current-password"]').fill(admin.password);
    await page.locator('input[autocomplete="current-password"]').press('Enter');
    await page.waitForURL(/\/admin/);

    // something in every list, so the crawl reaches the editors of existing entries too
    // the page's props carry the token the admin sends with every change (sdk createApi)
    const token = (await page.content()).match(/"csrfToken":"([^"]+)"/)?.[1];
    expect(token, 'the admin page carries a CSRF token').toBeTruthy();
    for (const type of ['page', 'post']) {
        const created = await page.request.post(`/api/v1/content/${type}/entries`, {
            headers: { 'X-CSRF-TOKEN': token },
            data: { title: `A ${type} from the browser test`, status: 'published' },
        });
        expect(created.status(), `creating a ${type}: ${await created.text()}`).toBeLessThan(300);
    }

    const queue = ['/admin'];
    const visited = new Set();
    while (queue.length && visited.size < MAX_SCREENS) {
        const path = queue.shift();
        if (visited.has(path)) continue;
        visited.add(path);
        seen.on(path);
        await page.goto(path, { waitUntil: 'networkidle' });
        for (const next of await adminLinks(page)) if (!visited.has(next)) queue.push(next);
    }

    console.log(`Opened ${visited.size} admin screens.`);
    expect(visited.size, 'the crawl found the admin').toBeGreaterThan(20);
    expect(
        [...visited].some((p) => /\/admin\/content\/[^/]+\/[^/]+/.test(p)),
        'an entry editor was opened',
    ).toBe(true);
    expect(seen.problems).toEqual([]);
});
