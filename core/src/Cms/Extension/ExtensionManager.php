<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

use Kestello\Cms\Cms;
use Kestello\Framework\Core\Autoloader;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\MigrationRunner;
use Kestello\Framework\Database\QueryBuilder;
use RuntimeException;
use Throwable;

/**
 * Discovers, boots and manages modules, plugins and themes.
 *
 * Boot order (every request):
 *   1. core modules register()
 *   2. active plugins register(), in dependency order
 *   3. active theme chain register() (parent before child)
 *   4. everything boot(), same order
 *
 * A package that throws during register/boot, or causes a fatal error
 * attributable to its files, is suspended (safe mode) and the site keeps
 * running without it. Core modules are never suspended: their failures
 * propagate.
 */
final class ExtensionManager
{
    public const string DEFAULT_THEME = 'base';

    /** @var array<string, Package> */
    private array $plugins = [];

    /** @var array<string, Package> */
    private array $themes = [];

    /** @var list<string> */
    private array $discoveryErrors = [];

    /** @var list<array{provider: Provider, owner: string}> */
    private array $providers = [];

    /** @var array<string, Package> slug => booted plugin */
    private array $activePlugins = [];

    /** @var array<string, list<string>> slug => reasons the plugin could not run */
    private array $rejected = [];

    /** @var list<Package> Active theme first, then its parents. */
    private array $themeChain = [];

    private bool $discovered = false;
    private bool $booted = false;
    private bool $recovery = false;

    private readonly Autoloader $autoloader;
    private readonly DependencyResolver $resolver;

    public function __construct(
        private readonly Cms $cms,
        private readonly StateStore $state,
        private readonly SuspensionList $suspensions,
        private readonly string $coreVersion,
        ?DependencyResolver $resolver = null,
    ) {
        $this->autoloader = new Autoloader();
        $this->autoloader->register();
        $this->resolver = $resolver ?? new DependencyResolver($coreVersion);
        $cms->setExtensions($this);
    }

    // ── Discovery ───────────────────────────────────────────────────

    public function discover(bool $force = false): void
    {
        if ($this->discovered && !$force) {
            return;
        }

        $repo = new PackageRepository();
        $this->plugins = $repo->discover($this->cms->paths->plugins(), PackageType::Plugin);
        $this->themes = $repo->discover($this->cms->paths->themes(), PackageType::Theme);
        $this->discoveryErrors = $repo->errors();
        $this->discovered = true;
        \Kestello\Framework\Database\Schema::packagePrefixes(array_values(array_map(static fn(Package $p): string => $p->tablePrefix(), [...array_values($this->plugins), ...array_values($this->themes)])));
        \Kestello\Framework\Storage\StorageManager::packageSlugs(array_keys($this->plugins));
    }

    /** @return array<string, Package> */
    public function plugins(): array
    {
        $this->discover();
        return $this->plugins;
    }

    /** @return array<string, Package> */
    public function themes(): array
    {
        $this->discover();
        return $this->themes;
    }

    /** @return list<string> Invalid manifests found during discovery. */
    public function discoveryErrors(): array
    {
        return $this->discoveryErrors;
    }

    /**
     * Declared permissions of a package by its execution-context owner
     * ("plugin:acme-seo"), or null for core and unknown owners.
     *
     * @return array<string, mixed>|null
     */
    public function permissionsFor(string $owner): ?array
    {
        [$type, $slug] = array_pad(explode(':', $owner, 2), 2, '');
        $package = match ($type) {
            'plugin' => $this->plugins()[$slug] ?? null,
            'theme' => $this->themes()[$slug] ?? null,
            default => null,
        };

        return $package?->permissions();
    }

    /** @return array<string, Package> */
    public function activePlugins(): array
    {
        return $this->activePlugins;
    }

    /** @return array<string, list<string>> */
    public function rejectedPlugins(): array
    {
        return $this->rejected;
    }

