<?php

declare(strict_types=1);

namespace Kestello\Cms\Design;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\Manifest;
use Kestello\Cms\Extension\Package;
use Kestello\Cms\Extension\PackageType;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Zip\Zip;
use Kestello\Support\Zip\ZipEntry;

/**
 * Themes made, exported and imported in the admin.
 *
 * A theme made or imported here is **data only**: a child theme holding
 * a manifest, design token values, custom CSS, templates, parts,
 * patterns, fonts and a screenshot. It carries no PHP and no Vue code,
 * so installing one can't run code on the server or ship script to
 * visitors. Themes with code arrive the developer way (CLI or, from M6,
 * signed packages).
 */
final class ThemePackages
{
    /** Folders and files a data-only theme may contain. */
    private const string ALLOWED = '#^(?:theme\.json|styles\.json|README\.md|LICENSE(?:\.md|\.txt)?|screenshot\.(?:png|jpe?g|webp)|(?:templates|parts|patterns)/[a-z0-9][a-z0-9-]{0,60}\.json|fonts/fonts\.json|fonts/gf-[a-z0-9-]{1,60}/[a-z0-9-]{1,80}\.woff2)$#';
    private const int MAX_ZIP = 32 << 20;
    private const int MAX_UNPACKED = 64 << 20;

    public function __construct(
        private readonly Cms $cms,
        private readonly ThemeDocuments $documents,
        private readonly ThemeTemplates $templates,
        private readonly ThemeStyles $styles,
        private readonly ThemeFonts $fonts,
    ) {}

    /** Themes for the Themes screen. @return list<array<string, mixed>> */
    public function list(): array
    {
        $ext = $this->cms->extensions();
        $active = $ext->configuredTheme();   // a switch takes effect on the next request
        $out = [];
        foreach ($ext->themes() as $slug => $theme) {
            $out[] = [
                'slug' => $slug,
                'name' => $theme->manifest->name,
                'version' => $theme->version(),
                'description' => $theme->manifest->description,
                'parent' => $theme->manifest->parent,
                'active' => $slug === $active,
                'data_only' => self::dataOnly($theme->path),
                'screenshot' => $this->screenshot($theme) !== null,
                'author' => is_array($theme->manifest->raw['author'] ?? null) ? ($theme->manifest->raw['author']['name'] ?? null) : null,
            ];
        }
        usort($out, static fn($a, $b) => [$b['active'], $a['name']] <=> [$a['active'], $b['name']]);

        return $out;
    }

    public function screenshot(Package $theme): ?string
    {
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            if (is_file($theme->path('screenshot.' . $ext))) {
                return $theme->path('screenshot.' . $ext);
            }
        }

