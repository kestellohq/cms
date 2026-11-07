<?php

declare(strict_types=1);

namespace Kestello\Support;

/**
 * Content types: by extension, by content (finfo, with a magic-byte
 * fallback), and the extensions that may never be stored or served as-is.
 *
 *   Mime::fromExtension('photo.JPG')   // image/jpeg
 *   Mime::detect($path)                // sniffed from the bytes
 *   Mime::extensions('image/jpeg')     // ['jpg', 'jpeg']
 *
 * @api
 */
final class Mime
{
    public const array TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'avif' => 'image/avif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'heic' => 'image/heic',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav', 'flac' => 'audio/flac',
        'pdf' => 'application/pdf', 'zip' => 'application/zip', 'gz' => 'application/gzip', 'tar' => 'application/x-tar', '7z' => 'application/x-7z-compressed',
        'json' => 'application/json', 'xml' => 'application/xml', 'csv' => 'text/csv', 'txt' => 'text/plain', 'md' => 'text/markdown', 'ics' => 'text/calendar',
        'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'rtf' => 'application/rtf', 'epub' => 'application/epub+zip',
    ];

    /** Safe to show inline from our own origin; anything else is served as a download. */
    public const array INLINE_SAFE = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/x-icon', 'image/bmp',
        'video/mp4', 'video/webm', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/flac', 'application/pdf', 'text/plain',
        'font/woff', 'font/woff2', 'font/ttf', 'font/otf', 'text/css',
    ];

    /** Extensions a web server might execute or a browser might run; never accepted as uploads. */
    public const array DANGEROUS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'pgif', 'inc', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash',
        'asp', 'aspx', 'jsp', 'jspx', 'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'vbs', 'ps1', 'jar', 'htaccess', 'htpasswd', 'ini', 'user.ini',
        'html', 'htm', 'xhtml', 'shtml', 'svgz', 'xml', 'xsl', 'xslt', 'swf', 'hta',
    ];

    /** @var array<string, array{0: int, 1: string, 2: string}> offset, bytes, type */
    private const array MAGIC = [
        'png' => [0, "\x89PNG\r\n\x1a\n", 'image/png'],
        'jpg' => [0, "\xff\xd8\xff", 'image/jpeg'],
        'gif87' => [0, 'GIF87a', 'image/gif'],
        'gif89' => [0, 'GIF89a', 'image/gif'],
        'pdf' => [0, '%PDF-', 'application/pdf'],
        'zip' => [0, "PK\x03\x04", 'application/zip'],
        'gz' => [0, "\x1f\x8b", 'application/gzip'],
        'bmp' => [0, 'BM', 'image/bmp'],
        'woff' => [0, 'wOFF', 'font/woff'],
        'woff2' => [0, 'wOF2', 'font/woff2'],
        'flac' => [0, 'fLaC', 'audio/flac'],
        'ogg' => [0, 'OggS', 'audio/ogg'],
        'webm' => [0, "\x1a\x45\xdf\xa3", 'video/webm'],
        'mp3' => [0, 'ID3', 'audio/mpeg'],
    ];

    /** @var array<string, string> added at runtime (plugins) */
    private static array $extra = [];

    public static function register(string $extension, string $type): void
    {
        self::$extra[strtolower(ltrim($extension, '.'))] = strtolower($type);
    }

    /** By extension (of a path or a bare extension); application/octet-stream when unknown. */
    public static function fromExtension(string $pathOrExtension): string
    {
        $ext = self::extension($pathOrExtension);

        return self::$extra[$ext] ?? self::TYPES[$ext] ?? 'application/octet-stream';
    }

    /** @return list<string> */
    public static function extensions(string $type): array
    {
        $type = strtolower(trim(explode(';', $type)[0]));

        return array_keys(array_filter(self::$extra + self::TYPES, static fn(string $t) => $t === $type));
    }

    /** Sniff a file's type from its bytes. */
    public static function detect(string $path): string
    {
        $handle = @fopen($path, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 4096);
        if ($handle !== false) {
            fclose($handle);
        }

        return self::detectBytes($head, $path);
    }

    /** Sniff from the first bytes of a file (4 kB is plenty). */
    public static function detectBytes(string $bytes, string $nameHint = ''): string
    {
        if (str_starts_with($bytes, 'RIFF') && strlen($bytes) >= 12) {
            $kind = substr($bytes, 8, 4);
            if ($kind === 'WEBP') {
                return 'image/webp';
            }
            if ($kind === 'WAVE') {
                return 'audio/wav';
            }
        }
        if (strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp') {
            $brand = substr($bytes, 8, 4);
            return match (true) {
                in_array($brand, ['avif', 'avis'], true) => 'image/avif',
                in_array($brand, ['heic', 'heix', 'mif1'], true) => 'image/heic',
                $brand === 'qt  ' => 'video/quicktime',
                str_starts_with($brand, 'M4A') => 'audio/mp4',
                default => 'video/mp4',
            };
        }

        $type = null;
        if (class_exists(\finfo::class)) {
            $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $type = is_string($sniffed) && $sniffed !== '' ? $sniffed : null;
        }
        if ($type === null || $type === 'application/octet-stream') {
            foreach (self::MAGIC as [$offset, $magic, $magicType]) {
                if (substr($bytes, $offset, strlen($magic)) === $magic) {
                    $type = $magicType;
                    break;
                }
            }
        }
        $type ??= 'application/octet-stream';

        // Zip containers (docx, xlsx, epub, odt…) and plain text (csv, md, json) need the name to tell apart.
        if ($nameHint !== '' && in_array($type, ['application/zip', 'text/plain', 'application/octet-stream'], true)) {
            $byName = self::fromExtension($nameHint);
            $zipFamily = str_contains($byName, 'openxmlformats') || str_contains($byName, 'opendocument') || $byName === 'application/epub+zip';
            $textFamily = in_array($byName, ['text/csv', 'text/markdown', 'application/json', 'text/calendar'], true);
            if (($type === 'application/zip' && $zipFamily) || ($type === 'text/plain' && $textFamily)) {
                return $byName;
            }
        }

        return $type;
    }

    /** True when the content and the extension agree (upload validation). */
    public static function matches(string $path, string $name): bool
    {
        $byName = self::fromExtension($name);

        return $byName !== 'application/octet-stream' && self::detect($path) === $byName;
    }

    /** Refuses any dangerous extension anywhere in the name ("shell.php.jpg" too). */
    public static function isDangerous(string $name): bool
    {
        $parts = explode('.', strtolower(basename($name)));
        array_shift($parts);
        foreach ($parts as $part) {
            if (in_array(rtrim($part), self::DANGEROUS, true)) {
                return true;
            }
        }

        return str_starts_with(strtolower(basename($name)), '.ht') || str_ends_with(strtolower($name), '.user.ini');
    }

    public static function isInlineSafe(string $type): bool
    {
        return in_array(strtolower(trim(explode(';', $type)[0])), self::INLINE_SAFE, true);
    }

    private static function extension(string $value): string
    {
        $value = strtolower($value);

        return str_contains($value, '.') || str_contains($value, '/') ? pathinfo($value, PATHINFO_EXTENSION) : ltrim($value, '.');
    }
}
