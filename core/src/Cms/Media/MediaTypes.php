<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

/**
 * Which files the library takes. The extension and the content sniffed by
 * finfo must both be on this list and agree. Images are always allowed;
 * the other kinds can be switched off in Settings > Media.
 *
 * SVG is refused for now: it can carry scripts, and the sanitizer that
 * would make it safe arrives with the image pipeline.
 *
 * @api
 */
final class MediaTypes
{
    public const array KINDS = ['image', 'document', 'audio', 'video', 'archive'];

    /** ext => [kind, accepted sniffed types (the first is stored and served)] */
    private const array TYPES = [
        'jpg' => ['image', ['image/jpeg']],
        'jpeg' => ['image', ['image/jpeg']],
        'png' => ['image', ['image/png']],
        'gif' => ['image', ['image/gif']],
        'webp' => ['image', ['image/webp']],
        'avif' => ['image', ['image/avif', 'image/heif']],
        'pdf' => ['document', ['application/pdf']],
        'txt' => ['document', ['text/plain']],
        'csv' => ['document', ['text/csv', 'text/plain', 'application/csv']],
        'rtf' => ['document', ['application/rtf', 'text/rtf']],
        'doc' => ['document', ['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage']],
        'xls' => ['document', ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage']],
        'ppt' => ['document', ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage']],
        'docx' => ['document', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
        'xlsx' => ['document', ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']],
        'pptx' => ['document', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream']],
        'odt' => ['document', ['application/vnd.oasis.opendocument.text', 'application/zip']],
        'ods' => ['document', ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip']],
        'odp' => ['document', ['application/vnd.oasis.opendocument.presentation', 'application/zip']],
        'mp3' => ['audio', ['audio/mpeg', 'audio/mp3']],
        'm4a' => ['audio', ['audio/mp4', 'audio/x-m4a', 'video/mp4']],
        'ogg' => ['audio', ['audio/ogg', 'application/ogg', 'video/ogg']],
        'oga' => ['audio', ['audio/ogg', 'application/ogg']],
        'wav' => ['audio', ['audio/wav', 'audio/x-wav', 'audio/vnd.wave']],
        'flac' => ['audio', ['audio/flac', 'audio/x-flac']],
        'mp4' => ['video', ['video/mp4']],
        'm4v' => ['video', ['video/x-m4v', 'video/mp4']],
        'mov' => ['video', ['video/quicktime']],
        'webm' => ['video', ['video/webm', 'audio/webm']],
        'zip' => ['archive', ['application/zip']],
    ];

    /** Never stored, whatever the settings say (executable or scriptable when served). */
    public const array BLOCKED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc', 'htaccess', 'htpasswd', 'ini', 'user.ini',
        'html', 'htm', 'xhtml', 'shtml', 'xht', 'svg', 'svgz', 'xml', 'xsl', 'js', 'mjs', 'cjs', 'css', 'swf', 'jar', 'jsp', 'asp', 'aspx',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'scr', 'vbs', 'ps1', 'hta', 'lnk',
    ];

    /** SVG, when switched on: sanitized (Support\Svg) and never rasterised. */
    public const array SVG = ['image', ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain']];

    /**
     * @param list<string> $enabled kinds switched on (images always are)
     * @param bool $svg whether SVG images are accepted (sanitized)
     */
    public function __construct(private readonly array $enabled = self::KINDS, private readonly bool $svg = false) {}

    /** @return array{0: string, 1: list<string>}|null */
    private function type(string $ext): ?array
    {
        $ext = strtolower($ext);

        return $ext === 'svg' ? ($this->svg ? self::SVG : null) : (self::TYPES[$ext] ?? null);
    }

    public static function extension(string $filename): string
    {
        $base = strtolower(basename(str_replace('\\', '/', $filename)));

        return str_contains($base, '.') ? substr($base, strrpos($base, '.') + 1) : '';
    }

    /**
     * Problems with a file name before any bytes arrive. Double extensions
     * that hide a blocked one ("shell.php.jpg") are refused too.
     *
     * @return list<string>
     */
    public function checkName(string $filename): array
    {
        $base = strtolower(basename(str_replace('\\', '/', $filename)));
        $ext = self::extension($base);
        $parts = array_slice(explode('.', $base), 1);
        foreach ($parts as $i => $part) {
            $lastSvg = $this->svg && $part === 'svg' && $i === count($parts) - 1;
            if (in_array($part, self::BLOCKED, true) && !$lastSvg) {
                return ["Files of type .{$part} can't be uploaded."];
            }
        }
        $type = $this->type($ext);
        if ($ext === '' || $type === null) {
            return ['This type of file is not supported.' . ($ext !== '' ? " (.{$ext})" : '')];
        }
        if (!$this->allows($type[0])) {
            return ['Uploading ' . $type[0] . ' files is switched off in the media settings.'];
        }

        return [];
    }

    public function allows(string $kind): bool
    {
        return $kind === 'image' || in_array($kind, $this->enabled, true);
    }

    /** @return array{kind: string, mime: string}|null for an allowed extension */
    public function forExtension(string $ext): ?array
    {
        $type = $this->type($ext);

        return $type === null || !$this->allows($type[0]) ? null : ['kind' => $type[0], 'mime' => $type[1][0]];
    }

    /** Whether content sniffed as $sniffed may carry extension $ext. */
    public function agrees(string $ext, string $sniffed): bool
    {
        return in_array(strtolower($sniffed), array_map('strtolower', $this->type($ext)[1] ?? []), true);
    }

    /** The upload picker's accept attribute ("image/*,.pdf,…"). */
    public function accept(?string $kind = null): string
    {
        $exts = [];
        foreach (($this->svg ? self::TYPES + ['svg' => self::SVG] : self::TYPES) as $ext => [$k]) {
            if ($this->allows($k) && ($kind === null || $k === $kind)) {
                $exts[] = '.' . $ext;
            }
        }

        return implode(',', $exts);
    }

    /** @return list<string> */
    public function enabledKinds(): array
    {
        return array_values(array_filter(self::KINDS, $this->allows(...)));
    }
}
