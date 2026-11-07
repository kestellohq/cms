<?php

declare(strict_types=1);

namespace Kestello\Cms\Package;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\Manifest;
use Kestello\Cms\Extension\ManifestException;
use Kestello\Cms\Extension\Package;
use Kestello\Cms\Extension\PackageChecker;
use Kestello\Cms\Extension\PackageType;
use Kestello\Support\Zip\Zip;
use Kestello\Support\Zip\ZipEntry;
use Throwable;

/**
 * Installs plugin and theme zips.
 *
 * Who may install what:
 *   - tampered packages: nobody
 *   - signed by a key in app.packages.trusted_keys: the admin and the CLI
 *   - unsigned or signed by an unknown key: the CLI (a developer on the
 *     server), and the admin only when app.packages.admin_unsigned is true
 *     in the server's config
 * plugin:check runs on every package; its errors refuse the install.
 *
 * The zip holds the package at its root or in one top folder; the folder
 * name doesn't matter, the manifest's slug does.
 */
final class PackageInstaller
{
    private const int MAX_BYTES = 256 << 20;
    private const string TOKEN = '/^[a-f0-9]{32}$/';

    public function __construct(private readonly Cms $cms) {}

    /**
     * Read a package zip without installing it.
     *
     * @return array<string, mixed>
     * @throws ContentException
     */
    public function inspect(string $zipPath): array
    {
        $work = $this->extract($zipPath);
        try {
            return $this->describe($zipPath, $work);
        } finally {
            self::removeTree($work['root']);
        }
    }

    /**
     * @param array{replace?: bool, allow_unsigned?: bool, replace_active?: bool} $options (replace_active: CLI only; the update pipeline replaces active packages safely)
     * @return array{package: Package, replaced: bool, check: array<string, mixed>}
     * @throws ContentException
     */
    public function install(string $zipPath, array $options = []): array
    {
        $staged = $this->stage($zipPath);
        try {
            $check = $staged['check'];
            $this->assertAllowed($check, $options);
            $installed = $check['installed'];
            if ($installed !== null) {
                if (!($options['replace'] ?? false)) {
                    throw ContentException::conflict("{$check['name']} is already installed (version {$installed['version']}).");
                }
                if ($installed['active'] && !($options['replace_active'] ?? false)) {
                    throw ContentException::conflict("Deactivate {$check['name']} before replacing it, or update it.");
                }
            }
            $package = $this->extensions()->install($staged['package'], $staged['type'], $installed !== null);

            return ['package' => $package, 'replaced' => $installed !== null, 'check' => $check];
        } catch (ContentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ContentException::conflict($e->getMessage());
        } finally {
            $this->discard($staged);
        }
    }

    /**
     * Unpack and describe a package in storage/tmp; discard() it when done.
     *
     * @return array{root: string, package: string, type: PackageType, check: array<string, mixed>}
     * @throws ContentException
     */
    public function stage(string $zipPath): array
    {
        $work = $this->extract($zipPath);
        try {
            return $work + ['check' => $this->describe($zipPath, $work)];
        } catch (Throwable $e) {
            self::removeTree($work['root']);
            throw $e instanceof ContentException ? $e : ContentException::invalid(['file' => [$e->getMessage()]]);
        }
    }

    /** @param array{root: string} $staged */
    public function discard(array $staged): void
    {
        self::removeTree($staged['root']);
    }

    /**
     * Signature policy and code check (see the class comment).
     *
     * @param array<string, mixed> $check
     * @param array{allow_unsigned?: bool} $options
     * @throws ContentException
     */
    public function assertAllowed(array $check, array $options): void
    {
        $sig = $check['signature']['status'];
        if ($sig === PackageSignature::TAMPERED) {
            throw ContentException::invalid(['file' => ['The package was changed after it was signed. It was not installed.']]);
        }
        if ($sig !== PackageSignature::SIGNED && !($options['allow_unsigned'] ?? false)) {
            throw ContentException::forbidden($sig === PackageSignature::UNTRUSTED
                ? 'This package is signed by a key this site does not trust. A developer can install it from the command line, or add the key to app.packages.trusted_keys.'
                : 'This package is not signed. Packages with code are installed here only when signed by a trusted publisher; a developer can install it from the command line.');
        }
        $errors = array_values(array_filter($check['findings'], static fn(array $f): bool => $f['level'] === 'error'));
        if ($errors !== []) {
            throw ContentException::invalid(['file' => array_map(static fn(array $f): string => "{$f['file']}" . ($f['line'] > 0 ? ":{$f['line']}" : '') . ": {$f['message']}", array_slice($errors, 0, 10))]);
        }
    }

    // ── Uploads waiting for confirmation (the admin shows what a package does first) ──

