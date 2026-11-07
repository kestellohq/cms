<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

/**
 * An installed plugin or theme on disk.
 *
 * @api
 */
final readonly class Package
{
    public string $path;

    public function __construct(
        public Manifest $manifest,
        string $path,
    ) {
        $this->path = rtrim($path, '/');
    }

    public function type(): PackageType
    {
        return $this->manifest->type;
    }

    public function slug(): string
    {
        return $this->manifest->slug;
    }

    public function version(): string
    {
        return $this->manifest->version;
    }

    /** Execution-context owner id: "plugin:acme-seo", "theme:aurora". */
    public function owner(): string
    {
        return $this->manifest->type->value . ':' . $this->manifest->slug;
    }

    /**
     * Declared permissions from the manifest, e.g. ['network' => ['api.github.com'], 'auth' => ['authenticator']].
     *
     * @return array<string, mixed>
     */
    public function permissions(): array
    {
        return $this->manifest->permissions;
    }

    /** Required prefix for this package's database tables: "p_acme_seo_" / "t_aurora_". */
    public function tablePrefix(): string
    {
        $letter = $this->manifest->type === PackageType::Plugin ? 'p' : 't';

        return $letter . '_' . str_replace('-', '_', $this->manifest->slug) . '_';
    }

    /** Settings key prefix: "plugin.acme-seo.". */
    public function settingsPrefix(): string
    {
        return $this->manifest->type->value . '.' . $this->manifest->slug . '.';
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->path : $this->path . '/' . ltrim($relative, '/');
    }

    public function srcPath(): string
    {
        return $this->path('src');
    }

    public function migrationsPath(): string
    {
        return $this->path('database/migrations');
    }

    public function assetsPath(): string
    {
        return $this->path('assets');
    }


}
