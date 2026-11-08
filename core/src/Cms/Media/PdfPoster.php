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
    /** The poster's longest side at most: larger pages are drawn at a lower resolution. */
    public const int MAX_SIDE = 2000;
    /** Pages beyond this many pixels are thrown away instead of stored. */
    public const int MAX_PIXELS = 40_000_000;

    /** @var array<string, ?string> */
    private static array $found = [];

    /** Ghostscript's path, or null when it can't be used here. */
    public static function binary(): ?string
    {
        return self::$found['gs'] ??= (Process::available() ? Process::which('gs') : null);
    }

    /**
     * The resolution to draw page 1 at, from Ghostscript's -dPDFINFO report:
     * DPI, or lower when the page would be longer than MAX_SIDE pixels. Null
     * when the report names no usable page size.
     */
    public static function dpiFor(string $report): ?int
    {
        if (preg_match('/Page 1 MediaBox:\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]/', $report, $m) !== 1) {
            return null;
        }
        $points = max(abs((float) $m[3] - (float) $m[1]), abs((float) $m[4] - (float) $m[2]));
        if ($points < 1) {
            return null;
        }
        $dpi = (int) floor(min(self::DPI, self::MAX_SIDE * 72 / $points));

        return $dpi >= 1 ? $dpi : null;
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
        $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'TMPDIR' => dirname($png)];
        try {
            // the page's size first: a PDF can declare a page of any size, and
            // Ghostscript would draw all of it before anything here could look
            $info = Process::run([
                $gs, '-q', '-dSAFER', '-dNODISPLAY', '-dNOPROMPT', '-dPDFINFO', '-dFirstPage=1', '-dLastPage=1', '--', $pdf,
            ], cwd: dirname($png), env: $env, timeout: self::TIMEOUT);
            $dpi = self::dpiFor($info->output . "\n" . $info->errorOutput);
            if ($dpi === null) {
                return null;
            }
            $r = Process::run([
                $gs, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNOPROMPT', '-dNOINTERPOLATE', '-dPDFSTOPONERROR',
                '-dFirstPage=1', '-dLastPage=1',
                '-sDEVICE=png16m', '-r' . $dpi, '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
                '-sOutputFile=' . $png,
                '--', $pdf,            // nothing after -- is read as an option
            ], cwd: dirname($png), env: $env, timeout: self::TIMEOUT);
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
