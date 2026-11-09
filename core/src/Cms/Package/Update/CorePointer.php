<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use RuntimeException;

/**
 * core/current.php, which names the core release the entry scripts load.
 *
 * While a core update checks the new release, the file is a trial pointer:
 * it names the new release only as long as the updater holds the update
 * lock. When PHP stops the updater halfway, the lock goes with it, and the
 * next request loads the old release again, which then puts the database
 * back (UpdateRecovery). A development checkout has no old release folder:
 * its trial pointer falls back to "..", which is core/ itself.
 */
final class CorePointer
{
    /** What a trial pointer falls back to on a development checkout: core/releases/.. is core/. */
    public const string CHECKOUT = '..';

    public static function plain(string $version): string
    {
        return "<?php\n\n// The core release the site runs; written by the updater.\nreturn " . var_export($version, true) . ";\n";
    }

    /** @param ?string $previous the release to fall back to, null for a development checkout */
    public static function trial(string $to, ?string $previous, string $lockFile): string
    {
        $back = var_export($previous ?? self::CHECKOUT, true);
        $new = var_export($to, true);
        $lock = var_export($lockFile, true);

        return <<<PHP
            <?php

            // Written by the updater while it checks Kestello {$to}. Until the update is
            // confirmed this names {$to} only while the updater holds the update lock: if
            // PHP stops it halfway, the site loads the release it ran before.
            return (static function (): string {
                \$lock = @fopen({$lock}, 'c');
                if (\$lock === false) {
                    return {$back};
                }
                if (@flock(\$lock, LOCK_EX | LOCK_NB)) {
                    flock(\$lock, LOCK_UN);
                    fclose(\$lock);

                    return {$back};
                }
                fclose(\$lock);

                return {$new};
            })();

            PHP;
    }

    /** The release the file names right now, null for none (or the development checkout). */
    public static function read(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        Files::forgetCompiled($file);
        $version = (static fn(string $f): mixed => require $f)($file);

        return is_string($version) && $version !== self::CHECKOUT && preg_match('/^\d+\.\d+\.\d+[0-9A-Za-z.+-]*$/', $version) === 1 ? $version : null;
    }

    /** Replace the file in one rename: a request never reads half of it. */
    public static function write(string $file, string $contents): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException('Could not write core/current.php.');
        }
        @chmod($tmp, 0644);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Could not switch core/current.php.');
        }
        Files::forgetCompiled($file);
    }

    /** Back to running core/ directly (a development checkout). */
    public static function remove(string $file): void
    {
        if (is_file($file) && !@unlink($file)) {
            throw new RuntimeException('Could not remove core/current.php.');
        }
        Files::forgetCompiled($file);
    }
}