    /** The running theme, or null when no theme could be loaded. */
    public function activeTheme(): ?Package
    {
        return $this->themeChain[0] ?? null;
    }

    /** @return list<Package> Active theme, then parents. */
    public function themeChain(): array
    {
        return $this->themeChain;
    }

    public function isRecoveryMode(): bool
    {
        return $this->recovery;
    }

    public function suspensions(): SuspensionList
    {
        return $this->suspensions;
    }

    public function stateFor(PackageType $type, string $slug): ?PackageState
    {
        return $this->state->get($type, $slug);
    }

    public function isPluginEnabled(string $slug): bool
    {
        return $this->state->get(PackageType::Plugin, $slug)?->active ?? false;
    }

    /** The configured theme slug (may differ from the running one if it failed). */
    public function configuredTheme(): string
    {
        foreach ($this->state->all(PackageType::Theme) as $state) {
            if ($state->active) {
                return $state->slug;
            }
        }

        return self::DEFAULT_THEME;
    }

    /**
     * Find the active package (plugin or theme chain member) owning $owner id.
     */
    public function findActive(PackageType $type, string $slug): ?Package
    {
        if ($type === PackageType::Plugin) {
            return $this->activePlugins[$slug] ?? null;
        }

        foreach ($this->themeChain as $theme) {
            if ($theme->slug() === $slug) {
                return $theme;
            }
        }

        return null;
    }

    // ── Boot ────────────────────────────────────────────────────────

    /**
     * Add a core module. Modules use the same lifecycle as plugins but run as "core".
     */
    public function addModule(Provider $module): void
    {
        $module->register($this->cms->container);
        $this->providers[] = ['provider' => $module, 'owner' => 'core'];
    }

    /**
     * @param bool $recovery Skip all plugins and use the default theme.
     */
    public function boot(bool $recovery = false): void
    {
        if ($this->booted) {
            throw new RuntimeException('Extensions already booted.');
        }

        $this->booted = true;
        $this->recovery = $recovery;
        $this->discover();
        $this->registerFatalErrorHandler();

        if (!$recovery) {
            foreach ($this->resolveActivePlugins() as $plugin) {
                if ($this->registerPackage($plugin)) {
                    $this->activePlugins[$plugin->slug()] = $plugin;
                }
            }
        }

        $this->registerTheme($recovery ? self::DEFAULT_THEME : $this->configuredTheme());

        foreach ($this->providers as $entry) {
            $this->guard($entry['owner'], fn() => $entry['provider']->boot($this->cms), 'boot');
        }

        if (!$recovery) {
            $this->runPendingUpgrades();
        }
    }

    /**
     * Active plugins that can run together, in load order.
     *
     * @return list<Package>
     */
    private function resolveActivePlugins(): array
    {
        $candidates = [];

        foreach ($this->state->all(PackageType::Plugin) as $state) {
            $plugin = $this->plugins[$state->slug] ?? null;

            if (!$state->active || $plugin === null) {
                continue;
            }

            if ($this->suspensions->isSuspended($plugin->owner())) {
                $this->rejected[$plugin->slug()] = ['suspended: ' . $this->suspensions->reason($plugin->owner())];
                continue;
            }

            $candidates[$plugin->slug()] = $plugin;
        }

        [$runnable, $rejected] = $this->resolver->runnable($candidates);
        $this->rejected += $rejected;

        try {
            return $this->resolver->order($runnable);
        } catch (RuntimeException $e) {
            // A cycle should have been refused at activation; never take the site down for it.
            error_log('[kestello] ' . $e->getMessage() . ' — plugins skipped.');
            foreach ($runnable as $slug => $_) {
                $this->rejected[$slug] = [$e->getMessage()];
            }
            return [];
        }
    }

