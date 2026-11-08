/**
 * Runs in the hidden frame the admin opens at /system/prerender (saved page
 * HTML). The frame's import map points `vue` at a build that can draw HTML
 * and `@kestello/*` at their SSR builds, so this draws each waiting page
 * exactly as the Node renderer would, and sends the HTML back. The server
 * takes it only for a page that is waiting, and only from an administrator.
 */
import { renderToString } from 'vue/server-renderer';
import { createKestelloApp } from '@kestello/app';
import { MAX_PER_RUN, nextPage, registryFor } from './queue.js';

const config = JSON.parse(document.getElementById('kestello-prerender')?.textContent ?? '{}');

async function api(method, path, body) {
    const response = await fetch('/api/v1' + path, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf ?? '' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    if (!response.ok) throw new Error(`${method} ${path}: ${response.status}`);
    return (await response.json())?.data;
}

async function draw(page) {
    const { app, head } = await createKestelloApp({
        page: page.page,
        registry: registryFor(page.modules),
        ssr: true,
        importer: (entry) => import(entry.file),
        messages: page.messages ?? {},
    });
    const html = await renderToString(app);
    return { html, title: head.title ?? '' };
}

async function run() {
    const tried = new Set();
    let saved = 0;
    let failed = 0;
    while (tried.size < MAX_PER_RUN) {
        const list = await api('GET', '/system/prerender?limit=10');
        const page = nextPage(list?.pages, tried);
        if (!page) break;
        tried.add(page.key);
        try {
            await api('POST', `/system/prerender/${page.key}`, await draw(page));
            saved++;
        } catch (error) {
            failed++;
            console.warn('[kestello] could not save the HTML of', page.url, error);
        }
    }
    return { saved, failed };
}

run()
    .catch((error) => {
        console.warn('[kestello] saving page HTML stopped', error);
        return { saved: 0, failed: 1 };
    })
    .then((result) => window.parent?.postMessage({ type: 'kestello:prerender-done', ...result }, window.location.origin));