    /** Keep an uploaded zip for an hour; returns its token. */
    public function hold(string $uploadedPath): string
    {
        $dir = $this->cms->paths->storage('tmp/packages');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        foreach (glob($dir . '/*.zip') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
        $token = bin2hex(random_bytes(16));
        if (!copy($uploadedPath, $dir . '/' . $token . '.zip')) {
            throw new \RuntimeException('Could not keep the upload.');
        }

        return $token;
    }

    public function held(string $token): string
    {
        $file = $this->cms->paths->storage('tmp/packages') . '/' . $token . '.zip';
        if (preg_match(self::TOKEN, $token) !== 1 || !is_file($file) || filemtime($file) < time() - 3600) {
            throw ContentException::invalid(['token' => ['The upload has expired. Upload the package again.']]);
        }

        return $file;
    }

    public function release(string $token): void
    {
        if (preg_match(self::TOKEN, $token) === 1) {
            @unlink($this->cms->paths->storage('tmp/packages') . '/' . $token . '.zip');
        }
    }

    /** @return array<string, array{name: string, public_key: string}> */
    public function trustedKeys(): array
    {
        return PackageSignature::trustedKeys($this->cms->config->get('app.packages.trusted_keys', []));
    }

    public function adminMayInstallUnsigned(): bool
    {
        return (bool) $this->cms->config->get('app.packages.admin_unsigned', false);
    }

    // ── Internals ───────────────────────────────────────────────────

    /** @return array{root: string, package: string, type: PackageType} */
    private function extract(string $zipPath): array
    {
        try {
            $zip = Zip::open($zipPath);
            $names = array_values(array_filter(array_map(static fn(ZipEntry $e): string => $e->name, $zip->entries()), static fn(string $n): bool => !str_ends_with($n, '/')));
        } catch (Throwable $e) {
            throw ContentException::invalid(['file' => ['This is not a zip file: ' . $e->getMessage()]]);
        }
        $prefix = '';
        $type = null;
        foreach (['plugin.json' => PackageType::Plugin, 'theme.json' => PackageType::Theme] as $file => $t) {
            if (in_array($file, $names, true)) {
                $type = $t;
                break;
            }
        }
        if ($type === null) {
            $tops = array_unique(array_map(static fn(string $n): string => explode('/', $n, 2)[0], array_filter($names, static fn(string $n): bool => $n !== PackageSignature::FILE)));
            if (count($tops) === 1) {
                $prefix = reset($tops) . '/';
                foreach (['plugin.json' => PackageType::Plugin, 'theme.json' => PackageType::Theme] as $file => $t) {
                    if (in_array($prefix . $file, $names, true)) {
                        $type = $t;
                        break;
                    }
                }
            }
        }
        if ($type === null) {
            throw ContentException::invalid(['file' => ['There is no plugin.json or theme.json in the zip (at its root or in one folder).']]);
        }

        $root = $this->cms->paths->storage('tmp/pkg-' . bin2hex(random_bytes(6)));
        mkdir($root, 0700, true);
        try {
            $zip->extractTo($root . '/x', self::MAX_BYTES, 200, static fn(ZipEntry $e): bool => $e->name !== PackageSignature::FILE && !$e->isSymlink);
            $file = $root . '/x/' . $prefix . $type->manifestFile();
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new ManifestException("{$type->manifestFile()} must contain a JSON object.");
            }
            $manifest = Manifest::fromArray($data, $type, $type->manifestFile());
            // the manifest wants its folder to be named after the slug
            $package = $root . '/p/' . $manifest->slug;
            mkdir(dirname($package), 0700, true);
            rename(rtrim($root . '/x/' . $prefix, '/'), $package);
        } catch (ManifestException | \JsonException $e) {
            self::removeTree($root);
            throw ContentException::invalid(['file' => [$e->getMessage()]]);
        } catch (Throwable $e) {
            self::removeTree($root);
            throw ContentException::invalid(['file' => ['The zip could not be unpacked: ' . $e->getMessage()]]);
        }

        return ['root' => $root, 'package' => $package, 'type' => $type];
    }

    /**
     * @param array{root: string, package: string, type: PackageType} $work
     * @return array<string, mixed>
     */
    private function describe(string $zipPath, array $work): array
    {
        $manifest = Manifest::load($work['package'], $work['type']);
        $signature = PackageSignature::verify(Zip::open($zipPath), $this->trustedKeys());
        $findings = (new PackageChecker())->check($work['package'], $work['type']);
        $hasCode = $manifest->provider !== null || is_dir($work['package'] . '/src') || glob($work['package'] . '/{,*/,*/*/}*.php', GLOB_BRACE) !== [];

        return [
            'type' => $work['type']->value,
            'slug' => $manifest->slug,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'description' => $manifest->description,
            'author' => is_array($manifest->raw['author'] ?? null) ? (string) ($manifest->raw['author']['name'] ?? '') : (string) ($manifest->raw['author'] ?? ''),
            'requires' => ['core' => $manifest->requiresCore, 'php' => $manifest->requiresPhp, 'plugins' => $manifest->dependencies],
            'permissions' => $manifest->permissions,
            'replaces' => $manifest->replaces,
            'code' => $hasCode,
            'signature' => $signature,
            'findings' => $findings,
            'installed' => $this->installed($work['type'], $manifest->slug),
        ];
    }

    /**
     * The installed copy of a package, read now (call again after taking the
     * update lock: another update may have finished in the meantime).
     *
     * @return array{version: string, active: bool}|null
     */
    public function installed(PackageType $type, string $slug): ?array
    {
        $this->extensions()->discover(true);
        $list = $type === PackageType::Plugin ? $this->extensions()->plugins() : $this->extensions()->themes();
        $current = $list[$slug] ?? null;
        if ($current === null) {
            return null;
        }

        return ['version' => $current->version(), 'active' => $type === PackageType::Plugin
            ? $this->extensions()->isPluginEnabled($slug)
            : $this->extensions()->configuredTheme() === $slug];
    }

    private function extensions(): ExtensionManager
    {
        return $this->cms->extensions();
    }

    private static function removeTree(string $dir): void
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
}
