<?php

declare(strict_types=1);

namespace Kestello\Cms\Design;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Http\Client\HttpClient;

/**
 * Fonts served from this site. A Google font is downloaded once, when
 * someone who designs adds it; visitors never contact Google, so no
 * consent banner is needed for fonts and nothing leaks to a third party.
 *
 * Files live in storage/fonts/{slug}/ and are served by /system/fonts/...
 * with a long cache. Only WOFF2 files from fonts.gstatic.com are accepted.
 */
final class ThemeFonts
{
    public const string ROUTE = '/system/fonts';
    public const array SUBSETS = ['latin', 'latin-ext'];
    public const array CATEGORIES = ['sans-serif' => 'sans-serif', 'serif' => 'serif', 'display' => 'sans-serif', 'handwriting' => 'cursive', 'monospace' => 'monospace'];
    private const int MAX_FILE = 1_048_576;
    private const int MAX_FILES = 24;
    private const string FAMILY = '/^[A-Za-z0-9][A-Za-z0-9 ]{0,60}$/';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $memo = null;

    public function __construct(private readonly Cms $cms, private readonly ?HttpClient $http = null) {}

    public function dir(string $slug = ''): string
    {
        return $this->cms->paths->storage('fonts' . ($slug === '' ? '' : '/' . $slug));
    }

    /**
     * Installed fonts, as font choices for design tokens.
     *
     * @return array<string, array{label: string, stack: string, family: string, category: string, files: list<array<string, mixed>>, bytes: int}>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $out = [];
        try {
            foreach (QueryBuilder::table('theme_fonts')->orderBy('family')->get() as $row) {
                $row = (array) $row;
                $family = (string) $row['family'];
                $fallback = self::CATEGORIES[(string) $row['category']] ?? 'sans-serif';
                $out[(string) $row['slug']] = [
                    'label' => $family, 'family' => $family, 'category' => (string) $row['category'],
                    'stack' => "'{$family}', {$fallback}",
                    'files' => json_decode((string) $row['files'], true) ?: [],
                    'bytes' => (int) $row['bytes'],
                ];
            }
        } catch (\Throwable) {
            // not migrated yet
        }

        return $this->memo = $out;
    }

    /** @font-face rules for these installed fonts, pointing at this site. */
    public function faces(array $slugs): string
    {
        $out = [];
        foreach (array_unique($slugs) as $slug) {
            $font = $this->all()[$slug] ?? null;
            if ($font === null) {
                continue;
            }
            foreach ($font['files'] as $f) {
                $range = isset($f['range']) && preg_match('/^[U+0-9A-Fa-f, ?-]{1,2000}$/', (string) $f['range']) === 1 ? "\n  unicode-range: {$f['range']};" : '';
                $out[] = "@font-face {\n  font-family: '{$font['family']}';\n  font-style: {$f['style']};\n  font-weight: {$f['weight']};\n  font-display: swap;\n  src: url(" . self::ROUTE . "/{$slug}/{$f['file']}) format('woff2');{$range}\n}";
            }
        }

        return implode("\n", $out);
    }

    /** The file to preload for a hosted font: regular weight, upright, latin; else its first file. */
    public function mainFileUrl(string $slug): ?string
    {
        $files = $this->all()[$slug]['files'] ?? [];
        if ($files === []) {
            return null;
        }
        $score = static fn(array $f): int => (($f['style'] ?? '') === 'normal' ? 4 : 0) + (($f['subset'] ?? 'latin') === 'latin' ? 2 : 0)
            + ((int) ($f['weight'] ?? 0) === 400 ? 1 : 0);
        usort($files, static fn(array $a, array $b): int => $score($b) <=> $score($a));

        return self::ROUTE . "/{$slug}/{$files[0]['file']}";
    }