    /**
     * Wire autoloading and call register(). Returns false if the package was suspended.
     */
    private function registerPackage(Package $package): bool
    {
        $manifest = $package->manifest;

        if ($manifest->namespace !== null) {
            $this->autoloader->addNamespace($manifest->namespace, $package->srcPath());
        }

        if ($manifest->provider === null) {
            return true;
        }

        return $this->guard($package->owner(), function () use ($package, $manifest): void {
            $class = $manifest->provider;

            if (!class_exists($class)) {
                throw new RuntimeException("Provider class {$class} not found in {$package->srcPath()}.");
            }

            $provider = new $class();

            if (!$provider instanceof Provider) {
                throw new RuntimeException("{$class} must extend " . Provider::class . '.');
            }

            $provider->setPackage($package);
            $provider->register($this->cms->container);
            $this->providers[] = ['provider' => $provider, 'owner' => $package->owner()];
        }, 'register');
    }

    /**
     * Load the theme chain (child first) and add template paths. Falls back to
     * the default theme, then to core pages only.
     */
    private function registerTheme(string $slug): void
    {
        $chain = $this->resolveThemeChain($slug);

        if ($chain === [] && $slug !== self::DEFAULT_THEME) {
            $chain = $this->resolveThemeChain(self::DEFAULT_THEME);
        }

        // Register parents first so a child can build on its parent's services.
        foreach (array_reverse($chain) as $theme) {
            if (!$this->registerPackage($theme)) {
                // A broken parent or child takes the whole chain down to the default theme.
                $this->themeChain = [];
                if ($slug !== self::DEFAULT_THEME) {
                    $this->registerTheme(self::DEFAULT_THEME);
                }
                return;
            }
        }

        $this->themeChain = $chain;
    }

    /**
     * @return list<Package> child first; empty if the theme or a parent is unusable.
     */
    private function resolveThemeChain(string $slug): array
    {
        $chain = [];
        $seen = [];

        while ($slug !== null) {
            $theme = $this->themes[$slug] ?? null;

            if ($theme === null || isset($seen[$slug]) || count($chain) >= 3
                || $this->suspensions->isSuspended($theme->owner())
                || $this->resolver->problems($theme, []) !== []) {
                return [];
            }

            $seen[$slug] = true;
            $chain[] = $theme;
            $slug = $theme->manifest->parent;
        }

        return $chain;
    }

    /**
     * Run $callback as $owner. Packages that throw are suspended; core rethrows.
     */
    private function guard(string $owner, callable $callback, string $phase): bool
    {
        try {
            ExecutionContext::run($owner, $callback);
            return true;
        } catch (Throwable $e) {
            if ($owner === 'core') {
                throw $e;
            }

            $this->suspend($owner, sprintf('%s failed during %s: %s (%s:%d)', $owner, $phase, $e->getMessage(), $e->getFile(), $e->getLine()));

            // Drop its providers so nothing of it boots later in this request.
            $this->providers = array_values(array_filter($this->providers, static fn(array $p): bool => $p['owner'] !== $owner));
            [$type, $slug] = explode(':', $owner, 2);
            if ($type === PackageType::Plugin->value) {
                unset($this->activePlugins[$slug]);
            }

            return false;
        }
    }

    private function suspend(string $owner, string $reason): void
    {
        $this->suspensions->suspend($owner, $reason);
        error_log('[kestello] safe mode suspended ' . $owner . ': ' . $reason);
    }

    /**
     * After a fatal error, suspend the package whose file caused it (or, failing
     * that, the package that was executing). The next request runs without it.
     */
    private function registerFatalErrorHandler(): void
    {
        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
                return;
            }

            $owner = $this->ownerOfFile((string) $error['file']);

            if ($owner === null) {
                foreach (array_reverse(ExecutionContext::stack()) as $candidate) {
                    if ($candidate !== 'core') {
                        $owner = $candidate;
                        break;
                    }
                }
            }

