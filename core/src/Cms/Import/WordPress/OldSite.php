<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Redirects\Redirects;

/**
 * Reads addresses of the WordPress site being imported: which belong to it,
 * which post an address means, and which uploaded file an image address is
 * (whatever size WordPress made of it).
 */
final class OldSite
{
    private readonly string $host;

    public function __construct(public readonly string $url)
    {
        $this->host = self::bareHost((string) parse_url($url, PHP_URL_HOST));
    }

    /** One key per site, the same for http/https, www or not, and a trailing slash. */
    public static function key(string $url): string
    {
        $host = self::bareHost((string) parse_url($url, PHP_URL_HOST));
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return sha1($host . $path);
    }

    /** True for addresses on the old site, and for site-relative paths. */
    public function owns(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#')) {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        $host = parse_url(str_starts_with($url, '//') ? 'https:' . $url : $url, PHP_URL_HOST);

        return is_string($host) && $this->host !== '' && self::bareHost($host) === $this->host;
    }

    /**
     * The path of an old-site address as redirects store it ("/2024/06/hello"),
     * percent-decoding included; null for other sites and for the home page.
     */
    public function path(string $url): ?string
    {
        if (!$this->owns($url)) {
            return null;
        }
        $path = (string) parse_url(str_starts_with($url, '//') ? 'https:' . $url : $url, PHP_URL_PATH);
        $path = Redirects::normalize(rawurldecode($path === '' ? '/' : $path));

        return $path === null || $path === '/' ? null : $path;
    }

    /** Whether $url is the old site's home page, also when WordPress lived in a folder ("/blog/"); any query aside. */
    public function isHome(string $url): bool
    {
        if (!$this->owns($url)) {
            return false;
        }
        $path = (string) parse_url(str_starts_with($url, '//') ? 'https:' . $url : $url, PHP_URL_PATH);

        return rtrim($path, '/') === rtrim((string) parse_url($this->url, PHP_URL_PATH), '/');
    }

    /** The post id in ?p=12, ?page_id=12 or ?attachment_id=12 addresses of the old site. */
    public function queryId(string $url): ?int
    {
        if (!$this->owns($url)) {
            return null;
        }
        parse_str((string) parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY), $query);
        foreach (['p', 'page_id', 'attachment_id'] as $name) {
            if (isset($query[$name]) && is_string($query[$name]) && ctype_digit($query[$name]) && (int) $query[$name] > 0) {
                return (int) $query[$name];
            }
        }

        return null;
    }

    /**
     * "2024/06/photo.jpg" for an uploaded file at any of its sizes
     * (photo-300x200.jpg, photo-scaled.jpg, photo-rotated.jpg, edited
     * photo-e1712345678901.jpg); null when it is not in the uploads folder.
     */
    public function fileKey(string $url): ?string
    {
        if (!$this->owns($url)) {
            return null;
        }

        return self::uploadKey((string) parse_url(str_starts_with($url, '//') ? 'https:' . $url : $url, PHP_URL_PATH));
    }

    /** The uploads-relative key of a path like /wp-content/uploads/2024/06/photo-300x200.jpg. */
    public static function uploadKey(string $path): ?string
    {
        $path = rawurldecode($path);
        $at = strpos($path, '/wp-content/uploads/');
        if ($at === false) {
            return null;
        }
        $relative = substr($path, $at + strlen('/wp-content/uploads/'));
        if ($relative === '' || str_contains($relative, '..') || !str_contains(basename($relative), '.')) {
            return null;
        }
        $relative = (string) preg_replace('/(?:-\d+x\d+|-scaled|-rotated|-e\d{13})+(\.[A-Za-z0-9]+)$/', '$1', $relative);

        return mb_strtolower($relative);
    }

    private static function bareHost(string $host): string
    {
        $host = strtolower(trim($host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
