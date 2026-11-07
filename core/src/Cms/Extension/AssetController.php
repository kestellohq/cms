<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

use Kestello\Cms\Cms;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * Serves files from an active package's assets/ directory:
 *   /assets/plugins/{slug}/{path}   /assets/themes/{slug}/{path}
 *
 *   /assets/core/{path}             core/resources/assets (Vue, islands loader)
 *
 * Packages live outside the web root, so assets go through here. Only
 * whitelisted file types are served, never PHP; paths are confined to the
 * package's assets/ directory. Versioned URLs are cached forever: either
 * "?v=<version>" or a leading "~<version>/" path segment (used by the import
 * map, so relative imports between ES modules stay versioned too).
 */
final class AssetController
{
    private const array TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'map' => 'application/json',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'txt' => 'text/plain; charset=UTF-8',
    ];

    public function plugin(Request $request, string $slug, string $path): Response
    {
        return $this->serve($request, PackageType::Plugin, $slug, $path);
    }

    public function theme(Request $request, string $slug, string $path): Response
    {
        return $this->serve($request, PackageType::Theme, $slug, $path);
    }

    public function core(Request $request, string $path): Response
    {
        $root = Cms::instance()?->paths->coreAssets();

        return $root === null ? Response::notFound() : $this->coreFrom($root, $request, $path);
    }

    /**
     * A core asset from $root (core/resources/assets). The installer calls this
     * directly, because before installation there is no Cms to ask for paths.
     */
    public function coreFrom(string $root, Request $request, string $path): Response
    {
        [$path, $versioned] = self::stripVersion($path);
        $file = self::resolve($root, $path);

        // Vendored files carry their version in the filename.
        $versioned = $versioned || str_starts_with($path, 'vendor/');

        return $file === null ? Response::notFound() : $this->respond($request, $file, $versioned);
    }

    private function serve(Request $request, PackageType $type, string $slug, string $path): Response
    {
        $package = Cms::instance()?->extensions()->findActive($type, $slug);
        [$path, $versioned] = self::stripVersion($path);
        $file = $package !== null ? self::resolve($package->assetsPath(), $path) : null;

        if ($file === null) {
            return Response::notFound();
        }

        return $this->respond($request, $file, $versioned || isset($request->query['v']));
    }

    /**
     * "~1.2.0/js/app.js" → ["js/app.js", true]
     *
     * @return array{0: string, 1: bool}
     */
    private static function stripVersion(string $path): array
    {
        if (preg_match('#^~[0-9A-Za-z.+-]{1,64}/(.+)$#', $path, $m) === 1) {
            return [$m[1], true];
        }

        return [$path, false];
    }

    private function respond(Request $request, string $file, bool $versioned): Response
    {
        $mtime = (int) filemtime($file);
        $etag = '"' . md5($file . '|' . $mtime . '|' . filesize($file)) . '"';
        $headers = [
            'Content-Type' => self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))],
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
        ];

        if (str_ends_with($file, '.svg')) {
            // SVGs can carry script; never let one run in our origin when opened directly.
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        if (\Kestello\Framework\Core\Compression::etagMatches($request->header('If-None-Match'), $etag)) {
            return new Response('', 304, $headers);
        }

        return new Response((string) file_get_contents($file), 200, $headers);
    }

    /**
     * The real file for $path inside $root, or null if missing, outside root or not an allowed type.
     */
    public static function resolve(string $root, string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..') || str_starts_with($path, '/')) {
            return null;
        }

        // Hidden files (.vite manifests …) and server-only SSR bundles are never public.
        if (preg_match('#(^|/)\.|(^|/)build/ssr/#', $path) === 1) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            return null;
        }

        $realRoot = realpath($root);
        $realFile = realpath($root . '/' . $path);

        if ($realRoot === false || $realFile === false || !is_file($realFile) || !str_starts_with($realFile, $realRoot . '/')) {
            return null;
        }

        return $realFile;
    }
}
