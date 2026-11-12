// The browser tests: a site from the install zip, installed and used in Chromium as people do.
//
//   npm run build && npm run test:browser
//
// On a failure, `npx playwright show-trace tests/browser/.results/<test>/trace.zip` replays it.
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.KESTELLO_BROWSER_PORT ?? 8123);

export default defineConfig({
    testDir: '.',
    testMatch: '*.spec.mjs',
    outputDir: '.results',
    // one site, used in order: install, then sign in, then every screen
    workers: 1,
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    timeout: 10 * 60_000,
    expect: { timeout: 15_000 },
    reporter: process.env.CI ? [['list'], ['github']] : 'list',
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        // a step that cannot happen fails in seconds, not at the test's timeout
        actionTimeout: 15_000,
        navigationTimeout: 60_000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        ...devices['Desktop Chrome'],
    },
    webServer: {
        command: 'sh site.sh',
        cwd: fileURLToPath(new URL('.', import.meta.url)),
        url: `http://127.0.0.1:${port}/install`,
        env: { KESTELLO_BROWSER_PORT: String(port), KESTELLO_BROWSER_ZIP: process.env.KESTELLO_BROWSER_ZIP ?? '' },
        reuseExistingServer: false,
        timeout: 180_000,
        stdout: 'ignore',
        // PHP's built-in server logs every connection on stderr
        stderr: 'ignore',
    },
});
