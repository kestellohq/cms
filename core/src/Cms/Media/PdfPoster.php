<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Support\Process;

/**
 * The first page of a PDF as an image, for thumbnails in the library and
 * on pages. Ghostscript runs as its own process (never inside PHP), in
 * SAFER mode, without a shell, with a clean environment and a time limit:
 * PDFs are untrusted input and interpreters have had bugs.
 *
 * Without Ghostscript (or with proc_open disabled) PDFs simply get no
 * picture.
 */
final class PdfPoster
{
    public const int DPI = 110;
    public const float TIMEOUT = 25.0;
    /** Pages beyond this many pixels are thrown away instead of stored. */
    public const int MAX_PIXELS = 40_000_000;

    /** @var array<string, ?string> */
    private static array $found = [];

    /** Ghostscript's path, or null when it can't be used here. */
    public static function binary(): ?string
    {
        return self::$found['gs'] ??= (Process::available() ? Process::which('gs') : null);
    }

    /** @internal tests */
    public static function forget(): void
    {
        self::$found = [];
    }

    /**
     * Render page 1 of $pdf to a PNG at $png. Null when it can't be done.
     *
     * @return array{width: int, height: int}|null
     */
    public static function render(string $pdf, string $png): ?array
    {
        $gs = self::binary();
        if ($gs === null || !is_file($pdf)) {
            return null;
        }
        @unlink($png);
        try {
            $r = Process::run([
                $gs, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNOPROMPT', '-dNOINTERPOLATE',
                '-dFirstPage=1', '-dLastPage=1',
                '-sDEVICE=png16m', '-r' . self::DPI, '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
                '-sOutputFile=' . $png,
                '--', $pdf,            // nothing after -- is read as an option
            ], cwd: dirname($png), env: ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'TMPDIR' => dirname($png)], timeout: self::TIMEOUT);
        } catch (\Throwable) {
            return null;
        }
        if (!$r->ok() || !is_file($png)) {
            @unlink($png);

            return null;
        }
        $info = @getimagesize($png);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            @unlink($png);

            return null;
        }

        return ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /** Where the picture is kept, next to the PDF. */
    public static function key(string $pdfPath): string
    {
        $dir = dirname($pdfPath);

        return ($dir === '.' ? '' : $dir . '/') . 'v/poster.jpg';
    }
}
