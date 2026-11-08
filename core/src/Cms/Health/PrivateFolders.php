<?php

declare(strict_types=1);

namespace Kestello\Cms\Health;

use Kestello\Framework\Http\Client\HttpClient;
use Throwable;

/**
 * The folders browsers must never read: settings and secrets (config),
 * sessions, logs and backups (storage), code (core, content).
 *
 * Only public/ should be the web root. When a host serves the install folder
 * instead, the root .htaccess sends every request into public/, and each
 * private folder carries a deny-all .htaccess too, for Apache setups where
 * rewriting is off. Neither helps on nginx or with AllowOverride None, so the
 * Site Health check asks the site over HTTP for a file in storage/.
 */
final class PrivateFolders
{
    public const array FOLDERS = ['config', 'storage', 'core', 'content'];

    public const string DENY = "# Kestello reads this folder through PHP; browsers never may.\nRequire all denied\n";

    /** The file the check asks for, inside storage/. */
    public const string CANARY = 'framework/web-check.txt';

    /**
     * Put the deny-all .htaccess in each private folder that lacks one.
     *
     * @return list<string> the folders it was added to
     */
    public static function protect(string $root): array
    {
        $added = [];
        foreach (self::FOLDERS as $folder) {
            $dir = rtrim($root, '/') . '/' . $folder;
            if (is_dir($dir) && !is_file($dir . '/.htaccess') && @file_put_contents($dir . '/.htaccess', self::DENY) !== false) {
                $added[] = $folder;
            }
        }

        return $added;
    }

    /**
     * Ask the site at $base for a fresh secret written to storage/.
     *
     * @return bool|null true: readable from the web; false: refused; null: could not tell
     */
    public static function exposed(HttpClient $http, string $base, string $root, string $storage): ?bool
    {
        $base = rtrim($base, '/');
        $root = rtrim($root, '/') . '/';
        if ($base === '' || !str_starts_with(rtrim($storage, '/') . '/', $root)) {
            return null;    // no address, or storage lives outside the install folder
        }
        $file = rtrim($storage, '/') . '/' . self::CANARY;
        $secret = bin2hex(random_bytes(16));
        if (!is_dir(dirname($file)) || @file_put_contents($file, $secret) === false) {
            return null;
        }
        $path = substr($file, strlen($root));
        try {
            $response = $http->get($base . '/' . $path, ['timeout' => 5, 'max_redirects' => 0, 'allow_private' => true]);

            return str_contains($response->body, $secret);
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($file);
        }
    }
}