            if ($owner !== null) {
                $this->suspend($owner, sprintf('fatal error: %s (%s:%d)', $error['message'], $error['file'], $error['line']));
            }
        });
    }

    private function ownerOfFile(string $file): ?string
    {
        $real = realpath($file) ?: $file;

        foreach ([...array_values($this->plugins), ...array_values($this->themes)] as $package) {
            $root = realpath($package->path) ?: $package->path;
            if (str_starts_with($real, $root . '/')) {
                return $package->owner();
            }
        }

        return null;
    }

    /**
     * Files of an active plugin or theme were replaced with a new version:
     * run its new migrations and upgrade() hook once, and record the version.
     */
    private function runPendingUpgrades(): void
    {
        $running = [];
        foreach ($this->activePlugins as $slug => $plugin) {
            $running[] = [PackageType::Plugin, $slug, $plugin];
        }
        foreach ($this->themeChain as $theme) {
            $running[] = [PackageType::Theme, $theme->slug(), $theme];
        }
        foreach ($running as [$type, $slug, $plugin]) {
            $state = $this->state->get($type, $slug);

            if ($state === null || $state->version === $plugin->version()) {
                continue;
            }

            $this->guard($plugin->owner(), function () use ($plugin, $state): void {
                $this->migrator($plugin)->migrate();
                $this->providerFor($plugin)?->upgrade($this->cms, $state->version, $plugin->version());
                $this->state->save($state->with(version: $plugin->version()));
            }, 'upgrade');
        }
    }

    // ── Lifecycle ───────────────────────────────────────────────────

    /**
     * Copy a package directory into content/plugins or content/themes.
     */
    public function install(string $source, PackageType $type, bool $overwrite = false): Package
    {
        $source = rtrim($source, '/');
        $manifest = Manifest::load($source, $type);
        $target = ($type === PackageType::Plugin ? $this->cms->paths->plugins() : $this->cms->paths->themes()) . '/' . $manifest->slug;

        if (realpath($source) === realpath($target)) {
            throw new RuntimeException("{$manifest->slug} is already in place at {$target}.");
        }

        if (is_dir($target)) {
            if (!$overwrite) {
                throw new RuntimeException("{$type->value} \"{$manifest->slug}\" is already installed. Use --force to replace it.");
            }
            self::removeDirectory($target);
        }

        self::copyDirectory($source, $target);
        $this->discover(force: true);

        $package = $this->packages($type)[$manifest->slug] ?? throw new RuntimeException('Installed package could not be read back.');

        if ($this->state->get($type, $package->slug()) === null) {
            $this->state->save(new PackageState($type, $package->slug(), false, $package->version()));
        }

        return $package;
    }

    public function activatePlugin(string $slug): Package
    {
        $plugin = $this->plugins()[$slug] ?? throw new RuntimeException("Plugin \"{$slug}\" is not installed.");

        $set = $this->enabledPlugins();
        $set[$slug] = $plugin;

        $problems = $this->resolver->problems($plugin, $set);
        if ($problems !== []) {
            throw new RuntimeException("Cannot activate \"{$slug}\":\n  - " . implode("\n  - ", $problems));
        }

        $this->resolver->order($set); // throws on cycles

        $this->ensureLoaded($plugin);

        $provider = $this->providerFor($plugin);

        // Replaced by a newer version while it was off: it gets its upgrade() now, as it would have running.
        $previous = $this->state->get(PackageType::Plugin, $slug)?->version;

        ExecutionContext::run($plugin->owner(), function () use ($plugin, $provider, $slug, $previous): void {
            $this->migrator($plugin)->migrate();
            if ($previous !== null && $previous !== $plugin->version()) {
                $provider?->upgrade($this->cms, $previous, $plugin->version());
            }

            // Pre-flight: a plugin that cannot register/boot is refused here
            // instead of being activated and suspended on the next request.
            try {
                $provider?->register($this->cms->container);
                $this->cms->content->preflight(fn() => $provider?->boot($this->cms));
            } catch (Throwable $e) {
                throw new RuntimeException(sprintf(
                    'Cannot activate "%s": it failed to boot: %s (%s:%d)',
                    $slug,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ), 0, $e);
            }

            $provider?->activate($this->cms);
        });

        $this->state->save(new PackageState(PackageType::Plugin, $slug, true, $plugin->version()));
        $this->suspensions->clear($plugin->owner());

        return $plugin;
    }

    public function deactivatePlugin(string $slug): void
    {
        $plugin = $this->plugins()[$slug] ?? throw new RuntimeException("Plugin \"{$slug}\" is not installed.");

        $dependents = [];
        foreach ($this->enabledPlugins() as $other) {
            if ($other->slug() !== $slug && isset($other->manifest->dependencies[$slug])) {
                $dependents[] = $other->slug();
            }
        }

        if ($dependents !== []) {
            throw new RuntimeException("Cannot deactivate \"{$slug}\": required by " . implode(', ', $dependents) . '.');
        }

        try {
            $this->ensureLoaded($plugin);
            ExecutionContext::run($plugin->owner(), fn() => $this->providerFor($plugin)?->deactivate($this->cms));
        } catch (Throwable $e) {
            // Deactivation must always succeed, even for a broken plugin.
            error_log("[kestello] {$slug} deactivate() failed: {$e->getMessage()}");
        }

        $state = $this->state->get(PackageType::Plugin, $slug) ?? new PackageState(PackageType::Plugin, $slug, false, $plugin->version());
        $this->state->save($state->with(active: false));
        $this->suspensions->clear($plugin->owner());
    }

    /**
     * Remove a plugin's data (its migrations are rolled back, its settings
     * deleted) and, optionally, its files. The plugin must be inactive.
     */
    public function uninstallPlugin(string $slug, bool $deleteFiles = true): void
    {
        $plugin = $this->plugins()[$slug] ?? throw new RuntimeException("Plugin \"{$slug}\" is not installed.");

        if ($this->isPluginEnabled($slug)) {
            throw new RuntimeException("Deactivate \"{$slug}\" before uninstalling it.");
        }

        $this->ensureLoaded($plugin);

        ExecutionContext::run($plugin->owner(), function () use ($plugin): void {
            $this->providerFor($plugin)?->uninstall($this->cms);
            $this->migrator($plugin)->reset();
        });

        QueryBuilder::table('settings')->where('key', 'LIKE', QueryBuilder::escapeLike($plugin->settingsPrefix()) . '%')->delete();
        $this->state->delete(PackageType::Plugin, $slug);
        $this->suspensions->clear($plugin->owner());

        if ($deleteFiles) {
            self::removeDirectory($plugin->path);
            unset($this->plugins[$slug]);
        }
    }

    public function activateTheme(string $slug): Package
    {
        $theme = $this->themes()[$slug] ?? throw new RuntimeException("Theme \"{$slug}\" is not installed.");

        $problems = $this->resolver->problems($theme, []);
        if ($theme->manifest->parent !== null && !isset($this->themes[$theme->manifest->parent])) {
            $problems[] = "parent theme \"{$theme->manifest->parent}\" is not installed";
        }
        if ($problems !== []) {
            throw new RuntimeException("Cannot activate theme \"{$slug}\":\n  - " . implode("\n  - ", $problems));
        }

        foreach ($this->state->all(PackageType::Theme) as $state) {
            if ($state->active && $state->slug !== $slug) {
                $this->state->save($state->with(active: false));
            }
        }

        $this->state->save(new PackageState(PackageType::Theme, $slug, true, $theme->version()));
        $this->suspensions->clear($theme->owner());

        return $theme;
    }

    /**
     * Why $candidate (a new version of an installed package, not yet in
     * place) could not replace the installed one: its own requirements, and
     * the constraints active plugins put on it.
     *
     * @return list<string>
     */
    public function updateProblems(Package $candidate): array
    {
        $slug = $candidate->slug();
        if ($candidate->type() === PackageType::Theme) {
            $problems = $this->resolver->problems($candidate, []);
            if ($candidate->manifest->parent !== null && !isset($this->themes()[$candidate->manifest->parent])) {
                $problems[] = "parent theme \"{$candidate->manifest->parent}\" is not installed";
            }
            return $problems;
        }
        $set = $this->enabledPlugins();
        $set[$slug] = $candidate;
        $problems = $this->resolver->problems($candidate, $set);
        foreach ($set as $other) {
            $constraint = $other->slug() !== $slug ? ($other->manifest->dependencies[$slug] ?? null) : null;
            if ($constraint !== null && !\Kestello\Framework\Support\Semver::satisfies($candidate->version(), $constraint)) {
                $problems[] = "{$other->manifest->name} needs {$slug} {$constraint}";
            }
        }

        return $problems;
    }

    /**
     * Active plugins and themes that don't accept core $version: what a core
     * update to it would break.
     *
     * @return list<string>
     */
    public function coreUpdateProblems(string $version): array
    {
        $problems = [];
        foreach ([...array_values($this->enabledPlugins()), ...$this->themeChain()] as $package) {
            $m = $package->manifest;
            if (!\Kestello\Framework\Support\Semver::satisfiesRelease($version, $m->requiresCore)) {
                $kind = $package->type() === PackageType::Theme ? 'theme' : 'plugin';
                $problems[] = "The {$kind} {$m->name} {$m->version} supports Kestello {$m->requiresCore}, not {$version}.";
            }
        }

        return $problems;
    }

    public function migrator(Package $package): MigrationRunner
    {
        return new MigrationRunner($package->migrationsPath(), $package->owner(), $package->tablePrefix());
    }

    /**
     * @return array<string, Package> plugins whose state is active (whether or not they booted)
     */
    private function enabledPlugins(): array
    {
        $set = [];
        foreach ($this->state->all(PackageType::Plugin) as $state) {
            if ($state->active && isset($this->plugins()[$state->slug])) {
                $set[$state->slug] = $this->plugins[$state->slug];
            }
        }
        return $set;
    }

    /**
     * @return array<string, Package>
     */
    private function packages(PackageType $type): array
    {
        return $type === PackageType::Plugin ? $this->plugins() : $this->themes();
    }

    /**
     * Make a package's classes and provider available outside of boot (lifecycle commands).
     */
    private function ensureLoaded(Package $package): void
    {
        if ($this->providerFor($package) !== null || $package->manifest->provider === null) {
            if ($package->manifest->namespace !== null) {
                $this->autoloader->addNamespace($package->manifest->namespace, $package->srcPath());
            }
            return;
        }

        ExecutionContext::run($package->owner(), function () use ($package): void {
            if ($package->manifest->namespace !== null) {
                $this->autoloader->addNamespace($package->manifest->namespace, $package->srcPath());
            }

            $class = (string) $package->manifest->provider;
            if (!class_exists($class)) {
                throw new RuntimeException("Provider class {$class} not found.");
            }

            $provider = new $class();
            if (!$provider instanceof Provider) {
                throw new RuntimeException("{$class} must extend " . Provider::class . '.');
            }

            $provider->setPackage($package);
            $this->providers[] = ['provider' => $provider, 'owner' => $package->owner()];
        });
    }

    private function providerFor(Package $package): ?Provider
    {
        foreach ($this->providers as $entry) {
            if ($entry['owner'] === $package->owner()) {
                return $entry['provider'];
            }
        }

        return null;
    }

    private static function copyDirectory(string $from, string $to): void
    {
        $skip = ['.git', 'node_modules', '.DS_Store'];
        mkdir($to, 0775, true);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $f): bool => !in_array($f->getFilename(), $skip, true) && !$f->isLink(),
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? @mkdir($target, 0775, true) : copy($item->getPathname(), $target);
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }

    /** @internal for diagnostics */
    public function coreVersion(): string
    {
        return $this->coreVersion;
    }
}