        return null;
    }

    /**
     * The new-theme wizard: a data-only child theme of $parent. With
     * $fromSite, the running theme's site changes (styles, templates,
     * parts, patterns) become the new theme's files.
     *
     * @param array{name: string, slug?: string, description?: string, parent: string, variation?: ?string, from_site?: bool, author?: string} $input
     * @throws ContentException
     */
    public function create(array $input): Package
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            throw ContentException::invalid(['name' => ['Give the theme a name of up to 60 characters.']]);
        }
        $slug = trim((string) ($input['slug'] ?? '')) ?: self::slugify($name);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 64) {
            throw ContentException::invalid(['slug' => ['Use lowercase letters, digits and single dashes.']]);
        }
        $themes = $this->cms->extensions()->themes();
        if (isset($themes[$slug]) || is_dir($this->cms->paths->themes() . '/' . $slug)) {
            throw ContentException::invalid(['slug' => ['A theme with this folder name already exists.']]);
        }
        $parent = (string) ($input['parent'] ?? '');
        if (!isset($themes[$parent])) {
            throw ContentException::invalid(['parent' => ['Choose an installed theme to start from.']]);
        }
        $fromSite = !empty($input['from_site']);
        if ($fromSite && $this->cms->extensions()->activeTheme()?->slug() !== $parent) {
            throw ContentException::invalid(['from_site' => ['Site changes can only be copied from the running theme.']]);
        }

        $files = ['theme.json' => self::json([
            'slug' => $slug,
            'name' => $name,
            'version' => '1.0.0',
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 300),
            'author' => ['name' => mb_substr(trim((string) ($input['author'] ?? '')), 0, 100)],
            'requires' => ['core' => self::coreConstraint($this->cms->extensions()->coreVersion())],
            'parent' => $parent,
        ])];

        $styles = [];
        $variation = $input['variation'] ?? null;
        if (is_string($variation) && $variation !== '') {
            $def = $this->styles->definition();
            if (!isset($def['variations'][$variation])) {
                throw ContentException::invalid(['variation' => ['The theme has no such style variation.']]);
            }
            $styles['values'] = $def['variations'][$variation]['values'];
        }
        if ($fromSite) {
            $styles = $this->siteStyles($styles);
            $files += $this->siteDocuments($parent);
        }
        if ($styles !== []) {
            $files['styles.json'] = self::json($styles);
        }

        return $this->write($slug, $files, fonts: $fromSite ? $this->usedFonts($styles) : []);
    }

    /**
     * A zip of a theme. For the running theme, $withSite puts the site's
     * changes into the zip as the theme's own files, so another site
     * looks the same after importing it. Hosted fonts the theme uses go in
     * as well.
     *
     * @return string path of the zip (in storage/tmp; the caller removes it)
     */
    public function export(string $slug, bool $withSite = true): string
    {
        $theme = $this->cms->extensions()->themes()[$slug] ?? throw new \OutOfBoundsException('No such theme.');
        if (!self::dataOnly($theme->path)) {
            throw ContentException::conflict('This theme contains code. Package it with its developer tools instead; only themes made in the admin can be exported here.');
        }
        $isActive = $this->cms->extensions()->activeTheme()?->slug() === $slug;
        $files = [];
        foreach (self::walk($theme->path) as $relative) {
            if ($relative !== 'fonts/fonts.json' && !str_starts_with($relative, 'fonts/')) {
                $files[$relative] = (string) file_get_contents($theme->path . '/' . $relative);
            }
        }
        $styles = isset($files['styles.json']) ? (json_decode($files['styles.json'], true) ?: []) : [];
        if ($isActive && $withSite) {
            $styles = $this->siteStyles($styles);
            $files = array_replace($files, $this->siteDocuments($slug));
        }
        if ($styles !== []) {
            $files['styles.json'] = self::json($styles);
        }
        $fonts = array_unique([...$this->usedFonts($styles), ...$this->themeFontSlugs($theme)]);

        $dir = $this->cms->paths->storage('tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/theme-' . $slug . '-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = Zip::create($path);
        foreach ($files as $name => $contents) {
            $zip->addString($slug . '/' . $name, $contents);
        }
        $this->addFonts($fonts, static fn(string $name, string $file) => $zip->addFile($file, $slug . '/' . $name), static fn(string $json) => $zip->addString($slug . '/fonts/fonts.json', $json));
        $zip->close();

        return $path;
    }

    /**
     * Install a theme zip. Everything in it is checked: only data files,
     * a valid manifest, an installed parent, no code.
     *
     * @return array{theme: Package, replaced: bool, fonts: list<string>}
     * @throws ContentException
     */
    public function import(string $zipPath, bool $replace = false): array
    {
        if (!is_file($zipPath) || filesize($zipPath) > self::MAX_ZIP) {
            throw ContentException::invalid(['file' => ['Choose a theme zip of up to 32 MB.']]);
        }
        try {
            $reader = Zip::open($zipPath);
            $entries = array_values(array_filter($reader->entries(), static fn(ZipEntry $e) => !$e->isDirectory));
        } catch (\Throwable $e) {
            throw ContentException::invalid(['file' => ['This is not a zip file: ' . $e->getMessage()]]);
        }
        // one top folder ("my-theme/…") or the files at the root
        $names = array_map(static fn(ZipEntry $e) => $e->name, $entries);
        $names = array_values(array_filter($names, static fn(string $n) => !str_starts_with($n, '__MACOSX/') && basename($n) !== '.DS_Store'));
        $prefix = '';
        if (!in_array('theme.json', $names, true)) {
            $tops = array_unique(array_map(static fn(string $n) => explode('/', $n, 2)[0], $names));
            if (count($tops) !== 1 || !in_array($tops[0] . '/theme.json', $names, true)) {
                throw ContentException::invalid(['file' => ['The zip has no theme.json. Export themes from Appearance → Themes.']]);
            }
            $prefix = $tops[0] . '/';
        }
        $refused = [];
        foreach ($names as $n) {
            if (!str_starts_with($n, $prefix) || preg_match(self::ALLOWED, substr($n, strlen($prefix))) !== 1) {
                $refused[] = $n;
            }
        }
        if ($refused !== []) {
            throw ContentException::invalid(['file' => ['Only data-only themes can be imported here, and this one contains: ' . implode(', ', array_slice($refused, 0, 5)) . (count($refused) > 5 ? ' and ' . (count($refused) - 5) . ' more' : '') . '. Themes with code are installed by a developer.']]);
        }

        $tmp = $this->cms->paths->storage('tmp/theme-import-' . bin2hex(random_bytes(6)));
        try {
            $wanted = array_flip($names);
            $reader->extractTo($tmp . '/x', self::MAX_UNPACKED, 200, static fn(ZipEntry $e) => isset($wanted[$e->name]));
            $root = $tmp . '/x' . ($prefix === '' ? '' : '/' . rtrim($prefix, '/'));
            // the folder must carry the theme's slug
            $declared = json_decode((string) @file_get_contents($root . '/theme.json'), true);
            $declaredSlug = is_array($declared) && is_string($declared['slug'] ?? null) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $declared['slug']) === 1 ? $declared['slug'] : null;
            if ($declaredSlug === null) {
                throw ContentException::invalid(['file' => ['theme.json has no valid slug.']]);
            }
            if (basename($root) !== $declaredSlug) {
                $moved = $tmp . '/_/' . $declaredSlug;
                mkdir(dirname($moved), 0775, true);
                if (!rename($root, $moved)) {
                    throw ContentException::conflict('The theme could not be unpacked.');
                }
                $root = $moved;
            }
            try {
                $manifest = Manifest::load($root, PackageType::Theme);
            } catch (\Throwable $e) {
                throw ContentException::invalid(['file' => ['theme.json is not valid: ' . $e->getMessage()]]);
            }
            if ($manifest->provider !== null || $manifest->namespace !== null) {
                throw ContentException::invalid(['file' => ['The theme declares PHP code. Themes with code are installed by a developer.']]);
            }
            $themes = $this->cms->extensions()->themes();
            if ($manifest->parent === null || !isset($themes[$manifest->parent])) {
                throw ContentException::invalid(['file' => ['This theme builds on "' . ($manifest->parent ?? 'nothing') . '", which is not installed here. Install that theme first.']]);
            }
            if (isset($themes[$manifest->slug]) && !$replace) {
                throw ContentException::invalid(['replace' => ['A theme called "' . $manifest->slug . '" is already installed. Replace it to update it.']]);
            }
            if (isset($themes[$manifest->slug]) && !self::dataOnly($themes[$manifest->slug]->path)) {
                throw ContentException::invalid(['file' => ['The installed "' . $manifest->slug . '" contains code and can\'t be replaced from the admin.']]);
            }
            foreach (['templates', 'parts', 'patterns'] as $folder) {
                foreach (glob($root . '/' . $folder . '/*.json') ?: [] as $f) {
                    if (!is_array(json_decode((string) file_get_contents($f), true))) {
                        throw ContentException::invalid(['file' => [$folder . '/' . basename($f) . ' is not valid JSON.']]);
                    }
                }
            }
            if (is_file($root . '/styles.json')) {
                $raw = json_decode((string) file_get_contents($root . '/styles.json'), true);
                if (!is_array($raw)) {
                    throw ContentException::invalid(['file' => ['styles.json is not valid JSON.']]);
                }
                if (is_string($raw['custom_css'] ?? null) && ($problem = ThemeStyles::checkCss($raw['custom_css'])) !== null) {
                    throw ContentException::invalid(['file' => ['The theme\'s CSS is refused: ' . $problem]]);
                }
            }
            $installedFonts = $this->installFonts($root);
            $replaced = isset($themes[$manifest->slug]);
            $theme = $this->cms->extensions()->install($root, PackageType::Theme, overwrite: $replaced);
            $this->templates->forget();
            $this->styles->forget();

            return ['theme' => $theme, 'replaced' => $replaced, 'fonts' => $installedFonts];
        } finally {
            self::removeTree($tmp);
        }
    }

    /** Remove an inactive data-only theme (and the site's changes to it). */
    public function delete(string $slug): void
    {
        $ext = $this->cms->extensions();
        $theme = $ext->themes()[$slug] ?? throw new \OutOfBoundsException('No such theme.');
        if ($ext->activeTheme()?->slug() === $slug || $ext->configuredTheme() === $slug) {
            throw ContentException::conflict('Switch to another theme before deleting this one.');
        }
        foreach ($ext->themes() as $other) {
            if ($other->manifest->parent === $slug) {
                throw ContentException::conflict('"' . $other->manifest->name . '" builds on this theme. Delete it first.');
            }
        }
        if (!self::dataOnly($theme->path)) {
            throw ContentException::conflict('This theme contains code; a developer removes it.');
        }
        self::removeTree($theme->path);
        QueryBuilder::table('theme_documents')->where('theme', $slug)->delete();
        QueryBuilder::table('theme_document_revisions')->where('theme', $slug)->delete();
        $ext->discover(force: true);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** True when every file of the theme is one a data-only theme may hold. */
    public static function dataOnly(string $path): bool
    {
        foreach (self::walk($path) as $relative) {
            if (preg_match(self::ALLOWED, $relative) !== 1) {
                return false;
            }
        }
        $manifest = json_decode((string) @file_get_contents($path . '/theme.json'), true);

        return is_array($manifest) && empty($manifest['provider']) && empty($manifest['namespace']) && !empty($manifest['parent']);
    }

    /** @return list<string> relative paths of the files in a folder */
    private static function walk(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() || $file->isLink()) {
                $out[] = substr($file->getPathname(), strlen(rtrim($path, '/')) + 1);
            }
        }
        sort($out);

        return $out;
    }

    /** The site's published styles laid over a theme styles.json array. */
    private function siteStyles(array $styles): array
    {
        $published = $this->styles->published();
        $values = $this->styles->resolve($published);
        // only values that differ from the parent's defaults are written
        $defaults = array_map(static fn(array $t) => $t['value'], $this->styles->definition()['tokens']);
        $changed = array_filter($values, static fn($v, $k) => !array_key_exists($k, $defaults) || $defaults[$k] !== $v, ARRAY_FILTER_USE_BOTH);
        $styles['values'] = array_replace((array) ($styles['values'] ?? []), $changed);
        if ($styles['values'] === []) {
            unset($styles['values']);
        }
        $css = trim(trim((string) ($styles['custom_css'] ?? '')) . "\n\n" . trim($published['custom_css']));
        if ($css !== '') {
            $styles['custom_css'] = $css;
        }

        return $styles;
    }

    /** @return array<string, string> theme files for the site's published templates, parts and patterns */
    private function siteDocuments(string $theme): array
    {
        $files = [];
        foreach ($this->documents->allPublished($theme) as $doc) {
            if (isset(ThemeTemplates::KINDS[$doc['kind']]) && preg_match(ThemeTemplates::KEY_PATTERN, $doc['key']) === 1) {
                $files[ThemeTemplates::KINDS[$doc['kind']] . '/' . $doc['key'] . '.json'] = self::json($doc['data']);
            }
        }

        return $files;
    }

    /** @return list<string> hosted font slugs the styles use */
    private function usedFonts(array $styles): array
    {
        $out = [];
        $installed = $this->fonts->all();
        foreach ($this->styles->resolve(['variation' => null, 'values' => (array) ($styles['values'] ?? [])]) as $value) {
            if (is_string($value) && isset($installed[$value])) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function themeFontSlugs(Package $theme): array
    {
        $json = json_decode((string) @file_get_contents($theme->path('fonts/fonts.json')), true);

        return is_array($json) ? array_values(array_filter(array_keys($json), fn($k) => is_string($k) && isset($this->fonts->all()[$k]))) : [];
    }

    /**
     * @param list<string> $slugs
     * @param callable(string $name, string $file): mixed $addFile
     * @param callable(string $json): mixed $addIndex
     */
    private function addFonts(array $slugs, callable $addFile, callable $addIndex): void
    {
        $index = [];
        foreach ($slugs as $slug) {
            $font = $this->fonts->all()[$slug] ?? null;
            if ($font === null) {
                continue;
            }
            $index[$slug] = ['family' => $font['family'], 'category' => $font['category'], 'files' => $font['files']];
            $added = [];
            foreach ($font['files'] as $f) {
                $file = $this->fonts->file($slug, (string) $f['file']);
                if ($file !== null && !isset($added[$file])) {
                    $added[$file] = true;
                    $addFile('fonts/' . $slug . '/' . $f['file'], $file);
                }
            }
        }
        if ($index !== []) {
            $addIndex(self::json($index));
        }
    }

    /** Fonts shipped in a theme folder that this site doesn't have yet. @return list<string> */
    private function installFonts(string $root): array
    {
        $index = json_decode((string) @file_get_contents($root . '/fonts/fonts.json'), true);
        if (!is_array($index)) {
            return [];
        }
        $out = [];
        foreach ($index as $slug => $font) {
            if (!is_string($slug) || preg_match('/^gf-[a-z0-9-]{1,60}$/', $slug) !== 1 || !is_array($font) || isset($this->fonts->all()[$slug])) {
                continue;
            }
            $files = [];
            foreach ((array) ($font['files'] ?? []) as $f) {
                $src = $root . '/fonts/' . $slug . '/' . (string) ($f['file'] ?? '');
                if (is_array($f) && preg_match('/^[a-z0-9-]{1,80}\.woff2$/', (string) ($f['file'] ?? '')) === 1 && is_file($src) && str_starts_with((string) file_get_contents($src, false, null, 0, 4), 'wOF2')) {
                    $files[] = ['src' => $src] + $f;
                }
            }
            if ($files !== [] && $this->fonts->addLocal($slug, (string) ($font['family'] ?? ''), (string) ($font['category'] ?? 'sans-serif'), $files)) {
                $out[] = $slug;
            }
        }

        return $out;
    }

    /** @param array<string, string> $files */
    private function write(string $slug, array $files, array $fonts = []): Package
    {
        $outer = $this->cms->paths->storage('tmp/theme-new-' . bin2hex(random_bytes(6)));
        $tmp = $outer . '/' . $slug;
        try {
            foreach ($files as $name => $contents) {
                $target = $tmp . '/' . $name;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0775, true);
                }
                file_put_contents($target, $contents);
            }
            $this->addFonts($fonts, static function (string $name, string $file) use ($tmp): void {
                @mkdir(dirname($tmp . '/' . $name), 0775, true);
                copy($file, $tmp . '/' . $name);
            }, static fn(string $json) => file_put_contents($tmp . '/fonts/fonts.json', $json));

            return $this->cms->extensions()->install($tmp, PackageType::Theme);
        } finally {
            self::removeTree($outer);
        }
    }

    public static function slugify(string $name): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '-'));

        return substr($slug, 0, 64) ?: 'my-theme';
    }

    private static function coreConstraint(string $version): string
    {
        $parts = explode('.', preg_replace('/[^0-9.].*$/', '', $version) ?: '0.1.0');

        return '^' . ($parts[0] ?? '0') . '.' . ($parts[1] ?? '1');
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
