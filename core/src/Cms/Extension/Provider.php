<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

use Kestello\Cms\Cms;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Log\Log;
use Kestello\Framework\Settings\Settings;

/**
 * Base class for everything that extends Kestello: core modules, plugins, themes.
 *
 * Lifecycle (all optional):
 *   register()   every request, before anything boots: bind services into the container
 *   boot()       every request, after all providers registered: routes, events, filters, views…
 *   activate()   once, after migrations ran on activation
 *   upgrade()    once, when a new version's files are detected (after its migrations ran)
 *   deactivate() once, on deactivation (keep data)
 *   uninstall()  once, before data and files are removed
 *
 * @api
 */
abstract class Provider
{
    private ?Package $package = null;

    /** @internal set by the ExtensionManager */
    final public function setPackage(Package $package): void
    {
        $this->package = $package;
    }

    public function register(Container $container): void {}

    public function boot(Cms $cms): void {}

    public function activate(Cms $cms): void {}

    public function upgrade(Cms $cms, string $fromVersion, string $toVersion): void {}

    public function deactivate(Cms $cms): void {}

    public function uninstall(Cms $cms): void {}

    /** The package this provider belongs to (null for core modules). */
    final protected function package(): ?Package
    {
        return $this->package;
    }

    /** Absolute path inside the package. */
    final protected function path(string $relative = ''): string
    {
        return $this->requirePackage()->path($relative);
    }

    /**
     * Read one of this package's settings (stored as "plugin.<slug>.<key>").
     */
    final protected function setting(string $key, mixed $default = null): mixed
    {
        return Settings::getInstance()->get($this->requirePackage()->settingsPrefix() . $key, $default);
    }

    /**
     * Read one of this package's secrets (client secrets, API keys). They are
     * stored sealed with the app key under their own setting name, so a
     * value copied to another key or package won't open. Set them with
     * `bin/cms plugin:config <slug> --secret=<key>` (value read from stdin).
     */
    final protected function secret(string $key, string $default = ''): string
    {
        $name = $this->requirePackage()->settingsPrefix() . $key;
        $value = Settings::getInstance()->get($name);
        if (!is_string($value) || $value === '') {
            return $default;
        }

        return self::openSecret($name, $value) ?? $default;
    }

    /** @internal Open a sealed package secret; null when it can't be opened. */
    public static function openSecret(string $name, string $sealed): ?string
    {
        try {
            return \Kestello\Support\AppKey::secretStore()->open($sealed, 'settings.' . $name);
        } catch (\Throwable) {
            Log::error("secret {$name} could not be opened (app key changed or value not sealed)");
            return null;
        }
    }

    /**
     * Load a route file from the package; it receives $router.
     */
    final protected function loadRoutes(Cms $cms, string $relative = 'routes.php'): void
    {
        $file = $this->path($relative);
        $router = $cms->routes;

        (static function (string $__file) use ($router): void {
            require $__file;
        })($file);
    }

    private function requirePackage(): Package
    {
        return $this->package ?? throw new \LogicException(static::class . ' is not attached to a package.');
    }
}
