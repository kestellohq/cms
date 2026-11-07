// Kestello SSR renderer (optional). PHP posts the page object plus the SSR
// module paths of the page and layouts; we answer with rendered HTML.
//
//   node core/ssr/server.mjs            (or: npm run ssr)
//   env: SSR_HOST (127.0.0.1), SSR_PORT (13714), KESTELLO_ROOT (cwd)
//
// Enable in config/app.php:  'ssr' => ['url' => 'http://127.0.0.1:13714']
// Security: binds to localhost by default, accepts only POST /render with a
// size-capped JSON body, and only imports .mjs files under */assets/build/ssr/
// inside KESTELLO_ROOT. It never fetches anything itself.
import { createServer } from 'node:http';
import { register, createRequire } from 'node:module';
import { realpathSync } from 'node:fs';
import { resolve as resolvePath, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HOST = process.env.SSR_HOST ?? '127.0.0.1';
const PORT = Number(process.env.SSR_PORT ?? 13714);
const ROOT = realpathSync(resolvePath(process.env.KESTELLO_ROOT ?? process.cwd()));
const MAX_BODY = 2 * 1024 * 1024;
// next to this file, so a release in core/releases/<version>/ finds its own build
const RUNTIME = fileURLToPath(new URL('../resources/assets/build/ssr/app/index.mjs', import.meta.url));

const require = createRequire(resolvePath(ROOT, 'package.json'));
const vueUrl = pathToFileURL(require.resolve('vue')).href;
const built = (path) => pathToFileURL(fileURLToPath(new URL(`../resources/assets/build/ssr/${path}`, import.meta.url))).href;
register('./hooks.mjs', import.meta.url, {
    data: { runtime: pathToFileURL(RUNTIME).href, sdk: built('sdk/index.mjs'), ui: built('ui/index.mjs'), vue: vueUrl },
});

const { renderToString } = await import(pathToFileURL(require.resolve('vue/server-renderer')).href);
const { createKestelloApp } = await import(pathToFileURL(RUNTIME).href);

/** Only built SSR modules inside the site may be imported. */
function allowed(file) {
    if (typeof file !== 'string' || !file.endsWith('.mjs')) return null;
    let real;
    try {
        real = realpathSync(file);
    } catch {
        return null;
    }
    if (!real.startsWith(ROOT + sep) || !real.includes(`${sep}assets${sep}build${sep}ssr${sep}`)) return null;
    return pathToFileURL(real).href;
}

async function render({ page, modules, messages }) {
    if (!page || typeof page.component !== 'string' || !modules || typeof modules !== 'object') {
        throw Object.assign(new Error('bad request'), { status: 400 });
    }
    const registry = {};
    for (const [name, file] of Object.entries(modules)) {
        const url = allowed(file);
        if (url) registry[name] = { file: url };
    }
    if (!registry[page.component]) throw Object.assign(new Error('component not allowed'), { status: 400 });

    const catalogue = messages && typeof messages === 'object' && !Array.isArray(messages) ? messages : {};
    const { app, head } = await createKestelloApp({ page, registry, ssr: true, importer: (entry) => import(entry.file), messages: catalogue });
    const body = await renderToString(app);

    return { body, head: '', title: head.title ?? '' };
}

createServer((req, res) => {
    const reply = (status, data) => {
        res.writeHead(status, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(data));
    };

    if (req.method === 'GET' && req.url === '/health') return reply(200, { ok: true });
    if (req.method !== 'POST' || req.url !== '/render') return reply(404, { error: 'not found' });

    let size = 0;
    const chunks = [];
    req.on('data', (chunk) => {
        size += chunk.length;
        if (size > MAX_BODY) {
            reply(413, { error: 'too large' });
            req.destroy();
            return;
        }
        chunks.push(chunk);
    });
    req.on('end', async () => {
        if (res.writableEnded) return;
        try {
            reply(200, await render(JSON.parse(Buffer.concat(chunks).toString('utf8'))));
        } catch (error) {
            console.error('[kestello-ssr]', error.message);
            reply(error.status ?? 500, { error: error.status ? error.message : 'render failed' });
        }
    });
}).listen(PORT, HOST, () => console.log(`[kestello-ssr] listening on http://${HOST}:${PORT} (root ${ROOT})`));
