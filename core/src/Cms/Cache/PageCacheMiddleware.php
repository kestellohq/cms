<?php

declare(strict_types=1);

namespace Kestello\Cms\Cache;

use Closure;
use Kestello\Cms\Cms;
use Kestello\Cms\Frontend\Csp;
use Kestello\Cms\View\PageRenderer;
use Kestello\Contracts\Cache\PageCache;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Session\Session;
use Kestello\Framework\Settings\Settings;

/**
 * Guest pages from the page cache. The outermost site middleware:
 * a hit never reaches routing, the database or the renderer.
 *
 * Cached: GET/HEAD from visitors without a session cookie or credentials,
 * 200 responses without cookies, not marked private/no-store, with no session
 * started while rendering, for addresses whose query string is empty or only
 * ?page=N (campaign parameters such as utm_* are ignored). Never: admin, API,
 * sign-in, installer, system, uploads, search.
 *
 * The CSP nonce is stored as a placeholder and replaced with this request's
 * nonce on every hit, so cached pages never share a nonce.
 */
final class PageCacheMiddleware implements MiddlewareInterface
{
    public const array SKIP = ['/admin', '/api/', '/login', '/logout', '/install', '/system/', '/auth/', '/forgot-password', '/reset-password', '/uploads/', '/assets/', '/storage/', '/search'];

    /** Campaign and click ids: the page is the same with or without them. */
    public const array IGNORED_QUERY = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga', '_gl'];

    private const string NONCE = '__KESTELLO_NONCE__';

    private const int MAX_BYTES = 2_097_152;

    private const array TYPES = ['text/html', 'application/json', 'application/xml', 'application/rss+xml', 'application/atom+xml', 'text/plain', 'text/xml'];

    public function __construct(private readonly Cms $cms) {}

