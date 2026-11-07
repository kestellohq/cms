<?php

declare(strict_types=1);

namespace Kestello\Cms\Storage;

use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Storage\LocalDisk;
use Kestello\Support\Mime;

/**
 * Sends a file from a local disk. Types that could run script on our origin
 * (HTML, SVG, XML, JS…) are always downloads, every file gets nosniff and a
 * sandboxing CSP, and conditional requests get 304.
 *
 * With storage.sendfile = 'x-accel-redirect' (nginx) or 'x-sendfile'
 * (Apache/LiteSpeed) the web server sends the bytes (and handles ranges).
 */
final class FileResponder
{
    /**
     * @param array{sendfile?: ?string, sendfile_prefix?: string, cache?: string, inline_svg?: bool} $options
     *        inline_svg: the disk only holds sanitized SVG (the media library), so it may display
     */
    public static function send(Request $request, LocalDisk $disk, string $path, ?string $downloadName = null, array $options = []): Response
    {
        $full = $disk->path($path);
        clearstatcache(true, $full);
        $size = (int) filesize($full);
        $mtime = (int) filemtime($full);
        $etag = '"' . substr(hash('sha256', $path . '|' . $size . '|' . $mtime), 0, 32) . '"';

        $type = Mime::fromExtension($path);
        $inline = $downloadName === null && (in_array($type, Mime::INLINE_SAFE, true) || ($type === 'image/svg+xml' && !empty($options['inline_svg'])));
        $name = str_replace(['"', '\\', "\r", "\n", '/'], '', $downloadName ?? basename($path));
        $headers = [
            'Content-Type' => $inline ? $type : ($type === 'text/plain' ? 'text/plain; charset=UTF-8' : 'application/octet-stream'),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . (preg_replace('/[^\x20-\x7e]/', '_', $name) ?? 'file') . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-site',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'Cache-Control' => $options['cache'] ?? 'public, max-age=86400',
        ];

        $ifNone = $request->header('If-None-Match');
        if ($ifNone !== '' && in_array($etag, array_map('trim', explode(',', $ifNone)), true)) {
            return new Response('', 304, array_intersect_key($headers, array_flip(['ETag', 'Cache-Control', 'Last-Modified'])));
        }

        $sendfile = $options['sendfile'] ?? null;
        if ($sendfile === 'x-accel-redirect') {
            $prefix = rtrim((string) ($options['sendfile_prefix'] ?? '/protected-storage'), '/');
            $relative = substr($full, strlen(dirname($disk->root())) + 1);

            return new Response('', 200, $headers + ['X-Accel-Redirect' => $prefix . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)))]);
        }
        if ($sendfile === 'x-sendfile') {
            return new Response('', 200, $headers + ['X-Sendfile' => $full]);
        }

        $headers['Content-Length'] = (string) $size;
        if ($request->method === 'HEAD') {
            return new Response('', 200, $headers);
        }

        return Response::stream($disk->readStream($path), 200, $headers);
    }
}
