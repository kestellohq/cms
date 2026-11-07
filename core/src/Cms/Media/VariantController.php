<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Cms\Cms;
use Kestello\Contracts\Media\ImageException;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * GET /system/media/{id}/{name}?s=…: serves a variant, making it first if
 * needed. Already made: a permanent redirect to the stored file (storage or
 * CDN serves it from then on). Just made: the bytes, cached for a year.
 *
 * @internal
 */
final class VariantController
{
    public function show(Request $request, string $id, string $name): Response
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $variants = $cms->container->make(MediaVariants::class);
        $sig = is_string($request->query['s'] ?? null) ? $request->query['s'] : '';
        if (!ctype_digit($id) || !$variants->verify((int) $id, $name, $sig)) {
            return self::missing(403, 'This image address is invalid.');
        }
        $media = Media::query()->find((int) $id);
        if (!$media instanceof Media) {
            return self::missing(404, 'Not found.');
        }
        $planned = $variants->planned($media, $name);
        if ($planned === null) {
            // a size changed since the page was cached: send the current variant of the same size and width
            $current = self::replacement($variants, $media, $name);

            return $current === null ? self::missing(404, 'This image size no longer exists.')
                : new Response('', 302, ['Location' => $variants->signedUrl($media, $current), 'Cache-Control' => 'public, max-age=300']);
        }
        try {
            $made = $variants->make($media, $planned);
        } catch (ImageException $e) {
            error_log('[media] variant failed for ' . $id . '/' . $name . ': ' . $e->getMessage());

            return self::missing(500, 'The image could not be made.');
        }
        if ($made['bytes'] === null) {
            $url = $cms->storage->disk($media->disk)->url($made['path']);

            return new Response('', 301, ['Location' => $url, 'Cache-Control' => 'public, max-age=86400']);
        }

        return new Response($made['bytes'], 200, [
            'Content-Type' => 'image/' . $made['format'],
            'Content-Length' => (string) strlen($made['bytes']),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function replacement(MediaVariants $variants, Media $media, string $name): ?string
    {
        if (preg_match('/^([a-z][a-z0-9_]{0,39})-(\d{1,5})-([0-9a-f]{10})\.(avif|webp|jpeg|png)$/', $name, $x) !== 1) {
            return null;
        }
        $size = $variants->registry()->get($x[1]);
        if ($size === null || $variants->hash($media, $size) === $x[3]) {
            return null;   // current definition, unplanned width: never made
        }
        [, , , , $format] = $x;
        $x[3] = $format;
        $best = null;
        foreach ($variants->plan($media, [$x[1]]) as $v) {
            if ($v['format'] === $x[3] && ($best === null || abs($v['width'] - (int) $x[2]) < abs($best['width'] - (int) $x[2]))) {
                $best = $v;
            }
        }

        return $best['name'] ?? null;
    }

    private static function missing(int $status, string $message): Response
    {
        return new Response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
