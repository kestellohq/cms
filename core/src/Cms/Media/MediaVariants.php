<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Cms\Cms;
use Kestello\Cms\Media\Image\ImageProcessor;
use Kestello\Contracts\Cache\Store;
use Kestello\Contracts\Media\ImageEngine;
use Kestello\Contracts\Media\ImageException;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Hash;

/**
 * Image variants: every registered size in its responsive widths, as AVIF
 * (when the engine can), WebP, and JPEG or PNG for old browsers.
 *
 * Variants live next to the original ("<dir>/v/<size>-<width>-<hash>.<fmt>")
 * and are listed in media_variants. The hash covers the size definition,
 * the qualities and (for cropped sizes) the focal point, so any change makes
 * new files; prune() removes the old ones.
 *
 * Missing variants are made on demand: pages link to a signed address
 * (/system/media/<id>/<name>?s=…) that generates, stores and serves the
 * file. Only registered sizes, widths and formats can be signed, so nobody
 * can make the server render arbitrary images.
 *
 * @api
 */
final class MediaVariants
{
    public const string ROUTE = '/system/media';
    private const int VERSION = 1;

    public function __construct(private readonly Cms $cms) {}

    public function registry(): MediaRegistry
    {
        return $this->cms->media;
    }

    public function engine(): ?ImageEngine
    {
        return $this->cms->media->activeEngine();
    }

    public function processor(): ImageProcessor
    {
        return new ImageProcessor($this->engine(), max(0, min(10000, (int) Settings::getInstance()->get('media_max_edge', 2560))));
    }

    /** @return array{avif: int, webp: int, jpeg: int} */
    public function qualities(): array
    {
        $s = Settings::getInstance();
        $q = static fn(string $k, int $d): int => max(1, min(100, (int) $s->get($k, $d)));

        return ['avif' => $q('media_quality_avif', 50), 'webp' => $q('media_quality_webp', 75), 'jpeg' => $q('media_quality_jpeg', 80)];
    }

    /** Whether variants can be made for this file. */
    public function eligible(Media $m): bool
    {
        $engine = $this->engine();
        $src = self::source($m);

        return $src !== null && $engine !== null && $engine->reads($src['mime']);
    }

    /**
     * The picture variants are made from: the image itself, or the stored
     * first page of a PDF.
     *
     * @return array{path: string, width: int, height: int, mime: string}|null
     */
    public static function source(Media $m): ?array
    {
        if ($m->kind === 'image') {
            return $m->width > 0 && $m->height > 0 && empty($m->meta['animated'])
                ? ['path' => $m->path, 'width' => (int) $m->width, 'height' => (int) $m->height, 'mime' => $m->mime] : null;
        }
        $p = $m->meta['poster'] ?? null;
        if (is_array($p) && is_string($p['path'] ?? null) && (int) ($p['width'] ?? 0) > 0 && (int) ($p['height'] ?? 0) > 0) {
            return ['path' => $p['path'], 'width' => (int) $p['width'], 'height' => (int) $p['height'], 'mime' => 'image/jpeg'];
        }

        return null;
    }

    /** @return list<string> formats, best first; the last is the fallback */
    public function formats(Media $m): array
    {
        $engine = $this->engine();
        if ($engine === null) {
            return [];
        }
        $out = [];
        if ($engine->writes('avif') && filter_var(Settings::getInstance()->get('media_avif', '1'), FILTER_VALIDATE_BOOLEAN)) {
            $out[] = 'avif';
        }
        if ($engine->writes('webp')) {
            $out[] = 'webp';
        }
        $out[] = !empty($m->meta['alpha']) ? 'png' : 'jpeg';

        return $out;
    }

    /** @return array{0: float, 1: float} */
    public static function focus(Media $m): array
    {
        $f = $m->meta['focus'] ?? null;

        return is_array($f) && count($f) === 2 ? [max(0.0, min(1.0, (float) $f[0])), max(0.0, min(1.0, (float) $f[1]))] : [0.5, 0.5];
    }

