<?php

declare(strict_types=1);

namespace Kestello\Cms\View;

use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\Package;
use Kestello\Cms\Extension\PackageType;
use Kestello\Framework\Core\Paths;

/**
 * Every Vue page and layout the site can render, built from the Vite
 * manifests of core and active packages (assets/build/.vite/manifest.json).
 *
 * Names and precedence (first match wins):
 *   1. active theme, then its parents   'Home', 'layouts/Site', and overrides
 *                                       such as '@core/auth/Login', '@shop/Cart'
 *   2. active plugins                   '@<slug>/…' only (cannot hijack others)
 *   3. core                             '@core/…' and fallback 'layouts/Site'
 *
 * Only registered components can be loaded by the browser or the SSR server.
 */
final class PageRegistry
{
    /** Core pages that wear the site theme (and its stylesheets) rather than the admin skin. */
    public const array CORE_SITE_PAGES = ['@core/errors/', '@core/content/', '@core/blocks/'];


    /** @var array<string, array{owner: string, url: string, css: list<string>, js: list<array{path: string, url: string}>, ssr: ?string}>|null */
    private ?array $entries = null;

    /** Core's shared browser modules (import map names) and their sources. */
    public const array MODULES = [
        '@kestello/app' => 'resources/js/app/index.js',
        '@kestello/sdk' => 'resources/js/sdk/index.js',
        '@kestello/ui' => 'resources/js/ui/index.js',
    ];

    /** @var array<string, array{path: string, url: string, ssr: ?string}> */
    private array $modules = [];

    /** @var list<string> manifests read, for the asset version */
    private array $manifests = [];

    public function __construct(
        private readonly Paths $paths,
        private readonly ?ExtensionManager $extensions,
        private readonly string $coreVersion,
        private readonly ?\Closure $extraThemeStylesheets = null,
    ) {}

    public function has(string $name): bool
    {
        return isset($this->entries()[$name]);
    }

    /**
     * @return array{owner: string, url: string, css: list<string>, js: list<array{path: string, url: string}>, ssr: ?string}|null
     */
    public function get(string $name): ?array
    {
        return $this->entries()[$name] ?? null;
    }

    /**
     * What the browser runtime receives: name => { file, css }.
     *
     * @return array<string, array{file: string, css: list<string>}>
     */
    public function client(): array
    {
        $out = [];
        foreach ($this->entries() as $name => $entry) {
            $out[$name] = ['file' => $entry['url'], 'css' => $entry['css']];
        }

        return $out;
    }

    /**
     * Names of all layouts (the SSR server needs them to resolve string layouts).
     *
     * @return list<string>
     */
    public function layouts(): array
    {
        return array_values(array_filter(array_keys($this->entries()), static fn(string $n): bool => str_starts_with($n, 'layouts/')));
    }

    /**
     * The @kestello/app runtime module.
     *
     * @return array{path: string, url: string, ssr: ?string}|null
     */
    public function runtime(): ?array
    {
        $this->entries();

        return $this->modules['@kestello/app'] ?? null;
    }

    /**
     * Shared modules for the import map: name => {path, url, ssr}.
     *
     * @return array<string, array{path: string, url: string, ssr: ?string}>
     */
    public function modules(): array
    {
        $this->entries();

        return $this->modules;
    }

    /**
     * Stylesheets of the admin app, and of every other core screen outside
     * the theme (sign-in, the installer): Phosphor icons and the UI kit.
     *
     * @return list<string>
     */
    public function adminStylesheets(): array
    {
        if ($this->adminSheets !== null) {
            return $this->adminSheets;
        }
        // The content hash busts long-lived caches whenever a file changes,
        // not only when the core version does.
        $sheets = [];
        foreach (['css/phosphor.css', 'css/admin-ui.css'] as $relative) {
            $file = $this->paths->coreAssets() . '/' . $relative;
            $sheets[] = '/assets/core/~' . rawurlencode($this->coreVersion) . '/' . $relative . '?h=' . (is_file($file) ? self::fileHash($file) : '0');
        }

        // The shared modules' own CSS (the UI kit's component styles). Pages
        // reach these modules through the import map, so walking a page's
        // imports never finds it. Read from core's manifest directly: plugins
        // are collected before core, and their admin pages need it too.
        $buildDir = $this->paths->coreAssets() . '/build';
        $manifest = self::readJson($buildDir . '/.vite/manifest.json') ?? [];
        foreach (self::MODULES as $src) {
            if (isset($manifest[$src])) {
                $seen = [];
                array_push($sheets, ...$this->walk($manifest, $src, $buildDir, '/assets/core/~' . rawurlencode($this->coreVersion) . '/build/', $seen)[1]);
            }
        }

        return $this->adminSheets = array_values(array_unique($sheets));
    }