    /**
     * Download a Google font (the weights asked for, normal and italic) and
     * keep it on this site.
     *
     * @param list<int> $weights
     * @return array<string, mixed> the installed font
     * @throws ContentException
     */
    public function addGoogle(string $family, array $weights = [400, 700], bool $italic = false, string $category = 'sans-serif'): array
    {
        $family = trim(preg_replace('/\s+/', ' ', $family) ?? '');
        if (preg_match(self::FAMILY, $family) !== 1) {
            throw ContentException::invalid(['family' => ['Use the family name as Google Fonts shows it, such as "Inter" or "Playfair Display".']]);
        }
        $weights = array_values(array_unique(array_filter(array_map('intval', $weights), static fn(int $w) => $w >= 100 && $w <= 900 && $w % 100 === 0)));
        sort($weights);
        if ($weights === []) {
            throw ContentException::invalid(['weights' => ['Choose at least one weight.']]);
        }
        if (!isset(self::CATEGORIES[$category])) {
            $category = 'sans-serif';
        }
        $slug = 'gf-' . trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $family) ?? ''), '-');
        if (isset($this->all()[$slug])) {
            throw ContentException::invalid(['family' => ['This font is already installed.']]);
        }
        $http = $this->http ?? $this->cms->container->make(HttpClient::class);

        $axes = $italic
            ? 'ital,wght@' . implode(';', [...array_map(static fn($w) => "0,{$w}", $weights), ...array_map(static fn($w) => "1,{$w}", $weights)])
            : 'wght@' . implode(';', $weights);
        $url = 'https://fonts.googleapis.com/css2?family=' . str_replace('%20', '+', rawurlencode($family)) . ':' . $axes . '&display=swap';
        try {
            // a current browser's User-Agent gets WOFF2 files
            $res = $http->get($url, ['headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', 'Accept' => 'text/css,*/*;q=0.1'], 'timeout' => 15, 'max_bytes' => 512_000]);
        } catch (\Throwable $e) {
            throw ContentException::conflict('Google Fonts could not be reached: ' . $e->getMessage());
        }
        if ($res->status === 400 || $res->status === 404) {
            throw ContentException::invalid(['family' => ['Google Fonts has no font "' . $family . '" with those weights.']]);
        }
        if (!$res->ok()) {
            throw ContentException::conflict('Google Fonts answered with HTTP ' . $res->status . '.');
        }
        $faces = self::parse($res->body);
        if ($faces === []) {
            throw ContentException::invalid(['family' => ['Google Fonts sent no usable font files for "' . $family . '".']]);
        }
        if (count($faces) > self::MAX_FILES) {
            $faces = array_slice($faces, 0, self::MAX_FILES);
        }

        $dir = $this->dir($slug);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw ContentException::conflict('The fonts folder could not be created.');
        }
        $files = [];
        $bytes = 0;
        $byUrl = [];   // variable fonts: one file serves every weight
        try {
            foreach ($faces as $i => $face) {
                if (!isset($byUrl[$face['url']])) {
                    $body = $http->get($face['url'], ['timeout' => 20, 'max_bytes' => self::MAX_FILE, 'headers' => ['Accept' => 'font/woff2,*/*;q=0.1']])->throw()->body;
                    if (!str_starts_with($body, 'wOF2')) {
                        throw new \RuntimeException('a file was not a WOFF2 font');
                    }
                    $name = sprintf('%d-%s-%s-%d.woff2', $face['weight'], $face['style'], $face['subset'], $i);
                    file_put_contents($dir . '/' . $name, $body);
                    $bytes += strlen($body);
                    $byUrl[$face['url']] = $name;
                }
                $files[] = ['weight' => $face['weight'], 'style' => $face['style'], 'subset' => $face['subset'], 'range' => $face['range'], 'file' => $byUrl[$face['url']]];
            }
        } catch (\Throwable $e) {
            $this->deleteFiles($slug);
            throw ContentException::conflict('The font files could not be downloaded: ' . $e->getMessage());
        }
        $now = gmdate('Y-m-d H:i:s');
        QueryBuilder::table('theme_fonts')->insert(['slug' => $slug, 'family' => $family, 'category' => $category, 'source' => 'google', 'files' => json_encode($files), 'bytes' => $bytes, 'created_at' => $now, 'updated_at' => $now]);
        $this->memo = null;

        return $this->all()[$slug];
    }

    /**
     * Install font files that came inside a theme package.
     *
     * @param list<array<string, mixed>> $files {src, weight, style, subset, range, file}; files may repeat (variable fonts)
     */
    public function addLocal(string $slug, string $family, string $category, array $files): bool
    {
        $family = trim($family);
        if (preg_match('/^gf-[a-z0-9-]{1,60}$/', $slug) !== 1 || preg_match(self::FAMILY, $family) !== 1 || isset($this->all()[$slug])) {
            return false;
        }
        $dir = $this->dir($slug);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $clean = [];
        $bytes = 0;
        $copied = [];
        foreach (array_slice($files, 0, self::MAX_FILES) as $f) {
            $src = (string) $f['src'];
            $name = (string) $f['file'];
            if (!isset($copied[$name])) {
                if (filesize($src) > self::MAX_FILE || !copy($src, $dir . '/' . $name)) {
                    continue;
                }
                $copied[$name] = true;
                $bytes += (int) filesize($src);
            }
            $clean[] = [
                'weight' => max(100, min(900, (int) ($f['weight'] ?? 400))),
                'style' => ($f['style'] ?? '') === 'italic' ? 'italic' : 'normal',
                'subset' => in_array($f['subset'] ?? '', self::SUBSETS, true) ? $f['subset'] : 'latin',
                'range' => is_string($f['range'] ?? null) && preg_match('/^[U+0-9A-Fa-f, ?-]{1,2000}$/', $f['range']) === 1 ? $f['range'] : null,
                'file' => $name,
            ];
        }
        if ($clean === []) {
            $this->deleteFiles($slug);
            return false;
        }
        $now = gmdate('Y-m-d H:i:s');
        QueryBuilder::table('theme_fonts')->insert(['slug' => $slug, 'family' => $family, 'category' => isset(self::CATEGORIES[$category]) ? $category : 'sans-serif', 'source' => 'theme', 'files' => json_encode($clean), 'bytes' => $bytes, 'created_at' => $now, 'updated_at' => $now]);
        $this->memo = null;

        return true;
    }

    /**
     * The @font-face blocks of a Google Fonts stylesheet, Latin subsets only.
     *
     * @return list<array{weight: int, style: string, subset: string, range: ?string, url: string}>
     */
    public static function parse(string $css): array
    {
        $out = [];
        preg_match_all('#/\*\s*([a-z0-9-]+)\s*\*/\s*@font-face\s*\{([^}]*)\}#i', $css, $m, PREG_SET_ORDER);
        foreach ($m as [, $subset, $body]) {
            if (!in_array($subset, self::SUBSETS, true)) {
                continue;
            }
            preg_match('/font-style:\s*(normal|italic)/', $body, $style);
            preg_match('/font-weight:\s*(\d{3})/', $body, $weight);
            preg_match('#src:\s*url\((https://fonts\.gstatic\.com/[A-Za-z0-9/._-]+\.woff2)\)#', $body, $url);
            preg_match('/unicode-range:\s*([U+0-9A-Fa-f, ?-]+);/', $body, $range);
            if (!isset($style[1], $weight[1], $url[1])) {
                continue;
            }
            $out[] = ['weight' => (int) $weight[1], 'style' => $style[1], 'subset' => $subset, 'range' => isset($range[1]) ? trim($range[1]) : null, 'url' => $url[1]];
        }

        return $out;
    }

    /** Remove a font. Tokens that used it fall back to their theme default. */
    public function remove(string $slug): bool
    {
        $n = QueryBuilder::table('theme_fonts')->where('slug', $slug)->delete();
        $this->deleteFiles($slug);
        $this->memo = null;

        return $n > 0;
    }

    private function deleteFiles(string $slug): void
    {
        if (preg_match('/^gf-[a-z0-9-]{1,60}$/', $slug) !== 1) {
            return;
        }
        $dir = $this->dir($slug);
        foreach (glob($dir . '/*.woff2') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /** The file for /system/fonts/{slug}/{file}, or null. */
    public function file(string $slug, string $file): ?string
    {
        if (preg_match('/^gf-[a-z0-9-]{1,60}$/', $slug) !== 1 || preg_match('/^[a-z0-9-]{1,80}\.woff2$/', $file) !== 1) {
            return null;
        }
        $path = $this->dir($slug) . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /** @internal tests */
    public function forget(): void
    {
        $this->memo = null;
    }
}
