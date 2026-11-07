<?php

declare(strict_types=1);

namespace Kestello\Cms\Storage;

use Kestello\Cms\Cms;
use Kestello\Contracts\Storage\StorageException;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Storage\LocalDisk;

/**
 * Serves local disks: public files under the disk's URL ("/files/…",
 * "/uploads/…"; only files with public visibility) and expiring signed
 * links at /system/files for private ones.
 */
final class StorageController
{
    public function public(Request $request, string $disk, string $path): Response
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        try {
            $local = $cms->storage->disk($disk);
            $path = rawurldecode($path);
            if (!$local instanceof LocalDisk || !$local->exists($path) || $local->visibility($path) !== 'public') {
                return self::missing();
            }

            return FileResponder::send($request, $local, $path, null, ['inline_svg' => $disk === 'media'] + self::options($cms));
        } catch (StorageException) {
            return self::missing();
        }
    }

    public function signed(Request $request): Response
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $link = SignedFileUrl::verify($request->query);
        if ($link === null) {
            return new Response('This link is invalid or has expired.', 403, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        try {
            $local = $cms->storage->disk($link['disk']);
            if (!$local instanceof LocalDisk || !$local->exists($link['path'])) {
                return self::missing();
            }

            return FileResponder::send($request, $local, $link['path'], $link['name'], ['cache' => 'private, no-store'] + self::options($cms));
        } catch (StorageException) {
            return self::missing();
        }
    }

    /** @return array{sendfile: ?string, sendfile_prefix: string} */
    private static function options(Cms $cms): array
    {
        $sendfile = $cms->config->get('storage.sendfile');

        return [
            'sendfile' => in_array($sendfile, ['x-accel-redirect', 'x-sendfile'], true) ? $sendfile : null,
            'sendfile_prefix' => (string) $cms->config->get('storage.sendfile_prefix', '/protected-storage'),
        ];
    }

    private static function missing(): Response
    {
        return new Response('Not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }
}
