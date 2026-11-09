<?php

declare(strict_types=1);

namespace Kestello\Cms\View\Prerender;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\AssetController;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Frontend\Csp;
use Kestello\Cms\View\PageRegistry;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Session\Session;

/**
 * Where an administrator's browser draws waiting pages to HTML.
 *
 *   GET /system/prerender                  a page with no visible content, opened by the
 *                                          admin in a hidden frame: its import map points
 *                                          Vue and @kestello/* at their SSR builds, and
 *                                          frame.js draws each waiting page and sends
 *                                          the HTML to POST /api/v1/system/prerender/{key}
 *   GET /system/prerender/modules/{path}   those SSR builds (core/…, themes/<slug>/…,
 *                                          plugins/<slug>/…), for the same people only
 *
 * Only people who may manage the system (administrators) get either.
 */
final class PrerenderController
{
    public const string ROUTE = '/system/prerender';

    public const string CAPABILITY = 'system.manage';

    public function __construct(private readonly Cms $cms, private readonly PageRegistry $registry) {}

    public function frame(Request $request): Response
    {
        if (!$this->cms->container->has(PrerenderStore::class) || !$this->allowed($request)) {
            return Response::notFound();
        }
        $runtime = $this->registry->modules();
        $vue = '/assets/core/~' . rawurlencode($this->cms->version()) . '/build/prerender/vue.js';
        $imports = ['vue' => $vue, 'vue/server-renderer' => $vue];
        foreach ($runtime as $name => $module) {
            if ($module['ssrUrl'] === null) {
                return Response::notFound();     // core built without its SSR bundle
            }
            $imports[$name] = $module['ssrUrl'];
        }
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $map = json_encode(['imports' => $imports], $flags);
        $config = json_encode(['version' => $this->registry->version(), 'csrf' => $this->csrf()], $flags);
        $nonce = htmlspecialchars($this->cms->container->make(Csp::class)->nonce(), ENT_QUOTES, 'UTF-8');
        $script = '/assets/core/~' . rawurlencode($this->cms->version()) . '/build/prerender/frame.js';

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="robots" content="noindex">
        <title>Saving page HTML</title>
        <script type="importmap" nonce="{$nonce}">{$map}</script>
        <script type="application/json" id="kestello-prerender">{$config}</script>
        <script type="module" nonce="{$nonce}" src="{$script}"></script>
        </head>
        <body></body>
        </html>

        HTML;

        return (new Response($html, 200))
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    public function module(Request $request, string $path): Response
    {
        if (!$this->allowed($request)) {
            return Response::notFound();
        }
        if (preg_match('#^(core|themes/([a-z0-9-]+)|plugins/([a-z0-9-]+))/(.+)$#', $path, $m) !== 1) {
            return Response::notFound();
        }
        if ($m[1] === 'core') {
            $root = $this->cms->paths->coreAssets();
        } else {
            $type = str_starts_with($m[1], 'themes/') ? PackageType::Theme : PackageType::Plugin;
            $root = $this->cms->extensions()->findActive($type, $m[2] !== '' ? $m[2] : $m[3])?->assetsPath();
        }
        $file = $root === null ? null : AssetController::resolveSsr($root, $m[4]);
        if ($file === null) {
            return Response::notFound();
        }

        return (new Response((string) file_get_contents($file), 200))
            ->withHeader('Content-Type', 'text/javascript; charset=UTF-8')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // the address carries the package's version: kept by this browser only, never by a shared cache
            ->withHeader('Cache-Control', 'private, max-age=31536000, immutable')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    private function allowed(Request $request): bool
    {
        try {
            return $this->cms->auth->authenticate($request)->can(self::CAPABILITY);
        } catch (\Throwable) {
            return false;
        }
    }

    private function csrf(): string
    {
        $session = $this->cms->container->has(Session::class) ? $this->cms->container->make(Session::class) : null;

        return $session !== null && session_status() === PHP_SESSION_ACTIVE ? $session->token() : '';
    }

    /** @internal for the route list */
    public static function modulesRoute(): string
    {
        return PageRegistry::SSR_MODULES . '/{path}';
    }
}
