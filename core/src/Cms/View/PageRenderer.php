<?php

declare(strict_types=1);

namespace Kestello\Cms\View;

use Closure;
use Kestello\Cms\Frontend\Csp;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Support\Translator;
use RuntimeException;
use Throwable;

/**
 * Turns a PageResponse into the HTML shell (first visit, optionally
 * server-rendered) or page JSON (client navigation with X-Kestello: true).
 */
final class PageRenderer
{
    private const int JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param Closure(): array<string, mixed> $shared props merged into every page
     * @param Closure(array): string          $head   extra <head> HTML for the page data (filter "page.head")
     * @param ?Closure(array): array{open: string, close: string} $body HTML right after <body> and before </body> (filters "page.body_open", "page.footer")
     */
    public function __construct(
        private readonly PageRegistry $registry,
        private readonly Csp $csp,
        private readonly Paths $paths,
        private readonly Closure $shared,
        private readonly Closure $head,
        private readonly bool $debug = false,
        private readonly ?SsrGateway $ssr = null,
        private readonly ?Closure $body = null,
        private readonly ?Prerender\PrerenderStore $prerender = null,
    ) {}

    public function registry(): PageRegistry
    {
        return $this->registry;
    }

    /**
     * Render $page into $base (keeping its status, headers and cookies).
     */
    public function render(Request $request, PageResponse $page, Response $base): Response
    {
        $component = $page->component;

        if (!$this->registry->has($component)) {
            $message = "Page component \"{$component}\" is not registered (is its package active and built?)";
            if ($this->debug) {
                throw new RuntimeException($message);
            }
            error_log("[kestello] {$message}");
            return $base->withContent('<h1>Page unavailable</h1>')->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        $data = [
            'component' => $component,
            'props' => array_replace(($this->shared)(), $page->props),
            'url' => self::publicUri($request),
            'version' => $this->registry->version(),
        ];

        $data['preload'] = array_values(array_filter($page->preload, $this->registry->has(...)));

        if ($request->header('X-Kestello') === 'true') {
            unset($data['preload']);
            return $base
                ->withContent(json_encode($data, self::JSON_FLAGS))
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Kestello', 'true')
                ->withHeader('Vary', 'X-Kestello');
        }

        return $base
            ->withContent($this->shell($data, $base->statusCode()))
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Vary', 'X-Kestello');
    }

    /**
     * @param array{component: string, props: array<string, mixed>, url: string, version: string} $data
     */
    private function shell(array $data, int $status = 200): string
    {
        $entry = $this->registry->get($data['component']);
        $runtime = $this->registry->runtime() ?? throw new RuntimeException('Core frontend is not built: run "npm run build".');
        $vendor = $this->vendor();
        $nonce = self::e($this->csp->nonce());
        $imports = ['vue' => $vendor['url']];
        $integrity = [$vendor['url'] => $vendor['integrity']];
        $preload = '';
        $preloadFiles = [['path' => $runtime['path'], 'url' => $runtime['url']]];
        $isAdmin = isset($data['props']['admin']);
        foreach ($this->registry->modules() as $name => $module) {
            $imports[$name] = $module['url'];
            if ($name !== '@kestello/app') {
                $hash = self::sri($module['path']);
                if ($hash !== null) {
                    $integrity[$module['url']] = $hash;
                }
                if ($isAdmin) {
                    $preloadFiles[] = ['path' => $module['path'], 'url' => $module['url']];
                }
            }
        }

        $extraJs = [];
        /** @var list<string> $extraCss the stylesheets of the blocks on the page */
        $extraCss = [];
        foreach ($data['preload'] ?? [] as $name) {
            $more = $this->registry->get($name);
            $extraJs = [...$extraJs, ...($more['js'] ?? [])];
            $extraCss = [...$extraCss, ...($more['css'] ?? [])];
        }
        unset($data['preload']);
        $pageJson = json_encode($data, self::JSON_FLAGS);

        // Admin pages are per-user and need no SEO: always rendered in the browser.
        // Others: by the Node renderer when there is one, else from saved HTML.
        $ssr = null;
        if (!isset($data['props']['admin'])) {
            $ssr = $this->ssr?->render($data, $this->registry);
            if ($ssr === null && $this->prerender !== null && self::sameForEveryone($data)) {
                $ssr = $this->saved($data, $pageJson, $status);
            }
        }

        $seen = [];
        foreach ([...$preloadFiles, ...($entry['js'] ?? []), ...$extraJs] as $file) {
            if (isset($seen[$file['url']])) {
                continue;
            }
            $seen[$file['url']] = true;
            $hash = self::sri($file['path']);
            if ($hash !== null) {
                $integrity[$file['url']] = $hash;
            }
            $preload .= '<link rel="modulepreload" href="' . self::e($file['url']) . '"' . ($hash !== null ? ' integrity="' . self::e($hash) . '"' : '') . ">\n";
        }

        // the page's own stylesheets are swapped on navigation; those of the blocks
        // on it are kept (data-kestello-keep), as the browser loads each block once
        $styles = '';
        foreach (array_values(array_unique([...($entry['css'] ?? []), ...$extraCss])) as $url) {
            $keep = in_array($url, $extraCss, true) ? ' data-kestello-keep' : '';
            $styles .= '<link rel="stylesheet" href="' . self::e($url) . "\" data-kestello-css{$keep}>\n";
        }

        $siteName = (string) ($data['props']['site']['name'] ?? 'Kestello');
        $title = $ssr['title'] ?? '';
        if ($title === '') {
            $title = \Kestello\Cms\Site\Seo::documentTitle(['site' => ['name' => $siteName]] + $data['props'], (string) ($data['url'] ?? '/'));
        }

        $map = json_encode(['imports' => $imports, 'integrity' => $integrity], self::JSON_FLAGS);
        $boot = json_encode(['registry' => $this->registry->client()], self::JSON_FLAGS);
        $lang = $this->escape(str_replace('_', '-', Translator::locale()));
        $dir = Translator::direction();
        $head = ($this->head)($data);
        $body = $this->body !== null ? ($this->body)($data) : ['open' => '', 'close' => ''];

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$lang}" dir="{$dir}">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$this->escape($title)}</title>
        {$styles}<script type="importmap" nonce="{$nonce}">{$map}</script>
        {$preload}{$head}{$this->raw($ssr['head'] ?? '')}
        </head>
        <body>
        {$this->raw($body['open'])}<div id="app"{$this->raw($ssr !== null ? ' data-ssr' : '')}>{$this->raw($ssr['body'] ?? '')}</div>
        <noscript><p style="padding:2rem;font-family:system-ui">This site needs JavaScript enabled.</p></noscript>
        <script type="application/json" id="kestello-page">{$pageJson}</script>
        <script type="module" nonce="{$nonce}">import { boot } from '@kestello/app'; boot({$boot});</script>
        {$this->raw($body['close'])}</body>
        </html>

        HTML;
    }

