<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Kestello\Cms\Content\ContentException;
use RuntimeException;

/** @internal File moves and the update lock shared by the plugin, theme and core updaters. */
final class Files
{
    public static function move(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }
        // another filesystem: copy, then remove
        self::copyTree($from, $to);
        self::removeTree($from);
    }

    public static function copyTree(string $from, string $to): void
    {
        if (!is_dir($from)) {
            throw new RuntimeException("{$from} is missing.");
        }
        is_dir($to) || mkdir($to, 0775, true);
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            if ($item->isLink()) {
                continue;
            }
            $dest = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                @mkdir($dest, 0775, true);
            } elseif (!copy($item->getPathname(), $dest)) {
                throw new RuntimeException("Could not copy {$item->getPathname()}.");
            } else {
                @chmod($dest, ((int) fileperms($item->getPathname())) & 0777);
            }
        }
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * One update (of anything) at a time.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function locked(string $storage, callable $fn): mixed
    {
        $dir = $storage . '/framework';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $lock = fopen($dir . '/update.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw ContentException::conflict('Another update is running. Try again when it has finished.');
        }
        @set_time_limit(0);
        @ignore_user_abort(true);
        try {
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Swapped PHP files must not come from OPcache. */
    public static function forgetCompiled(?string $file = null): void
    {
        if ($file !== null && function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        clearstatcache(true);
    }
}