    public function handle(Request $request, Closure $next): Response
    {
        PageTags::reset();
        $key = $this->key($request);
        if ($key === null) {
            return $next($request);
        }
        $cache = $this->cms->container->make(PageCache::class);
        try {
            $hit = $cache->get($key);
        } catch (\Throwable $e) {
            error_log('[kestello] page cache unavailable: ' . $e->getMessage());
            $hit = null;
        }
        if ($hit !== null) {
            return $this->serve($request, $hit, 'HIT');
        }

        $response = $next($request);
        if (!$this->cacheable($response)) {
            return $response;
        }
        $nonce = $this->nonce();
        $headers = [];
        foreach ($response->headers() as $name => $value) {
            if (!in_array(strtolower($name), ['cache-control', 'etag', 'expires', 'pragma', 'last-modified', 'set-cookie', 'x-kestello-cache'], true)) {
                $headers[$name] = $nonce !== '' ? str_replace($nonce, self::NONCE, $value) : $value;
            }
        }
        $body = $nonce !== '' ? str_replace($nonce, self::NONCE, $response->content()) : $response->content();
        $page = ['status' => $response->statusCode(), 'headers' => $headers, 'body' => $body, 'etag' => '"' . substr(hash('sha256', $key . "\0" . $body), 0, 32) . '"'];
        $ttl = PageTags::ttl($this->setting('page_cache_ttl', 86400, 60, 604800));
        if ($ttl === 0) {
            return $response;
        }
        try {
            $cache->put($key, $page, PageTags::all() ?: ['content'], $ttl);
        } catch (\Throwable $e) {
            error_log('[kestello] page cache write failed: ' . $e->getMessage());
            return $response;
        }

        foreach ($this->cacheHeaders($page['etag'], 'MISS') as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** The cache key, or null when this request must not use the cache. */
    private function key(Request $request): ?string
    {
        if (($request->method !== 'GET' && $request->method !== 'HEAD') || !$this->enabled()) {
            return null;
        }
        foreach (self::SKIP as $prefix) {
            if ($request->path === rtrim($prefix, '/') || str_starts_with($request->path, $prefix)) {
                return null;
            }
        }
        $session = $this->cms->container->has(Session::class) ? $this->cms->container->make(Session::class)->name() : 'cms_session';
        if (isset($request->cookies[$session]) || $request->header('Authorization') !== '') {
            return null;       // signed in (or might be): personal pages
        }
        $version = $this->cms->container->has(PageRenderer::class)
            ? $this->cms->container->make(PageRenderer::class)->registry()->version()   // builds, themes, plugins
            : $this->cms->version();
        $navigating = $request->header('X-Kestello') === 'true';
        if ($navigating && $request->header('X-Kestello-Version') !== '' && $request->header('X-Kestello-Version') !== $version) {
            return null;       // an old client: FrontendMiddleware tells it to reload
        }
        $query = array_diff_key($request->query, array_flip(self::IGNORED_QUERY));
        $allowed = (array) $this->cms->filters->apply('pagecache.query', ['page']);
        foreach ($query as $name => $value) {
            if (!in_array($name, $allowed, true) || !is_string($value) || strlen($value) > 50) {
                return null;   // unknown parameters: every value would be a new page to store
            }
        }
        if (isset($query['page']) && preg_match('/^[1-9][0-9]{0,4}$/', $query['page']) !== 1) {
            return null;
        }
        ksort($query);

        return hash('sha256', implode("\0", [$version, $navigating ? 'json' : 'html', $request->path, http_build_query($query)]));
    }

    private function cacheable(Response $response): bool
    {
        if ($response->statusCode() !== 200 || $response->cookies() !== [] || $response->bodyStream() !== null
            || PageTags::isUncacheable() || session_status() === PHP_SESSION_ACTIVE || strlen($response->content()) > self::MAX_BYTES) {
            return false;
        }
        $type = '';
        foreach ($response->headers() as $name => $value) {
            $lower = strtolower($name);
            if ($lower === 'cache-control' && preg_match('/\b(private|no-store)\b/i', $value) === 1) {
                return false;
            }
            if ($lower === 'set-cookie') {
                return false;
            }
            if ($lower === 'content-type') {
                $type = strtolower(trim(explode(';', $value)[0]));
            }
        }

        return in_array($type, self::TYPES, true);
    }

    /** @param array{status: int, headers: array<string, string>, body: string, etag: string} $page */
    private function serve(Request $request, array $page, string $state): Response
    {
        $headers = $this->cacheHeaders($page['etag'], $state);
        if (\Kestello\Framework\Core\Compression::etagMatches($request->header('If-None-Match'), $page['etag'])) {
            $vary = $page['headers']['Vary'] ?? null;
            return new Response('', 304, $headers + ($vary !== null ? ['Vary' => $vary] : []));
        }
        $nonce = $this->nonce();
        $out = [];
        foreach ($page['headers'] as $name => $value) {
            $out[$name] = str_replace(self::NONCE, $nonce, $value);
        }

        return new Response(str_replace(self::NONCE, $nonce, $page['body']), $page['status'], $headers + $out);
    }

    /** @return array<string, string> */
    private function cacheHeaders(string $etag, string $state): array
    {
        // Browsers always revalidate (a purge can't reach them); a CDN may keep pages for "cdn_max_age".
        $shared = $this->setting('cdn_max_age', 0, 0, 86400);
        $control = 'public, max-age=0, must-revalidate' . ($shared > 0 ? ", s-maxage={$shared}, stale-while-revalidate=" . min(86400, $shared * 10) : '');

        return ['Cache-Control' => $control, 'ETag' => $etag, 'X-Kestello-Cache' => $state];
    }

    private function nonce(): string
    {
        return $this->cms->container->has(Csp::class) ? $this->cms->container->make(Csp::class)->nonce() : '';
    }

    private function enabled(): bool
    {
        $value = Settings::getInstance()->get('page_cache');
        if ($value === null || $value === '') {
            return !(bool) $this->cms->config->get('app.debug', false);    // on by default, off while debugging
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function setting(string $key, int $default, int $min, int $max): int
    {
        $value = Settings::getInstance()->get($key);

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}
