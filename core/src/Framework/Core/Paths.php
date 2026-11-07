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
 */
final class Paths
{
    public readonly string $root;
    public readonly string $core;

    public function __construct(string $root, string $core)
    {
        $this->root = rtrim($root, '/');
        $this->core = rtrim($core, '/');
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
        return self::join($this->root . '/storage', $path);
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