    public function hash(Media $m, MediaSize $size): string
    {
        $parts = [self::VERSION, $size->spec(), $this->qualities()];
        if (isset($m->meta['original'])) {
            $parts[] = $m->path;       // an edited picture: each edit is a new file, so new sizes
        }
        if ($size->fit === 'cover') {
            $parts[] = self::focus($m);
        }

        return substr(hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR)), 0, 10);
    }

    public static function name(MediaSize $size, int $width, string $hash, string $format): string
    {
        return "{$size->name}-{$width}-{$hash}.{$format}";
    }

    public static function key(Media $m, string $name): string
    {
        $dir = dirname($m->path);

        return ($dir === '.' ? '' : $dir . '/') . 'v/' . $name;
    }

    /**
     * Every variant this file should have.
     *
     * @return list<array{size: MediaSize, width: int, format: string, name: string}>
     */
    public function plan(Media $m, ?array $only = null): array
    {
        if (!$this->eligible($m)) {
            return [];
        }
        $out = [];
        $formats = $this->formats($m);
        foreach ($this->cms->media->sizes() as $size) {
            if ($only !== null && !in_array($size->name, $only, true)) {
                continue;
            }
            $hash = $this->hash($m, $size);
            $src = self::source($m);
            foreach ($size->widthsFor($src['width'], $src['height']) as $w) {
                foreach ($formats as $f) {
                    $out[] = ['size' => $size, 'width' => $w, 'format' => $f, 'name' => self::name($size, $w, $hash, $f)];
                }
            }
        }

        return $out;
    }

    /** Find a planned variant by its file name. @return array{size: MediaSize, width: int, format: string, name: string}|null */
    public function planned(Media $m, string $name): ?array
    {
        if (preg_match('/^([a-z][a-z0-9_]{0,39})-(\d{1,5})-([0-9a-f]{10})\.(avif|webp|jpeg|png)$/', $name, $x) !== 1) {
            return null;
        }
        $size = $this->cms->media->get($x[1]);
        if ($size === null) {
            return null;
        }
        foreach ($this->plan($m, [$size->name]) as $v) {
            if ($v['name'] === $name) {
                return $v;
            }
        }

        return null;
    }

    // ── addresses ───────────────────────────────────────────────────────

    public function signature(int $id, string $name): string
    {
        return substr(Hash::hmac("{$id}/{$name}", 'media.variant'), 0, 32);
    }

    public function verify(int $id, string $name, string $sig): bool
    {
        if (strlen($sig) !== 32) {
            return false;
        }
        // old links keep working after a key rotation
        foreach ([\Kestello\Support\AppKey::derive('hmac.media.variant'), ...\Kestello\Support\AppKey::derivePrevious('hmac.media.variant')] as $key) {
            if (hash_equals(substr(hash_hmac('sha256', "{$id}/{$name}", $key), 0, 32), $sig)) {
                return true;
            }
        }

        return false;
    }

    public function signedUrl(Media $m, string $name): string
    {
        return self::ROUTE . '/' . (int) $m->getKey() . '/' . $name . '?s=' . $this->signature((int) $m->getKey(), $name);
    }

    /**
     * How themes and the admin get images: per size, srcset strings per
     * format, the fallback src and the largest width × height. Existing
     * variants link to storage directly; missing ones to the signed generator.
     *
     * @param list<Media> $media
     * @param list<string>|null $sizes default: public sizes
     * @return array<int, array<string, array{width: int, height: int, src: string, srcset: array<string, string>}>> by media id
     */
    public function sources(array $media, ?array $sizes = null): array
    {
        $media = array_values(array_filter($media, fn(Media $m) => $this->eligible($m)));
        if ($media === []) {
            return [];
        }
        $existing = [];
        foreach (QueryBuilder::table('media_variants')->whereIn('media_id', array_map(static fn(Media $m) => (int) $m->getKey(), $media))->get() as $row) {
            $row = (array) $row;
            $existing[(int) $row['media_id']][(string) $row['name']] = (string) $row['path'];
        }
        $only = $sizes ?? array_keys(array_filter($this->cms->media->sizes(), static fn(MediaSize $s) => $s->public));
        $out = [];
        foreach ($media as $m) {
            $id = (int) $m->getKey();
            $disk = null;
            try {
                $disk = $this->cms->storage->disk($m->disk);
            } catch (\Throwable) {
            }
            $bySize = [];
            foreach ($this->plan($m, $only) as $v) {
                $path = $existing[$id][$v['name']] ?? null;
                $url = $path !== null && $disk !== null ? self::safeUrl($disk, $path) : null;
                $url ??= $this->signedUrl($m, $v['name']);
                $src = self::source($m);
                $dims = $v['size']->plan($v['width'], $src['width'], $src['height'], self::focus($m));
                $entry = &$bySize[$v['size']->name];
                $entry['srcset'][$v['format']][] = $url . ' ' . $v['width'] . 'w';
                if ($v['width'] >= ($entry['width'] ?? 0)) {
                    $entry['width'] = $v['width'];
                    $entry['height'] = $dims['height'];
                    if ($v['format'] === 'jpeg' || $v['format'] === 'png') {
                        $entry['src'] = $url;
                    }
                }
                unset($entry);
            }
            foreach ($bySize as $name => $entry) {
                $bySize[$name]['srcset'] = array_map(static fn(array $l) => implode(', ', $l), $entry['srcset']);
            }
            $out[$id] = $bySize;
        }

        return $out;
    }

    private static function safeUrl(\Kestello\Contracts\Storage\Disk $disk, string $path): ?string
    {
        try {
            return $disk->url($path);
        } catch (\Throwable) {
            return null;
        }
    }

    // ── making them ─────────────────────────────────────────────────────

    /**
     * Make one variant (or return the stored one).
     *
     * @param array{size: MediaSize, width: int, format: string, name: string} $v
     * @return array{path: string, bytes: ?string, format: string} bytes when just made
     */
    public function make(Media $m, array $v): array
    {
        $row = QueryBuilder::table('media_variants')->where('media_id', $m->getKey())->where('name', $v['name'])->first();
        if ($row !== null) {
            return ['path' => (string) ((array) $row)['path'], 'bytes' => null, 'format' => $v['format']];
        }
        $lock = $this->cache()?->lock("media-variant:{$m->getKey()}:{$v['name']}", 60);
        if ($lock !== null && !$lock->acquire()) {
            $lock->block(30);           // someone else is making it
            $lock->release();
            $row = QueryBuilder::table('media_variants')->where('media_id', $m->getKey())->where('name', $v['name'])->first();
            if ($row !== null) {
                return ['path' => (string) ((array) $row)['path'], 'bytes' => null, 'format' => $v['format']];
            }
        }
        try {
            $made = $this->render($m, [$v]);

            return ['path' => $made[0]['path'], 'bytes' => $made[0]['bytes'], 'format' => $v['format']];
        } finally {
            $lock?->release();
        }
    }

    /**
     * Make every missing variant (or only those of $sizes). Returns how many.
     *
     * @param list<string>|null $sizes
     */
    public function generate(Media $m, ?array $sizes = null): int
    {
        $have = array_flip(QueryBuilder::table('media_variants')->where('media_id', $m->getKey())->pluck('name'));
        $missing = array_values(array_filter($this->plan($m, $sizes), static fn(array $v) => !isset($have[$v['name']])));

        return count($missing) === 0 ? 0 : count($this->render($m, $missing));
    }

    /** Delete variants no longer planned (sizes changed, focus moved). Returns how many. */
    public function prune(Media $m): int
    {
        $planned = array_flip(array_column($this->plan($m), 'name'));
        $n = 0;
        foreach (QueryBuilder::table('media_variants')->where('media_id', $m->getKey())->get() as $row) {
            $row = (array) $row;
            if (!isset($planned[(string) $row['name']])) {
                $this->deleteRow($m, $row);
                $n++;
            }
        }

        return $n;
    }

    /** Delete all of a file's variants (and a PDF's stored first page). */
    public function deleteAll(Media $m): void
    {
        foreach (QueryBuilder::table('media_variants')->where('media_id', $m->getKey())->get() as $row) {
            $this->deleteRow($m, (array) $row);
        }
        $poster = $m->meta['poster']['path'] ?? null;
        if (is_string($poster)) {
            try {
                $this->cms->storage->disk($m->disk)->delete($poster);
            } catch (\Throwable) {
            }
        }
    }

    private function deleteRow(Media $m, array $row): void
    {
        try {
            $this->cms->storage->disk($m->disk)->delete((string) $row['path']);
        } catch (\Throwable) {
            // gone already, or the disk is unreachable: the row still goes
        }
        QueryBuilder::table('media_variants')->where('id', $row['id'])->delete();
    }

    /**
     * Decode the original once per size, then make the widths largest first.
     *
     * @param list<array{size: MediaSize, width: int, format: string, name: string}> $variants
     * @return list<array{path: string, bytes: string}>
     */
    private function render(Media $m, array $variants): array
    {
        $engine = $this->engine() ?? throw new ImageException('No image engine is available.');
        $disk = $this->cms->storage->disk($m->disk);
        $source = tempnam(sys_get_temp_dir(), 'kmv');
        $made = [];
        $src = self::source($m) ?? throw new ImageException('This file has no picture to make sizes from.');
        try {
            $in = $disk->readStream($src['path']);
            $outFile = fopen($source, 'wb');
            stream_copy_to_stream($in, $outFile);
            is_resource($in) && fclose($in);
            fclose($outFile);
            ImageProcessor::reserveMemory($src['width'], $src['height']);
            $quality = $this->qualities();
            $bySize = [];
            foreach ($variants as $v) {
                $bySize[$v['size']->name][] = $v;
            }
            foreach ($bySize as $list) {
                $size = $list[0]['size'];
                usort($list, static fn($a, $b) => $b['width'] <=> $a['width']);
                $img = $engine->open($source);
                $w0 = $img->width();
                $h0 = $img->height();
                $first = $size->plan($list[0]['width'], $w0, $h0, self::focus($m));
                if ($first['crop'] !== null) {
                    $img->crop(...$first['crop']);
                }
                $current = null;
                foreach ($list as $v) {
                    $dims = $size->plan($v['width'], $w0, $h0, self::focus($m));
                    if ($current !== $v['width']) {
                        $img->resize($dims['width'], $dims['height']);
                        $current = $v['width'];
                    }
                    $bytes = $img->encode($v['format'], $quality[$v['format']] ?? 100);
                    if ($v['format'] === 'jpeg' || $v['format'] === 'png') {
                        $bytes = Optimizer::optimize($bytes, $v['format']);
                    }
                    $key = self::key($m, $v['name']);
                    $disk->put($key, $bytes, [
                        'content_type' => 'image/' . $v['format'],
                        'visibility' => 'public',
                        'cache_control' => 'public, max-age=31536000, immutable',
                    ]);
                    try {
                        QueryBuilder::table('media_variants')->insert([
                            'media_id' => $m->getKey(), 'name' => $v['name'], 'path' => $key, 'format' => $v['format'],
                            'width' => $dims['width'], 'height' => $dims['height'], 'size' => strlen($bytes),
                            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                        ]);
                    } catch (\PDOException) {
                        // made at the same time by another request: same bytes, same key
                    }
                    $made[] = ['path' => $key, 'bytes' => $bytes];
                }
                $img->destroy();
            }
        } finally {
            @unlink($source);
        }

        return $made;
    }

    private function cache(): ?Store
    {
        try {
            $store = $this->cms->drivers->resolve('cache');
        } catch (\Throwable) {
            return null;
        }

        return $store instanceof Store ? $store : null;
    }
}