    /** @var list<string>|null */
    private ?array $adminSheets = null;

    /** A short content hash, once per file per request (every admin page shares the same stylesheet). */
    private static function fileHash(string $file): string
    {
        static $hashes = [];
        $stat = @stat($file);
        $key = $file . ':' . ($stat['mtime'] ?? 0) . ':' . ($stat['size'] ?? 0);

        return $hashes[$key] ??= substr(hash_file('sha256', $file), 0, 10);
    }

    /**
     * Changes whenever any build changes; clients reload on mismatch.
     */
    public function version(): string
    {
        // Only stats the manifests (no parsing): the page cache asks on every request.
        if ($this->version === null) {
            $parts = [$this->coreVersion];
            foreach ($this->manifestFiles() as $file) {
                if (is_file($file)) {
                    $parts[] = $file . ':' . (string) @filemtime($file) . ':' . (string) @filesize($file);
                }
            }
            $this->version = substr(md5(implode('|', $parts)), 0, 12);
        }

        return $this->version;
    }

    private ?string $version = null;

    /** The manifests entries() reads, in the same order. @return list<string> */
    private function manifestFiles(): array
    {
        $files = [];
        foreach ($this->extensions?->themeChain() ?? [] as $theme) {
            $files[] = $theme->assetsPath() . '/build/.vite/manifest.json';
        }
        foreach ($this->extensions?->activePlugins() ?? [] as $plugin) {
            $files[] = $plugin->assetsPath() . '/build/.vite/manifest.json';
        }
        $files[] = $this->paths->coreAssets() . '/build/.vite/manifest.json';

        return $files;
    }

    /**
     * @return array<string, array{owner: string, url: string, css: list<string>, js: list<array{path: string, url: string}>, ssr: ?string}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $this->entries = [];
        $themeStyles = $this->themeStylesheets();

        foreach ($this->extensions?->themeChain() ?? [] as $theme) {
            $this->collect($theme->owner(), $theme->assetsPath() . '/build', $this->packageUrl($theme) . 'build/', null, $themeStyles);
        }

        foreach ($this->extensions?->activePlugins() ?? [] as $plugin) {
            $this->collect($plugin->owner(), $plugin->assetsPath() . '/build', $this->packageUrl($plugin) . 'build/', '@' . $plugin->slug() . '/', $themeStyles);
        }

        $this->collect('core', $this->paths->coreAssets() . '/build', '/assets/core/~' . rawurlencode($this->coreVersion) . '/build/', '@core/', $this->adminStylesheets(), $themeStyles);

        return $this->entries;
    }

    /**
     * @param list<string> $stylesheets
     * @param list<string> $siteStylesheets for core pages/layouts skinned by the theme
     */
    private function collect(string $owner, string $buildDir, string $baseUrl, ?string $prefix, array $stylesheets, array $siteStylesheets = []): void
    {
        $manifestFile = $buildDir . '/.vite/manifest.json';
        $manifest = self::readJson($manifestFile);
        if ($manifest === null) {
            return;
        }
        $this->manifests[] = $manifestFile;
        $ssrManifest = self::readJson($buildDir . '/ssr/.vite/manifest.json') ?? [];

        foreach ($manifest as $src => $chunk) {
            if (!is_array($chunk) || empty($chunk['isEntry']) || !is_string($chunk['file'] ?? null)) {
                continue;
            }

            if ($owner === 'core' && ($module = array_search($src, self::MODULES, true)) !== false) {
                $ssrFile = $ssrManifest[$src]['file'] ?? null;
                $this->modules[$module] ??= [
                    'path' => $buildDir . '/' . $chunk['file'],
                    'url' => $baseUrl . $chunk['file'],
                    'ssr' => is_string($ssrFile) ? $buildDir . '/ssr/' . $ssrFile : null,
                ];
                continue;
            }

            if (preg_match('#^resources/js/(pages|layouts)/(.+)\.vue$#', (string) $src, $m) !== 1) {
                continue;
            }

            $name = self::name($m[1], $m[2], $prefix);
            if ($name === null || isset($this->entries[$name])) {
                continue; // a higher-precedence package already provides it
            }

            [$js, $css] = $this->walk($manifest, (string) $src, $buildDir, $baseUrl);
            $ssrFile = $ssrManifest[$src]['file'] ?? null;

            $sheets = $stylesheets;
            if ($owner === 'core' && $siteStylesheets !== [] && ($name === 'layouts/Site' || self::isSitePage($name))) {
                $sheets = $siteStylesheets;
            }
            if ($this->isAdminPage($name, $owner, $prefix)) {
                $sheets = $this->adminStylesheets();
            }

            $this->entries[$name] = [
                'owner' => $owner,
                'url' => $baseUrl . $chunk['file'],
                'css' => array_values(array_unique([...$sheets, ...$css])),
                'js' => $js,
                'ssr' => is_string($ssrFile) ? $buildDir . '/ssr/' . $ssrFile : null,
            ];
        }
    }

