<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Framework\Settings\Settings;
use Kestello\Support\Process;

/**
 * Lossless squeezing of the JPEG and PNG variants with jpegoptim and
 * oxipng (or optipng) when the server has them. The pixels don't change;
 * files get 5–20% smaller. Each run has a time limit, and the smaller of
 * before and after is kept, so a tool can never make things worse. Hosts
 * without the tools (or without proc_open) skip this silently.
 *
 * AVIF and WebP come out of the image engine already compressed.
 */
final class Optimizer
{
    public const float TIMEOUT = 20.0;

    /** format => binaries, preferred first */
    private const array TOOLS = ['jpeg' => ['jpegoptim'], 'png' => ['oxipng', 'optipng']];

    /** @var array<string, ?string>|null */
    private static ?array $found = null;

    /** @return array<string, string> format => the tool that will be used */
    public static function tools(): array
    {
        if (self::$found === null) {
            self::$found = [];
            foreach (self::TOOLS as $format => $names) {
                foreach ($names as $name) {
                    $path = Process::available() ? Process::which($name) : null;
                    if ($path !== null) {
                        self::$found[$format] = $path;
                        break;
                    }
                }
            }
        }

        return array_map(static fn(string $p) => basename($p), self::$found);
    }

    /** @internal tests: pretend these binaries are installed (null = look again) */
    public static function use(?array $paths): void
    {
        self::$found = $paths;
    }

    public static function enabled(): bool
    {
        return filter_var(Settings::getInstance()->get('media_optimize', '1'), FILTER_VALIDATE_BOOLEAN);
    }

    /** The same image, maybe smaller. */
    public static function optimize(string $bytes, string $format): string
    {
        self::tools();
        $bin = self::$found[$format] ?? null;
        if ($bin === null || !self::enabled() || $bytes === '') {
            return $bytes;
        }
        $file = tempnam(sys_get_temp_dir(), 'kopt');
        if ($file === false) {
            return $bytes;
        }
        try {
            file_put_contents($file, $bytes);
            $command = match (basename($bin)) {
                'jpegoptim' => [$bin, '--quiet', '--strip-all', '--all-progressive', '--', $file],
                'oxipng' => [$bin, '--quiet', '--opt', '2', '--strip', 'safe', '--', $file],
                'optipng' => [$bin, '-quiet', '-o2', '-strip', 'all', '--', $file],
                default => [$bin, $file],         // a test double
            };
            $r = Process::run($command, env: ['PATH' => '/usr/local/bin:/usr/bin:/bin'], timeout: self::TIMEOUT);
            clearstatcache(true, $file);
            if (!$r->ok()) {
                return $bytes;
            }
            $out = (string) file_get_contents($file);
            // keep the result only when it is still the same kind of image and smaller
            $magic = $format === 'jpeg' ? "\xFF\xD8\xFF" : "\x89PNG";

            return $out !== '' && strlen($out) < strlen($bytes) && str_starts_with($out, $magic) ? $out : $bytes;
        } catch (\Throwable) {
            return $bytes;
        } finally {
            @unlink($file);
        }
    }
}
