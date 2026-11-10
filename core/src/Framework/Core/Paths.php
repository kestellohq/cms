<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

/**
 * Every filesystem location the application uses.
 *
 * Core code must never build paths from a base directory by hand. Ask
 * Paths instead, so the site layout and the core location can change
 * (versioned core releases, custom storage dirs) without touching callers.
 *
 *  - root: the site install (config, storage, content, public)
 *  - core: the running core release (src, views, migrations, defaults)
 *  - storage: what the site writes (cache, sessions, logs, uploaded files);
 *    root/storage unless KESTELLO_STORAGE names another folder (fromEnv())
 */
final class Paths
{
    public readonly string $root;
    public readonly string $core;
    public readonly string $storage;

    public function __construct(string $root, string $core, ?string $storage = null)
    {
        $this->root = rtrim($root, '/');
        $this->core = rtrim($core, '/');
        $this->storage = $storage === null || trim($storage) === '' ? $this->root . '/storage' : self::absolute(rtrim($storage, '/'), $this->root);
    }

    /**
     * Paths for a site, with storage from KESTELLO_STORAGE when it is set: an
     * absolute folder, or one relative to the site, such as a throwaway folder
     * for a test run.
     *
     * @param array<string, string>|null $env null: the process environment
     */
    public static function fromEnv(string $root, string $core, ?array $env = null): self
    {
        $storage = $env === null ? getenv('KESTELLO_STORAGE') : ($env['KESTELLO_STORAGE'] ?? false);

        return new self($root, $core, is_string($storage) ? $storage : null);
    }

    private static function absolute(string $path, string $root): string
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1 ? $path : $root . '/' . preg_replace('#^\./#', '', $path);
    }

    public function root(string $path = ''): string
    {
        return self::join($this->root, $path);
    }

    public function core(string $path = ''): string
    {
        return self::join($this->core, $path);
    }

    // ── Site ────────────────────────────────────────────────────────

    public function config(string $path = ''): string
    {
        return self::join($this->root . '/config', $path);
    }

    public function storage(string $path = ''): string
    {
        return self::join($this->storage, $path);
    }

    public function cache(): string
    {
        return $this->storage('cache');
    }

    public function compiled(): string
    {
        return $this->storage('compiled');
    }

    public function logs(): string
    {
        return $this->storage('logs');
    }

    public function sessions(): string
    {
        return $this->storage('sessions');
    }

    public function public(string $path = ''): string
    {
        return self::join($this->root . '/public', $path);
    }

    public function content(string $path = ''): string
    {
        return self::join($this->root . '/content', $path);
    }

    public function plugins(): string
    {
        return $this->content('plugins');
    }

    public function themes(): string
    {
        return $this->content('themes');
    }

    public function uploads(): string
    {
        return $this->content('uploads');
    }

    // ── Core ────────────────────────────────────────────────────────

    /** Public core assets (vendored Vue, built Vue pages), served at /assets/core/. */
    public function coreAssets(): string
    {
        return $this->core . '/resources/assets';
    }

    public function coreMigrations(): string
    {
        return $this->core . '/database/migrations';
    }

    public function coreConfig(): string
    {
        return $this->core . '/config';
    }

    private static function join(string $base, string $path): string
    {
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}