    /** New admin pages wear the admin UI kit, not the theme. */
    private function isAdminPage(string $name, string $owner, ?string $prefix): bool
    {
        if ($name === 'layouts/Admin') {
            return true;
        }
        // Field inputs (pages/fields/*) render inside the admin editor.
        if ($owner === 'core') {
            return str_starts_with($name, '@core/admin/') || str_starts_with($name, '@core/fields/');
        }

        return $prefix !== null && (str_starts_with($name, $prefix . 'admin/') || str_starts_with($name, $prefix . 'fields/'));
    }

    /** @internal Whether a core component renders inside the site theme (and takes its stylesheets). */
    public static function isSitePage(string $name): bool
    {
        foreach (self::CORE_SITE_PAGES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function name(string $kind, string $relative, ?string $prefix): ?string
    {
        if ($prefix === null) {
            // Themes name pages freely, including overrides of core/plugin pages.
            return $kind === 'layouts' ? 'layouts/' . $relative : $relative;
        }

        if ($prefix === '@core/') {
            return $kind === 'layouts' ? 'layouts/' . $relative : '@core/' . $relative;
        }

        // Plugins live in their own namespace; their layouts are not global.
        return $prefix . ($kind === 'layouts' ? 'layouts/' : '') . $relative;
    }

    /**
     * The entry's JS files (entry + static imports) and CSS, depth first.
     *
     * @param array<string, mixed> $manifest
     * @return array{0: list<array{path: string, url: string}>, 1: list<string>}
     */
    private function walk(array $manifest, string $key, string $buildDir, string $baseUrl, array &$seen = []): array
    {
        if (isset($seen[$key]) || !is_array($manifest[$key] ?? null)) {
            return [[], []];
        }
        $seen[$key] = true;
        $chunk = $manifest[$key];

        $js = [['path' => $buildDir . '/' . $chunk['file'], 'url' => $baseUrl . $chunk['file']]];
        $css = [];

        foreach ($chunk['imports'] ?? [] as $import) {
            [$moreJs, $moreCss] = $this->walk($manifest, (string) $import, $buildDir, $baseUrl, $seen);
            array_push($js, ...$moreJs);
            array_push($css, ...$moreCss);
        }
        foreach ($chunk['css'] ?? [] as $file) {
            $css[] = $baseUrl . $file;
        }

        return [$js, $css];
    }

    /**
     * Stylesheets declared by the active theme chain (parents first).
     *
     * @return list<string>
     */
    private function themeStylesheets(): array
    {
        $urls = [];

        foreach (array_reverse($this->extensions?->themeChain() ?? []) as $theme) {
            foreach ($theme->manifest->stylesheets as $sheet) {
                if (str_starts_with($sheet, '/')) {
                    $urls[] = $sheet;
                    continue;
                }
                $file = $theme->assetsPath() . '/' . $sheet;
                $urls[] = $this->packageUrl($theme) . implode('/', array_map('rawurlencode', explode('/', $sheet)))
                    . (is_file($file) && !str_contains($sheet, '..') ? '?h=' . self::fileHash($file) : '');
            }
        }

        // generated last (global styles), so they win over the theme's files
        foreach ($this->extraThemeStylesheets !== null ? ($this->extraThemeStylesheets)() : [] as $url) {
            if (is_string($url) && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private function packageUrl(Package $package): string
    {
        $kind = $package->manifest->type === PackageType::Plugin ? 'plugins' : 'themes';

        return "/assets/{$kind}/{$package->slug()}/~" . rawurlencode($package->version()) . '/';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }
}
