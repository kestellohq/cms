<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

use Kestello\Cms\Cms;
use Kestello\Cms\Site\Seo;

/**
 * A media library image as an email can show it: a whole http(s) address
 * (a mail app has no page to resolve "/media/…" against) and its size.
 *
 *   MailMedia::image(12)   ['url' => 'https://…/media/logo.png', 'width' => 320, 'height' => 80], or null
 *
 * @api
 */
final class MailMedia
{
    /**
     * Null when the file is gone, isn't an image with a known size, or the
     * site has no address yet (Settings → General).
     *
     * @return array{url: string, width: int, height: int}|null
     */
    public static function image(mixed $id): ?array
    {
        $cms = Cms::instance();
        if ($cms === null) {
            return null;
        }
        try {
            $media = Seo::media($id);
        } catch (\Throwable) {
            return null;
        }
        if ($media === null || !is_numeric($media['width']) || !is_numeric($media['height']) || (int) $media['width'] < 1 || (int) $media['height'] < 1) {
            return null;
        }
        $url = str_starts_with($media['url'], '/') && !str_starts_with($media['url'], '//') ? Seo::siteUrl($cms) . $media['url'] : $media['url'];

        return preg_match('#^https?://[^/\s]#i', $url) === 1 ? ['url' => $url, 'width' => (int) $media['width'], 'height' => (int) $media['height']] : null;
    }
}