    /**
     * The page's saved HTML, or null; then the page waits for an
     * administrator's browser to draw it, and its cached copy carries a tag
     * so it is dropped when the HTML arrives.
     *
     * @param array{component: string, props: array<string, mixed>, url: string, version: string} $data
     * @return array{head: string, body: string, title: string}|null
     */
    private function saved(array $data, string $pageJson, int $status): ?array
    {
        if ($this->prerender === null) {
            return null;
        }
        $key = Prerender\PrerenderStore::key($pageJson);
        try {
            $saved = $this->prerender->find($key);
            if ($saved !== null) {
                return ['head' => '', 'body' => $saved['body'], 'title' => $saved['title']];
            }
            if ($status === 200 && self::plainAddress($data['url'])) {
                \Kestello\Cms\Cache\PageTags::add(self::tag($key));
                $this->prerender->want($key, [
                    'url' => $data['url'],
                    'page' => $data,
                    'modules' => array_map(static fn(array $m): string => $m['url'], $this->registry->siteSsr()),
                    'messages' => SsrGateway::messages($data),
                ]);
            }
        } catch (Throwable $e) {
            error_log('[kestello] saved page HTML skipped: ' . $e->getMessage());     // a full or read-only disk never stops a page
        }

        return null;
    }

    /** The page cache tag of a page waiting for its HTML. */
    public static function tag(string $key): string
    {
        return 'html:' . substr($key, 0, 40);
    }

    /**
     * Whether every visitor gets this exact page: nobody signed in, no
     * session (form errors, a flash message, a CSRF token). Only those have
     * one key for everyone, so only those are saved.
     *
     * @param array{props: array<string, mixed>} $data
     */
    private static function sameForEveryone(array $data): bool
    {
        $p = $data['props'];

        return ($p['auth']['user'] ?? null) === null
            && ($p['csrfToken'] ?? '') === ''
            && (array) ($p['errors'] ?? []) === []     // shared as {} when empty
            && (array) ($p['old'] ?? []) === []
            && array_filter((array) ($p['flash'] ?? [])) === [];
    }

    /** No query string but a page number: other queries are endless, and would push real pages out of the queue. */
    private static function plainAddress(string $url): bool
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return $query === '' || preg_match('/^page=\d{1,4}$/', $query) === 1;
    }

    private function escape(string $value): string
    {
        return self::e($value);
    }

    /** Pre-escaped or trusted HTML (SSR output, filters run by trusted packages). */
    private function raw(string $html): string
    {
        return $html;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return array{url: string, integrity: string}
     */
    private function vendor(): array
    {
        static $vendor = null;

        if ($vendor === null) {
            $data = json_decode((string) @file_get_contents($this->paths->coreAssets() . '/vendor/vendor.json'), true);
            $vue = is_array($data) ? ($data['vue'] ?? []) : [];
            $vendor = [
                'url' => '/assets/core/vendor/' . rawurlencode((string) ($vue['file'] ?? '')),
                'integrity' => (string) ($vue['integrity'] ?? ''),
            ];
        }

        return $vendor;
    }

    private static function sri(string $file): ?string
    {
        static $cache = [];

        try {
            $key = $file . '|' . filemtime($file) . '|' . filesize($file);
            return $cache[$key] ??= 'sha384-' . base64_encode(hash_file('sha384', $file, true));
        } catch (Throwable) {
            return null;
        }
    }

    /** The request address without tracking parameters (which the page cache ignores, so no visitor's end up in another's page). */
    private static function publicUri(Request $request): string
    {
        $query = array_diff_key($request->query, array_flip(\Kestello\Cms\Cache\PageCacheMiddleware::IGNORED_QUERY));

        return $request->path . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
